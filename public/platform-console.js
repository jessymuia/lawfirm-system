(() => {
    'use strict';

    const root = document.getElementById('platform-root');
    if (!root) return;

    const API = root.dataset.api || '/api/platform';
    const PRODUCT = root.dataset.product || 'Platform';
    const TOKEN_KEY = 'platform-console-token';
    const state = {
        token: sessionStorage.getItem(TOKEN_KEY) || '',
        staff: null,
        abilities: [],
        page: 'overview',
        slug: '',
        tenantTab: 'overview',
        search: '',
        status: '',
        busy: false,
        mfaChallenge: '',
        mfaSetup: null,
        recoveryCodes: [],
        toastTimer: null,
        invoiceStatus: '',
        plans: [],
        planMeta: {},
    };

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
    const fmt = (value) => Number(value || 0).toLocaleString();
    const money = (value, currency = 'KES') => {
        if (value === null || value === undefined || value === '') return '—';
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value));
        } catch (_) {
            return `${currency} ${fmt(value)}`;
        }
    };
    // billing amounts are stored in minor units (cents); show them in the currency's normal unit
    const minor = (value, currency = 'KES') => (value === null || value === undefined || value === '') ? '—' : money(Number(value) / 100, currency);
    const minorList = (byCurrency) => Object.entries(byCurrency || {}).map(([currency, value]) => minor(value, currency)).join(' · ') || '—';
    const toMinor = (text) => { const n = parseFloat(String(text).replace(/,/g, '')); return Number.isFinite(n) ? Math.round(n * 100) : null; };
    const date = (value) => {
        if (!value) return '—';
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? esc(value) : parsed.toLocaleString();
    };
    const badge = (value) => {
        const text = String(value || 'unknown');
        return `<span class="badge badge-${esc(text.toLowerCase().replace(/[^a-z0-9_-]/g, '-'))}">${esc(text.replaceAll('_', ' '))}</span>`;
    };
    const keyedRows = (value) => Array.isArray(value)
        ? value
        : Object.entries(value || {}).map(([name, item]) => ({ name, ...(item || {}) }));
    // YYYY-MM-DD for today (+ n days) in the operator's own time zone, for date inputs
    const localDate = (plusDays) => { const d = new Date(Date.now() + plusDays * 86400000); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
    const can = (ability) => state.abilities.includes(ability);
    const toast = (message, kind = '') => {
        const host = document.getElementById('toast-region');
        if (!host) return;
        const item = document.createElement('div');
        item.className = `toast ${kind}`;
        item.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        item.textContent = message;
        host.appendChild(item);
        window.setTimeout(() => item.remove(), 5000);
    };
    const clearSession = () => {
        sessionStorage.removeItem(TOKEN_KEY);
        state.token = '';
        state.staff = null;
        state.abilities = [];
        state.busy = false;
    };

    // preloader: a top progress bar for every call, and a "Saving…" card when a write takes more than a moment
    const loader = { n: 0, writes: 0, timer: 0, shownAt: 0, label: '' };
    function loaderEl() {
        let el = document.getElementById('pc-loader');
        if (!el) {
            el = document.createElement('div');
            el.id = 'pc-loader';
            el.className = 'lms-loader';
            el.hidden = true;
            el.setAttribute('role', 'status');
            el.innerHTML = '<div class="lms-loader-card"><div class="lms-orbit" aria-hidden="true"><span class="lms-ring"></span><span class="lms-tile"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M7 21h10M5 7h14M5 7l-3 7a3 3 0 0 0 6 0L5 7zM19 7l-3 7a3 3 0 0 0 6 0l-3-7z"/></svg></span></div><div class="lms-label">Loading…</div><div class="lms-shimmer"><span></span></div></div>';
            document.body.appendChild(el);
        }
        return el;
    }
    function loaderSync() {
        const el = loaderEl();
        window.clearTimeout(loader.timer);
        if (loader.n > 0) {
            loader.timer = window.setTimeout(() => {
                el.classList.toggle('block', loader.writes > 0);
                el.querySelector('.lms-label').textContent = loader.label || (loader.writes > 0 ? 'Saving…' : 'Loading…');
                if (el.hidden) loader.shownAt = Date.now();
                el.hidden = false;
            }, loader.writes > 0 ? 120 : 180);
        } else if (!el.hidden) {
            loader.timer = window.setTimeout(() => { el.hidden = true; el.classList.remove('block'); }, Math.max(0, 380 - (Date.now() - (loader.shownAt || 0))));
        }
    }
    // Styled confirmation / input dialog (replaces the browser's confirm() and prompt()).
    // ask({ title, message, confirmText, danger, reason: 'label', type: 'slug', fields: [{ name, label, type, placeholder }] })
    // -> resolves to an object of the entered values (reason, typed, field names), or null when cancelled.
    function ask(opts) {
        return new Promise((resolve) => {
            let host = document.getElementById('ask-root');
            if (!host) { host = document.createElement('div'); host.id = 'ask-root'; document.body.appendChild(host); }
            const fields = [...(opts.fields || [])];
            if (opts.reason) fields.push({ name: 'reason', label: opts.reason === true ? 'Reason (required, recorded in the audit log)' : opts.reason, type: 'textarea' });
            if (opts.typeToConfirm) fields.push({ name: 'typed', label: `Type "${opts.typeToConfirm}" to confirm`, type: 'text', match: opts.typeToConfirm });
            const inputs = fields.map((f, i) => `<div class="field"><label for="ask-f${i}">${esc(f.label)}</label>${f.type === 'textarea'
                ? `<textarea id="ask-f${i}" name="${esc(f.name)}" rows="3" required minlength="3"></textarea>`
                : `<input id="ask-f${i}" name="${esc(f.name)}" type="${esc(f.type || 'text')}" ${f.placeholder ? `placeholder="${esc(f.placeholder)}"` : ''} autocomplete="off" required>`}</div>`).join('');
            host.innerHTML = `<div class="ask-backdrop"><form class="ask-card" role="alertdialog" aria-modal="true" aria-labelledby="ask-title" novalidate>
                <div class="ask-icon ${opts.danger ? 'danger' : ''}" aria-hidden="true">${opts.danger ? '!' : '?'}</div>
                <h2 id="ask-title">${esc(opts.title || 'Are you sure?')}</h2>
                ${opts.message ? `<p class="ask-msg">${esc(opts.message)}</p>` : ''}
                ${opts.irreversible ? '<p class="ask-warn">This cannot be undone.</p>' : ''}
                ${inputs}<p class="ask-error" hidden></p>
                <div class="ask-actions"><button type="button" class="btn btn-secondary" data-ask="cancel">${esc(opts.cancelText || 'Cancel')}</button><button type="submit" class="btn ${opts.danger ? 'btn-danger' : 'btn-primary'}">${esc(opts.confirmText || 'Confirm')}</button></div>
            </form></div>`;
            const form = host.querySelector('form');
            const close = (value) => { host.innerHTML = ''; document.removeEventListener('keydown', onKey, true); resolve(value); };
            const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(null); } };
            document.addEventListener('keydown', onKey, true);
            host.querySelector('[data-ask="cancel"]').addEventListener('click', () => close(null));
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const values = Object.fromEntries(new FormData(form));
                const err = form.querySelector('.ask-error');
                for (const f of fields) {
                    const v = String(values[f.name] || '').trim();
                    values[f.name] = v;
                    const bad = f.match ? v !== f.match : (f.type === 'textarea' ? v.length < 3 : !v);
                    if (bad) { err.textContent = f.match ? `Type ${f.match} exactly to continue.` : `Please fill in: ${f.label}.`; err.hidden = false; return; }
                }
                close(values);
            });
            (form.querySelector('input,textarea') || form.querySelector('button[type=submit]')).focus();
        });
    }

    async function request(path, options = {}) {
        const write = (options.method || 'GET') !== 'GET';
        loader.n++; if (write) loader.writes++;
        if (write && path === '/tenants' && options.method === 'POST') loader.label = 'Creating the firm… this can take a moment';
        loaderSync();
        try { return await requestInner(path, options); } finally {
            loader.n--; if (write) loader.writes--;
            if (loader.n === 0) loader.label = '';
            loaderSync();
        }
    }

    async function requestInner(path, options = {}) {
        const headers = { Accept: 'application/json', ...(options.headers || {}) };
        if (state.token) headers.Authorization = `Bearer ${state.token}`;
        if (options.body !== undefined) headers['Content-Type'] = 'application/json';
        const response = await fetch(`${API}${path}`, {
            method: options.method || 'GET',
            headers,
            body: options.body === undefined ? undefined : JSON.stringify(options.body),
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const contentType = response.headers.get('content-type') || '';
        const payload = contentType.includes('application/json') ? await response.json() : null;
        if (response.status === 401 && state.token) {
            clearSession();
            renderLogin();
            toast('Your platform sign-in expired. Please sign in again.', 'error');
        }
        if (!response.ok) {
            throw new Error(payload?.message || `Request failed (${response.status}).`);
        }
        return payload || {};
    }

    function navigate(page, slug = '', tab = 'overview') {
        state.page = page;
        state.slug = slug;
        state.tenantTab = tab;
        const fragment = slug ? `#firms/${encodeURIComponent(slug)}` : `#${page}`;
        if (window.location.hash !== fragment) window.history.replaceState(null, '', fragment);
        renderShell();
        loadPage().catch((error) => toast(error.message, 'error'));
    }

    function pageFromHash() {
        const fragment = decodeURIComponent(window.location.hash.slice(1));
        if (fragment.startsWith('firms/')) {
            state.page = 'tenant';
            state.slug = fragment.slice('firms/'.length);
            return;
        }
        const allowed = ['overview', 'firms', 'billing', 'plans', 'health', 'audit', 'security'];
        state.page = allowed.includes(fragment) ? fragment : 'overview';
        state.slug = '';
    }

    function renderLogin(error = '', mfa = false) {
        root.innerHTML = `
            <main class="login-screen">
                <div class="login-wrap">
                    <div class="product-mark">
                        <div class="mark-icon" aria-hidden="true">L</div>
                        <div class="mark-copy"><strong>${esc(PRODUCT)}</strong><span>Private platform operations</span></div>
                    </div>
                    <section class="login-card" aria-labelledby="login-title">
                        <div class="login-eyebrow">${mfa ? 'Secure verification' : 'Platform staff'}</div>
                        <h1 id="login-title">${mfa ? 'Verify it’s you' : 'Sign in to control plane'}</h1>
                        <p>${mfa ? 'Enter the authenticator code or one of your recovery codes.' : 'Use your platform staff account. Firm accounts cannot sign in here.'}</p>
                        ${mfa ? `
                            <form id="platform-mfa-login" novalidate>
                                <div class="field"><label for="mfa-code">Authentication or recovery code</label><input id="mfa-code" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus></div>
                                <button class="btn btn-primary login-submit" type="submit">Verify and continue</button>
                                <button class="btn btn-secondary login-submit" type="button" data-action="back-to-login">Use another account</button>
                            </form>
                        ` : `
                            <form id="platform-login" novalidate>
                                <div class="field"><label for="staff-email">Work email</label><input id="staff-email" name="email" type="email" autocomplete="username" required autofocus></div>
                                <div class="field"><label for="staff-password">Password</label><input id="staff-password" name="password" type="password" autocomplete="current-password" required></div>
                                <button class="btn btn-primary login-submit" type="submit">Sign in securely</button>
                            </form>
                        `}
                        ${error ? `<p class="form-error" role="alert">${esc(error)}</p>` : ''}
                        <div class="security-note"><b aria-hidden="true">●</b><span>Platform access is isolated from firm accounts and protected by role permissions, short-lived tokens, and required two-factor authentication.</span></div>
                    </section>
                </div>
            </main>
            <div id="toast-region" class="toast-region" aria-live="polite"></div>`;
    }

    function renderEnrollment() {
        const setup = state.mfaSetup;
        const optional = !!state.mfaVoluntary;
        root.innerHTML = `
            <main class="login-screen">
                <div class="login-wrap">
                    <div class="product-mark"><div class="mark-icon" aria-hidden="true">L</div><div class="mark-copy"><strong>${esc(PRODUCT)}</strong><span>Platform staff security</span></div></div>
                    <section class="login-card enroll-card" aria-labelledby="enroll-title">
                        ${optional
                            ? '<button type="button" class="modal-close enroll-close" data-action="cancel-enrollment" aria-label="Close">×</button>'
                            : ''}
                        <div class="login-eyebrow">${optional ? 'Optional' : 'Required before console access'}</div>
                        <h1 id="enroll-title">Set up two-factor authentication</h1>
                        <ol class="enroll-steps">
                            <li>Open an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy…).</li>
                            <li>Choose <strong>Add account</strong> and scan this QR code.</li>
                            <li>Enter the six-digit code the app shows.</li>
                        </ol>
                        <div class="qr-box" id="enroll-qr" aria-label="QR code for your authenticator app">Loading…</div>
                        <details class="enroll-manual"><summary>Can’t scan? Enter the key by hand</summary>
                            <div class="mfa-secret">${esc(setup?.secret || '')}</div>
                            <p class="subtle authenticator-owner">Issuer: ${esc(PRODUCT)} Platform · Account: ${esc(state.staff?.email || '')}</p>
                        </details>
                        <form id="platform-enroll" novalidate>
                            <div class="field"><label for="enroll-code">Current authenticator code</label><input id="enroll-code" name="code" inputmode="numeric" autocomplete="one-time-code" required></div>
                            <button class="btn btn-primary login-submit" type="submit">Confirm and secure account</button>
                            ${optional
                                ? '<button class="btn btn-secondary login-submit" type="button" data-action="cancel-enrollment">Cancel</button>'
                                : '<button class="btn btn-secondary login-submit" type="button" data-action="logout">Sign out</button>'}
                        </form>
                        <p class="form-error" id="enroll-error" hidden role="alert"></p>
                    </section>
                </div>
            </main>
            <div id="toast-region" class="toast-region" aria-live="polite"></div>`;
        const box = document.getElementById('enroll-qr');
        if (window.PlatformQR && setup?.uri) {
            window.PlatformQR.svg(setup.uri).then((svg) => { if (box) box.innerHTML = svg; }).catch(() => { if (box) box.textContent = 'The QR code could not be drawn. Use the key below instead.'; });
        } else if (box) {
            box.textContent = 'Use the key below to add this account by hand.';
            document.querySelector('.enroll-manual')?.setAttribute('open', '');
        }
    }

    function navButton(page, label, icon) {
        return `<button class="nav-button ${state.page === page || (page === 'firms' && state.page === 'tenant') ? 'active' : ''}" type="button" data-page="${page}" ${state.page === page ? 'aria-current="page"' : ''}><span class="nav-icon" aria-hidden="true">${icon}</span><span>${label}</span></button>`;
    }

    function pageInfo() {
        const names = {
            overview: ['Operations overview', 'Revenue, firms that need attention, and platform health.'],
            firms: ['Firms', 'Provision and operate tenant accounts without opening firm business data.'],
            tenant: [state.slug, 'Firm settings, lifecycle, usage, billing, and audited support access.'],
            billing: ['Billing', 'Subscriptions, invoices and payments across every firm.'],
            plans: ['Plans', 'The plans firms can subscribe to: prices, limits and features.'],
            health: ['Service health', 'Platform services, availability, and public status incidents.'],
            audit: ['Platform audit', 'An append-only record of staff actions, refusals, and failures.'],
            security: ['My account', 'Your name, password and two-factor authentication.'],
        };
        return names[state.page] || names.overview;
    }

    function renderShell() {
        const [title, description] = pageInfo();
        const navigation = [
            navButton('overview', 'Overview', '⌂'),
            navButton('firms', 'Firms', '▤'),
            navButton('billing', 'Billing', '$'),
            navButton('plans', 'Plans', '◧'),
            navButton('health', 'Health & incidents', '◉'),
            navButton('audit', 'Audit log', '≋'),
            navButton('security', 'My account', '◇'),
        ].join('');
        root.innerHTML = `
            <div class="console">
                <aside class="sidebar" aria-label="Platform navigation">
                    <div class="sidebar-brand"><div class="mark-icon" aria-hidden="true">L</div><div class="mark-copy"><strong>${esc(PRODUCT)}</strong><span>Operator console</span></div></div>
                    <div class="nav-label">Platform</div>
                    <nav class="nav-list">${navigation}</nav>
                    <div class="sidebar-bottom">
                        <div class="staff-mini"><strong>${esc(state.staff?.name || 'Platform staff')}</strong><span class="role-chip">${esc(state.staff?.role || 'staff')}</span></div>
                        <button class="btn logout-button" type="button" data-action="logout">↪ &nbsp; Sign out</button>
                    </div>
                </aside>
                <section class="workspace">
                    <header class="topbar">
                        <div class="topbar-copy"><strong>${esc(title)}</strong><span>Law Management System · Operator console</span></div>
                        <div class="topbar-right"><span class="status-dot" aria-hidden="true"></span><span>${esc(state.staff?.name || state.staff?.email || 'Signed in')} · ${esc(state.staff?.role || 'staff')}</span></div>
                    </header>
                    <main id="page-content" class="content" tabindex="-1"><div class="loading" role="status">Loading platform data…</div></main>
                </section>
            </div>
            <div id="modal-root"></div>
            <div id="toast-region" class="toast-region" aria-live="polite"></div>`;
    }

    function heading(title, desc, actions = '') {
        return `<div class="page-heading"><div><h1>${esc(title)}</h1><p>${esc(desc)}</p></div><div class="heading-actions">${actions}</div></div>`;
    }

    function metric(label, value, note = '', tone = '') {
        return `<section class="card span-3"><div class="metric-label">${esc(label)}</div><div class="metric-value ${tone}">${esc(value)}</div><div class="metric-note">${esc(note)}</div></section>`;
    }

    function table(headers, rows, empty = 'No records found.') {
        return `<div class="table-wrap"><table><thead><tr>${headers.map((h) => `<th scope="col">${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows || `<tr><td colspan="${headers.length}" class="empty">${esc(empty)}</td></tr>`}</tbody></table></div>`;
    }

    async function loadPage() {
        if (!state.staff) return;
        const panel = document.getElementById('page-content');
        if (!panel) return;
        panel.innerHTML = '<div class="loading" role="status">Loading platform data…</div>';
        switch (state.page) {
        case 'overview': return loadOverview(panel);
        case 'firms': return loadFirms(panel);
        case 'tenant': return loadTenant(panel);
        case 'billing': return loadBilling(panel);
        case 'plans': return loadPlans(panel);
        case 'health': return loadHealth(panel);
        case 'audit': return loadAudit(panel);
        case 'security': return loadSecurity(panel);
        default: return loadOverview(panel);
        }
    }

    async function loadOverview(panel) {
        const requests = await Promise.allSettled([
            request('/fleet'), request('/health'), request('/billing/funnel?days=30'), request('/tenants'),
            request('/billing/subscriptions'), request('/billing/invoices?status=overdue'), request('/audit?limit=6'), request('/incidents'),
        ]);
        const get = (i) => requests[i].status === 'fulfilled' ? requests[i].value : null;
        const fleet = get(0)?.data || {};
        const health = get(1)?.data || {};
        const funnel = get(2)?.data || {};
        const firms = get(3)?.data || [];
        const subsRes = get(4) || {};
        const subs = subsRes.data || [];
        const overdueRes = get(5) || {};
        const overdueInvoices = overdueRes.data || [];
        const audit = get(6)?.data || [];
        const incidents = (get(7)?.data || []).filter((i) => i.status !== 'resolved');
        const errorCount = requests.filter((item) => item.status === 'rejected').length;
        const migration = fleet.by_migration_status || {};
        const now = Date.now();
        const day = 86400000;

        // ---- top cards
        const mrr = subsRes.summary?.monthly_recurring || {};
        const paying = subs.filter((s) => ['active', 'past_due'].includes(s.status)).length;
        const overdueTotals = overdueRes.summary?.overdue || {};
        const overdueCount = new Set(overdueInvoices.map((i) => i.slug)).size;
        const endingTrials = subs.filter((s) => s.status === 'trialing' && s.trial_ends_at && new Date(s.trial_ends_at).getTime() - now <= 7 * day)
            .sort((a, b) => new Date(a.trial_ends_at) - new Date(b.trial_ends_at));
        const failed = Number(migration.failed || 0);
        const behind = Number(fleet.behind || 0);
        const svc = health.status || 'unknown';
        const problems = failed + behind + (svc === 'down' || svc === 'degraded' ? 1 : 0) + incidents.length;
        const statusTone = svc === 'down' || failed ? 'metric-bad' : (problems ? 'metric-warn' : 'metric-accent');
        const statusValue = problems === 0 && svc !== 'unknown' ? 'Healthy' : (svc === 'unknown' ? 'Unknown' : `${problems} issue${problems === 1 ? '' : 's'}`);
        const statusNote = problems === 0 ? 'All services and incidents are clear' : [
            svc !== 'healthy' && svc !== 'ok' && svc !== 'unknown' ? `service ${svc}` : '', failed ? `${failed} firm update problem${failed === 1 ? '' : 's'}` : '',
            behind ? `${behind} firm${behind === 1 ? '' : 's'} need${behind === 1 ? 's' : ''} an update` : '', incidents.length ? `${incidents.length} open incident${incidents.length === 1 ? '' : 's'}` : '',
        ].filter(Boolean).join(' · ');

        // ---- needs attention
        const attention = [];
        const add = (slug, name, reason, tone, page) => attention.push({ slug, name, reason, tone, page });
        const seenOverdue = new Map();
        overdueInvoices.forEach((i) => { const cur = seenOverdue.get(i.slug) || { name: i.firm, due: {} }; cur.due[i.currency] = (cur.due[i.currency] || 0) + Number(i.amount_due || 0); seenOverdue.set(i.slug, cur); });
        seenOverdue.forEach((v, slug) => add(slug, v.name, `Overdue invoice · ${minorList(v.due)}`, 'overdue'));
        firms.filter((f) => f.migration_status === 'failed').forEach((f) => add(f.slug, f.name, 'A system update needs attention', 'failed'));
        firms.filter((f) => f.status === 'suspended').forEach((f) => add(f.slug, f.name, 'Suspended', 'suspended'));
        firms.filter((f) => f.status === 'offboarded').forEach((f) => add(f.slug, f.name, `Offboarded · purge after ${f.purge_after ? String(f.purge_after).slice(0, 10) : 'not set'}`, 'pending'));
        subs.filter((s) => s.status === 'past_due' && !seenOverdue.has(s.slug)).forEach((s) => add(s.slug, s.firm, 'Subscription past due', 'overdue'));
        subs.filter((s) => s.cancel_at_period_end && s.period_end).forEach((s) => add(s.slug, s.firm, `Cancels on ${String(s.period_end).slice(0, 10)}`, 'pending'));
        const liveFirms = firms.filter((f) => ['active', 'provisioning'].includes(f.status));
        const daysSince = (value) => value ? Math.floor((now - new Date(value).getTime()) / day) : null;
        const quiet = liveFirms.map((f) => ({ f, days: daysSince(f.last_active_day) })).filter((x) => x.days === null || x.days >= 14);
        const backupAge = (f) => f.last_backup ? daysSince(f.last_backup.at) : null;
        const backupBad = liveFirms.filter((f) => !f.last_backup || f.last_backup.result !== 'ok' || backupAge(f) >= 3);
        backupBad.forEach((f) => add(f.slug, f.name, !f.last_backup ? 'No backup on record' : (f.last_backup.result !== 'ok' ? 'Last backup failed' : `Last backup ${backupAge(f)} days ago`), 'failed'));
        quiet.forEach(({ f, days }) => add(f.slug, f.name, days === null ? 'No activity recorded yet' : `No activity for ${days} days`, 'pending'));
        endingTrials.forEach((s) => { const d = Math.ceil((new Date(s.trial_ends_at).getTime() - now) / day); add(s.slug, s.firm, d <= 0 ? 'Trial has ended' : `Trial ends in ${d} day${d === 1 ? '' : 's'}`, 'pending'); });
        const attentionRows = attention.slice(0, 8).map((a) => `<tr><td><button class="text-link table-primary" type="button" data-open-firm="${esc(a.slug)}">${esc(a.name)}</button></td><td>${esc(a.reason)}</td><td>${badge(a.tone)}</td></tr>`).join('');

        // ---- recent signups
        const planOf = (slug) => subs.find((s) => s.slug === slug);
        const signupRows = [...firms].filter((f) => f.status !== 'purged').sort((a, b) => new Date(b.created_at) - new Date(a.created_at)).slice(0, 5).map((f) => {
            const s = planOf(f.slug);
            return `<tr><td><button class="text-link table-primary" type="button" data-open-firm="${esc(f.slug)}">${esc(f.name)}</button><span class="table-sub">${esc(f.slug)}</span></td><td>${esc(f.plan || '—')}</td><td>${badge(s?.status || f.status)}</td><td>${f.created_at ? esc(new Date(f.created_at).toLocaleDateString()) : '—'}</td></tr>`;
        }).join('');

        // ---- revenue by plan
        const byPlan = {};
        subs.filter((s) => ['active', 'past_due'].includes(s.status)).forEach((s) => {
            const p = byPlan[s.plan] || (byPlan[s.plan] = { firms: 0, money: {} });
            p.firms += 1; p.money[s.currency] = (p.money[s.currency] || 0) + Number(s.price || 0);
        });
        const planRows = Object.entries(byPlan).sort((a, b) => b[1].firms - a[1].firms).map(([plan, p]) => `<tr><td class="table-primary">${esc(plan)}</td><td>${fmt(p.firms)}</td><td>${minorList(p.money)}</td></tr>`).join('');

        // ---- activity
        const activityRows = audit.map((a) => `<tr><td>${esc(a.action)}<span class="table-sub">${esc(a.staff_email || 'System')}${a.tenant_slug ? ' · ' + esc(a.tenant_slug) : ''}</span></td><td>${badge(a.result)}</td><td>${date(a.created_at)}</td></tr>`).join('');

        const activityFirmRows = [...liveFirms].sort((a, b) => String(a.last_active_day || '').localeCompare(String(b.last_active_day || ''))).slice(0, 6).map((f) => {
            const d = daysSince(f.last_active_day);
            const u = f.usage || {};
            const state = d === null ? 'inactive' : d >= 14 ? 'quiet' : 'active';
            return `<tr><td><button class="text-link table-primary" type="button" data-open-firm="${esc(f.slug)}">${esc(f.name)}</button></td><td>${fmt(u.users)} users · ${fmt(u.matters)} matters</td><td>${d === null ? 'Never' : (d === 0 ? 'Today' : `${d} day${d === 1 ? '' : 's'} ago`)}</td><td>${badge(state)}</td></tr>`;
        }).join('');
        const backupRows = [...liveFirms].map((f) => {
            const d = backupAge(f);
            const state = !f.last_backup ? 'missing' : (f.last_backup.result !== 'ok' ? 'failed' : (d >= 3 ? 'stale' : 'ok'));
            return { f, d, state };
        }).sort((a, b) => (a.state === 'ok') - (b.state === 'ok')).slice(0, 6).map(({ f, d, state }) => `<tr><td><button class="text-link table-primary" type="button" data-open-firm="${esc(f.slug)}">${esc(f.name)}</button></td><td>${f.last_backup ? date(f.last_backup.at) : '—'}</td><td>${badge(state)}</td></tr>`).join('');
        const conv = Number(funnel.trials?.started) ? Math.round(100 * Number(funnel.trials?.converted || 0) / Number(funnel.trials.started)) : null;
        const incidentCard = incidents.length ? `
                <section class="card span-12">
                    <div class="card-title-row"><div><h2>Open incidents</h2><p class="card-caption">Visible on the public status page.</p></div><button class="btn btn-secondary" type="button" data-page="health">Manage</button></div>
                    ${table(['Incident', 'State', 'Started'], incidents.map((i) => `<tr><td class="table-primary">${esc(i.title || `Incident ${i.id}`)}</td><td>${badge(i.status)}</td><td>${date(i.starts_at || i.started_at || i.created_at)}</td></tr>`).join(''))}
                </section>` : '';

        panel.innerHTML = `
            ${heading('Platform overview', 'Revenue, firms that need attention, and platform health. Business records remain inside each firm.', [
                can('provision') ? '<button class="btn btn-primary" type="button" data-action="provision">Provision firm</button>' : '',
                can('operate') ? '<button class="btn btn-secondary" type="button" data-action="bill-run">Generate invoices</button><button class="btn btn-secondary" type="button" data-action="open-incident">Open incident</button>' : '',
            ].join(''))}
            ${errorCount ? `<div class="inline-error">${errorCount} overview data source(s) could not be loaded. Use the module pages to retry individually.</div>` : ''}
            <div class="grid">
                ${metric('Monthly recurring revenue', minorList(mrr), `${fmt(paying)} paying firm${paying === 1 ? '' : 's'}`, 'metric-accent')}
                ${metric('Overdue invoices', Object.keys(overdueTotals).length ? minorList(overdueTotals) : 'None', overdueCount ? `${fmt(overdueCount)} firm${overdueCount === 1 ? '' : 's'} owe this` : 'Nothing past its due date', overdueCount ? 'metric-bad' : 'metric-accent')}
                ${metric('Trials ending soon', fmt(endingTrials.length), 'Ending within 7 days', endingTrials.length ? 'metric-warn' : '')}
                ${metric('Platform status', statusValue, statusNote, statusTone)}
                ${incidentCard}
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>Needs attention</h2><p class="card-caption">Firms with an unpaid invoice, an update problem, a suspension or an ending trial.</p></div><div class="card-actions">${can('operate') && firms.some((f) => f.update_needed) ? `<button class="btn btn-primary" type="button" data-fleet-action="update-all">Update ${fmt(firms.filter((f) => f.update_needed).length)} firm${firms.filter((f) => f.update_needed).length === 1 ? '' : 's'}</button>` : ''}<button class="btn btn-secondary" type="button" data-page="firms">All firms</button></div></div>
                    ${table(['Firm', 'Reason', 'Type'], attentionRows, 'Nothing needs attention right now.')}
                </section>
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>Recent signups</h2><p class="card-caption">Newest firms on the platform.</p></div><button class="btn btn-secondary" type="button" data-page="firms">Browse firms</button></div>
                    ${table(['Firm', 'Plan', 'Status', 'Joined'], signupRows, 'No firms yet.')}
                </section>
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>Signup and trial funnel</h2><p class="card-caption">Last ${fmt(funnel.days || 30)} days${conv === null ? '' : ` · ${conv}% of trials converted`}.</p></div><button class="btn btn-secondary" type="button" data-page="billing">Billing overview</button></div>
                    <div class="detail-grid">
                        <div class="detail-item"><span>Signups</span><strong>${fmt(funnel.signups?.submitted)}</strong></div>
                        <div class="detail-item"><span>Verified</span><strong>${fmt(funnel.signups?.verified)}</strong></div>
                        <div class="detail-item"><span>Trials</span><strong>${fmt(funnel.trials?.started)}</strong></div>
                        <div class="detail-item"><span>Converted</span><strong>${fmt(funnel.trials?.converted)}</strong></div>
                    </div>
                </section>
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>Revenue by plan</h2><p class="card-caption">Paying firms and what each plan brings in monthly.</p></div><button class="btn btn-secondary" type="button" data-page="plans">Plans</button></div>
                    ${table(['Plan', 'Firms', 'Monthly'], planRows, 'No paying firms yet.')}
                </section>
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>Firm activity</h2><p class="card-caption">Least recently active first. A firm quiet for 14 days may be about to leave.</p></div><button class="btn btn-secondary" type="button" data-page="firms">All firms</button></div>
                    ${table(['Firm', 'Size', 'Last active', 'State'], activityFirmRows, 'No active firms yet.')}
                </section>
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>Backups</h2><p class="card-caption">Newest backup per firm. Problems are listed first.</p></div><button class="btn btn-secondary" type="button" data-page="health">Health</button></div>
                    ${table(['Firm', 'Last backup', 'State'], backupRows, 'No active firms yet.')}
                </section>
                <section class="card span-12">
                    <div class="card-title-row"><div><h2>Recent platform activity</h2><p class="card-caption">Latest staff actions.</p></div><button class="btn btn-secondary" type="button" data-page="audit">Audit log</button></div>
                    ${table(['Action', 'Result', 'When'], activityRows, 'No activity recorded yet.')}
                </section>
            </div>`;
    }

    async function loadFirms(panel) {
        const params = new URLSearchParams();
        if (state.search) params.set('q', state.search);
        if (state.status) params.set('status', state.status);
        const suffix = params.toString() ? `?${params}` : '';
        const result = await request(`/tenants${suffix}`);
        const firms = result.data || [];
        const rows = firms.map((firm) => `<tr>
            <td><button class="text-link table-primary" type="button" data-open-firm="${esc(firm.slug)}">${esc(firm.name)}</button><span class="table-sub">${esc(firm.slug)}</span></td>
            <td>${badge(firm.status)}</td><td>${esc(firm.plan || '—')}</td>
            <td>${fmt(firm.usage?.users || 0)} users<span class="table-sub">${fmt(firm.usage?.matters || 0)} matters</span></td>
            <td>${firm.legal_hold ? '<span class="badge badge-pending">Legal hold</span>' : '—'}</td>
        </tr>`).join('');
        const actions = can('provision') ? '<button class="btn btn-primary" type="button" data-action="provision">Provision firm</button>' : '';
        panel.innerHTML = `
            ${heading('Firms', 'Find and manage firm accounts. Each firm’s records stay private to that firm.', actions)}
            <section class="card">
                <form id="firm-filter" class="toolbar">
                    <label class="subtle" for="firm-search">Search firms</label><input id="firm-search" name="q" value="${esc(state.search)}" placeholder="Name or slug">
                    <label class="subtle" for="firm-status">Status</label><select id="firm-status" name="status">
                        <option value="">All statuses</option>${['active', 'suspended', 'provisioning', 'offboarded', 'purged'].map((v) => `<option value="${v}" ${state.status === v ? 'selected' : ''}>${v}</option>`).join('')}
                    </select><button class="btn btn-secondary" type="submit">Filter</button>
                </form>
                <div class="table-spacer" aria-hidden="true"></div>
                ${table(['Firm', 'Status', 'Plan', 'Latest usage', 'Governance'], rows, 'No firms match those filters.')}
                <p class="subtle">Usage figures are daily control-plane aggregates; no client names or matter content are shown here.</p>
            </section>`;
    }

    function tabButton(key, label) {
        return `<button type="button" class="tab-button ${state.tenantTab === key ? 'active' : ''}" data-tenant-tab="${key}">${label}</button>`;
    }

    async function loadTenant(panel) {
        const slug = encodeURIComponent(state.slug);
        const lookups = [
            request(`/tenants/${slug}`),
            request(`/tenants/${slug}/billing`).catch(() => null),
            request(`/tenants/${slug}/flags`).catch(() => null),
            request(`/tenants/${slug}/integrations`).catch(() => null),
            can('impersonate') ? request(`/tenants/${slug}/support-sessions`).catch(() => null) : Promise.resolve(null),
        ];
        const [detailResult, billingResult, flagsResult, channelsResult, supportResult] = await Promise.all(lookups);
        const firm = detailResult.data || {};
        state.currentTenant = firm;
        const billing = billingResult?.data || null;
        const flags = flagsResult?.data || [];
        const support = supportResult || null;
        const actions = [];
        if (can('operate') && firm.status === 'active') actions.push(`<button class="btn btn-secondary" type="button" data-tenant-action="suspend" data-slug="${esc(firm.slug)}">Suspend</button>`);
        if (can('operate') && firm.status === 'suspended') actions.push(`<button class="btn btn-primary" type="button" data-tenant-action="resume" data-slug="${esc(firm.slug)}">Resume</button>`);
        if (can('operate') && !['purged', 'offboarded'].includes(firm.status)) {
            actions.push(`<button class="btn ${firm.update_needed ? 'btn-primary' : 'btn-secondary'}" type="button" data-tenant-action="migrate" data-slug="${esc(firm.slug)}" title="${firm.update_needed ? 'This firm is behind the current release' : 'Already up to date; safe to repeat'}">Run system updates</button>`);
        }
        if (can('operate') && firm.status !== 'purged') {
            actions.push(`<button class="btn btn-secondary" type="button" data-tenant-action="backup" data-slug="${esc(firm.slug)}">Queue backup</button>`);
        }
        if (can('destructive') && firm.status !== 'purged') {
            actions.push(`<button class="btn btn-secondary" type="button" data-tenant-action="legal-hold" data-slug="${esc(firm.slug)}">${firm.legal_hold ? 'Review legal hold' : 'Set legal hold'}</button>`);
            if (!firm.offboarded_at) actions.push(`<button class="btn btn-danger" type="button" data-tenant-action="offboard" data-slug="${esc(firm.slug)}">Offboard</button>`);
            if (firm.offboarded_at) actions.push(`<button class="btn btn-danger" type="button" data-tenant-action="purge" data-slug="${esc(firm.slug)}">Purge data</button>`);
            actions.push(`<button class="btn btn-secondary" type="button" data-tenant-action="eject" data-slug="${esc(firm.slug)}">Queue export</button>`);
            if (firm.final_export) actions.push(`<button class="btn btn-secondary" type="button" data-tenant-action="download-export" data-slug="${esc(firm.slug)}">Download final export</button>`);
        }
        const tabs = [
            tabButton('overview', 'Overview'),
            tabButton('billing', 'Billing'),
            tabButton('channels', 'Payments & messaging'),
            tabButton('flags', 'Feature flags'),
        ];
        if (can('impersonate')) tabs.push(tabButton('support', 'Support access'));
        panel.innerHTML = `
            ${heading(firm.name || firm.slug, `Firm ${firm.slug} · created ${date(firm.created_at)}`, `<button class="btn btn-secondary" type="button" data-page="firms">← All firms</button>`)}
            <div class="action-row tenant-actions">${actions.join('')}</div>
            <div class="tabs" role="tablist" aria-label="Firm detail sections">${tabs.join('')}</div>
            <div id="tenant-tab-content"></div>`;
        const target = document.getElementById('tenant-tab-content');
        if (!target) return;
        if (state.tenantTab === 'overview') renderTenantOverview(target, firm);
        else if (state.tenantTab === 'billing') renderTenantBilling(target, firm, billing);
        else if (state.tenantTab === 'channels') renderTenantChannels(target, channelsResult);
        else if (state.tenantTab === 'flags') renderTenantFlags(target, flags);
        else renderTenantSupport(target, firm, support);
    }

    function renderTenantOverview(target, firm) {
        const usage = firm.usage || {};
        const recent = (firm.recent_actions || []).map((action) => `<tr><td>${date(action.created_at)}</td><td>${esc(action.staff_email || 'System')}</td><td>${esc(action.action)}</td><td>${badge(action.result)}</td><td class="audit-detail">${esc(action.detail || '')}</td></tr>`).join('');
        const history = firm.usage_history || {};
        const metrics = Object.entries(history).slice(0, 5).map(([metricName, points]) => {
            const list = Array.isArray(points) ? points : [];
            const max = Math.max(1, ...list.map((point) => Number(point.value) || 0));
            return `<div class="card span-6"><div class="card-title-row"><h2>${esc(metricName.replaceAll('_', ' '))}</h2><span class="subtle">30-day usage</span></div><div class="chart-bars">${list.slice(-8).map((point) => `<div class="chart-row"><span>${esc(point.day)}</span><progress max="${max}" value="${Number(point.value) || 0}" aria-label="${esc(point.day)}: ${fmt(point.value)}"></progress><strong>${fmt(point.value)}</strong></div>`).join('') || '<div class="empty">No history recorded.</div>'}</div></div>`;
        }).join('');
        target.innerHTML = `
            <div class="grid">
                <section class="card span-12">
                    <div class="card-title-row"><div><h2>Tenant configuration</h2><p class="card-caption">Platform metadata only. Client and matter records are intentionally not shown.</p></div>${firm.legal_hold ? '<span class="badge badge-pending">Legal hold active</span>' : ''}</div>
                    <div class="detail-grid">
                        ${detailItem('Status', firm.status)}${detailItem('Plan', firm.plan)}${detailItem('Region', firm.region)}
                        ${detailItem('Domains', (firm.domains || []).join(', '))}${detailItem('Usage snapshot', firm.usage_day)}${detailItem('Purge after', firm.purge_after ? date(firm.purge_after) : '—')}${detailItem('Final export', firm.final_export?.at ? date(firm.final_export.at) : 'Not created')}
                    </div>
                    ${firm.final_export_error ? `<p class="inline-error spaced-error">Final export: ${esc(firm.final_export_error)}</p>` : ''}
                </section>
                ${Object.entries(usage).map(([key, value]) => metric(key.replaceAll('_', ' '), fmt(value), 'Latest daily usage snapshot')).join('')}
                ${metrics}
                <section class="card span-12">
                    <div class="card-title-row"><div><h2>Recent platform actions</h2><p class="card-caption">Staff actions against this tenant; denials and errors are included.</p></div></div>
                    ${table(['Time', 'Staff', 'Action', 'Result', 'Detail'], recent, 'No platform actions recorded.')}
                </section>
            </div>`;
    }

    function detailItem(label, value) {
        return `<div class="detail-item"><span>${esc(label)}</span><strong>${esc(value || '—')}</strong></div>`;
    }

    function renderTenantBilling(target, firm, billing) {
        const subscription = billing?.subscription;
        const invoices = billing?.invoices || [];
        const op = can('operate');
        const rows = invoices.map((invoice) => {
            const cur = invoice.currency || 'KES';
            const acts = [];
            if (op && invoice.status === 'open' && invoice.amount_due > 0) {
                acts.push(`<button class="btn btn-secondary btn-small" type="button" data-action="bill-pay" data-slug="${esc(firm.slug)}" data-number="${esc(invoice.number)}" data-currency="${esc(cur)}" data-due="${esc(invoice.amount_due)}">Record payment</button>`);
                if (cur === 'KES') acts.push(`<button class="btn btn-secondary btn-small" type="button" data-action="bill-mpesa" data-slug="${esc(firm.slug)}" data-number="${esc(invoice.number)}">M-Pesa request</button>`);
            }
            return `<tr><td class="mono">${esc(invoice.number)}</td><td>${esc(String(invoice.period_start || '—').slice(0, 10))} – ${esc(String(invoice.period_end || '—').slice(0, 10))}</td><td>${minor(invoice.total, cur)}</td><td>${badge(invoice.status)}</td><td>${minor(invoice.amount_due, cur)}</td><td><div class="row-actions">${acts.join('')}</div></td></tr>`;
        }).join('');
        const subActions = [];
        if (op && !subscription) subActions.push(`<button class="btn btn-primary" type="button" data-action="bill-subscribe" data-slug="${esc(firm.slug)}" data-email="">Start subscription</button>`);
        if (op && subscription && subscription.status !== 'canceled') {
            subActions.push(`<button class="btn btn-primary" type="button" data-action="bill-charge" data-slug="${esc(firm.slug)}" data-currency="${esc(subscription.currency || 'KES')}">Create invoice</button>`);
            subActions.push(`<button class="btn btn-secondary" type="button" data-action="bill-change" data-slug="${esc(firm.slug)}" data-current="${esc(subscription.plan)}">Change plan</button>`);
            if (!subscription.cancel_at_period_end) subActions.push(`<button class="btn btn-danger" type="button" data-action="bill-cancel" data-slug="${esc(firm.slug)}">Cancel subscription</button>`);
        }
        target.innerHTML = `
            <div class="grid">
                <section class="card span-12">
                    <div class="card-title-row"><div><h2>Subscription</h2><p class="card-caption">Plan and payment status for ${esc(firm.name)}.</p></div>${subscription ? badge(subscription.status) : '<span class="badge">No subscription</span>'}</div>
                    ${subscription ? `<div class="detail-grid">
                        ${detailItem('Plan', subscription.plan)}${detailItem('Currency', subscription.currency)}${detailItem('Provider', subscription.provider)}
                        ${detailItem('Billing email', subscription.billing_email)}${detailItem('Period ends', subscription.period_end ? date(subscription.period_end) : '—')}
                        ${detailItem('Trial ends', subscription.trial_ends_at ? date(subscription.trial_ends_at) : '—')}${detailItem('Autopay', subscription.autopay ? 'Enabled' : 'Off')}
                        ${detailItem('Renewal', subscription.cancel_at_period_end ? 'Ends at period end' : 'Renews automatically')}${detailItem('Credit', minor(subscription.credit_balance || 0, subscription.currency))}
                    </div>${billing.autopay_url ? `<p class="notice notice-warn spaced-notice">A secure card-setup link is available for the firm. Share it using an approved channel: <span class="mono">${esc(billing.autopay_url)}</span></p>` : ''}` : '<div class="empty">No subscription is recorded for this firm. Start one to bill it.</div>'}
                    ${subActions.length ? `<div class="row-actions spaced-actions">${subActions.join('')}</div>` : ''}
                </section>
                <section class="card span-12"><div class="card-title-row"><div><h2>Subscription invoices</h2><p class="card-caption">Latest 50 invoices for this firm.</p></div></div>${table(['Invoice', 'Period', 'Total', 'Status', 'Owing', ''], rows, 'No subscription invoices found.')}</section>
            </div>`;
    }

    const INTEGRATIONS = {
        mpesa: { title: 'M-Pesa (Daraja)', blurb: 'Collects invoice payments from the firm’s clients by STK push, into the firm’s own paybill or till.', fields: [['consumer_key', 'Consumer key', 1], ['consumer_secret', 'Consumer secret', 1], ['shortcode', 'Paybill / till number'], ['passkey', 'Lipa na M-Pesa passkey', 1], ['environment', 'Environment', 0, [['sandbox', 'Sandbox (testing)'], ['production', 'Production (live money)']]]] },
        stripe: { title: 'Card payments (Stripe)', blurb: 'Collects invoice payments by card, into the firm’s own Stripe account.', fields: [['secret_key', 'Secret key (sk_…)', 1], ['webhook_secret', 'Webhook signing secret (whsec_…)', 1]] },
        textsms: { title: 'SMS (TextSMS)', blurb: 'Hearing reminders and payment links sent from the firm’s own sender ID.', fields: [['api_key', 'API key', 1], ['partner_id', 'Partner ID'], ['shortcode', 'Sender ID / shortcode']] },
        mail: { title: 'Email (SMTP)', blurb: 'Invoices, reminders and password resets sent from the firm’s own mailbox.', fields: [['host', 'SMTP server'], ['port', 'Port (587 or 465)'], ['username', 'Username'], ['password', 'Password', 1], ['scheme', 'Security', 0, [['smtp', 'STARTTLS (port 587)'], ['smtps', 'SSL/TLS (port 465)']]], ['from_address', 'Send from (email address)'], ['from_name', 'Sender name (the firm)']] },
    };

    function renderTenantChannels(target, result) {
        const data = result && result.data;
        if (!data) { target.innerHTML = '<section class="card"><p class="subtle">Could not read this firm’s channels.</p></section>'; return; }
        state.channels = data;
        const ownOnly = result.meta && result.meta.own_only;
        const slug = state.currentTenant && state.currentTenant.slug;
        const rows = Object.keys(INTEGRATIONS).map((key) => {
            const r = data[key] || { ready: false, missing: [] };
            const badge = !r.ready ? '<span class="badge badge-pending">Not set up</span>' : (r.source === 'firm' ? '<span class="badge badge-ok">Firm’s own account</span>' : '<span class="badge badge-pending">Using the platform’s account</span>');
            const missing = r.missing && r.missing.length ? `<span class="subtle">Missing: ${esc(r.missing.map((m) => m.replaceAll('_', ' ')).join(', '))}</span>` : '';
            const act = can('operate') ? `<button class="btn btn-secondary btn-small" type="button" data-action="config-integration" data-slug="${esc(slug)}" data-provider="${key}">${r.ready && r.source === 'firm' ? 'Edit' : 'Set up'}</button>` : '';
            return `<tr><td><strong class="table-primary">${esc(INTEGRATIONS[key].title)}</strong><span class="subtle">${esc(INTEGRATIONS[key].blurb)}</span></td><td>${badge}${missing}</td><td>${act}</td></tr>`;
        }).join('');
        target.innerHTML = `<section class="card"><div class="card-title-row"><div><h2>Payments &amp; messaging</h2><p class="card-caption">Each firm collects its own money and sends its own messages. Enter the firm’s own accounts here after creating it. Secrets are stored encrypted and are never shown again.${ownOnly ? ' Platform accounts are not used as a fallback.' : ' Until a firm has its own, the platform’s account is used where one exists.'}</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>Channel</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table></div></section>`;
    }

    function openIntegrationModal(slug, provider) {
        const def = INTEGRATIONS[provider];
        if (!def || !can('operate')) return;
        const st = (state.channels && state.channels[provider] && state.channels[provider].fields) || {};
        const host = state.currentTenant && state.currentTenant.domains && state.currentTenant.domains[0];
        const base = host ? `${location.protocol}//${host}${location.port && /localhost$/.test(host) ? ':' + location.port : ''}` : '';
        const hints = { mpesa: base ? `Daraja callback base: <code>${esc(base)}/api/v1/pay/callback/mpesa/…</code> (the system adds the secret part for each request).` : '', stripe: base ? `Stripe webhook endpoint: <code>${esc(base)}/api/v1/pay/callback/stripe</code> (events: checkout.session.completed, checkout.session.expired).` : '' };
        const fields = def.fields.map(([name, label, secret, options]) => {
            const f = st[name] || {};
            const note = f.source === 'firm' ? 'Saved (leave empty to keep)' : (f.source === 'deployment' ? 'Using the platform’s value' : 'Not set');
            const input = options ? `<select id="int-${name}" name="${name}"><option value="">Keep current</option>${options.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('')}</select>`
                : `<input id="int-${name}" name="${name}" type="${secret ? 'password' : 'text'}" autocomplete="off" maxlength="255" placeholder="${f.source === 'firm' ? (secret ? '•••••••• (saved)' : 'Saved; type to replace') : ''}">`;
            return `<div class="field"><label for="int-${name}">${esc(label)}</label>${input}<span class="subtle">${note}</span></div>`;
        }).join('');
        document.getElementById('modal-root').innerHTML = `
            <div class="modal-backdrop" data-action="close-modal">
                <section class="modal" role="dialog" aria-modal="true" aria-labelledby="int-title">
                    <div class="modal-head"><h2 id="int-title">${esc(def.title)}</h2><button type="button" class="modal-close" data-action="close-modal" aria-label="Close">×</button></div>
                    <p class="subtle">${esc(def.blurb)} Fields left empty are not changed.</p>
                    <form id="integration-form" data-slug="${esc(slug)}" data-provider="${esc(provider)}">${fields}${hints[provider] ? `<p class="subtle">${hints[provider]}</p>` : ''}
                        <div class="modal-actions"><button class="btn btn-secondary" type="button" data-action="close-modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
                    </form>
                </section>
            </div>`;
        document.querySelector('#integration-form input, #integration-form select')?.focus();
    }

    async function submitIntegration(form) {
        const { slug, provider } = form.dataset;
        const body = {};
        Object.entries(Object.fromEntries(new FormData(form))).forEach(([k, v]) => { if (String(v).trim() !== '') body[k] = String(v).trim(); });
        if (!Object.keys(body).length) throw new Error('Fill in at least one field to save.');
        await request(`/tenants/${encodeURIComponent(slug)}/integrations/${encodeURIComponent(provider)}`, { method: 'PUT', body });
        document.getElementById('modal-root').innerHTML = '';
        toast('Saved. The values are stored encrypted for this firm.', 'success');
        await loadTenant(document.getElementById('page-content'));
    }

    function renderTenantFlags(target, flags) {
        const rows = flags.map((flag) => `<div class="flag-row">
            <div><strong>${esc(flag.flag)}</strong><span>${esc(flag.description || `Default: ${flag.default ? 'on' : 'off'}`)}</span></div>
            <label class="subtle" for="flag-${esc(flag.flag)}">Tenant override
                <select id="flag-${esc(flag.flag)}" name="flag:${esc(flag.flag)}" ${can('operate') ? '' : 'disabled'}>
                    <option value="inherit" ${flag.tenant_override === null || flag.tenant_override === undefined ? 'selected' : ''}>Use default (${flag.default ? 'on' : 'off'})</option>
                    <option value="on" ${flag.tenant_override === true ? 'selected' : ''}>Force on</option>
                    <option value="off" ${flag.tenant_override === false ? 'selected' : ''}>Force off</option>
                </select>
            </label>
        </div>`).join('');
        target.innerHTML = `
            <section class="card"><div class="card-title-row"><div><h2>Feature flags</h2><p class="card-caption">Overrides are scoped to this tenant; default behavior remains defined by the product.</p></div></div>
                ${flags.length ? `<form id="tenant-flags">${rows}${can('operate') ? '<div class="modal-actions"><button class="btn btn-primary" type="submit">Save overrides</button></div>' : ''}</form>` : '<div class="empty">No flags are available.</div>'}
            </section>`;
    }

    function renderTenantSupport(target, firm, result) {
        if (!result) {
            target.innerHTML = '<div class="notice notice-warn">Support access details could not be loaded. Confirm that the tenant is active and retry.</div>';
            return;
        }
        const rows = (result.data || []).map((item) => `<tr><td>${date(item.created_at)}</td><td>${esc(item.staff_email || item.platform_staff_id || '—')}</td><td>${badge(item.status)}</td><td>${esc(item.mode || 'read_only')}</td><td>${date(item.expires_at)}</td><td>${item.status === 'approved' ? `<button class="btn btn-secondary" type="button" data-support-action="start" data-slug="${esc(firm.slug)}" data-id="${esc(item.id)}">Start session</button>` : item.status === 'active' ? `<button class="btn btn-secondary" type="button" data-support-action="end" data-slug="${esc(firm.slug)}" data-id="${esc(item.id)}">End</button>` : '—'}</td></tr>`).join('');
        target.innerHTML = `
            <div class="section-stack">
                <div class="notice notice-warn">Support access is governed by the firm’s policy. Requests and sessions are audited in both the platform and firm audit logs. Never enter client content in a support reason.</div>
                <section class="card"><div class="card-title-row"><div><h2>Request support access</h2><p class="card-caption">A firm may require an administrator’s approval before a one-time session can start.</p></div></div>
                    <form id="support-request" class="grid">
                        <div class="field span-6"><label for="support-reason">Reason (required)</label><textarea id="support-reason" name="reason" minlength="10" maxlength="1000" required></textarea></div>
                        <div class="field span-3"><label for="support-minutes">Duration</label><select id="support-minutes" name="minutes"><option value="15">15 minutes</option><option value="30" selected>30 minutes</option><option value="60">60 minutes</option></select></div>
                        <div class="field span-3"><label for="support-mode">Mode</label><select id="support-mode" name="mode"><option value="read_only">Read only</option><option value="full">Full access</option></select></div>
                        <div class="span-12"><button class="btn btn-primary" type="submit">Request access</button></div>
                    </form>
                </section>
                <section class="card"><div class="card-title-row"><div><h2>Recent sessions</h2><p class="card-caption">Last 50 records.</p></div></div>${table(['Requested', 'Staff', 'State', 'Mode', 'Expires', 'Action'], rows, 'No support access sessions.')}</section>
            </div>`;
    }

    async function loadBilling(panel) {
        const status = state.invoiceStatus ? `?status=${encodeURIComponent(state.invoiceStatus)}` : '';
        const results = await Promise.allSettled([request('/billing/funnel?days=30'), request('/billing/subscriptions'), request(`/billing/invoices${status}`)]);
        const funnel = results[0].status === 'fulfilled' ? results[0].value.data || {} : {};
        const subs = results[1].status === 'fulfilled' ? results[1].value : { data: [], summary: {} };
        const invs = results[2].status === 'fulfilled' ? results[2].value : { data: [], summary: {} };
        const signups = funnel.signups || {};
        const trials = funnel.trials || {};
        const byStatus = subs.summary?.by_status || {};
        const today = localDate(0);
        const subRows = (subs.data || []).map((row) => `<tr>
            <td><button class="text-link table-primary" type="button" data-open-firm="${esc(row.slug)}" data-open-tab="billing">${esc(row.firm)}</button><span class="subtle mono">${esc(row.slug)}</span></td>
            <td>${esc(row.plan)}</td><td>${badge(row.status)}${row.cancel_at_period_end ? '<span class="subtle"> ends at period end</span>' : ''}</td>
            <td>${minor(row.price, row.currency)}</td><td>${row.period_end ? esc(String(row.period_end).slice(0, 10)) : '—'}</td><td>${row.autopay ? 'Card saved' : '—'}</td></tr>`).join('');
        const invRows = (invs.data || []).map((row) => {
            const overdue = row.status === 'open' && String(row.due_at).slice(0, 10) < today;
            const act = row.status === 'open' && can('operate') ? `<button class="btn btn-secondary btn-small" type="button" data-action="bill-pay" data-number="${esc(row.number)}" data-slug="${esc(row.slug)}" data-currency="${esc(row.currency)}" data-due="${esc(row.amount_due)}">Record payment</button>` : '';
            return `<tr><td class="mono">${esc(row.number)}</td>
                <td><button class="text-link table-primary" type="button" data-open-firm="${esc(row.slug)}" data-open-tab="billing">${esc(row.firm)}</button></td>
                <td>${esc(String(row.issued_at).slice(0, 10))}</td><td>${esc(String(row.due_at).slice(0, 10))}</td>
                <td>${minor(row.total, row.currency)}</td><td>${minor(row.amount_due, row.currency)}</td>
                <td>${badge(overdue ? 'overdue' : row.status)}</td><td>${act}</td></tr>`;
        }).join('');
        panel.innerHTML = `
            ${heading('Billing', 'Subscriptions, invoices and payments across every firm. Provider keys and payment credentials stay server-side.')}
            ${can('operate') ? '<div class="toolbar" style="margin-bottom:12px"><button class="btn btn-primary" type="button" data-action="bill-run">Generate invoices</button><span class="subtle">Recurring invoices are also generated automatically every night. To bill a firm for something extra, open the firm and choose Create invoice.</span></div>' : ''}
            <div class="grid">
                ${metric('Monthly recurring', minorList(subs.summary?.monthly_recurring), 'Active and past-due subscriptions')}
                ${metric('Subscriptions', fmt((byStatus.active || 0) + (byStatus.past_due || 0) + (byStatus.trialing || 0)), `${fmt(byStatus.trialing)} on trial · ${fmt(byStatus.past_due)} past due`)}
                ${metric('Unpaid invoices', minorList(invs.summary?.open), 'Open, not yet paid')}
                ${metric('Overdue', minorList(invs.summary?.overdue), 'Open and past the due date', invs.summary?.overdue && Object.keys(invs.summary.overdue).length ? 'warn' : '')}
                <section class="card span-12"><div class="card-title-row"><div><h2>Subscriptions</h2><p class="card-caption">One row per firm. Open a firm to change its plan, cancel, or take a payment.</p></div></div>
                    ${table(['Firm', 'Plan', 'Status', 'Monthly price', 'Renews', 'Autopay'], subRows, 'No firm has a subscription yet. Open a firm and start one.')}</section>
                <section class="card span-12"><div class="card-title-row"><div><h2>Invoices</h2><p class="card-caption">Latest 200 subscription invoices.</p></div>
                    <form id="invoice-filter" class="toolbar"><label class="subtle" for="invoice-status">Show</label><select id="invoice-status" name="status">
                        ${[['', 'All'], ['open', 'Open'], ['overdue', 'Overdue'], ['paid', 'Paid'], ['void', 'Void']].map(([v, l]) => `<option value="${v}" ${state.invoiceStatus === v ? 'selected' : ''}>${l}</option>`).join('')}
                    </select><button class="btn btn-secondary" type="submit">Apply</button></form></div>
                    ${table(['Invoice', 'Firm', 'Issued', 'Due', 'Total', 'Owing', 'Status', ''], invRows, 'No invoices match.')}</section>
                <section class="card span-12"><div class="card-title-row"><div><h2>Signup funnel</h2><p class="card-caption">Last ${fmt(funnel.days || 30)} days.</p></div></div>
                    <div class="progress-list">
                        ${progressRow('Submitted', signups.submitted, signups.submitted)}
                        ${progressRow('Verified', signups.verified, signups.submitted)}
                        ${progressRow('Provisioned', signups.ready, signups.submitted)}
                        ${progressRow('Trials converted', trials.converted, trials.started)}
                    </div>
                    ${trials.median_days_to_convert ? `<p class="subtle">Median time to convert: ${esc(trials.median_days_to_convert)} days. Trial conversion: ${trials.conversion_rate === null || trials.conversion_rate === undefined ? '—' : `${Math.round(trials.conversion_rate * 100)}%`}.</p>` : ''}
                </section>
            </div>`;
    }

    function progressRow(label, value, total) {
        const pct = total ? Math.min(100, Math.round((Number(value || 0) / Number(total)) * 100)) : 0;
        return `<div class="progress-row"><span>${esc(label)}</span><progress max="100" value="${pct}" aria-label="${esc(label)} ${pct}%"></progress><strong>${fmt(value)}</strong></div>`;
    }

    // Friendly names for the technical probe names; the technical wording never reaches the screen.
    function serviceName(name) {
        const names = { database: 'Core data', cache: 'Cache', storage: 'File storage', queue: 'Background jobs', tenant_databases: 'Firm workspaces', scheduler: 'Scheduled tasks', mail: 'Email', search: 'Search' };
        const key = String(name || '').toLowerCase();
        return names[key] || (key ? key.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase()) : 'Service');
    }

    async function loadHealth(panel) {
        const results = await Promise.allSettled([request('/health'), request('/sla?days=30'), request('/incidents')]);
        const health = results[0].status === 'fulfilled' ? results[0].value.data || {} : {};
        const sla = results[1].status === 'fulfilled' ? results[1].value.data || {} : {};
        const incidents = results[2].status === 'fulfilled' ? results[2].value.data || [] : [];
        const componentRows = keyedRows(health.components).map((item) => `<tr><td class="table-primary">${esc(serviceName(item.name || item.component))}</td><td>${badge(item.status)}</td><td>${esc(item.latency_ms === undefined || item.latency_ms === null ? '—' : `${item.latency_ms} ms`)}</td><td>${esc(item.checked_at ? date(item.checked_at) : '—')}</td><td class="audit-detail">${esc(item.reason || item.message || item.detail || '')}</td></tr>`).join('');
        const incidentRows = incidents.map((incident) => `<tr><td>${esc(incident.title || `Incident ${incident.id}`)}<span class="table-sub">${esc(incident.impact || '')}</span></td><td>${badge(incident.status)}</td><td>${date(incident.starts_at || incident.created_at)}</td><td class="audit-detail">${esc(incident.message || '')}</td><td>${can('operate') && incident.status !== 'resolved' ? `<form data-incident-update="${esc(incident.id)}" class="toolbar"><select name="status" aria-label="Set incident status"><option value="identified">Identified</option><option value="monitoring">Monitoring</option><option value="resolved">Resolved</option></select><input name="message" aria-label="Update message" placeholder="Update note" required><button class="btn btn-secondary" type="submit">Update</button></form>` : '—'}</td></tr>`).join('');
        const slaRows = keyedRows(sla.components).map((item) => `<tr><td>${esc(serviceName(item.name || item.component))}</td><td>${item.availability === null || item.availability === undefined ? '—' : `${Number(item.availability).toFixed(2)}%`}</td><td>${fmt(item.measured_minutes)}</td><td>${fmt(item.down_minutes)}</td><td>${item.coverage === null || item.coverage === undefined ? '—' : `${Number(item.coverage).toFixed(1)}%`}</td></tr>`).join('');
        panel.innerHTML = `
            ${heading('Service health', 'Live service checks, availability, and incident updates.', can('operate') ? '<button class="btn btn-primary" type="button" data-action="open-incident">Open incident</button>' : '')}
            <div class="grid">
                <section class="card span-12"><div class="card-title-row"><div><h2>Platform services</h2><p class="card-caption">Overall status ${badge(health.status || 'unknown')}</p></div><button class="btn btn-secondary" type="button" data-action="refresh">Refresh</button></div>${table(['Component', 'State', 'Latency', 'Last probe', 'Note'], componentRows, 'No probes have been recorded.')}</section>
                <section class="card span-12"><div class="card-title-row"><div><h2>30-day availability</h2><p class="card-caption">Unknown time is excluded; read coverage with availability.</p></div></div>${table(['Component', 'Availability', 'Measured min', 'Down min', 'Coverage'], slaRows, 'No SLA measurements yet.')}</section>
                <section class="card span-12"><div class="card-title-row"><div><h2>Incidents and maintenance</h2><p class="card-caption">Public incident text is visible on the status page; never include firm or client details.</p></div></div>${table(['Incident', 'State', 'Started', 'Latest update', 'Action'], incidentRows, 'No incidents recorded.')}</section>
            </div>`;
    }

    async function loadAudit(panel) {
        const params = new URLSearchParams();
        Object.entries(state.auditFilters || {}).forEach(([key, value]) => { if (value) params.set(key, value); });
        params.set('limit', '100');
        const result = await request(`/audit?${params}`);
        const rows = (result.data || []).map((item) => `<tr><td>${date(item.created_at)}</td><td>${esc(item.staff_email || 'System')}</td><td>${esc(item.action)}</td><td>${esc(item.tenant_slug || '—')}</td><td>${badge(item.result)}</td><td class="audit-detail">${esc(item.detail || '')}</td></tr>`).join('');
        panel.innerHTML = `
            ${heading('Platform audit', 'Staff actions, permission refusals, and failures. Sensitive credentials are redacted by the server.')}
            <section class="card">
                <form id="audit-filter" class="toolbar">
                    <input name="tenant_slug" value="${esc(state.auditFilters?.tenant_slug || '')}" placeholder="Firm slug" aria-label="Filter by firm">
                    <input name="staff_email" value="${esc(state.auditFilters?.staff_email || '')}" placeholder="Staff email" aria-label="Filter by staff">
                    <input name="action" value="${esc(state.auditFilters?.action || '')}" placeholder="Action" aria-label="Filter by action">
                    <select name="result" aria-label="Filter by result"><option value="">All results</option>${['ok', 'denied', 'failed', 'busy'].map((v) => `<option value="${v}" ${state.auditFilters?.result === v ? 'selected' : ''}>${v}</option>`).join('')}</select>
                    <button class="btn btn-secondary" type="submit">Apply filters</button>
                </form>
                <div class="table-spacer" aria-hidden="true"></div>
                ${table(['Time', 'Staff', 'Action', 'Firm', 'Result', 'Detail'], rows, 'No audit records match these filters.')}
            </section>`;
    }

    async function loadSecurity(panel) {
        const result = await request('/mfa');
        const mfa = result || {};
        panel.innerHTML = `
            ${heading('My account', 'Your sign-in details and security. Other staff accounts are managed by an administrator.')}
            <div class="grid">
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>Profile</h2><p class="card-caption">How your name appears in the audit log.</p></div>${badge(state.staff?.role)}</div>
                    <form id="profile-form" class="account-form">
                        <div class="field"><label for="acct-name">Full name</label><input id="acct-name" name="name" value="${esc(state.staff?.name || '')}" required minlength="2" maxlength="120" autocomplete="name"></div>
                        <div class="field"><label for="acct-email">Email</label><input id="acct-email" value="${esc(state.staff?.email || '')}" disabled><span class="subtle">Your email and role are set by an administrator.</span></div>
                        <button class="btn btn-primary" type="submit">Save name</button>
                    </form>
                </section>
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>Change password</h2><p class="card-caption">At least 12 characters with upper and lower case letters and a number.</p></div></div>
                    <form id="password-form" class="account-form" autocomplete="off">
                        <div class="field"><label for="acct-current">Current password</label><input id="acct-current" name="current_password" type="password" required autocomplete="current-password"></div>
                        <div class="field"><label for="acct-new">New password</label><input id="acct-new" name="password" type="password" required minlength="12" autocomplete="new-password"></div>
                        <div class="field"><label for="acct-confirm">Confirm new password</label><input id="acct-confirm" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password"></div>
                        <button class="btn btn-primary" type="submit">Change password</button>
                    </form>
                </section>
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>Two-factor authentication</h2><p class="card-caption">A second step when you sign in, using an authenticator app on your phone.</p></div>${badge(mfa.enabled ? 'enabled' : 'not enabled')}</div>
                    <p class="subtle">${mfa.required ? 'The platform requires an authenticator factor before staff can use operational modules.' : 'Two-factor is optional here; turn it on for stronger account protection.'}</p>
                    ${mfa.enabled ? `<p class="subtle">Recovery codes remaining: ${fmt(mfa.recovery_codes_left)}</p>` : ''}
                    ${mfa.enabled ? (mfa.required ? '<div class="notice">Two-factor is on and required by policy. It cannot be disabled here.</div>' : '<button class="btn btn-secondary" type="button" data-action="disable-mfa">Disable two-factor</button>') : '<button class="btn btn-primary" type="button" data-action="setup-mfa">Set up two-factor</button>'}
                </section>
                <section class="card span-6">
                    <div class="card-title-row"><div><h2>What your role can do</h2><p class="card-caption">Allowed actions: ${esc((state.abilities || []).join(', ') || '—')}</p></div></div>
                    ${table(['Role', 'Read', 'Operate', 'Provision', 'Destructive', 'Support'], [
                        `<tr><td>${badge('viewer')}</td><td>Yes</td><td>—</td><td>—</td><td>—</td><td>—</td></tr>`,
                        `<tr><td>${badge('operator')}</td><td>Yes</td><td>Yes</td><td>Yes</td><td>—</td><td>—</td></tr>`,
                        `<tr><td>${badge('support')}</td><td>Yes</td><td>—</td><td>—</td><td>—</td><td>Yes</td></tr>`,
                        `<tr><td>${badge('admin')}</td><td>Yes</td><td>Yes</td><td>Yes</td><td>Yes</td><td>Yes</td></tr>`,
                    ].join(''))}
                </section>
            </div>`;
    }

    async function openProvisionModal() {
        if (!can('provision')) return;
        let plans = [];
        let currencies = ['KES'];
        try {
            const result = await request('/plans');
            plans = (result.data || []).filter((p) => !p.archived);
            currencies = (result.meta && result.meta.currencies) || currencies;
        } catch (error) { /* the form still works with the free trial */ }
        const planOptions = plans.length
            ? plans.map((p) => `<option value="${esc(p.code)}"${p.code === 'trial' ? ' selected' : ''}>${esc(p.name)}${p.code === 'trial' ? ' (free trial)' : ` — ${esc(Object.entries(p.prices || {}).filter(([, v]) => v).map(([c, v]) => minor(v, c)).join(' / ') || 'no price')} per month`}</option>`).join('')
            : '<option value="trial" selected>Trial</option>';
        document.getElementById('modal-root').innerHTML = `
            <div class="modal-backdrop" data-action="close-modal">
                <section class="modal" role="dialog" aria-modal="true" aria-labelledby="provision-title">
                    <div class="modal-head"><h2 id="provision-title">Provision a firm</h2><button type="button" class="modal-close" data-action="close-modal" aria-label="Close">×</button></div>
                    <p class="subtle">Creates the firm’s private workspace, sets up its starting data, creates the first administrator, starts the chosen plan, and emails the administrator their sign-in details.</p>
                    <form id="provision-firm">
                        <div class="field"><label for="new-firm-name">Firm name</label><input id="new-firm-name" name="name" required maxlength="255"></div>
                        <div class="field"><label for="new-firm-slug">URL slug</label><input id="new-firm-slug" name="slug" required pattern="[a-z0-9][a-z0-9-]{0,62}" placeholder="example-firm"></div>
                        <div class="field"><label for="new-admin-email">First administrator email (sign-in details are sent here)</label><input id="new-admin-email" name="admin_email" type="email" required></div>
                        <div class="field"><label for="new-admin-name">Administrator name</label><input id="new-admin-name" name="admin_name"></div>
                        <div class="field"><label for="new-plan">Initial plan</label><select id="new-plan" name="plan">${planOptions}</select></div>
                        <div class="field"><label for="new-currency">Billing currency</label><select id="new-currency" name="currency">${currencies.map((c) => `<option value="${esc(c)}">${esc(c)}</option>`).join('')}</select></div>
                        <div class="field"><label for="new-trial-days">Free days before the first charge <span class="subtle">(paid plans only; 0 = bill now)</span></label><input id="new-trial-days" name="trial_days" type="number" min="0" max="365" value="0"></div>
                        <div class="field"><label for="new-brand-color">Firm brand colour <span class="subtle">(sidebar, buttons and preloader; the firm can change it later)</span></label><input id="new-brand-color" name="brand_color" type="color" value="#0e6b50"></div>
                        <div class="modal-actions"><button class="btn btn-secondary" type="button" data-action="close-modal">Cancel</button><button class="btn btn-primary" type="submit">Provision firm</button></div>
                    </form>
                </section>
            </div>`;
        document.getElementById('new-firm-name')?.focus();
    }

async function loadPlans(panel) {
        const result = await request('/plans');
        state.plans = result.data || [];
        state.planMeta = result.meta || {};
        const currencies = state.planMeta.currencies || [];
        const rows = state.plans.map((plan) => {
            const limits = plan.limits || {};
            const val = (v, unit = '') => (v === null || v === undefined ? 'Unlimited' : `${fmt(v)}${unit}`);
            const lim = [['Users', val(limits.users)], ['Storage', val(limits.storage_gb, ' GB')], ['Audit history', limits.audit_online_months === null || limits.audit_online_months === undefined ? 'Unlimited' : `${fmt(limits.audit_online_months)} mo`]];
            const actions = [];
            if (can('operate')) {
                actions.push(`<button class="btn btn-secondary btn-small" type="button" data-action="edit-plan" data-code="${esc(plan.code)}">Edit</button>`);
                if (!plan.system) actions.push(`<button class="btn btn-secondary btn-small" type="button" data-action="${plan.archived ? 'restore-plan' : 'archive-plan'}" data-code="${esc(plan.code)}">${plan.archived ? 'Restore' : 'Archive'}</button>`);
            }
            if (can('destructive') && !plan.system) actions.push(`<button class="btn btn-danger btn-small" type="button" data-action="delete-plan" data-code="${esc(plan.code)}">Delete</button>`);
            const priced = currencies.filter((c) => plan.prices[c] !== undefined);
            const prices = !priced.length ? '<span class="subtle">—</span>'
                : priced.every((c) => Number(plan.prices[c]) === 0) ? '<div class="plan-price">Free</div>'
                : priced.map((c) => `<div class="plan-price">${minor(plan.prices[c], c)}<span> / month</span></div>`).join('');
            return `<tr class="${plan.archived ? 'plan-archived' : ''}">
                <td class="plan-name"><strong>${esc(plan.name)}</strong><span class="plan-code">${esc(plan.code)}</span>${plan.archived ? '<span class="plan-flag">Archived</span>' : ''}${plan.system ? '<span class="plan-note">Used for every free trial</span>' : ''}${plan.description ? `<span class="plan-note">${esc(plan.description)}</span>` : ''}</td>
                <td>${prices}</td>
                <td><dl class="plan-limits">${lim.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('')}</dl></td>
                <td class="plan-features">${plan.features.length ? plan.features.map((f) => `<span class="chip">${esc(f.replaceAll('_', ' '))}</span>`).join('') : '<span class="subtle">None</span>'}</td>
                <td class="plan-use"><strong>${fmt(plan.firms)}</strong> ${plan.firms === 1 ? 'firm' : 'firms'}<span class="subtle">${fmt(plan.subscribers)} ${plan.subscribers === 1 ? 'subscription' : 'subscriptions'}</span></td>
                <td class="plan-actions"><div class="row-actions">${actions.join('')}</div></td></tr>`;
        }).join('');
        const vat = state.planMeta.tax_rate_bp ? `VAT of ${(state.planMeta.tax_rate_bp / 100).toFixed(0)}% is added to invoices.` : 'Invoices carry no VAT yet: set BILLING_TAX_RATE_BP=1600 for Kenya’s 16%.';
        panel.innerHTML = `
            ${heading('Plans', 'What firms can subscribe to. Changes apply to future invoices and plan changes; firms already billed keep their current period.', can('operate') ? '<button class="btn btn-primary" type="button" data-action="new-plan">New plan</button>' : '')}
            <section class="card">
                <div class="table-wrap"><table class="plans-table"><colgroup><col style="width:19%"><col style="width:15%"><col style="width:17%"><col style="width:24%"><col style="width:10%"><col style="width:15%"></colgroup><thead><tr><th scope="col">Plan</th><th scope="col">Monthly price</th><th scope="col">Limits</th><th scope="col">Features</th><th scope="col">In use</th><th scope="col" class="plan-actions-head">Actions</th></tr></thead><tbody>${rows || '<tr><td colspan="6" class="empty">No plans.</td></tr>'}</tbody></table></div>
                <p class="subtle">Archive a plan to stop offering it while firms on it keep it. A plan that firms use cannot be deleted. ${esc(vat)}</p>
            </section>`;
    }

    function openPlanModal(code = '') {
        const plan = code ? state.plans.find((p) => p.code === code) : null;
        const currencies = state.planMeta.currencies || [];
        const features = state.planMeta.features || [];
        const lim = plan?.limits || { users: 5, storage_gb: 5, audit_online_months: 12 };
        const val = (v) => (v === null || v === undefined ? '' : v);
        document.getElementById('modal-root').innerHTML = `
            <div class="modal-backdrop" data-action="close-modal">
                <section class="modal" role="dialog" aria-modal="true" aria-labelledby="plan-title">
                    <div class="modal-head"><h2 id="plan-title">${plan ? `Edit ${esc(plan.name)}` : 'New plan'}</h2><button type="button" class="modal-close" data-action="close-modal" aria-label="Close">×</button></div>
                    <form id="plan-form" data-code="${esc(code)}">
                        ${plan ? '' : '<div class="field"><label for="plan-code">Code</label><input id="plan-code" name="code" required pattern="[a-z][a-z0-9_]{1,31}" placeholder="boutique"><small class="subtle">Lower-case letters, digits and underscores. Cannot be changed later.</small></div>'}
                        <div class="field"><label for="plan-name">Name</label><input id="plan-name" name="name" required maxlength="80" value="${esc(plan?.name || '')}"></div>
                        <div class="field"><label for="plan-desc">Short description</label><input id="plan-desc" name="description" maxlength="300" value="${esc(plan?.description || '')}"></div>
                        ${currencies.map((c) => `<div class="field"><label for="plan-price-${c}">Monthly price (${esc(c)})</label><input id="plan-price-${c}" name="price:${c}" inputmode="decimal" placeholder="e.g. 25000" value="${plan && plan.prices[c] !== undefined ? esc(plan.prices[c] / 100) : ''}"></div>`).join('')}
                        <div class="field"><label for="plan-users">Users included</label><input id="plan-users" name="users" inputmode="numeric" placeholder="blank = unlimited" value="${esc(val(lim.users))}"></div>
                        <div class="field"><label for="plan-storage">Storage included (GB)</label><input id="plan-storage" name="storage_gb" inputmode="decimal" placeholder="blank = unlimited" value="${esc(val(lim.storage_gb))}"></div>
                        <div class="field"><label for="plan-audit">Audit history kept online (months)</label><input id="plan-audit" name="audit_online_months" inputmode="numeric" placeholder="blank = unlimited" value="${esc(val(lim.audit_online_months))}"></div>
                        <fieldset class="field"><legend>Features</legend><div class="check-grid">${features.map((f) => `<label class="check"><input type="checkbox" name="feature" value="${esc(f)}" ${plan?.features.includes(f) ? 'checked' : ''}> ${esc(f.replaceAll('_', ' '))}</label>`).join('')}</div></fieldset>
                        <div class="field"><label for="plan-reason">Reason for this change (audited)</label><input id="plan-reason" name="reason" required minlength="5" maxlength="500"></div>
                        <div class="modal-actions"><button class="btn btn-secondary" type="button" data-action="close-modal">Cancel</button><button class="btn btn-primary" type="submit">${plan ? 'Save plan' : 'Create plan'}</button></div>
                    </form>
                </section>
            </div>`;
        document.getElementById(plan ? 'plan-name' : 'plan-code')?.focus();
    }

    async function submitPlan(form) {
        const data = new FormData(form);
        const prices = {};
        (state.planMeta.currencies || []).forEach((c) => { const v = String(data.get(`price:${c}`) || '').trim(); prices[c] = v === '' ? null : toMinor(v); });
        const num = (name) => { const v = String(data.get(name) || '').trim(); return v === '' ? null : Number(v); };
        const body = {
            name: data.get('name'), description: data.get('description') || null, prices,
            features: data.getAll('feature'), limits: { users: num('users'), storage_gb: num('storage_gb'), audit_online_months: num('audit_online_months') },
            reason: data.get('reason'),
        };
        const code = form.dataset.code;
        if (code) await request(`/plans/${encodeURIComponent(code)}`, { method: 'PUT', body });
        else await request('/plans', { method: 'POST', body: { ...body, code: data.get('code') } });
        document.getElementById('modal-root').innerHTML = '';
        toast(code ? 'Plan saved.' : 'Plan created.', 'success');
        await loadPage();
    }

    async function planAction(action, code) {
        const plan = state.plans.find((p) => p.code === code);
        const label = { 'archive-plan': 'archive', 'restore-plan': 'restore', 'delete-plan': 'delete' }[action];
        const name = plan?.name || code;
        const copy = {
            'archive-plan': { title: `Archive the ${name} plan?`, message: 'New firms can no longer be put on it. Firms already on it keep it until you move them.', confirmText: 'Archive plan', danger: false },
            'restore-plan': { title: `Restore the ${name} plan?`, message: 'It becomes available to choose again.', confirmText: 'Restore plan', danger: false },
            'delete-plan': { title: `Delete the ${name} plan?`, message: 'The plan is removed for good. Only plans with no firms on them can be deleted.', confirmText: 'Delete plan', danger: true, irreversible: true },
        }[action];
        const answer = await ask({ ...copy, reason: true });
        if (!answer) return;
        const reason = answer.reason;
        if (action === 'delete-plan') await request(`/plans/${encodeURIComponent(code)}`, { method: 'DELETE', body: { reason } });
        else await request(`/plans/${encodeURIComponent(code)}/${label}`, { method: 'POST', body: { reason } });
        toast(`Plan ${label}d.`, 'success');
        await loadPage();
    }

    // ---- billing actions on one firm ------------------------------------------------------------------------------
    async function openBillingModal(kind, ctx = {}) {
        if (!can('operate')) return;
        let plans = state.plans;
        if (!plans.length && (kind === 'subscribe' || kind === 'change-plan')) {
            try { const r = await request('/plans'); plans = state.plans = r.data || []; state.planMeta = r.meta || {}; } catch (error) { toast(error.message, 'error'); return; }
        }
        const reason = '<div class="field"><label for="bill-reason">Reason (audited)</label><input id="bill-reason" name="reason" required minlength="5" maxlength="500"></div>';
        const offered = plans.filter((p) => !p.archived && p.code !== ctx.current);
        const planSelect = `<div class="field"><label for="bill-plan">Plan</label><select id="bill-plan" name="plan" required>${offered.map((p) => `<option value="${esc(p.code)}">${esc(p.name)}</option>`).join('')}</select></div>`;
        const forms = {
            subscribe: ['Start a subscription', 'Subscribes the firm to a plan and issues its first invoice.', `${planSelect}
                <div class="field"><label for="bill-currency">Currency</label><select id="bill-currency" name="currency">${(state.planMeta.currencies || ['KES']).map((c) => `<option>${esc(c)}</option>`).join('')}</select></div>
                <div class="field"><label for="bill-provider">How they pay</label><select id="bill-provider" name="provider"><option value="manual">Manual (bank, cash, M-Pesa paybill)</option><option value="stripe">Card (Stripe)</option><option value="mpesa">M-Pesa</option></select></div>
                <div class="field"><label for="bill-email">Billing email</label><input id="bill-email" name="billing_email" type="email" value="${esc(ctx.email || '')}"></div>
                <div class="field"><label for="bill-trial">Free trial days</label><input id="bill-trial" name="trial_days" inputmode="numeric" value="0"></div>${reason}`, 'Start subscription'],
            'change-plan': ['Change plan', `Now on ${ctx.current || 'a plan'}. The change takes effect immediately; unused time is credited and the rest of the period charged on the new plan.`, `${planSelect}${reason}`, 'Change plan'],
            cancel: ['Cancel subscription', 'The firm keeps access until the end of the period it has paid for, then the subscription ends. Nothing is deleted.', reason, 'Cancel at period end'],
            pay: [`Record payment: ${ctx.number}`, `Amount owing ${minor(ctx.due, ctx.currency)}. Use this for a bank transfer, cash or an M-Pesa paybill payment received outside the system.`, `
                <div class="field"><label for="bill-amount">Amount received (${esc(ctx.currency)})</label><input id="bill-amount" name="amount" required inputmode="decimal" value="${esc(Number(ctx.due || 0) / 100)}"></div>
                <div class="field"><label for="bill-ref">Payment reference</label><input id="bill-ref" name="reference" required maxlength="120" placeholder="Bank slip or M-Pesa code"></div>${reason}`, 'Record payment'],
            charge: ['Create an invoice', 'Issues a one-off invoice (setup fee, training, a manual overage) on this firm\'s subscription, with the next invoice number. Tax and any credit balance apply as usual.', `
                <div class="field"><label for="bill-desc">What is it for?</label><input id="bill-desc" name="description" required minlength="3" maxlength="200" placeholder="e.g. Onboarding and data migration"></div>
                <div class="field"><label for="bill-amt">Amount (${esc(ctx.currency || 'KES')}, per unit)</label><input id="bill-amt" name="amount" required inputmode="decimal"></div>
                <div class="field"><label for="bill-qty">Quantity</label><input id="bill-qty" name="quantity" inputmode="numeric" value="1"></div>${reason}`, 'Issue invoice'],
            run: ['Generate invoices', 'Choose the date to invoice up to. Today creates what is due now; a later date also creates the invoices for the coming months. Each month is only ever invoiced once, and new months are invoiced automatically every night.', `
                <div class="field"><label for="bill-through">Invoice up to</label><input id="bill-through" name="through" type="date" required value="${localDate(0)}" min="${localDate(0)}" max="${localDate(365)}"></div>`, 'Generate invoices'],
            mpesa: [`Ask for M-Pesa payment: ${ctx.number}`, 'Sends a payment prompt to the phone. The invoice is marked paid when the firm confirms on the phone.', `
                <div class="field"><label for="bill-phone">Phone number</label><input id="bill-phone" name="phone" required inputmode="tel" placeholder="2547XXXXXXXX"></div>${reason}`, 'Send prompt'],
        };
        const [title, note, fields, button] = forms[kind];
        document.getElementById('modal-root').innerHTML = `
            <div class="modal-backdrop" data-action="close-modal">
                <section class="modal" role="dialog" aria-modal="true" aria-labelledby="bill-title">
                    <div class="modal-head"><h2 id="bill-title">${esc(title)}</h2><button type="button" class="modal-close" data-action="close-modal" aria-label="Close">×</button></div>
                    <p class="subtle">${esc(note)}</p>
                    <form id="billing-form" data-kind="${esc(kind)}" data-slug="${esc(ctx.slug || '')}" data-number="${esc(ctx.number || '')}" data-currency="${esc(ctx.currency || '')}">${fields}
                        <div class="modal-actions"><button class="btn btn-secondary" type="button" data-action="close-modal">Close</button><button class="btn ${kind === 'cancel' ? 'btn-danger' : 'btn-primary'}" type="submit">${esc(button)}</button></div>
                    </form>
                </section>
            </div>`;
        document.querySelector('#billing-form input, #billing-form select')?.focus();
    }

    async function submitBilling(form) {
        const { kind, slug, number, currency } = form.dataset;
        const v = Object.fromEntries(new FormData(form));
        const s = encodeURIComponent(slug);
        let ranCount = 0;
        if (kind === 'subscribe') await request(`/tenants/${s}/subscription`, { method: 'POST', body: { plan: v.plan, currency: v.currency, provider: v.provider, billing_email: v.billing_email || null, trial_days: Number(v.trial_days || 0), reason: v.reason } });
        else if (kind === 'change-plan') await request(`/tenants/${s}/subscription/plan`, { method: 'POST', body: { plan: v.plan, reason: v.reason } });
        else if (kind === 'cancel') await request(`/tenants/${s}/subscription/cancel`, { method: 'POST', body: { reason: v.reason } });
        else if (kind === 'pay') {
            const amount = toMinor(v.amount);
            if (!amount) throw new Error('Enter the amount received.');
            await request(`/billing/invoices/${encodeURIComponent(number)}/payments`, { method: 'POST', body: { amount, currency, reference: v.reference, reason: v.reason } });
        } else if (kind === 'charge') {
            const amount = toMinor(v.amount);
            if (!amount) throw new Error('Enter the amount to invoice.');
            await request(`/tenants/${s}/invoices`, { method: 'POST', body: { description: v.description, amount, quantity: Number(v.quantity || 1), reason: v.reason } });
        } else if (kind === 'run') {
            const out = await request('/billing/run', { method: 'POST', body: { through: v.through || undefined } });
            ranCount = out.data?.invoices_issued ?? 0;
        } else if (kind === 'mpesa') await request(`/billing/invoices/${encodeURIComponent(number)}/mpesa`, { method: 'POST', body: { phone: v.phone, reason: v.reason } });
        document.getElementById('modal-root').innerHTML = '';
        toast({ subscribe: 'Subscription started.', 'change-plan': 'Plan changed.', cancel: 'Subscription will end at the period end.', pay: 'Payment recorded.', charge: 'Invoice issued.', run: ranCount ? `${ranCount} invoice${ranCount === 1 ? '' : 's'} generated.` : 'Nothing to invoice for that date: everything is already up to date.', mpesa: 'Payment prompt sent to the phone.' }[kind], 'success');
        if (state.page === 'tenant') await loadTenant(document.getElementById('page-content')); else await loadPage();
    }

    function showOncePassword(result) {
        const password = result.admin_password;
        const mailed = result.welcome_email === 'sent';
        if (!password) {
            toast(mailed ? 'Firm provisioned. The administrator was emailed their sign-in details.' : (result.note || 'Firm provisioned.'), mailed ? 'success' : 'error');
            navigate('tenant', result.data?.slug || '', 'channels');
            return;
        }
        document.getElementById('modal-root').innerHTML = `
            <div class="modal-backdrop">
                <section class="modal" role="dialog" aria-modal="true" aria-labelledby="password-title">
                    <div class="modal-head"><h2 id="password-title">Firm created</h2></div>
                    <div class="notice ${mailed ? '' : 'notice-warn'}">${mailed ? 'The sign-in details have been emailed to the firm administrator. This is the initial password, shown here once as a back-up.' : esc(result.note || 'The welcome email could not be sent.') + ' Give the administrator this password through a secure channel. It will not be shown again.'}</div>
                    <p><strong>${esc(result.data?.name || result.data?.slug || 'Firm administrator')}</strong></p>
                    <div class="mfa-secret" id="generated-password">${esc(password)}</div>
                    <div class="modal-actions"><button class="btn btn-secondary" type="button" data-action="copy-password">Copy password</button><button class="btn btn-primary" type="button" data-action="close-password" data-slug="${esc(result.data?.slug || '')}">I’ve recorded it. Set up their payments &amp; messaging</button></div>
                </section>
            </div>`;
    }

    async function submitLogin(form) {
        const values = Object.fromEntries(new FormData(form));
        const result = await request('/login', { method: 'POST', body: values });
        if (result.mfa_required) {
            state.mfaChallenge = result.challenge;
            renderLogin('', true);
            document.getElementById('mfa-code')?.focus();
            return;
        }
        await finishLogin(result);
    }

    async function submitMfaLogin(form) {
        const values = Object.fromEntries(new FormData(form));
        const result = await request('/login/mfa', { method: 'POST', body: { challenge: state.mfaChallenge, code: values.code } });
        state.mfaChallenge = '';
        await finishLogin(result);
    }

    async function finishLogin(result) {
        state.token = result.token;
        sessionStorage.setItem(TOKEN_KEY, state.token);
        const profile = await request('/me');
        state.staff = profile;
        state.abilities = profile.abilities || [];
        if (result.mfa_enrollment_required) {
            await beginEnrollment();
            return;
        }
        await openConsole();
    }

    async function openConsole() {
        const profile = await request('/me');
        state.staff = profile;
        state.abilities = profile.abilities || [];
        const mfa = await request('/mfa');
        if (mfa.required && !mfa.enabled) {
            await beginEnrollment();
            return;
        }
        pageFromHash();
        renderShell();
        loadPage().catch((error) => toast(error.message, 'error'));
    }

    async function beginEnrollment() {
        try {
            state.mfaSetup = await request('/mfa/setup', { method: 'POST', body: {} });
            renderEnrollment();
            document.getElementById('enroll-code')?.focus();
        } catch (error) {
            renderLogin(error.message);
        }
    }

    async function submitEnrollment(form) {
        const values = Object.fromEntries(new FormData(form));
        const result = await request('/mfa/enable', { method: 'POST', body: { code: values.code } });
        state.recoveryCodes = result.recovery_codes || [];
        root.innerHTML = `
            <main class="login-screen"><div class="login-wrap">
                <div class="product-mark"><div class="mark-icon" aria-hidden="true">L</div><div class="mark-copy"><strong>${esc(PRODUCT)}</strong><span>Platform staff security</span></div></div>
                <section class="login-card" aria-labelledby="recovery-title">
                    <div class="login-eyebrow">Save these now</div><h1 id="recovery-title">Recovery codes</h1>
                    <p>Each code works once. Store them in your approved password manager. They will not be shown again.</p>
                    <div>${state.recoveryCodes.map((code) => `<span class="recovery-code">${esc(code)}</span>`).join('')}</div>
                    <button class="btn btn-primary login-submit" type="button" data-action="finish-enrollment">Continue to console</button>
                </section>
            </div></main><div id="toast-region" class="toast-region" aria-live="polite"></div>`;
    }

    async function runTenantAction(slug, action) {
        const encoded = encodeURIComponent(slug);
        let path = `/tenants/${encoded}/${action}`;
        let method = 'POST';
        let body;
        if (action === 'suspend' || action === 'resume') {
            const suspend = action === 'suspend';
            const a = await ask({
                title: suspend ? `Suspend ${slug}?` : `Resume ${slug}?`,
                message: suspend ? 'Everyone at this firm is signed out and locked out until you resume it. Their data is untouched.' : 'The firm can sign in and work again straight away.',
                confirmText: suspend ? 'Suspend firm' : 'Resume firm', danger: suspend, reason: true,
            });
            if (!a) return;
            body = { reason: a.reason };
        } else if (action === 'migrate' || action === 'backup') {
            const a = await ask({
                title: action === 'migrate' ? `Run system updates for ${slug}?` : `Back up ${slug} now?`,
                message: action === 'migrate' ? 'Brings this firm up to the current release. It is audited and safe to repeat; the firm keeps working while it runs.' : 'Queues a fresh backup of this firm\'s data and files. It runs in the background.',
                confirmText: action === 'migrate' ? 'Run updates' : 'Start backup',
            });
            if (!a) return;
        } else if (action === 'legal-hold') {
            const nextHold = !Boolean(state.currentTenant?.legal_hold);
            const a = await ask({
                title: nextHold ? `Place a legal hold on ${slug}?` : `Lift the legal hold on ${slug}?`,
                message: nextHold ? 'While the hold is on, nothing for this firm can be deleted, purged or offboarded.' : 'The firm\'s data can be purged or offboarded again under the normal rules.',
                confirmText: nextHold ? 'Place hold' : 'Lift hold', danger: !nextHold, reason: true,
            });
            if (!a) return;
            body = { hold: nextHold, reason: a.reason };
        } else if (action === 'offboard' || action === 'purge') {
            const a = await ask({
                title: action === 'purge' ? `Permanently erase ${slug}?` : `Offboard ${slug}?`,
                message: action === 'purge' ? 'The firm\'s data and files are destroyed for good.' : 'The firm is closed and its access ends. Export its data first if it still needs a copy.',
                confirmText: action === 'purge' ? 'Erase permanently' : 'Offboard firm', danger: true, irreversible: true, typeToConfirm: slug, reason: true,
            });
            if (!a) return;
            body = { confirm: a.typed, reason: a.reason };
        } else if (action === 'eject') {
            const a = await ask({ title: `Export all of ${slug}'s data?`, message: 'Prepares a complete copy of the firm\'s data for download. Handle it as confidential.', confirmText: 'Prepare export', reason: true });
            if (!a) return;
            body = { reason: a.reason };
        }
        try {
            state.currentTenant = { ...(state.currentTenant || {}), slug };
            const result = await request(path, { method, body });
            toast(result.message || `${action} request accepted.`, 'success');
            await loadTenant(document.getElementById('page-content'));
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    /** Bring every firm that is behind (or whose last update failed) up to the current release, one at a time, and say how it went. */
    async function updateAllFirms() {
        if (!can('operate')) return;
        let list = [];
        try { list = ((await request('/tenants')).data || []).filter((f) => f.update_needed); } catch (error) { toast(error.message, 'error'); return; }
        if (!list.length) { toast('Every firm is already up to date.', 'success'); return; }
        const a = await ask({
            title: `Update ${list.length} firm${list.length === 1 ? '' : 's'}?`,
            message: `Brings ${list.length === 1 ? 'this firm' : 'these firms'} up to the current release, one after another: ${list.slice(0, 6).map((f) => f.name).join(', ')}${list.length > 6 ? ' and others' : ''}. Each is audited. Firms keep working while it runs.`,
            confirmText: 'Run updates',
        });
        if (!a) return;
        let ok = 0; const failed = [];
        for (const f of list) {
            toast(`Updating ${f.name} (${ok + failed.length + 1} of ${list.length})…`, 'success');
            try { await request(`/tenants/${encodeURIComponent(f.slug)}/migrate`, { method: 'POST' }); ok += 1; } catch (error) { failed.push(`${f.name}: ${error.message}`); }
        }
        toast(failed.length ? `${ok} updated, ${failed.length} need attention: ${failed.join('; ')}` : `All ${ok} firm${ok === 1 ? '' : 's'} updated.`, failed.length ? 'error' : 'success');
        navigate('dashboard');
    }

    async function downloadExport(slug) {
        const response = await fetch(`${API}/tenants/${encodeURIComponent(slug)}/final-export`, {
            headers: { Accept: 'application/octet-stream', Authorization: `Bearer ${state.token}` },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!response.ok) {
            let payload = {};
            try { payload = await response.json(); } catch (_) {}
            throw new Error(payload.message || `Download failed (${response.status}).`);
        }
        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = `firm-${slug}-final-export.zip`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(objectUrl);
        toast('Final export downloaded. Handle the file according to your data-transfer policy.', 'success');
    }

    async function openIncidentModal() {
        if (!can('operate')) return;
        document.getElementById('modal-root').innerHTML = `
            <div class="modal-backdrop" data-action="close-modal"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="incident-title">
                <div class="modal-head"><h2 id="incident-title">Open status incident</h2><button type="button" class="modal-close" data-action="close-modal" aria-label="Close">×</button></div>
                <div class="notice notice-warn">Incident titles and updates are public. Do not mention firms, clients, or privileged content.</div>
                <form id="incident-open">
                    <div class="field"><label for="incident-name">Title</label><input id="incident-name" name="title" maxlength="160" required></div>
                    <div class="field"><label for="incident-impact">Impact</label><select id="incident-impact" name="impact"><option value="minor">Minor</option><option value="major">Major</option><option value="none">No impact</option><option value="maintenance">Maintenance</option></select></div>
                    <div class="field"><label for="incident-message">Public update</label><textarea id="incident-message" name="message" maxlength="2000" required></textarea></div>
                    <div class="field"><label for="incident-components">Components (comma-separated, optional)</label><input id="incident-components" name="components" placeholder="e.g. sign-in, payments"></div>
                    <div class="modal-actions"><button class="btn btn-secondary" type="button" data-action="close-modal">Cancel</button><button class="btn btn-primary" type="submit">Publish incident</button></div>
                </form>
            </section></div>`;
    }

    async function handleSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        event.preventDefault();
        if (state.busy) return;
        const values = Object.fromEntries(new FormData(form));
        try {
            if (form.id === 'platform-login') {
                state.busy = true; await submitLogin(form); return;
            }
            if (form.id === 'platform-mfa-login') {
                state.busy = true; await submitMfaLogin(form); return;
            }
            if (form.id === 'platform-enroll') {
                state.busy = true; await submitEnrollment(form); return;
            }
            if (form.id === 'profile-form') {
                const out = await request('/me', { method: 'PATCH', body: { name: values.name } });
                state.staff = { ...state.staff, name: out.name };
                toast('Your name has been updated.', 'success'); renderShell(); await loadPage(); return;
            }
            if (form.id === 'password-form') {
                if (values.password !== values.password_confirmation) throw new Error('The two new passwords do not match.');
                const out = await request('/me/password', { method: 'POST', body: values });
                form.reset();
                toast(out.message || 'Password changed.', 'success'); return;
            }
            if (form.id === 'firm-filter') {
                state.search = String(values.q || '').trim(); state.status = String(values.status || '');
                await loadPage(); return;
            }
            if (form.id === 'provision-firm') {
                if (!can('provision')) return;
                state.busy = true;
                const result = await request('/tenants', { method: 'POST', body: values });
                showOncePassword(result);
                return;
            }
            if (form.id === 'plan-form') { state.busy = true; await submitPlan(form); return; }
            if (form.id === 'integration-form') { state.busy = true; await submitIntegration(form); return; }
            if (form.id === 'billing-form') { state.busy = true; await submitBilling(form); return; }
            if (form.id === 'invoice-filter') { state.invoiceStatus = String(values.status || ''); await loadPage(); return; }
            if (form.id === 'tenant-flags') {
                if (!can('operate')) return;
                const flags = {};
                form.querySelectorAll('select[name^="flag:"]').forEach((select) => {
                    flags[select.name.slice(5)] = select.value === 'inherit' ? null : select.value === 'on';
                });
                const a = await ask({ title: 'Save feature changes?', message: 'The firm sees these features switch on or off straight away.', confirmText: 'Save changes', reason: true });
                if (!a) return;
                const reason = a.reason;
                await request(`/tenants/${encodeURIComponent(state.slug)}/flags`, { method: 'PUT', body: { flags, reason } });
                toast('Feature flags saved.', 'success'); await loadTenant(document.getElementById('page-content')); return;
            }
            if (form.id === 'audit-filter') {
                state.auditFilters = values; await loadPage(); return;
            }
            if (form.id === 'incident-open') {
                const components = String(values.components || '').split(',').map((item) => item.trim()).filter(Boolean);
                const body = { title: values.title, impact: values.impact, message: values.message, ...(components.length ? { components } : {}) };
                if (!(await ask({ title: 'Publish this incident?', message: 'It appears on the public status page straight away.', confirmText: 'Publish incident' }))) return;
                await request('/incidents', { method: 'POST', body });
                document.getElementById('modal-root').innerHTML = '';
                toast('Incident published.', 'success'); await loadPage(); return;
            }
            if (form.dataset.incidentUpdate) {
                await request(`/incidents/${encodeURIComponent(form.dataset.incidentUpdate)}/updates`, { method: 'POST', body: { status: values.status, message: values.message } });
                toast('Incident updated.', 'success'); await loadPage(); return;
            }
            if (form.id === 'support-request') {
                const result = await request(`/tenants/${encodeURIComponent(state.slug)}/support-sessions`, { method: 'POST', body: { reason: values.reason, minutes: Number(values.minutes), mode: values.mode } });
                toast(result.next || 'Support request recorded.', 'success'); await loadTenant(document.getElementById('page-content')); return;
            }
        } catch (error) {
            const enrollError = document.getElementById('enroll-error');
            if (form.id === 'platform-enroll' && enrollError) {
                enrollError.textContent = error.message; enrollError.hidden = false;
            } else if (!document.getElementById('toast-region')) {
                renderLogin(error.message);
            } else {
                toast(error.message, 'error');
            }
        } finally {
            state.busy = false;
        }
    }

    async function handleClick(event) {
        const target = event.target.closest('button,[data-open-firm]');
        if (!target) return;
        if (target.dataset.page) { navigate(target.dataset.page); return; }
        if (target.dataset.openFirm) {
            state.tenantTab = target.dataset.openTab || 'overview';
            navigate('tenant', target.dataset.openFirm);
            return;
        }
        if (target.dataset.tenantTab) {
            state.tenantTab = target.dataset.tenantTab;
            await loadTenant(document.getElementById('page-content')).catch((error) => toast(error.message, 'error'));
            return;
        }
        if (target.dataset.fleetAction === 'update-all') {
            await updateAllFirms();
            return;
        }
        if (target.dataset.tenantAction) {
            if (target.dataset.tenantAction === 'download-export') {
                try { await downloadExport(target.dataset.slug); } catch (error) { toast(error.message, 'error'); }
            } else {
                await runTenantAction(target.dataset.slug, target.dataset.tenantAction);
            }
            return;
        }
        if (target.dataset.supportAction) {
            const slug = encodeURIComponent(target.dataset.slug);
            const id = encodeURIComponent(target.dataset.id);
            try {
                if (target.dataset.supportAction === 'start') {
                    if (!(await ask({ title: 'Start this support session?', message: 'It is approved and time-limited. You get a one-time code to use on the firm\'s support exchange.', confirmText: 'Start session' }))) return;
                    const result = await request(`/tenants/${slug}/support-sessions/${id}/start`, { method: 'POST', body: {} });
                    showSecretDialog('One-time support exchange code', result.code, result.note || 'This code is shown once.');
                } else {
                    if (!(await ask({ title: 'End this support session?', message: 'Your access to the firm stops immediately.', confirmText: 'End session', danger: true }))) return;
                    await request(`/tenants/${slug}/support-sessions/${id}/end`, { method: 'POST', body: {} });
                    toast('Support session ended.', 'success');
                    await loadTenant(document.getElementById('page-content'));
                }
            } catch (error) { toast(error.message, 'error'); }
            return;
        }
        const action = target.dataset.action;
        if (!action) return;
        if (action === 'back-to-login') { state.mfaChallenge = ''; renderLogin(); return; }
        if (action === 'logout') {
            if (!(await ask({ title: 'Sign out of the console?', message: 'You will need to sign in again to continue.', confirmText: 'Sign out' }))) return;
            try { await request('/logout', { method: 'POST', body: {} }); } catch (_) {}
            clearSession();
            // Where the console shares the app's address, signing out returns to the one sign-in page (Firm / Operator).
            if (root.dataset.appLogin) { window.location.assign(`${root.dataset.appLogin}?as=operator`); return; }
            window.history.replaceState(null, '', window.location.pathname); renderLogin(); return;
        }
        if (action === 'provision') { openProvisionModal(); return; }
        if (action === 'new-plan') { openPlanModal(); return; }
        if (action === 'edit-plan') { openPlanModal(target.dataset.code); return; }
        if (['archive-plan', 'restore-plan', 'delete-plan'].includes(action)) { try { await planAction(action, target.dataset.code); } catch (error) { toast(error.message, 'error'); } return; }
        if (action === 'bill-subscribe') { await openBillingModal('subscribe', { slug: target.dataset.slug, email: target.dataset.email }); return; }
        if (action === 'bill-change') { await openBillingModal('change-plan', { slug: target.dataset.slug, current: target.dataset.current }); return; }
        if (action === 'config-integration') { openIntegrationModal(target.dataset.slug, target.dataset.provider); return; }
        if (action === 'bill-cancel') { await openBillingModal('cancel', { slug: target.dataset.slug }); return; }
        if (action === 'bill-pay') { await openBillingModal('pay', { slug: target.dataset.slug, number: target.dataset.number, currency: target.dataset.currency, due: target.dataset.due }); return; }
        if (action === 'bill-charge') { await openBillingModal('charge', { slug: target.dataset.slug, currency: target.dataset.currency }); return; }
        if (action === 'bill-run') { await openBillingModal('run', {}); return; }
        if (action === 'bill-mpesa') { await openBillingModal('mpesa', { slug: target.dataset.slug, number: target.dataset.number }); return; }
        if (action === 'refresh') { await loadPage().catch((error) => toast(error.message, 'error')); return; }
        if (action === 'open-incident') { openIncidentModal(); return; }
        if (action === 'close-modal') {
            if (target.classList.contains('modal-backdrop') || target.dataset.action === 'close-modal') document.getElementById('modal-root').innerHTML = '';
            return;
        }
        if (action === 'copy-password') {
            const value = document.getElementById('generated-password')?.textContent || '';
            try { await navigator.clipboard.writeText(value); toast('Password copied. Store it securely and close this dialog.', 'success'); } catch (_) { toast('Clipboard access was denied; select and copy the password manually.', 'error'); }
            return;
        }
        if (action === 'close-password') {
            const slug = target.dataset.slug;
            document.getElementById('modal-root').innerHTML = '';
            navigate('tenant', slug, 'channels');   // straight on to this firm's M-Pesa, card, SMS and email setup
            return;
        }
        if (action === 'finish-enrollment') {
            state.recoveryCodes = [];
            await openConsole().catch((error) => toast(error.message, 'error'));
            return;
        }
        if (action === 'setup-mfa') { state.mfaVoluntary = true; await beginEnrollment(); return; }
        if (action === 'cancel-enrollment') {
            state.mfaSetup = null; state.mfaVoluntary = false;
            await openConsole().catch((error) => toast(error.message, 'error'));
            return;
        }
        if (action === 'disable-mfa') {
            const a = await ask({ title: 'Turn off two-factor?', message: 'Your account will be protected by your password only.', confirmText: 'Turn off two-factor', danger: true,
                fields: [{ name: 'password', label: 'Your platform password', type: 'password' }, { name: 'code', label: 'Current authenticator code', type: 'text', placeholder: '123456' }] });
            if (!a) return;
            const { password, code } = a;
            try {
                await request('/mfa/disable', { method: 'POST', body: { password, code } });
                toast('Two-factor disabled.', 'success'); await loadPage();
            } catch (error) { toast(error.message, 'error'); }
        }
    }

    function showSecretDialog(title, secret, note) {
        document.getElementById('modal-root').innerHTML = `
            <div class="modal-backdrop"><section class="modal" role="dialog" aria-modal="true" aria-labelledby="secret-title">
                <div class="modal-head"><h2 id="secret-title">${esc(title)}</h2></div>
                <div class="notice notice-warn">${esc(note)}</div><p class="mfa-secret">${esc(secret || '')}</p>
                <div class="modal-actions"><button class="btn btn-primary" type="button" data-action="close-support-code">I’ve recorded it</button></div>
            </section></div>`;
    }

    document.addEventListener('submit', (event) => { handleSubmit(event); });
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-action="close-support-code"]')) {
            document.getElementById('modal-root').innerHTML = '';
            loadTenant(document.getElementById('page-content')).catch((error) => toast(error.message, 'error'));
            return;
        }
        handleClick(event);
    });
    window.addEventListener('hashchange', () => {
        if (!state.staff) return;
        pageFromHash(); renderShell(); loadPage().catch((error) => toast(error.message, 'error'));
    });

    if (!state.token) {
        renderLogin();
    } else {
        openConsole().catch((error) => {
            clearSession();
            renderLogin(error.message);
        });
    }
})();
