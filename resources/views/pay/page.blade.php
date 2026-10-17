<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Pay invoice</title>
<style>
  :root { --bg: #f4f6f9; --card: #fff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0; --accent: #0369a1; --bad: #b42318; --ok: #15803d; --mpesa: #16a34a; }
  * { box-sizing: border-box; }
  body { font: 15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; margin: 0; padding: 24px 16px; background: var(--bg); color: var(--text); }
  main { max-width: 440px; margin: 0 auto; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
  h1 { font-size: 20px; margin: 0 0 4px; } .muted { color: var(--muted); font-size: 13px; }
  .amount { font-size: 30px; font-weight: 700; margin: 12px 0 4px; }
  .rows div { display: flex; justify-content: space-between; font-size: 14px; padding: 4px 0; border-bottom: 1px solid var(--line); }
  label { display: block; font-size: 13px; font-weight: 600; margin: 16px 0 4px; }
  input { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; }
  button { width: 100%; margin-top: 12px; padding: 12px; border: 0; border-radius: 8px; color: #fff; font: 600 15px system-ui, sans-serif; cursor: pointer; }
  button:disabled { opacity: .6; cursor: default; }
  .mpesa { background: var(--mpesa); } .card { background: var(--accent); }
  .or { text-align: center; color: var(--muted); font-size: 12px; margin: 16px 0 0; }
  .msg { margin-top: 14px; font-size: 14px; } .bad { color: var(--bad); } .ok { color: var(--ok); }
  [hidden] { display: none !important; }
</style>
</head>
<body>
<main>
  <div id="loading" class="muted">Loading…</div>
  <section id="view" hidden>
    <h1 id="firm"></h1>
    <p class="muted">Invoice <span id="number"></span> for <span id="client"></span></p>
    <div class="amount" id="due"></div>
    <div class="rows">
      <div><span>Invoice total</span><span id="total"></span></div>
      <div><span>Already paid</span><span id="paid"></span></div>
      <div><span>Due date</span><span id="duedate"></span></div>
    </div>

    <form id="mpesa-form" hidden>
      <label for="phone">M-Pesa phone number</label>
      <input id="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="0712 345 678" required>
      <button type="submit" class="mpesa" id="mpesa-btn">Pay with M-Pesa</button>
    </form>
    <p class="or" id="or" hidden>or</p>
    <button type="button" class="card" id="card-btn" hidden>Pay by card</button>
    <p class="msg" id="msg" role="status"></p>
  </section>
  <section id="done" hidden>
    <h1>Thank you</h1>
    <p id="done-text"></p>
  </section>
</main>
<script nonce="{{ $nonce }}">
(function () {
  const token = @json($token);
  const $ = (id) => document.getElementById(id);
  const cookie = (n) => decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith(n + '=')) || '').split('=').slice(1).join('='));
  let csrfReady = null;
  const api = async (method, url, body) => {
    if (method !== 'GET') { csrfReady = csrfReady || fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }); await csrfReady; }
    const res = await fetch(url, { method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') }, body: body ? JSON.stringify(body) : undefined });
    let data = {}; try { data = await res.json(); } catch (e) { /* not JSON */ }
    return { ok: res.ok, data };
  };
  let info = null;
  const fmt = (v) => (info.currency || 'KES') + ' ' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const msg = (t, cls) => { $('msg').textContent = t; $('msg').className = 'msg ' + (cls || ''); };
  const thanks = (text) => { $('view').hidden = true; $('done').hidden = false; $('done-text').textContent = text; };

  const load = async () => {
    const r = await api('GET', '/api/v1/pay/' + token);
    $('loading').hidden = true;
    if (!r.ok) { $('view').hidden = false; $('firm').textContent = 'Payment link'; msg(r.data.message || 'This link is not valid.', 'bad'); return; }
    info = r.data.data;
    $('view').hidden = false;
    $('firm').textContent = info.firm;
    $('number').textContent = info.invoice_number || '';
    $('client').textContent = info.client || '';
    $('due').textContent = fmt(info.outstanding);
    $('total').textContent = fmt(info.total);
    $('paid').textContent = fmt(info.paid);
    $('duedate').textContent = info.due_date || '';
    if (info.outstanding <= 0) { thanks('This invoice is paid in full.'); return; }
    $('mpesa-form').hidden = !info.methods.mpesa;
    $('card-btn').hidden = !info.methods.card;
    $('or').hidden = !(info.methods.mpesa && info.methods.card);
    if (!info.methods.mpesa && !info.methods.card) msg('Online payment is not set up yet. Please contact the firm.', 'bad');
    const q = new URLSearchParams(location.search);
    if (q.get('paid')) msg('Thank you. Your card payment is being confirmed; this page will show it shortly.', 'ok');
    if (q.get('cancelled')) msg('The card payment was cancelled. You can try again.', 'bad');
  };

  const poll = async (attempt, tries) => {
    const r = await api('GET', '/api/v1/pay/' + token + '/attempts/' + attempt);
    if (r.ok && r.data.data.state === 'succeeded') return thanks('Payment received' + (r.data.data.receipt ? ' (M-Pesa receipt ' + r.data.data.receipt + ')' : '') + '. A receipt will follow from the firm.');
    if (r.ok && r.data.data.state === 'failed') { $('mpesa-btn').disabled = false; return msg(r.data.data.error || 'The payment was not completed.', 'bad'); }
    if (tries > 40) { $('mpesa-btn').disabled = false; return msg('We have not heard back from M-Pesa yet. If you paid, it will show here shortly.', ''); }
    setTimeout(() => poll(attempt, tries + 1), 3000);
  };

  $('mpesa-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    $('mpesa-btn').disabled = true;
    msg('Sending the payment request to your phone…');
    const r = await api('POST', '/api/v1/pay/' + token + '/mpesa', { phone: $('phone').value });
    if (!r.ok) { $('mpesa-btn').disabled = false; return msg(r.data.message || 'That did not work.', 'bad'); }
    msg(r.data.data.message, 'ok');
    poll(r.data.data.attempt, 0);
  });
  $('card-btn').addEventListener('click', async () => {
    $('card-btn').disabled = true;
    const r = await api('POST', '/api/v1/pay/' + token + '/card');
    if (!r.ok) { $('card-btn').disabled = false; return msg(r.data.message || 'That did not work.', 'bad'); }
    location.href = r.data.data.redirect;
  });
  load();
})();
</script>
</body>
</html>
