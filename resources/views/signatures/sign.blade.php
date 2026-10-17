<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Sign document</title>
<style>
  :root { --bg: #f4f6f9; --card: #fff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0; --accent: #0369a1; --bad: #b42318; --ok: #15803d; }
  * { box-sizing: border-box; }
  body { font: 15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; margin: 0; padding: 24px 16px; background: var(--bg); color: var(--text); }
  main { max-width: 560px; margin: 0 auto; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
  h1 { font-size: 20px; margin: 0 0 4px; } .muted { color: var(--muted); font-size: 13px; }
  .doc { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px; border: 1px solid var(--line); border-radius: 8px; margin: 16px 0; }
  .doc a { color: var(--accent); font-weight: 600; text-decoration: none; }
  label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 4px; }
  input[type=text], textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; }
  canvas { width: 100%; height: 160px; border: 1px dashed var(--muted); border-radius: 8px; touch-action: none; background: #fff; }
  .row { display: flex; justify-content: space-between; align-items: center; }
  .check { display: flex; gap: 8px; align-items: flex-start; font-weight: normal; font-size: 14px; }
  button { padding: 12px 16px; border: 0; border-radius: 8px; color: #fff; background: var(--accent); font: 600 15px system-ui, sans-serif; cursor: pointer; }
  button.link { background: none; color: var(--muted); padding: 0; font-weight: 500; font-size: 13px; text-decoration: underline; }
  button.full { width: 100%; margin-top: 16px; } button:disabled { opacity: .6; cursor: default; }
  .msg { margin-top: 14px; font-size: 14px; } .bad { color: var(--bad); } .ok { color: var(--ok); }
  details { margin-top: 18px; } summary { cursor: pointer; color: var(--muted); font-size: 13px; }
  .hash { font-family: ui-monospace, monospace; font-size: 11px; color: var(--muted); overflow-wrap: anywhere; }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<main>
  <div id="loading" class="muted">Loading…</div>
  <section id="view" hidden>
    <p class="muted" id="firm"></p>
    <h1 id="title"></h1>
    <p id="note" class="muted"></p>
    <div class="doc">
      <div><div id="filename"></div><div class="muted">Read the document before you sign.</div></div>
      <a id="open" target="_blank" rel="noopener">Open</a>
    </div>
    <form id="sign-form">
      <label for="name">Your full name</label>
      <input id="name" type="text" autocomplete="name" required maxlength="191">
      <div class="row"><label for="pad">Draw your signature</label><button type="button" class="link" id="clear">Clear</button></div>
      <canvas id="pad" aria-label="Signature pad"></canvas>
      <label class="check"><input id="agree" type="checkbox" required> <span>I have read this document and I sign it electronically. I agree that this electronic signature has the same effect as my handwritten signature.</span></label>
      <button type="submit" class="full" id="sign-btn">Sign</button>
    </form>
    <details>
      <summary>I do not want to sign</summary>
      <form id="decline-form">
        <label for="reason">Tell the firm why</label>
        <textarea id="reason" rows="3" required minlength="3" maxlength="500"></textarea>
        <button type="submit" class="full" id="decline-btn" style="background:#b42318">Decline to sign</button>
      </form>
    </details>
    <p class="msg" id="msg" role="status"></p>
    <p class="hash">Document fingerprint (SHA-256): <span id="sha"></span></p>
  </section>
  <section id="done" hidden><h1 id="done-title"></h1><p id="done-text"></p></section>
</main>
<script nonce="{{ $nonce }}">
(function () {
  const token = @json($token);
  const $ = (id) => document.getElementById(id);
  const cookie = (n) => decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith(n + '=')) || '').split('=').slice(1).join('='));
  let csrf = null;
  const api = async (method, url, body) => {
    if (method !== 'GET') { csrf = csrf || fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }); await csrf; }
    const res = await fetch('/api/v1/sign/' + token + url, { method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, body: body ? JSON.stringify(body) : undefined });
    let data = {}; try { data = await res.json(); } catch (e) { /* not JSON */ }
    return { ok: res.ok, data };
  };
  const msg = (t, cls) => { $('msg').textContent = t || ''; $('msg').className = 'msg ' + (cls || ''); };
  const finish = (title, text) => { $('view').hidden = true; $('loading').hidden = true; $('done').hidden = false; $('done-title').textContent = title; $('done-text').textContent = text; };
  const firstError = (r, fallback) => (r.data && r.data.errors ? Object.values(r.data.errors).flat()[0] : null) || (r.data && r.data.message) || fallback;

  // signature pad
  const pad = $('pad');
  const ctx = pad.getContext('2d');
  let drawing = false; let drawn = false;
  const fit = () => { const r = pad.getBoundingClientRect(); const d = window.devicePixelRatio || 1; pad.width = r.width * d; pad.height = r.height * d; ctx.scale(d, d); ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0f172a'; drawn = false; };
  const at = (e) => { const r = pad.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
  pad.addEventListener('pointerdown', (e) => { drawing = true; pad.setPointerCapture(e.pointerId); const [x, y] = at(e); ctx.beginPath(); ctx.moveTo(x, y); });
  pad.addEventListener('pointermove', (e) => { if (!drawing) return; const [x, y] = at(e); ctx.lineTo(x, y); ctx.stroke(); drawn = true; });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach((ev) => pad.addEventListener(ev, () => { drawing = false; }));
  $('clear').addEventListener('click', () => { ctx.clearRect(0, 0, pad.width, pad.height); drawn = false; });

  const load = async () => {
    const r = await api('GET', '');
    $('loading').hidden = true;
    if (!r.ok) { finish('Link not valid', 'This signing link is not valid. Please contact the firm that sent it.'); return; }
    const d = r.data.data;
    if (d.state === 'signed') { finish('Already signed', 'You have signed this document. Thank you.'); return; }
    if (d.request_state !== 'pending' || d.state === 'declined') { finish('No longer open', 'This document is no longer waiting for your signature (' + d.request_state + ').'); return; }
    $('view').hidden = false;
    $('firm').textContent = d.firm; $('title').textContent = d.title; $('note').textContent = d.message || '';
    $('filename').textContent = d.filename || 'Document'; $('sha').textContent = d.sha256;
    $('open').href = '/api/v1/sign/' + token + '/document';
    $('open').textContent = d.is_pdf ? 'Open' : 'Download';
    fit();
    if (!d.your_turn) { $('sign-form').hidden = true; msg('Someone else signs before you. You will get a new link when it is your turn.'); }
  };

  $('sign-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!drawn) { msg('Please draw your signature.', 'bad'); return; }
    $('sign-btn').disabled = true;
    const r = await api('POST', '', { name: $('name').value.trim(), signature: pad.toDataURL('image/png'), agree: $('agree').checked });
    $('sign-btn').disabled = false;
    if (!r.ok) { msg(firstError(r, 'Your signature could not be recorded.'), 'bad'); return; }
    finish('Signed', r.data.message);
  });
  $('decline-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('POST', '/decline', { reason: $('reason').value.trim() });
    if (!r.ok) { msg(firstError(r, 'Could not decline.'), 'bad'); return; }
    finish('Declined', r.data.message);
  });
  load();
})();
</script>
</body>
</html>
