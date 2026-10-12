<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow">
<title>Atur kata sandi baru · RT05 TAKEDA</title>
<link rel="preload" href="/auth-assets/public-sans-latin-wght.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/auth-assets/bricolage-grotesque-latin-wght.woff2" as="font" type="font/woff2" crossorigin>
<style nonce="{{ $nonce }}">
/* Font dan logo gapura disalin byte-identik dari website/ (lisensi OFL di /auth-assets/OFL-*.txt). */
@font-face { font-family:'Public Sans Variable'; font-style:normal; font-display:swap; font-weight:100 900; src:url(/auth-assets/public-sans-latin-wght.woff2) format('woff2-variations'); unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD; }
@font-face { font-family:'Bricolage Grotesque Variable'; font-style:normal; font-display:swap; font-weight:200 800; src:url(/auth-assets/bricolage-grotesque-latin-wght.woff2) format('woff2-variations'); unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD; }
:root { --navy:#123e65; --ink:#183b50; --muted:#536a79; --sky:#eef5fa; --sky-deep:#d9eaf6; --line:#d8e3e9; --yellow:#f2cd5a; --error:#a3261b; --ok:#1f6b3a; --display:'Bricolage Grotesque Variable','Segoe UI',sans-serif; }
* { box-sizing:border-box; }
body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px 16px; color:var(--ink); background:var(--sky); font-family:'Public Sans Variable','Segoe UI',sans-serif; font-size:16px; line-height:1.6; -webkit-font-smoothing:antialiased; }
main { width:min(440px,100%); background:#fff; border:1px solid var(--line); border-top:4px solid var(--yellow); border-radius:16px; padding:28px 24px; box-shadow:0 6px 20px rgba(18,62,101,.1),0 2px 6px rgba(18,62,101,.05); }
.brand { display:flex; align-items:center; gap:12px; color:var(--navy); font-family:var(--display); font-size:22px; font-weight:700; line-height:1.2; letter-spacing:-.04em; }
.brand img { flex-shrink:0; }
.brand small { display:block; font-family:'Public Sans Variable','Segoe UI',sans-serif; font-size:11px; letter-spacing:.02em; font-weight:500; color:var(--muted); margin-top:4px; }
h1 { font-family:var(--display); font-size:26px; line-height:1.2; letter-spacing:-.02em; margin:22px 0 6px; color:var(--navy); }
p { margin:0 0 16px; color:var(--muted); }
label { display:block; font-weight:600; margin:14px 0 6px; }
input { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:10px; font:inherit; color:var(--ink); }
input:focus-visible, button:focus-visible { outline:3px solid #267eac; outline-offset:2px; }
.hint { font-size:14px; color:var(--muted); margin-top:4px; }
.field-error { font-size:14px; color:var(--error); margin-top:4px; }
input[aria-invalid="true"] { border-color:var(--error); }
button { width:100%; margin-top:22px; padding:12px; border:0; border-radius:10px; background:var(--navy); color:#fff; font:inherit; font-weight:700; cursor:pointer; }
button:disabled { opacity:.6; cursor:not-allowed; }
.status { margin-top:18px; padding:12px; border-radius:10px; }
.status.ok { background:#e7f4ec; color:var(--ok); }
.status.error { background:#fbeceb; color:var(--error); }
[hidden] { display:none !important; }
</style>
</head>
<body>
<main>
  <div class="brand"><img src="/auth-assets/rt05-mark.svg" width="44" height="44" alt=""><span>RT 05 Takeda<small>Taman Kedaung · RW 07</small></span></div>
  <h1>Atur kata sandi baru</h1>
  <p>Masukkan email akun dan kode reset dari email. Kode berlaku terbatas dan hanya dapat dipakai sekali.</p>
  <form id="reset" method="post" action="/api/v1/auth/reset-password" novalidate>
    <input type="hidden" name="_token" value="{{ $csrf }}">
    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="username" required maxlength="254" aria-describedby="email-error">
    <div class="field-error" id="email-error" data-field="email" hidden></div>
    <label for="token">Kode reset</label>
    <input id="token" name="token" type="text" autocomplete="one-time-code" required spellcheck="false" autocapitalize="off" aria-describedby="token-error">
    <div class="field-error" id="token-error" data-field="token" hidden></div>
    <label for="password">Kata sandi baru</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8" aria-describedby="password-hint password-error">
    <div class="hint" id="password-hint">Minimal 8 karakter, berisi huruf dan angka, maksimal 72 byte (huruf beraksen atau emoji dihitung lebih dari 1).</div>
    <div class="field-error" id="password-error" data-field="password" hidden></div>
    <label for="password_confirmation">Ulangi kata sandi baru</label>
    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required aria-describedby="password_confirmation-error">
    <div class="field-error" id="password_confirmation-error" data-field="password_confirmation" hidden></div>
    <button type="submit">Simpan kata sandi baru</button>
  </form>
  <div id="status" class="status" role="status" aria-live="polite" hidden></div>
</main>
<script nonce="{{ $nonce }}">
(function () {
  var form = document.getElementById('reset');
  var status = document.getElementById('status');
  var button = form.querySelector('button');
  // Token dan email ada di fragment (#) agar tidak pernah terkirim ke server atau log.
  var params = new URLSearchParams(window.location.hash.replace(/^#/, ''));
  if (params.get('token')) form.token.value = params.get('token');
  if (params.get('email')) form.email.value = params.get('email');
  if (window.location.hash) history.replaceState(null, '', window.location.pathname);

  function show(kind, text) { status.className = 'status ' + kind; status.textContent = text; status.hidden = false; }
  function clearErrors() {
    form.querySelectorAll('.field-error').forEach(function (el) { el.hidden = true; el.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
  }
  function fieldError(name, text) {
    var el = form.querySelector('[data-field="' + name + '"]');
    if (!el) return false;
    el.textContent = text;
    el.hidden = false;
    if (form[name]) form[name].setAttribute('aria-invalid', 'true');
    return true;
  }
  function focusFirstInvalid() { var el = form.querySelector('[aria-invalid="true"]'); if (el) el.focus(); }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (button.disabled) return;
    clearErrors();
    status.hidden = true;
    if (form.password.value !== form.password_confirmation.value) {
      fieldError('password_confirmation', 'Konfirmasi kata sandi tidak sama.');
      focusFirstInvalid();
      return;
    }
    button.disabled = true;
    button.textContent = 'Menyimpan…';
    fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form._token.value },
      body: JSON.stringify({ email: form.email.value.trim(), token: form.token.value.trim(), password: form.password.value, password_confirmation: form.password_confirmation.value })
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) { return { res: res, body: body }; });
    }).then(function (r) {
      if (r.res.ok) {
        form.hidden = true;
        show('ok', (r.body.data && r.body.data.message) || 'Kata sandi berhasil direset. Silakan masuk kembali.');
        return;
      }
      var error = r.body.error || {};
      Object.keys(error.fields || {}).forEach(function (name) { fieldError(name, error.fields[name][0]); });
      focusFirstInvalid();
      show('error', r.res.status === 419 ? 'Halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.' : (error.message || 'Kata sandi belum dapat direset.'));
    }).catch(function () {
      show('error', 'Tidak dapat terhubung. Periksa koneksi lalu coba lagi.');
    }).then(function () {
      button.disabled = false;
      button.textContent = 'Simpan kata sandi baru';
    });
  });
})();
</script>
</body>
</html>
