/* PTP Auth - Shared Styles v214 | Masterclass-style dark immersive */

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --gold: #FCB900; --gold-hover: #E5A800;
    --black: #0A0A0A; --surface: #111113; --surface-2: #1A1A1C;
    --border: #2A2A2E; --border-focus: #FCB900;
    --text: #FFFFFF; --text-dim: #9CA3AF; --text-muted: #6B7280;
    --red: #EF4444; --red-bg: rgba(239,68,68,.08);
    --green: #22C55E; --green-bg: rgba(34,197,94,.08);
    --font-display: 'Oswald', sans-serif;
    --font-body: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

html, body { height: 100%; -webkit-font-smoothing: antialiased; scrollbar-width: none; -ms-overflow-style: none; }
html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; }
body { font-family: var(--font-body); background: var(--black); color: var(--text); }

/* Layout */
.ptp-auth { min-height: 100vh; min-height: 100dvh; display: grid; grid-template-columns: 1fr; background: var(--black); }
@media (min-width: 960px) { .ptp-auth { grid-template-columns: 1fr 1fr; } }
@media (min-width: 1200px) { .ptp-auth { grid-template-columns: 1.1fr 0.9fr; } }

/* Brand panel */
.ptp-auth-brand { display: none; position: relative; background: var(--black); overflow: hidden; }
@media (min-width: 960px) { .ptp-auth-brand { display: flex; align-items: center; justify-content: center; } }
.ptp-auth-brand::before { content: ''; position: absolute; inset: 0; background-image: linear-gradient(rgba(252,185,0,.03) 1px, transparent 1px), linear-gradient(90deg, rgba(252,185,0,.03) 1px, transparent 1px); background-size: 60px 60px; pointer-events: none; }
.ptp-auth-brand::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 200px; background: linear-gradient(to top, rgba(252,185,0,.06), transparent); pointer-events: none; }
.ptp-auth-brand-inner { position: relative; z-index: 1; padding: 60px; max-width: 520px; width: 100%; }
.ptp-auth-brand-logo img { height: 36px; margin-bottom: 48px; display: block; }
.ptp-auth-brand-title { font-family: var(--font-display); font-size: clamp(42px, 5vw, 56px); font-weight: 700; line-height: 1.0; letter-spacing: -0.02em; text-transform: uppercase; color: var(--text); margin: 0 0 20px; }
.ptp-auth-brand-title span { color: var(--gold); }
.ptp-auth-brand-sub { font-size: 15px; line-height: 1.6; color: var(--text-dim); margin: 0 0 40px; max-width: 400px; }

/* Stats */
.ptp-auth-brand-stats { display: flex; gap: 32px; margin-bottom: 40px; padding-bottom: 32px; border-bottom: 1px solid var(--border); }
.ptp-auth-stat { display: flex; flex-direction: column; }
.ptp-auth-stat-num { font-family: var(--font-display); font-size: 28px; font-weight: 700; color: var(--gold); letter-spacing: -0.02em; }
.ptp-auth-stat-label { font-family: var(--font-display); font-size: 10px; font-weight: 600; color: var(--text-muted); letter-spacing: 0.12em; margin-top: 2px; }

/* Quote */
.ptp-auth-brand-quote { border-left: 2px solid var(--gold); padding-left: 20px; }
.ptp-auth-brand-quote p { font-size: 14px; line-height: 1.7; color: rgba(255,255,255,.6); font-style: italic; margin: 0 0 8px; }
.ptp-auth-brand-quote cite { font-size: 12px; font-style: normal; font-weight: 600; color: var(--gold); }

/* Form panel */
.ptp-auth-form-panel { display: flex; align-items: center; justify-content: center; min-height: 100vh; min-height: 100dvh; padding: 24px 20px; padding-top: max(24px, env(safe-area-inset-top)); padding-bottom: max(24px, env(safe-area-inset-bottom)); background: var(--surface); }
@media (min-width: 960px) { .ptp-auth-form-panel { padding: 48px; } }
.ptp-auth-form-inner { width: 100%; max-width: 400px; }

/* Mobile logo */
.ptp-auth-mobile-logo { display: block; text-align: center; margin-bottom: 32px; }
.ptp-auth-mobile-logo img { height: 32px; }
@media (min-width: 960px) { .ptp-auth-mobile-logo { display: none; } }

