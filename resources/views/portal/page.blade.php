<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ config('app.name') }} · Client portal</title>
<style>
  :root { --bg: #f4f6f9; --card: #fff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0; --accent: #0369a1; --bad: #b42318; --ok: #15803d; --chip: #eef2f7; }
  * { box-sizing: border-box; }
  body { font: 15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; margin: 0; background: var(--bg); color: var(--text); }
  header { background: var(--card); border-bottom: 1px solid var(--line); padding: 12px 16px; display: flex; align-items: center; gap: 12px; }
  header h1 { font-size: 17px; margin: 0; flex: 1; } header .who { color: var(--muted); font-size: 13px; }
  main { max-width: 760px; margin: 0 auto; padding: 20px 16px 48px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 18px; margin-bottom: 14px; }
  .narrow { max-width: 400px; margin: 40px auto; }
  h2 { font-size: 18px; margin: 0 0 8px; } h3 { font-size: 15px; margin: 18px 0 8px; }
  .muted { color: var(--muted); font-size: 13px; }
  label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 4px; }
  input, textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; background: #fff; }
  textarea { min-height: 80px; resize: vertical; }
  button { padding: 10px 14px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: 600 14px system-ui, sans-serif; cursor: pointer; }
  button.link { background: none; color: var(--accent); padding: 0; font-weight: 600; }
  button.ghost { background: var(--chip); color: var(--text); }
  button:disabled { opacity: .6; cursor: default; }
  .full { width: 100%; margin-top: 14px; }
  .tabs { display: flex; gap: 6px; margin-bottom: 14px; }
  .tabs button { background: var(--chip); color: var(--text); } .tabs button.on { background: var(--accent); color: #fff; }
  .row { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); }
  .row:last-child { border-bottom: 0; } .row .main { flex: 1; min-width: 0; } .row .title { font-weight: 600; overflow-wrap: anywhere; }
  .chip { display: inline-block; background: var(--chip); border-radius: 999px; padding: 1px 8px; font-size: 12px; margin-left: 6px; }
  .chip.bad { background: #fde8e7; color: var(--bad); } .chip.ok { background: #e7f6ec; color: var(--ok); }
  .thread { max-height: 360px; overflow-y: auto; border: 1px solid var(--line); border-radius: 8px; padding: 10px; background: #fafbfc; }
  .msg { max-width: 85%; margin: 6px 0; padding: 8px 10px; border-radius: 10px; white-space: pre-wrap; overflow-wrap: anywhere; }
  .msg.in { background: #fff; border: 1px solid var(--line); } .msg.out { background: #e0f2fe; margin-left: auto; }
  .msg .meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
  .note { margin-top: 10px; font-size: 14px; } .note.bad { color: var(--bad); } .note.ok { color: var(--ok); }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<header>
  <h1>{{ config('app.name') }} <span class="muted">Client portal</span></h1>
  <span class="who" id="who"></span>
  <button class="ghost" id="signout" hidden>Sign out</button>
</header>
<main>
  <section id="signin" class="card narrow" hidden>
    <h2>Sign in</h2>
    <p class="muted">Enter the phone number the firm has for you. We will text you a six-digit code.</p>
    <form id="phone-form">
      <label for="phone">Phone number</label>
      <input id="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0712 345 678" required>
      <button class="full" type="submit">Text me a code</button>
    </form>
    <form id="code-form" hidden>
      <label for="code">Code</label>
      <input id="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
      <button class="full" type="submit">Sign in</button>
      <p class="muted"><button type="button" class="link" id="again">Use a different number or send a new code</button></p>
    </form>
    <p class="note" id="signin-note" role="status"></p>
  </section>

  <section id="app" hidden>
    <div class="tabs" role="tablist">
      <button type="button" id="tab-matters" class="on">Matters</button>
      <button type="button" id="tab-invoices">Invoices</button>
    </div>
    <div id="view"></div>
  </section>
</main>
<script nonce="{{ $nonce }}">
(function () {
  const $ = (id) => document.getElementById(id);
  const KEY = 'portal-session';
  const store = { get: () => { try { return sessionStorage.getItem(KEY); } catch (e) { return null; } },
    set: (v) => { try { v ? sessionStorage.setItem(KEY, v) : sessionStorage.removeItem(KEY); } catch (e) { /* private mode */ } } };
  let token = store.get();
  let phone = '';

  // Builds elements without ever parsing data as HTML.
  const h = (tag, attrs, ...kids) => {
    const el = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (v === undefined || v === null || v === false) return;
      if (k.startsWith('on')) el.addEventListener(k.slice(2), v); else if (k === 'class') el.className = v; else el.setAttribute(k, v === true ? '' : v);
    });
    kids.flat().forEach((c) => { if (c !== null && c !== undefined && c !== false) el.append(c instanceof Node ? c : String(c)); });
    return el;
  };
  const cookie = (n) => decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith(n + '=')) || '').split('=').slice(1).join('='));
  let csrf = null;
  const api = async (method, url, body) => {
    if (method !== 'GET') { csrf = csrf || fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }); await csrf; }
    const headers = { 'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') };
    if (token) headers.Authorization = 'Bearer ' + token;
    const isForm = body instanceof FormData;
    if (body && !isForm) headers['Content-Type'] = 'application/json';
    const res = await fetch('/api/v1/portal' + url, { method, credentials: 'same-origin', headers, body: body ? (isForm ? body : JSON.stringify(body)) : undefined });
    if (res.status === 401 && token) { signedOut('Your session ended. Please sign in again.'); throw new Error('signed out'); }
    let data = {}; try { data = await res.json(); } catch (e) { /* not JSON */ }
    return { ok: res.ok, status: res.status, data };
  };
  const errorText = (r, fallback) => (r.data && r.data.errors ? Object.values(r.data.errors).flat()[0] : null) || (r.data && r.data.message) || fallback;
  const date = (d) => d ? new Date(String(d).replace(' ', 'T')).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '';
  const when = (d) => d ? new Date(String(d).replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
  const money = (c, v) => c + ' ' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const size = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
  const view = (...nodes) => { $('view').replaceChildren(...nodes); };

  // ---- sign in ----
  const note = (t, cls) => { $('signin-note').textContent = t || ''; $('signin-note').className = 'note ' + (cls || ''); };
  function signedOut(message) {
    token = null; store.set(null);
    $('app').hidden = true; $('signout').hidden = true; $('who').textContent = '';
    $('signin').hidden = false; $('phone-form').hidden = false; $('code-form').hidden = true;
    note(message || '', message ? 'bad' : '');
  }
  $('phone-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    phone = $('phone').value.trim();
    const r = await api('POST', '/login', { phone });
    if (!r.ok) { note(errorText(r, 'Please try again in a few minutes.'), 'bad'); return; }
    $('phone-form').hidden = true; $('code-form').hidden = false; $('code').value = ''; $('code').focus();
    note(r.data.message, 'ok');
  });
  $('code-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('POST', '/verify', { phone, code: $('code').value.trim() });
    if (!r.ok) { note(errorText(r, 'That code did not work.'), 'bad'); return; }
    token = r.data.data.token; store.set(token); note('');
    start();
  });
  $('again').addEventListener('click', () => { $('phone-form').hidden = false; $('code-form').hidden = true; note(''); });
  $('signout').addEventListener('click', async () => { try { await api('POST', '/logout'); } catch (e) { /* already out */ } signedOut('You have signed out.'); });

  // ---- matters ----
  async function matters() {
    tab('matters');
    const r = await api('GET', '/matters');
    if (!r.data.data.length) { view(h('div', { class: 'card' }, h('p', { class: 'muted' }, 'You have no matters with the firm yet.'))); return; }
    view(h('div', { class: 'card' }, h('h2', {}, 'Your matters'), r.data.data.map((m) => h('div', { class: 'row' },
      h('div', { class: 'main' },
        h('div', { class: 'title' }, m.case_number, h('span', { class: 'chip' + (m.status === 'closed' ? '' : ' ok') }, m.status),
          m.unread_messages ? h('span', { class: 'chip bad' }, m.unread_messages + ' new') : null),
        h('div', { class: 'muted' }, m.description || ''),
        h('div', { class: 'muted' }, (m.lawyer ? 'Your advocate: ' + m.lawyer : '') + (m.next_hearing ? ' · Next hearing ' + date(m.next_hearing) : ''))),
      h('button', { type: 'button', class: 'ghost', onclick: () => matter(m.id) }, 'Open'))))));
  }

  async function matter(id) {
    const r = await api('GET', '/matters/' + id);
    if (!r.ok) { view(h('div', { class: 'card' }, h('p', { class: 'note bad' }, errorText(r, 'This matter could not be opened.')))); return; }
    const m = r.data.data;
    const upNote = h('p', { class: 'note', role: 'status' });
    const msgNote = h('p', { class: 'note', role: 'status' });
    const thread = h('div', { class: 'thread', 'aria-live': 'polite' }, m.messages.length ? m.messages.map((x) => h('div', { class: 'msg ' + (x.direction === 'from_client' ? 'out' : 'in') },
      x.body, h('div', { class: 'meta' }, (x.direction === 'from_client' ? 'You' : (x.sender || 'The firm')) + ' · ' + when(x.sent_at)))) : h('p', { class: 'muted' }, 'No messages yet.'));
    const fileInput = h('input', { type: 'file', id: 'up-file', required: true });
    const titleInput = h('input', { id: 'up-title', maxlength: 255, required: true, placeholder: 'What is this document?' });
    const body = h('textarea', { id: 'msg-body', maxlength: 5000, required: true, placeholder: 'Write to your advocate…' });

    view(
      h('p', {}, h('button', { type: 'button', class: 'link', onclick: matters }, '← All matters')),
      h('div', { class: 'card' },
        h('h2', {}, m.case_number, h('span', { class: 'chip' }, m.status)),
        h('p', { class: 'muted' }, m.description || ''),
        m.lawyer ? h('p', { class: 'muted' }, 'Your advocate: ' + m.lawyer) : null,
        h('h3', {}, 'Hearings'),
        m.hearings.length ? m.hearings.map((x) => h('div', { class: 'row' }, h('div', { class: 'main' }, date(x.date), x.court ? ' · ' + x.court : ''),
          x.upcoming ? h('span', { class: 'chip ok' }, 'upcoming') : h('span', { class: 'chip' }, 'past'))) : h('p', { class: 'muted' }, 'No hearings scheduled.')),
      h('div', { class: 'card' },
        h('h2', {}, 'Documents'),
        m.documents.length ? m.documents.map((d) => h('div', { class: 'row' },
          h('div', { class: 'main' }, h('div', { class: 'title' }, d.title), h('div', { class: 'muted' },
            (d.direction === 'from_client' ? 'Sent by you' : 'Shared by ' + (d.shared_by || 'the firm')) + ' · ' + date(d.shared_at) + ' · ' + size(d.size))),
          h('button', { type: 'button', class: 'ghost', onclick: () => download(d) }, 'Download'))) : h('p', { class: 'muted' }, 'Nothing shared yet.'),
        h('h3', {}, 'Send the firm a document'),
        h('form', { onsubmit: async (e) => {
          e.preventDefault();
          const fd = new FormData(); fd.append('file', fileInput.files[0]); fd.append('title', titleInput.value.trim());
          upNote.textContent = 'Uploading…'; upNote.className = 'note';
          const u = await api('POST', '/matters/' + id + '/documents', fd);
          if (!u.ok) { upNote.textContent = errorText(u, 'The upload failed.'); upNote.className = 'note bad'; return; }
          matter(id);
        } }, h('label', { for: 'up-title' }, 'Title'), titleInput, h('label', { for: 'up-file' }, 'File (PDF, Word, Excel, image or text; up to 25 MB)'), fileInput,
        h('button', { type: 'submit', class: 'full' }, 'Send document'), upNote)),
      h('div', { class: 'card' },
        h('h2', {}, 'Messages'), thread,
        h('form', { onsubmit: async (e) => {
          e.preventDefault();
          const s = await api('POST', '/matters/' + id + '/messages', { body: body.value });
          if (!s.ok) { msgNote.textContent = errorText(s, 'The message was not sent.'); msgNote.className = 'note bad'; return; }
          matter(id);
        } }, h('label', { for: 'msg-body' }, 'New message'), body, h('button', { type: 'submit', class: 'full' }, 'Send message'), msgNote)));
    thread.scrollTop = thread.scrollHeight;
  }

  async function download(d) {
    const res = await fetch('/api/v1/portal/documents/' + d.id, { headers: { Authorization: 'Bearer ' + token }, credentials: 'same-origin' });
    if (res.status === 401) { signedOut('Your session ended. Please sign in again.'); return; }
    if (!res.ok) { alert('The document could not be downloaded.'); return; }
    const url = URL.createObjectURL(await res.blob());
    const a = h('a', { href: url, download: d.filename }); document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
  }

  // ---- invoices ----
  async function invoices() {
    tab('invoices');
    const r = await api('GET', '/invoices');
    if (!r.data.data.length) { view(h('div', { class: 'card' }, h('p', { class: 'muted' }, 'You have no invoices.'))); return; }
    const payNote = h('p', { class: 'note', role: 'status' });
    view(h('div', { class: 'card' }, h('h2', {}, 'Invoices'), r.data.data.map((i) => h('div', { class: 'row' },
      h('div', { class: 'main' },
        h('div', { class: 'title' }, i.invoice_number || 'Invoice', i.outstanding > 0
          ? h('span', { class: 'chip' + (i.overdue ? ' bad' : '') }, i.overdue ? 'overdue' : 'due ' + date(i.due_date))
          : h('span', { class: 'chip ok' }, 'paid')),
        h('div', { class: 'muted' }, (i.case_number ? i.case_number + ' · ' : '') + date(i.date) + ' · total ' + money(i.currency, i.total)
          + (i.outstanding > 0 ? ' · to pay ' + money(i.currency, i.outstanding) : ''))),
      i.outstanding > 0 ? h('button', { type: 'button', onclick: async (e) => {
        e.target.disabled = true;
        const p = await api('POST', '/invoices/' + i.id + '/pay');
        if (!p.ok) { e.target.disabled = false; payNote.textContent = errorText(p, 'Online payment is not available right now.'); payNote.className = 'note bad'; return; }
        window.location.assign(p.data.data.url);
      } }, 'Pay') : null)), payNote));
  }

  function tab(name) {
    $('tab-matters').className = name === 'matters' ? 'on' : '';
    $('tab-invoices').className = name === 'invoices' ? 'on' : '';
  }
  $('tab-matters').addEventListener('click', matters);
  $('tab-invoices').addEventListener('click', invoices);

  async function start() {
    try {
      const me = await api('GET', '/me');
      if (!me.ok) { signedOut(); return; }
      $('who').textContent = me.data.data.name;
      $('signin').hidden = true; $('app').hidden = false; $('signout').hidden = false;
      matters();
    } catch (e) { /* signedOut already shown */ }
  }
  token ? start() : signedOut();
})();
</script>
</body>
</html>
