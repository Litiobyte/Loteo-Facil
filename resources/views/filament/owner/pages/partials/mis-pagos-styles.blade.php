<style>
    .owner-payments { --op-green:#16803d; --op-soft:#eaf7ed; --op-ink:#142336; --op-muted:#718096; --op-line:#e3e9ed; --op-white:#fff; --op-bg:#f6f8f9; --op-amber:#a8660b; --op-amber-bg:#fff5df; color:var(--op-ink); font-family:Inter,ui-sans-serif,system-ui,sans-serif; font-size:14px; line-height:1.5; padding:26px; min-width:0; }
    .dark .owner-payments { --op-green:#8bdfaa; --op-soft:#203e2d; --op-ink:#edf4f0; --op-muted:#a4b4ac; --op-line:#35463e; --op-white:#1c2922; --op-bg:#141e19; --op-amber:#f3cb82; --op-amber-bg:#42351f; }
    .owner-payments :is(button,a,input):focus-visible { outline:3px solid #b1dec2; outline-offset:2px; }
    .owner-payments button { cursor:pointer; }
    .owner-payments svg { width:18px; height:18px; flex-shrink:0; }
    .op-crumb { font-size:12px; color:var(--op-muted); margin-bottom:8px; }
    .op-heading,.op-metric-top,.op-panel-head,.op-footer { display:flex; justify-content:space-between; align-items:center; gap:16px; }
    .op-heading { margin-bottom:25px; }
    .owner-payments h1 { font-size:29px; font-weight:750; letter-spacing:-.8px; margin-bottom:5px; }
    .owner-payments h2 { font-size:17px; font-weight:700; }
    .op-muted { color:var(--op-muted); }
    .op-button { display:inline-flex; justify-content:center; align-items:center; gap:8px; border:1px solid var(--op-line); border-radius:9px; padding:10px 14px; background:var(--op-white); color:var(--op-ink); font-weight:600; white-space:nowrap; }
    .op-button:hover { background:var(--op-soft); }
    .op-metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
    .op-metric { background:var(--op-white); border:1px solid var(--op-line); border-radius:14px; padding:21px; box-shadow:0 2px 6px #1b324009; }
    .op-metric-top { font-size:13px; color:var(--op-muted); }
    .op-symbol { width:34px; height:34px; border-radius:9px; background:var(--op-soft); display:grid; place-items:center; color:var(--op-green); }
    .op-metric strong { display:block; font-size:29px; font-weight:750; letter-spacing:-.8px; margin-top:12px; font-variant-numeric:tabular-nums; overflow-wrap:anywhere; }
    .op-metric small { display:block; font-size:12px; color:var(--op-muted); margin-top:5px; }
    .op-panel { border:1px solid var(--op-line); background:var(--op-white); border-radius:14px; overflow:hidden; min-width:0; }
    .op-panel-head { padding:21px; } .op-panel-head p,.op-panel-head>span { font-size:12px; }
    .op-tools { display:flex; gap:6px; flex-wrap:wrap; padding:0 21px 15px; }
    .op-tools button { border-radius:8px; padding:8px 14px; font-size:12px; font-weight:600; color:var(--op-muted); }
    .op-tools button[aria-pressed="true"] { color:var(--op-green); background:var(--op-soft); }
    .owner-payments .fi-ta { border:0; border-radius:0; box-shadow:none; background:var(--op-white); }
    .owner-payments .fi-ta-content { padding:0; }
    .op-list { overflow-x:auto; }
    .op-list-head,.op-payment { display:grid; grid-template-columns:85px minmax(95px,1fr) minmax(90px,1fr) repeat(3,minmax(75px,.8fr)) minmax(125px,1.2fr) 112px; gap:12px; align-items:center; min-width:890px; padding:14px 21px; }
    .op-list-head { color:var(--op-muted); background:var(--op-bg); font-size:11px; font-weight:700; border-block:1px solid var(--op-line); }
    .op-payment { font-size:12px; border-bottom:1px solid var(--op-line); }
    .op-reference { overflow-wrap:anywhere; font-family:ui-monospace,monospace; }
    .op-money { font-weight:650; text-align:right; font-variant-numeric:tabular-nums; }
    .op-positive { color:var(--op-green); }
    .op-pill { display:inline-block; border-radius:20px; padding:5px 9px; font-size:10px; font-weight:650; color:var(--op-amber); background:var(--op-amber-bg); }
    .op-success { color:var(--op-green); background:var(--op-soft); }
    .op-danger { color:#b84c36; background:#fff0e9; } .dark .op-danger { color:#ffaaa7; background:#492629; }
    .op-payment .op-button { font-size:12px; padding:7px 10px; }
    .op-mobile-label { display:none; }
    .op-footer { padding:15px 21px; font-size:11px; color:var(--op-muted); flex-wrap:wrap; }
    .op-hint { display:flex; gap:12px; margin-top:20px; padding:17px; border-radius:12px; background:var(--op-soft); font-size:13px; }
    .op-hint svg { color:var(--op-green); margin-top:3px; }
    .op-detail { padding:0; } .op-received { background:var(--op-soft); border-radius:12px; padding:18px; margin-block:16px; }
    .op-received small,.op-received strong { display:block; } .op-received strong { font-size:29px; color:var(--op-green); }
    .op-detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
    .op-detail-grid dt { color:var(--op-muted); font-size:12px; } .op-detail-grid dd { font-weight:600; overflow-wrap:anywhere; white-space:pre-line; }
    .op-applications { margin-top:22px; padding-top:17px; border-top:1px solid var(--op-line); }
    .op-application { display:flex; justify-content:space-between; gap:12px; margin-top:13px; font-size:13px; }
    .op-application small { display:block; } .op-application strong { white-space:nowrap; }
    @media(max-width:1200px) { .owner-payments { padding:10px; } .op-detail { padding:0; } }
    @media(max-width:760px) {
        .owner-payments { padding:0; } .op-heading,.op-panel-head { flex-wrap:wrap; } .owner-payments h1 { font-size:26px; }
        .op-metrics { grid-template-columns:1fr; gap:10px; } .op-metric { padding:17px; }
        .op-list-head { display:none; } .op-list { overflow:visible; }
        .op-payment { min-width:0; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px 8px; padding:17px; }
        .op-method { grid-column:1/3; grid-row:1; font-size:14px; font-weight:650; }
        .op-status { grid-column:3; grid-row:1/3; text-align:right; align-self:start; }
        .op-date { grid-column:1/3; grid-row:2; color:var(--op-muted); }
        .op-reference { grid-column:1/-1; grid-row:3; }
        .op-mobile-label { display:inline; } .op-date small,.op-money .op-mobile-label { display:block; font-size:10px; font-weight:400; color:var(--op-muted); }
        .op-money { grid-row:4; text-align:left; overflow-wrap:anywhere; }
        .op-payment .op-button { grid-column:1/-1; grid-row:5; }
        .op-detail-grid { grid-template-columns:1fr; }
    }
</style>