/* Form header */
.ptp-auth-form-header { margin-bottom: 28px; }
.ptp-auth-form-header h2 { font-family: var(--font-display); font-size: 28px; font-weight: 700; text-transform: uppercase; letter-spacing: -0.01em; color: var(--text); margin: 0 0 6px; }
.ptp-auth-form-header p { font-size: 14px; color: var(--text-dim); margin: 0; }

/* Messages */
.ptp-auth-msg { display: flex; align-items: center; gap: 10px; padding: 12px 14px; font-size: 13px; line-height: 1.4; margin-bottom: 20px; border: 1px solid; }
.ptp-auth-msg svg { flex-shrink: 0; }
.ptp-auth-msg-error { background: var(--red-bg); color: #FCA5A5; border-color: rgba(239,68,68,.2); }
.ptp-auth-msg-success { background: var(--green-bg); color: #86EFAC; border-color: rgba(34,197,94,.2); }

/* Google button */
.ptp-auth-google { display: flex; align-items: center; justify-content: center; gap: 12px; width: 100%; height: 48px; font-family: var(--font-body); font-size: 14px; font-weight: 500; color: var(--text); background: var(--surface-2); border: 1px solid var(--border); cursor: pointer; text-decoration: none; transition: border-color .2s, background .2s; -webkit-tap-highlight-color: transparent; }
.ptp-auth-google:hover { border-color: var(--text-muted); background: rgba(255,255,255,.04); }
.ptp-auth-google:active { transform: scale(.99); }

/* Divider */
.ptp-auth-divider { display: flex; align-items: center; gap: 16px; margin: 20px 0; }
.ptp-auth-divider::before, .ptp-auth-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.ptp-auth-divider span { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; }

/* Fields */
.ptp-auth-field { margin-bottom: 18px; }
.ptp-auth-field label { display: flex; justify-content: space-between; align-items: baseline; font-family: var(--font-display); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-dim); margin-bottom: 8px; }
.ptp-auth-field-link { font-family: var(--font-body); font-size: 12px; font-weight: 500; color: var(--gold); text-decoration: none; text-transform: none; letter-spacing: 0; }
.ptp-auth-field-link:hover { text-decoration: underline; }

.ptp-auth-field input[type="text"],
.ptp-auth-field input[type="email"],
.ptp-auth-field input[type="tel"],
.ptp-auth-field input[type="password"] { width: 100%; height: 48px; padding: 0 14px; font-family: var(--font-body); font-size: 15px; color: var(--text); background: var(--surface-2); border: 1px solid var(--border); -webkit-appearance: none; appearance: none; border-radius: 0; transition: border-color .2s, box-shadow .2s; outline: none; }
.ptp-auth-field input::placeholder { color: var(--text-muted); }
.ptp-auth-field input:focus { border-color: var(--gold); box-shadow: 0 0 0 1px var(--gold); }
.ptp-auth-field input.error { border-color: var(--red); }

/* Password toggle */
.ptp-auth-pass-wrap { position: relative; }
.ptp-auth-pass-wrap input { padding-right: 44px; }
.ptp-auth-pass-toggle { position: absolute; right: 0; top: 0; width: 44px; height: 48px; display: flex; align-items: center; justify-content: center; background: none; border: none; cursor: pointer; color: var(--text-muted); -webkit-tap-highlight-color: transparent; }
.ptp-auth-pass-toggle:hover { color: var(--text-dim); }

/* Grid row */
.ptp-auth-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 400px) { .ptp-auth-row { grid-template-columns: 1fr; } }

/* Remember me */
.ptp-auth-remember { margin-bottom: 20px; }
.ptp-auth-remember label { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-dim); cursor: pointer; -webkit-tap-highlight-color: transparent; }
.ptp-auth-remember input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--gold); cursor: pointer; }

/* Terms checkbox */
.ptp-auth-terms { display: flex; align-items: flex-start; gap: 10px; font-size: 13px; color: var(--text-dim); margin-bottom: 20px; line-height: 1.5; }
.ptp-auth-terms input { width: 18px; height: 18px; accent-color: var(--gold); margin-top: 2px; flex-shrink: 0; }
.ptp-auth-terms a { color: var(--gold); text-decoration: none; }
.ptp-auth-terms a:hover { text-decoration: underline; }

