<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Fleet migrations</title>
<style>
  body { font: 14px/1.45 system-ui, sans-serif; margin: 0; padding: 16px; background: #f7f7f8; color: #1d1d20; }
  main { max-width: 960px; margin: 0 auto; }
  h1 { font-size: 20px; margin: 0 0 12px; }
  h2 { font-size: 15px; margin: 20px 0 8px; }
  form, section { background: #fff; border: 1px solid #e2e2e6; border-radius: 6px; padding: 12px; margin-bottom: 12px; }
  input { width: 100%; max-width: 420px; padding: 6px; box-sizing: border-box; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid #eee; word-break: break-all; }
  .tiles { display: flex; flex-wrap: wrap; gap: 8px; }
  .tile { flex: 1 1 140px; background: #fff; border: 1px solid #e2e2e6; border-radius: 6px; padding: 10px; }
  .tile b { display: block; font-size: 22px; }
  .bar { height: 8px; background: #3b6fd8; border-radius: 4px; }
  .bad { color: #b42318; }
  [hidden] { display: none; }
</style>
</head>
<body>
<main>
  <h1>Fleet migrations (TEN-7)</h1>
  <form id="auth">
    <label for="token">Platform staff token</label><br>
    <input id="token" type="password" autocomplete="off" required>
    <button type="submit">Load</button>
    <p id="error" class="bad" hidden></p>
  </form>
  <div id="out" hidden>
    <div class="tiles">
      <div class="tile">Firms<b id="t-total"></b></div>
      <div class="tile">Behind this release<b id="t-behind"></b></div>
      <div class="tile">Failed<b id="t-failed"></b></div>
      <div class="tile">Pending / running<b id="t-active"></b></div>
    </div>
    <p>Latest migration: <code id="latest"></code></p>
    <section><h2>Schema versions</h2><table id="versions"><thead><tr><th>Version</th><th>Firms</th><th style="width:40%"></th></tr></thead><tbody></tbody></table></section>
    <section><h2>Failed migrations</h2><table id="failed"><thead><tr><th>Firm</th><th>Error</th></tr></thead><tbody></tbody></table></section>
  </div>
</main>
<script nonce="{{ $nonce }}">
(function () {
  var form = document.getElementById('auth'), tokenInput = document.getElementById('token'), err = document.getElementById('error');
  try { tokenInput.value = sessionStorage.getItem('platformToken') || ''; } catch (e) {}
  function cell(row, text) { var td = document.createElement('td'); td.textContent = text; row.appendChild(td); return td; }
  function render(d) {
    var counts = d.by_migration_status || {};
    document.getElementById('t-total').textContent = d.tenants;
    document.getElementById('t-behind').textContent = d.behind;
    document.getElementById('t-failed').textContent = counts.failed || 0;
    document.getElementById('t-active').textContent = (counts.pending || 0) + ' / ' + (counts.running || 0);
    document.getElementById('latest').textContent = d.latest_version || '(none)';
    var versions = d.schema_versions || {}, max = Math.max.apply(null, [1].concat(Object.values(versions)));
    var body = document.querySelector('#versions tbody'); body.textContent = '';
    Object.keys(versions).sort().reverse().forEach(function (v) {
      var tr = document.createElement('tr');
      var name = cell(tr, v); if (v !== d.latest_version) name.className = 'bad';
      cell(tr, versions[v]);
      var bar = document.createElement('div'); bar.className = 'bar'; bar.style.width = (100 * versions[v] / max) + '%';
      cell(tr, '').appendChild(bar);
      body.appendChild(tr);
    });
    var fbody = document.querySelector('#failed tbody'); fbody.textContent = '';
    (d.failed_migrations || []).forEach(function (f) { var tr = document.createElement('tr'); cell(tr, f.slug); cell(tr, f.error || ''); fbody.appendChild(tr); });
    if (!(d.failed_migrations || []).length) { var tr = document.createElement('tr'); cell(tr, 'None'); fbody.appendChild(tr); }
    document.getElementById('out').hidden = false;
  }
  function load() {
    var token = tokenInput.value.trim(); if (!token) return;
    err.hidden = true;
    fetch('fleet', { headers: { Accept: 'application/json', Authorization: 'Bearer ' + token } })
      .then(function (r) { if (!r.ok) throw new Error(r.status === 401 || r.status === 403 ? 'Token refused.' : 'Request failed (' + r.status + ').'); return r.json(); })
      .then(function (j) { try { sessionStorage.setItem('platformToken', token); } catch (e) {} render(j.data); })
      .catch(function (e) { err.textContent = e.message; err.hidden = false; });
  }
  form.addEventListener('submit', function (e) { e.preventDefault(); load(); });
  if (tokenInput.value) load();
  setInterval(function () { if (!document.getElementById('out').hidden) load(); }, 30000);
})();
</script>
</body>
</html>
