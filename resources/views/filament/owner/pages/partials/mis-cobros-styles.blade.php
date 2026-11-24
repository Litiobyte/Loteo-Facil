<style>
    .owner-charges {
        --oc-ink: #172535; --oc-muted: #798799; --oc-white: #fff; --oc-bg: #f5f7f8;
        --oc-line: #e4eaeb; --oc-green: #178044; --oc-soft: #eff7f2; --oc-red: #b84c36; --oc-red-bg: #fff0e9;
        color: var(--oc-ink); font-family: Inter, ui-sans-serif, system-ui, sans-serif;
        font-size: 14px; line-height: 1.5; padding: 26px; min-width: 0;
    }
    .dark .owner-charges { --oc-ink: #edf4f0; --oc-muted: #a4b4ac; --oc-white: #1c2922; --oc-bg: #141e19; --oc-line: #35463e; --oc-green: #8bdfaa; --oc-soft: #203e2d; --oc-red: #ffaaa7; --oc-red-bg: #492629; }
    .owner-charges :is(button, a, input):focus-visible { outline: 3px solid #8acdad; outline-offset: 2px; }
    .owner-charges button { cursor: pointer; }
    .owner-charges svg { width: 18px; height: 18px; flex-shrink: 0; }
    .oc-crumb { font-size: 12px; color: var(--oc-muted); margin-bottom: 9px; }
    .oc-heading, .oc-metric-top, .oc-panel-head, .oc-footer { display: flex; justify-content: space-between; align-items: center; gap: 20px; }
    .oc-heading { margin-bottom: 27px; }
    .owner-charges h1 { font-size: 29px; letter-spacing: -1.1px; font-weight: 750; margin-bottom: 6px; }
    .owner-charges h2 { font-size: 17px; letter-spacing: -.4px; font-weight: 700; }
    .oc-muted { color: var(--oc-muted); }
    .oc-button { display: inline-flex; justify-content: center; align-items: center; gap: 8px; border: 1px solid var(--oc-line); border-radius: 9px; background: var(--oc-white); padding: 10px 14px; color: var(--oc-ink); font-size: 13px; font-weight: 650; white-space: nowrap; }
    .oc-button:hover { background: var(--oc-soft); border-color: var(--oc-green); }
    .oc-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 15px; margin-bottom: 20px; }
    .oc-metric { background: var(--oc-white); border: 1px solid var(--oc-line); border-radius: 14px; padding: 19px 20px; min-height: 138px; box-shadow: 0 4px 17px rgb(30 55 46 / 2.5%); }
    .oc-metric-top { color: var(--oc-muted); font-size: 13px; font-weight: 600; }
    .oc-symbol { width: 34px; height: 34px; display: grid; place-items: center; border-radius: 10px; background: var(--oc-soft); color: var(--oc-green); flex-shrink: 0; }
    .oc-warning .oc-symbol { background: var(--oc-red-bg); color: var(--oc-red); }
    .oc-metric strong { display: block; font-size: 30px; letter-spacing: -1px; margin-top: 12px; line-height: 1.15; overflow-wrap: anywhere; }
    .oc-metric small { display: block; color: var(--oc-muted); margin-top: 8px; font-size: 12px; }
    .oc-warning small { color: var(--oc-red); }
    .oc-note { display: flex; gap: 12px; align-items: flex-start; background: var(--oc-soft); border: 1px solid var(--oc-line); border-radius: 12px; padding: 15px 18px; margin-bottom: 20px; font-size: 13px; }
    .oc-note svg { color: var(--oc-green); margin-top: 2px; }
    .oc-panel { border: 1px solid var(--oc-line); border-radius: 14px; background: var(--oc-white); overflow: hidden; min-width: 0; }
    .oc-panel-head { padding: 21px 20px 16px; }
    .oc-panel-head p, .oc-panel-head > span { font-size: 12px; margin-top: 4px; }
    .oc-tools { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 20px 15px; }
    .oc-tools button { font-size: 12px; font-weight: 650; border-radius: 8px; padding: 8px 14px; color: var(--oc-muted); }
    .oc-tools button[aria-pressed="true"] { background: var(--oc-soft); color: var(--oc-green); }
    .owner-charges .fi-ta { border: 0; box-shadow: none; border-radius: 0; background: var(--oc-white); }
    .owner-charges .fi-ta-content { padding: 0; }
    .oc-list { width: 100%; }
    .oc-list-head, .oc-charge { display: grid; grid-template-columns: minmax(160px, 2.4fr) minmax(95px, 1fr) repeat(3, minmax(85px, 1fr)) minmax(115px, 1fr) 104px; align-items: center; column-gap: 15px; }
    .oc-list-head { padding: 12px 20px; background: var(--oc-bg); border-block: 1px solid var(--oc-line); color: var(--oc-muted); font-size: 11px; font-weight: 700; }
    .oc-list-head span:nth-child(n+3):nth-child(-n+5) { text-align: right; }
    .oc-charge { padding: 12px 20px; min-height: 70px; border-bottom: 1px solid var(--oc-line); font-size: 12px; }
    .oc-concept { min-width: 0; overflow-wrap: anywhere; }
    .oc-concept h3 { font-size: 13px; font-weight: 650; line-height: 1.35; margin-bottom: 4px; }
    .oc-concept p { font-size: 11px; }
    .oc-money { text-align: right; font-weight: 650; font-size: 13px; font-variant-numeric: tabular-nums; }
    .oc-danger { color: var(--oc-red); }
    .oc-mobile-label { display: none; }
    .oc-pill { display: inline-block; border-radius: 20px; padding: 5px 8px; background: var(--oc-soft); color: var(--oc-green); font-size: 10px; font-weight: 650; }
    .oc-pill-due { background: var(--oc-red-bg); color: var(--oc-red); }
    .oc-charge .oc-button { padding: 8px 10px; font-size: 12px; }
    .oc-footer { padding: 14px 20px; color: var(--oc-muted); font-size: 11px; flex-wrap: wrap; }
    @media (max-width: 1200px) and (min-width: 761px) {
        .oc-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .oc-list-head, .oc-charge { grid-template-columns: minmax(120px, 2fr) 80px repeat(3, minmax(65px, 1fr)) 90px 84px; gap: 8px; padding-inline: 13px; }
        .oc-money, .oc-concept h3 { font-size: 12px; } .oc-charge .oc-button { font-size: 11px; padding-inline: 5px; }
        .owner-charges { padding: 10px; }
    }
    @media (max-width: 760px) {
        .owner-charges { padding: 0; } .oc-heading { flex-wrap: wrap; gap: 13px; } .owner-charges h1 { font-size: 26px; }
        .oc-metrics { grid-template-columns: 1fr; gap: 10px; } .oc-metric { min-height: 0; padding: 16px; }
        .oc-panel-head { flex-wrap: wrap; gap: 6px; padding: 17px 15px; } .oc-tools { padding-inline: 15px; }
        .oc-list-head { display: none; }
        .oc-charge { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 11px 8px; padding: 14px 15px; }
        .oc-concept { grid-column: 1 / -2; grid-row: 1; } .oc-status { grid-column: 3; grid-row: 1; text-align: right; align-self: start; }
        .oc-due { grid-column: 1 / -1; grid-row: 2; font-size: 11px; color: var(--oc-muted); }
        .oc-mobile-label { display: inline; } .oc-money { text-align: left; grid-row: 3; font-size: 13px; overflow-wrap: anywhere; }
        .oc-money .oc-mobile-label { display: block; font-size: 10px; font-weight: 450; color: var(--oc-muted); margin-bottom: 3px; }
        .oc-charge .oc-button { grid-column: 1 / -1; grid-row: 4; }
    }
</style>
