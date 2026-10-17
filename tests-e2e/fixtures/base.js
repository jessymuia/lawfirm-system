const base = require('@playwright/test');
const { Db } = require('../utils/db');

/**
 * Extends Playwright's base test with a `db` fixture (a connected Db
 * helper, closed automatically after each test) so every spec can assert
 * persisted/backend state without each file managing its own connection
 * lifecycle.
 */
const test = base.test.extend({
  db: async ({}, use) => {
    const db = new Db();
    await db.connect();
    await use(db);
    await db.close();
  },
});

const expect = base.expect;

module.exports = { test, expect };
