<style>
:root {
    --bg: #eef1f6;
    --surface: #fff;
    --border: #e6e9ef;
    --border-strong: #cbd5e1;
    --text: #0f172a;
    --text-soft: #334155;
    --muted: #64748b;
    --accent: #4f46e5;
    --accent-600: #4338ca;
    --accent-contrast: #fff;
    --danger: #dc2626;
    --danger-soft: #fef2f2;
    --radius-sm: 10px;
    --radius: 14px;
    --shadow: 0 4px 18px rgba(15, 23, 42, .06);
}
*, *::before, *::after { box-sizing: border-box; }
html, body {
    margin: 0;
    min-height: 100%;
    color: var(--text);
    background: var(--bg);
    font-family: 'Vazirmatn', Tahoma, 'Segoe UI', Arial, sans-serif;
    line-height: 1.5;
}
.container { width: 100%; max-width: 1320px; margin-inline: auto; padding-inline: 1rem; }
.row { --gutter: 1.5rem; display: flex; flex-wrap: wrap; justify-content: center; margin-inline: calc(var(--gutter) / -2); }
.row > * { flex-shrink: 0; width: 100%; max-width: 420px; padding-inline: calc(var(--gutter) / 2); }
@media (min-width: 768px) { .col-md-8 { flex: 0 0 auto; width: 66.6667%; } }
@media (min-width: 992px) { .col-lg-6 { flex: 0 0 auto; width: 50%; } }
@media (min-width: 1200px) { .col-xl-5 { flex: 0 0 auto; width: 41.6667%; } }
.d-flex { display: flex !important; }
.flex-column { flex-direction: column !important; }
.flex-grow-1 { flex-grow: 1 !important; }
.align-items-stretch { align-items: stretch !important; }
.justify-content-center { justify-content: center !important; }
.min-vh-100 { min-height: 100vh; min-height: 100dvh; }
.px-3 { padding-inline: 1rem !important; }
.pt-4 { padding-top: 1.5rem !important; }
.my-auto { margin-block: auto !important; }
.mb-0 { margin-bottom: 0 !important; }
.mb-1 { margin-bottom: .25rem !important; }
.mb-2 { margin-bottom: .5rem !important; }
.mb-3 { margin-bottom: 1rem !important; }
.mb-4 { margin-bottom: 1.5rem !important; }
.mt-2 { margin-top: .5rem !important; }
.mt-3 { margin-top: 1rem !important; }
.mt-4 { margin-top: 1.5rem !important; }
.pb-2 { padding-bottom: .5rem !important; }
.p-2 { padding: .5rem !important; }
.p-4 { padding: 1.5rem !important; }
.py-1 { padding-block: .25rem !important; }
.gap-2 { gap: .5rem !important; }
.text-center { text-align: center !important; }
.text-muted { color: var(--muted) !important; }
.text-danger { color: var(--danger) !important; }
.small { font-size: .875em; }
.w-100 { width: 100% !important; }
.position-relative { position: relative !important; }
.overflow-hidden { overflow: hidden !important; }
.rounded { border-radius: var(--radius-sm) !important; }
.border { border: 1px solid var(--border-strong) !important; }
.bg-white { background: #fff !important; }
.bg-light { background: #f8fafc !important; }
.authentication-bg {
    min-height: 100vh;
    min-height: 100dvh;
    background: linear-gradient(145deg, #eef2ff 0%, #f8fafc 45%, #ecfdf5 100%);
}
.auth-page-container,
.authentication-bg .card {
    position: relative;
    z-index: 2;
}
.auth-brand {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    gap: .65rem;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text);
    text-decoration: none;
}
.auth-brand:hover { color: var(--accent); }
.auth-brand__img {
    display: block;
    width: 48px;
    height: 48px;
    border-radius: 0;
    box-shadow: none;
}
.auth-brand__text { line-height: 1.3; }
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}
.card-body { padding: 1.25rem; }
h5 { margin: 0; font-size: 1.1rem; font-weight: 700; }
.form-label {
    display: block;
    margin-bottom: .35rem;
    font-size: .82rem;
    font-weight: 600;
    color: var(--text-soft);
}
input.form-control,
textarea.form-control,
select.form-control {
    display: block !important;
    width: 100% !important;
    min-height: 2.75rem !important;
    margin: 0 !important;
    padding: .55rem .8rem !important;
    font-size: 1rem !important;
    line-height: 1.4 !important;
    color: #0f172a !important;
    -webkit-text-fill-color: #0f172a;
    background-color: #fff !important;
    border: 2px solid #94a3b8 !important;
    border-radius: var(--radius-sm) !important;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, .06);
    opacity: 1 !important;
    visibility: visible !important;
    -webkit-appearance: none;
    appearance: none;
}
input.form-control::placeholder {
    color: #94a3b8 !important;
    opacity: 1 !important;
}
input.form-control:focus {
    outline: none !important;
    border-color: var(--accent) !important;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, .2) !important;
}
input.form-control:-webkit-autofill,
input.form-control:-webkit-autofill:hover,
input.form-control:-webkit-autofill:focus {
    -webkit-box-shadow: 0 0 0 1000px #fff inset !important;
    -webkit-text-fill-color: #0f172a !important;
}
.form-control.is-invalid { border-color: var(--danger); }
.form-check { display: flex; align-items: center; gap: .5rem; }
.form-check-input { width: 1.05rem; height: 1.05rem; }
.form-check-label { font-size: .875rem; }
.form-text { font-size: .78rem; color: var(--muted); margin-top: .3rem; }
.invalid-feedback { color: var(--danger); font-size: .8rem; margin-top: .25rem; }
.d-block { display: block !important; }
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: .5rem .95rem;
    font-size: .875rem;
    font-weight: 600;
    border-radius: var(--radius-sm);
    border: 1px solid transparent;
    cursor: pointer;
}
.btn-primary { background: var(--accent); color: var(--accent-contrast); }
.btn-primary:hover { background: var(--accent-600); }
.btn-outline-secondary {
    background: #fff;
    border-color: var(--border-strong);
    color: var(--text-soft);
}
.btn-outline-secondary:hover {
    border-color: var(--accent);
    color: var(--accent);
}
.btn:disabled { opacity: .55; cursor: not-allowed; }
.alert-danger {
    background: var(--danger-soft);
    color: #991b1b;
    padding: .75rem 1rem;
    border-radius: var(--radius-sm);
    margin-top: 1rem;
}
.login-captcha-box {
    min-height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: .5rem .75rem;
    background: #f8fafc;
}
.login-captcha-display {
    display: block;
    font-size: 1.65rem;
    font-weight: 800;
    letter-spacing: .22em;
    font-family: Tahoma, Arial, sans-serif;
    color: #1e293b;
    text-align: center;
    direction: ltr;
    unicode-bidi: plaintext;
    user-select: none;
}
.login-captcha-loading {
    font-size: .8rem;
    font-weight: 500;
    letter-spacing: 0;
    color: var(--muted);
}
.login-captcha-refresh-btn {
    flex-shrink: 0;
    min-width: 3.25rem;
    min-height: 56px;
    padding: .35rem .5rem;
    flex-direction: column;
    gap: .15rem;
}
.login-captcha-refresh-icon { display: block; }
.login-captcha-refresh-label { font-size: .65rem; font-weight: 600; }
#captcha-input {
    letter-spacing: .12em;
    font-family: Tahoma, Arial, sans-serif;
    direction: ltr;
    text-align: center;
}
.input-custom-icon { position: relative; }
.input-custom-icon .form-control { padding-inline-end: .8rem; }
</style>
