<style>
    .op-profile { --op-ink:#14213a; --op-muted:#6b788d; --op-line:#e4e9ee; --op-surface:#fff; --op-soft:#f8fafb; --op-green:#15803d; --op-green-bg:#e8f7ed; --op-blue:#edf5ff; --op-blue-ink:#33577a; color:var(--op-ink); font-size:14px; line-height:1.5; min-width:0; }
    .dark .op-profile { --op-ink:#e8eef4; --op-muted:#9daebf; --op-line:#33415a; --op-surface:#1a2438; --op-soft:#131c2e; --op-green:#6ee7a0; --op-green-bg:#1d3a2a; --op-blue:#1d2c47; --op-blue-ink:#b4ceee; }
    .op-heading { display:flex; align-items:center; gap:14px; margin-bottom:26px; }
    .op-heading-icon { display:grid; place-items:center; background:var(--op-green-bg); color:var(--op-green); width:52px; height:52px; border-radius:14px; flex-shrink:0; }
    .op-heading-icon svg { width:27px; height:27px; }
    .op-heading h1 { font-size:27px; font-weight:750; letter-spacing:-.8px; margin:0 0 4px; }
    .op-profile p { margin:4px 0 0; color:var(--op-muted); }
    .op-layout { display:grid; grid-template-columns:minmax(0,1.8fr) minmax(275px,.9fr); gap:20px; align-items:start; }
    .op-stack { display:grid; gap:18px; min-width:0; }
    .op-card { border:1px solid var(--op-line); background:var(--op-surface); border-radius:15px; box-shadow:0 3px 12px #1d314309; overflow:hidden; min-width:0; }
    .op-body { padding:21px 22px; }
    .op-card-head { padding:19px 22px; border-bottom:1px solid var(--op-line); display:flex; justify-content:space-between; gap:10px; }
    .op-card-head h2, .op-profile h2 { font-size:16px; font-weight:700; margin:0 0 4px; }
    .op-card-head p { font-size:12px; }
    .op-card-head svg { width:22px; height:22px; color:var(--op-muted); flex-shrink:0; }
    .op-identity { display:flex; align-items:center; gap:15px; overflow-wrap:anywhere; }
    .op-identity strong { font-size:16px; }
    .op-identity p { font-size:12px; margin-bottom:9px; }
    .op-avatar { display:grid; place-items:center; width:60px; height:60px; flex-shrink:0; border-radius:50%; background:var(--op-green-bg); color:var(--op-green); font-size:21px; font-weight:800; }
    .op-pill { display:inline-block; border-radius:30px; padding:5px 9px; font-size:11px; font-weight:650; color:var(--op-green); background:var(--op-green-bg); }
    .op-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:19px 18px; }
    .op-full { grid-column:1/-1; }
    .op-field { display:grid; align-content:start; gap:7px; min-width:0; scroll-margin-top:100px; }
    .op-field label { font-size:12px; font-weight:650; }
    .op-field input, .op-field select { width:100%; min-width:0; padding:10px 12px; border:1px solid var(--op-line); border-radius:8px; background:var(--op-surface); color:var(--op-ink); font:inherit; font-size:13px; color-scheme:light; }
    .dark .op-field input, .dark .op-field select { color-scheme:dark; }
    .op-field.op-missing input, .op-field.op-missing select { border-color:#e5bc75; background:#fffcf5; }
    .dark .op-field.op-missing input, .dark .op-field.op-missing select { border-color:#a26c1a; background:#332a1b; }
    .op-field select:disabled { opacity:.55; }
    .op-field input:focus-visible, .op-field select:focus-visible, .op-todo a:focus-visible { outline:3px solid #89c8a3; outline-offset:2px; }
    .op-field small, .op-note { font-size:11px; color:var(--op-muted); line-height:1.6; }
    .op-required, .op-error { color:#b91c1c; }
    .dark .op-required, .dark .op-error { color:#fca5a5; }
    .op-error { font-size:12px; }
    .op-field input[aria-invalid="true"], .op-field select[aria-invalid="true"] { border-color:#dc2626; }
    .dark .op-field input[aria-invalid="true"], .dark .op-field select[aria-invalid="true"] { border-color:#dc2626; }
    .op-actions { display:flex; justify-content:space-between; align-items:center; gap:15px; padding:18px 22px; }
    .op-actions > span { font-size:11px; color:var(--op-muted); }
    .op-actions button { flex-shrink:0; }
    .op-progress-top { display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:13px; }
    .op-progress-top > span { color:var(--op-green); font-size:26px; font-weight:800; }
    .op-progress { height:8px; border-radius:20px; background:var(--op-line); margin:15px 0 12px; overflow:hidden; }
    .op-progress > div { background:var(--op-green); height:100%; border-radius:20px; transition:width .2s; }
    .op-progress + p { font-size:12px; }
    .op-motivation { padding:13px; background:var(--op-green-bg); color:var(--op-green); border-radius:9px; font-size:12px; margin-top:16px; line-height:1.6; }
    .op-profile .op-section-label { font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:var(--op-muted); margin-bottom:14px; }
    .op-todo { display:grid; gap:8px; margin-bottom:14px; }
    .op-todo a { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:10px 11px; border:1px solid var(--op-line); border-radius:8px; font-size:12px; text-decoration:none; color:var(--op-ink); }
    .op-todo-icon { width:31px; height:31px; display:grid; place-items:center; background:#fff4df; color:#a26c1a; border-radius:9px; flex-shrink:0; }
    .dark .op-todo-icon { background:#332a1b; color:#e5bc75; }
    .op-todo-label { flex:1; }
    .op-todo a span:last-child { color:var(--op-green); white-space:nowrap; font-weight:650; }
    .op-tip { background:var(--op-blue); color:var(--op-blue-ink); border:1px solid var(--op-line); border-radius:12px; padding:17px 19px; font-size:12px; line-height:1.7; }
    .op-tip p { color:inherit; margin-top:7px; }
    .op-security { display:flex; gap:11px; font-size:11px; }
    .op-security svg { width:20px; height:20px; color:var(--op-muted); flex-shrink:0; }
    @media (max-width:1000px) { .op-layout { grid-template-columns:1fr; } }
    @media (max-width:560px) { .op-grid { grid-template-columns:1fr; } .op-heading h1 { font-size:23px; } .op-heading p { font-size:12px; } .op-body, .op-card-head { padding:18px; } .op-actions { flex-direction:column; align-items:stretch; } }
</style>
