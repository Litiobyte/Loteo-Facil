<style>
    .owner-statement {
        --os-green: #138747; --os-green-dark: #0a6535; --os-green-soft: #eaf7ef;
        --os-ink: #152238; --os-muted: #758399; --os-line: #e4e9ee;
        --os-bg: #f5f7f8; --os-white: #fff; --os-red: #c83d42; --os-red-bg: #fff0ef;
        --os-amber: #9a621b; --os-amber-bg: #fff4df;
        color: var(--os-ink); padding: 26px; border-radius: 14px;
        font-family: Inter, ui-sans-serif, system-ui, sans-serif; font-size: 14px; line-height: 1.5; min-width: 0;    }
    .dark .owner-statement {
        --os-ink: #edf4f0; --os-muted: #a4b4ac; --os-line: #35463e; --os-bg: #141e19;
        --os-white: #1c2922; --os-green-dark: #8bdfaa; --os-green-soft: #203e2d;
        --os-red: #ffaaa7; --os-red-bg: #492629; --os-amber: #f5c46c; --os-amber-bg: #49381d;
    }
    .owner-statement svg { width: 22px; height: 22px; flex-shrink: 0; }
    .owner-statement :is(button, a, input):focus-visible { outline: 3px solid #79cca1; outline-offset: 2px; }
    .owner-statement button { cursor: pointer; }
    .owner-statement h1 { font-size: 26px; letter-spacing: -.7px; font-weight: 750; }
    .owner-statement h2 { font-size: 15px; letter-spacing: -.2px; font-weight: 700; }
    .os-header, .os-heading, .os-filterbar, .os-dates, .os-metric-top, .os-section-head, .os-sumline, .os-footer { display: flex; justify-content: space-between; gap: 14px; align-items: center; }
    .os-header { align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; }
    .os-crumb { color: var(--os-muted); font-size: 12px; margin-bottom: 13px; }
    .os-heading { justify-content: flex-start; }
    .os-heading-icon { width: 46px; height: 46px; display: grid; place-items: center; background: var(--os-green-soft); color: var(--os-green-dark); border-radius: 14px; flex-shrink: 0; }
    .os-muted { color: var(--os-muted); font-size: 13px; }
    .os-panel { background: var(--os-white); border: 1px solid var(--os-line); border-radius: 14px; box-shadow: 0 4px 20px rgb(17 34 50 / 3.5%); min-width: 0; overflow: hidden; }
    .os-button { border-radius: 9px; padding: 10px 14px; background: var(--os-green); color: white; font-size: 12px; font-weight: 650; }
    .os-filterbar { padding: 16px 18px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 17px; }
    .owner-statement .os-filter-title { font-size: 12px; margin-bottom: 10px; }
    .os-ranges { display: flex; flex-wrap: wrap; gap: 6px; }
    .os-ranges button { background: var(--os-bg); color: var(--os-muted); border: 1px solid var(--os-line); border-radius: 8px; padding: 8px 11px; font-size: 12px; font-weight: 600; }
    .os-ranges button[aria-pressed="true"] { background: var(--os-green-soft); color: var(--os-green-dark); border-color: var(--os-green); }
    .os-dates { gap: 9px; }
    .os-dates label { font-size: 11px; color: var(--os-muted); }
    .os-dates input { display: block; border: 1px solid var(--os-line); border-radius: 8px; padding: 9px 10px; margin-top: 5px; color: var(--os-ink); background: var(--os-white); max-width: 155px; font-size: 12px; }
    .os-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 13px; margin-bottom: 18px; }
    .os-metric { padding: 17px 18px; min-height: 127px; }
    .os-metric-top { color: var(--os-muted); font-size: 12px; font-weight: 600; }
    .os-symbol { width: 32px; height: 32px; display: grid; place-items: center; border-radius: 9px; background: var(--os-bg); flex-shrink: 0; }
    .os-main-metric .os-symbol { background: var(--os-red-bg); color: var(--os-red); }
    .os-number { font-size: 27px; letter-spacing: -.9px; font-weight: 800; margin: 15px 0 5px; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .os-main-metric .os-number { color: var(--os-red); }
    .os-small, .os-note, .os-section-head p, .os-footer, .os-help { font-size: 11px; color: var(--os-muted); }
    .os-mainlayout { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(295px, 1fr); gap: 17px; align-items: start; }
    .os-column { display: grid; gap: 17px; min-width: 0; }
    .os-section-head { padding: 19px 20px 15px; align-items: flex-start; flex-wrap: wrap; }
    .os-section-head p { margin-top: 5px; }
    .os-link { color: var(--os-green-dark); font-size: 12px; font-weight: 700; }
    .os-link:hover { text-decoration: underline; }
    .os-summary { padding: 0 20px 20px; }
    .os-sumline { padding: 12px 0; border-bottom: 1px solid var(--os-line); font-size: 13px; }
    .os-sumline strong { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .os-final { background: var(--os-green-soft); color: var(--os-green-dark); padding: 15px; border: 0; border-radius: 9px; margin-top: 14px; font-weight: 700; flex-wrap: wrap; }
    .os-final strong { font-size: 19px; }
    .os-note { margin-top: 13px; }
    .os-notice { padding: 15px 17px; display: flex; gap: 11px; background: var(--os-amber-bg); border: 1px solid var(--os-line); border-radius: 12px; color: var(--os-amber); font-size: 12px; }
    .os-notice p { margin: 5px 0; }
    .os-tablewrap { overflow-x: auto; }
    .os-table { width: 100%; border-collapse: collapse; text-align: left; min-width: 480px; }
    .os-table th { text-transform: uppercase; font-size: 10px; letter-spacing: .4px; color: var(--os-muted); background: var(--os-bg); padding: 12px 17px; }
    .os-table td { padding: 14px 17px; border-bottom: 1px solid var(--os-line); font-size: 12px; }
    .os-table .os-money { text-align: right; font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .os-date { white-space: nowrap; }
    .os-badge { display: inline-flex; padding: 5px 8px; border-radius: 7px; font-size: 10px; font-weight: 700; white-space: nowrap; background: var(--os-bg); color: var(--os-muted); }
    .os-good { background: var(--os-green-soft); color: var(--os-green-dark); }
    .os-warn { background: var(--os-amber-bg); color: var(--os-amber); }
    .os-footer { border-top: 1px solid var(--os-line); padding: 14px 19px; flex-wrap: wrap; }
    .os-empty { padding: 28px 14px; text-align: center; color: var(--os-muted); }
    @media (max-width: 1100px) { .os-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } .os-mainlayout { grid-template-columns: 1fr; } }
    @media (max-width: 620px) {
        .owner-statement { padding: 14px; } .owner-statement h1 { font-size: 22px; }
        .os-cards { gap: 9px; } .os-metric { padding: 13px; } .os-number { font-size: 22px; }
        .os-dates { display: grid; grid-template-columns: 1fr 1fr; width: 100%; } .os-dates input { width: 100%; max-width: none; min-width: 0; }
        .os-section-head { padding: 16px 14px 13px; } .os-summary { padding: 0 14px 15px; }
    }
    @media print {
        .fi-sidebar, .fi-topbar, .fi-header, .fi-notifications, .os-filterbar, .os-print, .owner-statement .fi-ta-header-toolbar, .owner-statement .fi-pagination { display: none !important; }
        html, body { background-color: #fff !important; }
        .fi-layout { display: block !important; height: auto !important; min-height: 0 !important; overflow: visible !important; }
        .fi-main-ctn { display: block !important; height: auto !important; min-height: 0 !important; opacity: 1 !important; }
        .fi-main { display: block !important; height: auto !important; max-width: none !important; width: 100% !important; margin: 0 !important; padding-inline: 0 !important; }
        .fi-page-main { display: block !important; }
        .owner-statement { padding: 0; background: white; } .os-mainlayout { display: block; }
        .os-column { display: block; } .os-panel { margin-bottom: 14px; box-shadow: none; }
        .os-table tr, .os-sumline { break-inside: avoid; }
        .os-tablewrap { overflow: visible; } .os-table { min-width: 0; }
    }
</style>
