const mysql = require('mysql2/promise');

/**
 * §3.2: several journeys require asserting persisted/backend state that
 * the UI never displays directly — audit log rows, queued job payloads,
 * soft-delete timestamps, reminder-sent stamps. Mocking these away would
 * defeat the point of an E2E suite (the requirement is explicit: "do not
 * use mocks for functionality that is specifically being tested
 * end-to-end"), so this connects directly to the same database the app
 * under test is using and queries it for real.
 */
class Db {
  constructor() {
    this.pool = null;
  }

  async connect() {
    if (this.pool) return this.pool;
    this.pool = mysql.createPool({
      host: process.env.DB_HOST || '127.0.0.1',
      port: Number(process.env.DB_PORT || 3306),
      user: process.env.DB_USERNAME || 'root',
      password: process.env.DB_PASSWORD || '',
      database: process.env.DB_DATABASE || 'lawfirm_e2e',
      waitForConnections: true,
      connectionLimit: 5,
    });
    return this.pool;
  }

  async query(sql, params = []) {
    const pool = await this.connect();
    const [rows] = await pool.query(sql, params);
    return rows;
  }

  async findOne(sql, params = []) {
    const rows = await this.query(sql, params);
    return rows[0] || null;
  }

  async close() {
    if (this.pool) {
      await this.pool.end();
      this.pool = null;
    }
  }

  // --- Domain-specific helpers, so specs read as assertions on the
  // domain rather than raw SQL scattered through every test file. ---

  async getUserByEmail(email) {
    return this.findOne('SELECT * FROM users WHERE email = ? LIMIT 1', [email]);
  }

  async getCaseByCaseNumber(caseNumber) {
    return this.findOne(
      'SELECT * FROM cases WHERE case_number = ? AND deleted_at IS NULL LIMIT 1',
      [caseNumber]
    );
  }

  async getClientByPhone(phone) {
    return this.findOne(
      'SELECT * FROM clients WHERE phone_number = ? AND deleted_at IS NULL LIMIT 1',
      [phone]
    );
  }

  async isSoftDeleted(table, id) {
    const row = await this.findOne(`SELECT deleted_at, deleted_by FROM ${table} WHERE id = ?`, [id]);
    return row && row.deleted_at !== null;
  }

  async getLatestAuditFor(auditableType, auditableId, event = null) {
    const params = [auditableType, auditableId];
    let sql = 'SELECT * FROM audits WHERE auditable_type = ? AND auditable_id = ?';
    if (event) {
      sql += ' AND event = ?';
      params.push(event);
    }
    sql += ' ORDER BY id DESC LIMIT 1';
    return this.findOne(sql, params);
  }

  async getDocumentAccessRecords(documentId) {
    return this.query(
      'SELECT * FROM document_accesses WHERE document_id = ? ORDER BY id DESC',
      [documentId]
    );
  }

  async getHearingByCaseId(caseId) {
    return this.findOne(
      'SELECT * FROM hearings WHERE case_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
      [caseId]
    );
  }

  async countQueuedJobsContaining(needle) {
    const rows = await this.query('SELECT payload FROM jobs');
    return rows.filter((row) => row.payload && row.payload.includes(needle)).length;
  }

  async getInvoiceByNumber(invoiceNumber) {
    return this.findOne(
      'SELECT * FROM invoices WHERE invoice_number = ? LIMIT 1',
      [invoiceNumber]
    );
  }

  async getInvoiceItems(invoiceId) {
    return this.query(
      'SELECT * FROM invoice_items WHERE invoice_id = ? AND deleted_at IS NULL',
      [invoiceId]
    );
  }

  async getExpensesForCase(caseId) {
    return this.query(
      'SELECT * FROM expenses WHERE case_id = ? AND deleted_at IS NULL ORDER BY id DESC',
      [caseId]
    );
  }

  // Cleanup helpers — used in test teardown so re-running the suite
  // doesn't accumulate stale fixture rows across runs. Deletes happen in
  // dependency order, children before parents (the foreign keys are
  // RESTRICT, not CASCADE).
  // DATA-10: invoices, their items once issued, payments and trust entries are append-only, enforced by database
  // triggers. Test data that reached the ledger therefore cannot be removed either: such a case (and its client) is
  // soft-deleted instead, exactly as the application does, and the ledger rows stay. CI starts from an empty
  // database, so nothing accumulates there; locally a re-run simply leaves a few soft-deleted fixtures behind.
  async deleteCaseAndDependents(caseId) {
    const invoiceIds = (await this.query('SELECT id FROM invoices WHERE case_id = ?', [caseId])).map((r) => r.id);
    const documentIds = (await this.query('SELECT id FROM documents WHERE case_id = ?', [caseId])).map((r) => r.id);

    if (documentIds.length) {
      await this.query('DELETE FROM document_accesses WHERE document_id IN (?)', [documentIds]);
    }
    await this.query('DELETE FROM time_entries WHERE case_id = ?', [caseId]);
    await this.query('DELETE FROM hearings WHERE case_id = ?', [caseId]);
    await this.query('DELETE FROM documents WHERE case_id = ?', [caseId]);
    await this.query('DELETE FROM expenses WHERE case_id = ?', [caseId]);

    if (invoiceIds.length) {
      await this.query('UPDATE cases SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL', [caseId]);
      return;
    }
    await this.query('DELETE FROM cases WHERE id = ?', [caseId]);
  }

  async deleteClientAndDependents(clientId) {
    const caseIds = (await this.query('SELECT id FROM cases WHERE client_id = ?', [clientId])).map((r) => r.id);
    for (const caseId of caseIds) {
      await this.deleteCaseAndDependents(caseId);
    }
    const [{ n: ledgerRows }] = await this.query(
      'SELECT (SELECT COUNT(*) FROM trust_transactions WHERE client_id = ?) + (SELECT COUNT(*) FROM invoices WHERE client_id = ?) AS n',
      [clientId, clientId]
    );
    const [{ n: remainingCases }] = await this.query('SELECT COUNT(*) AS n FROM cases WHERE client_id = ?', [clientId]);
    if (Number(ledgerRows) > 0 || Number(remainingCases) > 0) {
      await this.query('UPDATE clients SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL', [clientId]);
      return;
    }
    await this.query('DELETE FROM clients WHERE id = ?', [clientId]);
  }
}

module.exports = { Db };
