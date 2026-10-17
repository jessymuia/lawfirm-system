<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Start your free trial</title>
<style>
  :root { --bg: #f4f6f9; --card: #fff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0; --accent: #0369a1; --bad: #b42318; --ok: #15803d; }
  * { box-sizing: border-box; }
  body { font: 15px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; margin: 0; padding: 24px 16px; background: var(--bg); color: var(--text); }
  main { max-width: 480px; margin: 0 auto; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  p.lead { color: var(--muted); margin: 0 0 20px; }
  label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 4px; }
  input, select { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; }
  input:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
  .addr { display: flex; align-items: center; gap: 6px; }
  .addr span { color: var(--muted); white-space: nowrap; }
  .hint { font-size: 12px; color: var(--muted); margin-top: 4px; min-height: 1em; }
  .hint.bad, .error { color: var(--bad); }
  .hint.ok { color: var(--ok); }
  .check { display: flex; gap: 8px; align-items: flex-start; font-weight: 400; }
  .check input { width: auto; margin-top: 4px; }
  button { margin-top: 20px; width: 100%; padding: 12px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: 600 15px system-ui, sans-serif; cursor: pointer; }
  button:disabled { opacity: .6; cursor: default; }
  .trap { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
  [hidden] { display: none !important; }
  a { color: var(--accent); }
</style>
</head>
<body>
<main>
  <section id="form-view">
    <h1>Start your free trial</h1>
    <p class="lead">{{ $trialDays }} days, every core feature, no card needed. We create your firm's own private workspace.</p>
    <form id="signup" novalidate>
      <label for="firm_name">Firm name</label>
      <input id="firm_name" name="firm_name" required maxlength="120" autocomplete="organization">

      <label for="slug">Your firm's address</label>
      <div class="addr"><input id="slug" name="slug" required maxlength="32" autocomplete="off" spellcheck="false" inputmode="url"><span>.{{ $baseDomain }}</span></div>
      <div class="hint" id="slug-hint"></div>

      <label for="name">Your name</label>
      <input id="name" name="name" required maxlength="120" autocomplete="name">

      <label for="email">Work email</label>
      <input id="email" name="email" type="email" required maxlength="191" autocomplete="email">

      <label for="phone">Phone (optional)</label>
      <input id="phone" name="phone" type="tel" maxlength="32" autocomplete="tel">

      <label for="password">Password</label>
      <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
      <div class="hint">At least 10 characters, with letters and numbers.</div>

      <label for="password_confirmation">Confirm password</label>
      <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">

      <div class="trap" aria-hidden="true"><label for="website">Leave this empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>

      <label class="check"><input id="accept_terms" name="accept_terms" type="checkbox" value="1"> <span>I accept the terms of service and the data processing terms.</span></label>

      <p class="error" id="form-error" role="alert"></p>
      <button type="submit" id="submit">Create my firm</button>
    </form>
  </section>

  <section id="sent-view" hidden>
    <h1>Check your email</h1>
    <p class="lead" id="sent-text"></p>
    <p>The link works for 24 hours. Nothing is created until you open it.</p>
  </section>

  <section id="verify-view" hidden>
    <h1 id="verify-title">Creating your firm…</h1>
    <p class="lead" id="verify-text">This usually takes under a minute. Keep this page open.</p>
    <p class="error" id="verify-error" role="alert"></p>
    <button type="button" id="retry" hidden>Try again</button>
  </section>
</main>
<script nonce="{{ $nonce }}">
(function () {
  const $ = (id) => document.getElementById(id);
  const api = async (method, url, body) => {
    const res = await fetch(url, { method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
    let data = {};
    try { data = await res.json(); } catch (e) { /* not JSON */ }
    return { ok: res.ok, status: res.status, data };
  };
  const show = (id) => ['form-view', 'sent-view', 'verify-view'].forEach((v) => { $(v).hidden = v !== id; });

  // ---- the verification link -------------------------------------------------------------------------------------
  const token = new URLSearchParams(location.search).get('token');
  if (location.pathname === '/signup/verify') {
    show('verify-view');
    history.replaceState(null, '', '/signup/verify');   // keep the token out of the address bar and history
    const fail = (msg) => { $('verify-title').textContent = 'That did not work'; $('verify-text').textContent = ''; $('verify-error').textContent = msg; };
    const poll = async () => {
      const r = await api('GET', '/api/signup/status?token=' + encodeURIComponent(token || ''));
      if (!r.ok) return fail(r.data.message || 'This link is not valid.');
      const s = r.data.data;
      if (s.status === 'ready') {
        $('verify-title').textContent = s.firm_name + ' is ready';
        $('verify-text').innerHTML = 'Taking you to your sign-in page… <a id="go"></a>';
        $('go').href = s.login_url; $('go').textContent = 'Continue';
        setTimeout(() => { location.href = s.login_url; }, 1200);
      } else if (s.status === 'failed') {
        fail(s.error); $('retry').hidden = false;
      } else {
        setTimeout(poll, 2000);
      }
    };
    const start = async () => {
      $('retry').hidden = true; $('verify-error').textContent = '';
      $('verify-title').textContent = 'Creating your firm…';
      const r = await api('POST', '/api/signup/verify', { token: token || '' });
      if (!r.ok) return fail(r.data.message || 'This link is not valid.');
      poll();
    };
    $('retry').addEventListener('click', start);
    start();
    return;
  }

  // ---- the form ----------------------------------------------------------------------------------------------------
  const slugify = (s) => s.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/&/g, ' and ')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32).replace(/-+$/, '');
  let slugTouched = false, timer = null;
  const checkSlug = () => {
    clearTimeout(timer);
    const slug = $('slug').value.trim();
    if (!slug) { $('slug-hint').textContent = ''; return; }
    timer = setTimeout(async () => {
      const r = await api('GET', '/api/signup/slug?slug=' + encodeURIComponent(slug) + '&email=' + encodeURIComponent($('email').value));
      if (!r.ok) return;
      const d = r.data.data;
      $('slug-hint').className = 'hint ' + (d.available ? 'ok' : 'bad');
      $('slug-hint').textContent = d.available ? 'Available' : d.problem;
    }, 300);
  };
  $('firm_name').addEventListener('input', () => { if (!slugTouched) { $('slug').value = slugify($('firm_name').value); checkSlug(); } });
  $('slug').addEventListener('input', () => { slugTouched = true; checkSlug(); });

  $('signup').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    $('form-error').textContent = '';
    if ($('password').value !== $('password_confirmation').value) { $('form-error').textContent = 'The passwords do not match.'; return; }
    $('submit').disabled = true;
    const f = new FormData($('signup'));
    const body = Object.fromEntries(f.entries());
    body.accept_terms = $('accept_terms').checked;
    const r = await api('POST', '/api/signup', body);
    $('submit').disabled = false;
    if (r.ok) {
      $('sent-text').textContent = 'We sent a link to ' + body.email + '. Open it to confirm your address and create ' + body.firm_name + '.';
      show('sent-view');
      return;
    }
    const errors = r.data.errors || {};
    const first = Object.values(errors)[0];
    $('form-error').textContent = (first && first[0]) || r.data.message || (r.status === 429 ? 'Too many attempts. Please wait a while and try again.' : 'Something went wrong. Please try again.');
  });
})();
</script>
</body>
</html>
