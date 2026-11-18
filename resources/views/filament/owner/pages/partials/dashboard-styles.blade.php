<style>
    .owner-dashboard {
        --od-green: #12813d; --od-green-dark: #09632e; --od-green-bg: #eaf7ef;
        --od-ink: #122033; --od-muted: #66758b; --od-line: #e3e8ee;
        --od-bg: #f6f8f9; --od-surface: #fff; --od-amber: #a25e08; --od-amber-bg: #fff5df;
        color: var(--od-ink); background: var(--od-bg); padding: 24px; border-radius: 16px;
        font-size: 14px; line-height: 1.5; min-width: 0;
    }
    .dark .owner-dashboard {
        --od-ink: #edf4f0; --od-muted: #a4b4ac; --od-line: #35463e; --od-bg: #141e19;
        --od-surface: #1c2922; --od-green-dark: #8bdfaa; --od-green-bg: #203e2d;
        --od-amber: #f5c46c; --od-amber-bg: #49381d;
    }
    .owner-dashboard svg { width: 22px; height: 22px; flex-shrink: 0; }
    .owner-dashboard a, .owner-dashboard button { transition: background-color .15s; }
    .owner-dashboard :is(a, button):focus-visible { outline: 3px solid #88ca9c; outline-offset: 3px; }
    .owner-dashboard h1 { font-size: 29px; letter-spacing: -.8px; font-weight: 750; line-height: 1.2; margin-bottom: 6px; }
    .owner-dashboard h2 { font-size: 17px; letter-spacing: -.3px; font-weight: 700; }
    .od-top, .od-title-line, .od-controls, .od-stat-top, .od-panel-head, .od-due, .od-due-left, .od-toolbar, .od-footer, .od-holding { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
    .od-top { align-items: flex-start; margin-bottom: 26px; flex-wrap: wrap; }
    .od-title-line { justify-content: flex-start; }
    .od-kicker { color: var(--od-muted); font-size: 12px; margin-bottom: 10px; }
    .od-title-icon { width: 54px; height: 54px; border-radius: 15px; display: grid; place-items: center; background: var(--od-green-bg); color: var(--od-green-dark); flex-shrink: 0; }
    .od-title-icon svg { width: 28px; height: 28px; }
    .od-controls { flex-wrap: wrap; gap: 8px; }
    .od-context { padding: 10px 13px; background: var(--od-surface); border: 1px solid var(--od-line); border-radius: 10px; font-weight: 600; }
    .od-muted, .od-small, .od-panel-head p, .od-footer { color: var(--od-muted); }
    .od-small, .od-panel-head p, .od-footer { font-size: 12px; }
    .od-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 15px; margin-bottom: 18px; }
    .od-stat, .od-panel { background: var(--od-surface); border: 1px solid var(--od-line); border-radius: 15px; box-shadow: 0 5px 20px rgb(23 41 54 / 4.5%); min-width: 0; }
    .od-stat { padding: 19px 20px; min-height: 157px; }
    .od-stat-top { font-size: 13px; color: var(--od-muted); font-weight: 600; }
    .od-stat-icon { width: 35px; height: 35px; border-radius: 10px; display: grid; place-items: center; background: var(--od-bg); flex-shrink: 0; }
    .od-number { font-size: clamp(22px, 2vw, 29px); font-weight: 750; letter-spacing: -1px; margin: 17px 0 4px; line-height: 1.2; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .od-main { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(290px, 1fr); gap: 18px; align-items: start; margin-top: 18px; }
    .od-column { display: grid; gap: 18px; min-width: 0; }
    .od-panel { overflow: hidden; }
    .od-panel-head { padding: 20px 22px 16px; flex-wrap: wrap; }
    .od-panel-head p { margin-top: 3px; }
    .od-panel-body { padding: 0 22px 22px; }
    .od-link { color: var(--od-green-dark); font-size: 13px; font-weight: 700; cursor: pointer; }
    .od-link:hover { text-decoration: underline; }
    .od-due { padding: 16px 0; border-top: 1px solid var(--od-line); }
    .od-due:hover, .od-holding:hover { background: var(--od-bg); }
    .od-due-left { justify-content: flex-start; min-width: 0; overflow-wrap: anywhere; }
    .od-due-right { text-align: right; flex-shrink: 0; }
    .od-due-right .od-tag { display: table; margin-left: auto; }
    .od-date-tile { width: 49px; border: 1px solid var(--od-line); border-radius: 11px; text-align: center; overflow: hidden; flex-shrink: 0; }
    .od-date-tile span { display: block; background: var(--od-bg); color: var(--od-muted); font-size: 10px; text-transform: uppercase; line-height: 18px; }
    .od-date-tile strong { font-size: 20px; line-height: 29px; }
    .od-tag { display: inline-flex; border-radius: 30px; padding: 3px 8px; font-size: 11px; font-weight: 700; background: var(--od-bg); color: var(--od-muted); white-space: nowrap; }
    .od-success { color: var(--od-green-dark); background: var(--od-green-bg); }
    .od-warning { color: var(--od-amber); background: var(--od-amber-bg); }
    .od-danger { color: #bd3b38; background: #fff0ef; }
    .od-note { background: var(--od-green-bg); border: 1px solid var(--od-line); border-radius: 11px; padding: 12px 14px; color: var(--od-green-dark); font-size: 12px; margin-top: 12px; }
    .od-toolbar { flex-wrap: wrap; margin-bottom: 12px; gap: 8px; }
    .od-filters { display: flex; flex-wrap: wrap; gap: 4px; background: var(--od-bg); padding: 4px; border-radius: 9px; }
    .od-filters button { padding: 6px 10px; border-radius: 7px; color: var(--od-muted); font-size: 12px; cursor: pointer; }
    .od-filters button[aria-pressed="true"] { background: var(--od-surface); color: var(--od-ink); box-shadow: 0 1px 4px rgb(23 41 54 / 10%); }
    .od-table-scroll { overflow-x: auto; }
    .od-table { width: 100%; border-collapse: collapse; text-align: left; min-width: 510px; }
    .od-table th { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--od-muted); background: var(--od-bg); padding: 11px 12px; }
    .od-table td { border-bottom: 1px solid var(--od-line); padding: 14px 12px; font-size: 13px; }
    .od-table .od-sum { text-align: right; font-weight: 700; white-space: nowrap; }
    .od-date { white-space: nowrap; color: var(--od-muted); }
    .od-empty { padding: 24px 12px; text-align: center; color: var(--od-muted); }
    .od-footer { margin-top: 16px; flex-wrap: wrap; }
    .od-holdings { display: grid; gap: 12px; }
    .od-holding { border: 1px solid var(--od-line); border-radius: 11px; padding: 15px; overflow-wrap: anywhere; }
    .od-holding b { font-size: 18px; flex-shrink: 0; }
    .od-quick { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .od-quick a { display: flex; align-items: center; gap: 10px; border: 1px solid var(--od-line); border-radius: 11px; padding: 13px; font-weight: 600; font-size: 13px; }
    .od-quick a:hover { background: var(--od-green-bg); }
    .od-quick a span { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 10px; background: var(--od-green-bg); color: var(--od-green-dark); flex-shrink: 0; }
    @media (max-width: 1200px) { .od-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .od-main { grid-template-columns: 1fr; } }
    @media (max-width: 650px) {
        .owner-dashboard { padding: 14px; }
        .owner-dashboard h1 { font-size: 24px; }
        .od-controls { font-size: 12px; }
        .od-stats, .od-main, .od-column { gap: 10px; }
        .od-stat { padding: 14px; min-height: 137px; }
        .od-panel-head { padding: 17px 16px 13px; }
        .od-panel-body { padding: 0 16px 17px; }
        .od-due { align-items: flex-start; flex-wrap: wrap; }
        .od-due-right { margin-left: auto; }
        .od-quick { grid-template-columns: 1fr; }
    }
</style>