/* Submit */
.ptp-auth-submit { width: 100%; height: 48px; font-family: var(--font-display); font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--black); background: var(--gold); border: 2px solid var(--gold); cursor: pointer; -webkit-appearance: none; appearance: none; border-radius: 0; transition: all .15s; -webkit-tap-highlight-color: transparent; }
.ptp-auth-submit:hover { background: var(--gold-hover); border-color: var(--gold-hover); }
.ptp-auth-submit:active { transform: scale(.98); }
.ptp-auth-submit:disabled { opacity: .5; cursor: not-allowed; transform: none; }

/* Spinner */
@keyframes ptpSpin { to { transform: rotate(360deg); } }
.ptp-auth-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid var(--black); border-top-color: transparent; border-radius: 50%; animation: ptpSpin .7s linear infinite; margin-right: 8px; vertical-align: middle; }

/* Footer links */
.ptp-auth-footer-links { margin-top: 24px; text-align: center; }
.ptp-auth-footer-links p { font-size: 14px; color: var(--text-dim); margin: 0 0 8px; }
.ptp-auth-footer-links a { color: var(--gold); font-weight: 600; text-decoration: none; }
.ptp-auth-footer-links a:hover { text-decoration: underline; }
.ptp-auth-coach-link { font-size: 12px !important; color: var(--text-muted) !important; padding-top: 12px; border-top: 1px solid var(--border); }
.ptp-auth-coach-link a { color: var(--gold) !important; font-weight: 500 !important; }

/* Hint */
.ptp-auth-hint { font-size: 11px; color: var(--text-muted); margin-top: 6px; }

/* Back link */
.ptp-auth-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted); text-decoration: none; margin-bottom: 24px; transition: color .2s; }
.ptp-auth-back:hover { color: var(--gold); }

/* Info box */
.ptp-auth-info { background: var(--surface-2); border: 1px solid var(--border); padding: 16px; margin-bottom: 24px; }
.ptp-auth-info p { font-size: 13px; line-height: 1.6; color: var(--text-dim); margin: 0; }

/* Animations */
@keyframes ptpFadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.ptp-auth-form-inner { animation: ptpFadeUp .5s ease-out; }
.ptp-auth-brand-inner { animation: ptpFadeUp .6s ease-out .1s both; }

/* ═══════════════════════════════════════
   v221: Mobile — standalone pages, no theme CSS to fight
   ═══════════════════════════════════════ */
@media (max-width: 959px) {
    .ptp-auth { display: flex; flex-direction: column; min-height: 100vh; min-height: 100dvh; }
    .ptp-auth-brand { display: none; }
    .ptp-auth-form-panel {
        flex: 1; display: flex; flex-direction: column;
        justify-content: center; align-items: center;
        min-height: 100vh; min-height: 100dvh;
        padding: 20px 16px;
        padding-top: max(20px, env(safe-area-inset-top));
        padding-bottom: max(20px, env(safe-area-inset-bottom));
        overflow-y: auto; -webkit-overflow-scrolling: touch;
    }
    .ptp-auth-form-inner { width: 100%; max-width: 400px; }
    .ptp-auth-mobile-logo { display: block; text-align: center; margin-bottom: 24px; }
    .ptp-auth-mobile-logo img { height: 28px; }
    .ptp-auth-form-header { margin-bottom: 20px; text-align: center; }
    .ptp-auth-form-header h2 { font-size: 24px; }
    .ptp-auth-form-header p { font-size: 13px; }
    /* iOS zoom prevention: inputs must be >= 16px */
    .ptp-auth-field input[type="text"],
    .ptp-auth-field input[type="email"],
    .ptp-auth-field input[type="tel"],
    .ptp-auth-field input[type="password"] { font-size: 16px; height: 50px; }
    .ptp-auth-submit { height: 50px; font-size: 14px; }
    .ptp-auth-google { height: 50px; }
    .ptp-auth-footer-links { margin-top: 20px; padding-bottom: 16px; }
}
@media (max-width: 380px) {
    .ptp-auth-form-panel { padding: 16px 12px; }
    .ptp-auth-form-header h2 { font-size: 22px; }
    .ptp-auth-field { margin-bottom: 14px; }
}
/* Keyboard open on mobile — compress vertical spacing */
@media (max-height: 500px) and (max-width: 959px) {
    .ptp-auth-form-panel { justify-content: flex-start; padding-top: 12px; }
    .ptp-auth-mobile-logo { margin-bottom: 12px; }
    .ptp-auth-form-header { margin-bottom: 12px; }
    .ptp-auth-field { margin-bottom: 10px; }
    .ptp-auth-remember { margin-bottom: 10px; }
    .ptp-auth-footer-links { display: none; }
}
