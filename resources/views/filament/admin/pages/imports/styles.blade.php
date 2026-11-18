{{-- Scoped to imports: the panel uses Filament's compiled CSS, not app.css. --}}
<style>
    .lf-import {
        --import-border: var(--lf-border);
        --import-surface: var(--lf-surface);
        --import-subtle: var(--lf-bg);
        --import-muted: var(--lf-text-muted);
        width: 100%;
        min-width: 0;
        max-width: none;
        grid-template-columns: minmax(0, 1fr);
        gap: 1.5rem;
    }
    :is(.lf-import, .lf-import-history) :is(form, .grid, .grid > *, .fi-section, .fi-section-content-ctn, .fi-section-content) { min-width: 0; }
    .lf-import-table-scroll { min-width: 0; max-width: 100%; overflow-x: auto; }
    .lf-import-table-scroll:focus-visible { outline: 2px solid var(--lf-primary); outline-offset: -2px; }
    .lf-import .lf-import-sample-table { width: max-content; min-width: 100%; }
    .lf-import .lf-import-sample-table :is(th, td) { min-width: 10rem; }
    .lf-import .lf-import-intro-icon > svg { width: 1.75rem; height: 1.75rem; }
    .lf-import .lf-import-upload-icon > svg { width: 2rem; height: 2rem; }
    .lf-import .lf-import-intro { display: flex; align-items: center; gap: 1rem; }
    .lf-import .lf-import-intro-icon,
    .lf-import .lf-import-upload-icon {
        display: grid; place-items: center; width: 3.5rem; height: 3.5rem;
        flex-shrink: 0; border-radius: .875rem; background: var(--lf-surface-subtle); color: var(--lf-primary);
    }
    .lf-import .lf-import-stepper {
        --stepper-circle-size: 3rem;
        --stepper-text: var(--lf-text);
        --stepper-track: var(--import-border);
        padding: .5rem 0;
    }
    .lf-import .lf-import-stepper-content { display: grid; gap: 1.25rem; }
    .lf-import .lf-import-stepper-summary { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
    .lf-import .lf-import-stepper-percentage { color: var(--import-muted); font-size: .875rem; font-weight: 500; }
    .lf-import .lf-import-stepper-track { position: relative; isolation: isolate; }
    .lf-import .lf-import-stepper-line,
    .lf-import .lf-import-stepper-progress {
        position: absolute; z-index: 0; top: calc(var(--stepper-circle-size) / 2); left: 12.5%;
        height: 3px; border-radius: 999px; transform: translateY(-50%);
    }
    .lf-import .lf-import-stepper-line { right: 12.5%; background: var(--stepper-track); }
    .lf-import .lf-import-stepper-progress { background: var(--lf-primary); }
    .lf-import .lf-import-stepper-steps {
        position: relative; z-index: 1; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0; margin: 0; padding: 0; list-style: none;
    }
    .lf-import .lf-import-stepper-step { display: flex; min-width: 0; flex-direction: column; align-items: center; gap: .625rem; text-align: center; }
    .lf-import .lf-import-stepper-circle {
        box-sizing: border-box; display: grid; place-items: center; flex-shrink: 0;
        width: var(--stepper-circle-size); height: var(--stepper-circle-size);
        border: 2px solid var(--import-border); border-radius: 50%; background: var(--import-surface);
        color: var(--import-muted); font-size: 1.125rem; font-weight: 700; line-height: 1;
    }
    .lf-import .lf-import-stepper-step[data-step-state="active"] .lf-import-stepper-circle,
    .lf-import .lf-import-stepper-step[data-step-state="completed"] .lf-import-stepper-circle {
        border-color: var(--lf-primary); background: var(--lf-primary); color: var(--lf-white);
    }
    .lf-import .lf-import-stepper-step[data-step-state="active"] .lf-import-stepper-circle { box-shadow: 0 0 0 4px var(--import-subtle); }
    .lf-import .lf-import-stepper-check { width: 1.5rem; height: 1.5rem; }
    .lf-import .lf-import-stepper-caption { display: grid; gap: .25rem; padding: 0 .25rem; overflow-wrap: anywhere; }
    .lf-import .lf-import-stepper-label { color: var(--stepper-text); font-size: .875rem; font-weight: 600; line-height: 1.25rem; }
    .lf-import .lf-import-stepper-description { color: var(--import-muted); font-size: .75rem; line-height: 1rem; }
    .lf-import .lf-import-stepper-sr-only {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
    }
    .dark .lf-import .lf-import-stepper { --stepper-text: var(--color-gray-200); }
    @media (max-width: 639px) {
        .lf-import .lf-import-stepper { --stepper-circle-size: 2.5rem; }
        .lf-import .lf-import-stepper-label { font-size: .75rem; line-height: 1rem; }
        .lf-import .lf-import-stepper-description { font-size: .6875rem; }
        .lf-import .lf-import-stepper-check { width: 1.25rem; height: 1.25rem; }
    }
    .lf-import .lf-import-panel {
        min-width: 0; overflow: hidden; border: 1px solid var(--import-border);
        border-radius: 1rem; background: var(--import-surface); box-shadow: var(--lf-shadow-card);
    }
    .lf-import .lf-import-panel > .fi-callout { margin: 1.5rem 1.5rem 0; }
    .lf-import .lf-import-panel > .fi-section,
    .lf-import .lf-import-panel > form > .fi-section { border-radius: 0; box-shadow: none; }
    .lf-import .lf-import-panel > form { gap: 0; }
    .lf-import .lf-import-considerations summary { cursor: pointer; font-size: .8125rem; font-weight: 600; }
    .lf-import .lf-import-considerations[open] summary { margin-bottom: .75rem; }
    .lf-import .lf-import-upload {
        display: grid; justify-items: center; gap: .75rem; padding: 2rem 1rem; cursor: pointer;
        border: 2px dashed var(--import-border); border-radius: .875rem; background: var(--import-subtle); text-align: center;
    }
    .lf-import .lf-import-upload:focus-within { border-color: var(--lf-primary); outline: 2px solid var(--lf-primary); outline-offset: 3px; }
    .lf-import .lf-import-upload.lf-import-upload-dragover { border-color: var(--lf-primary); outline: 2px solid var(--lf-primary); outline-offset: 3px; background: color-mix(in srgb, var(--lf-primary) 8%, var(--import-surface)); }
    .lf-import .lf-import-upload > .fi-input-wrp { width: min(100%, 30rem); text-align: left; }
    .lf-import .lf-import-upload-loaded .fi-input-wrp-content-ctn {
        display: flex; align-items: center; color: var(--color-success-700); font-weight: 700;
    }
    .lf-import .lf-import-upload-loaded .fi-input-wrp-content-ctn::before { content: '✓' / ''; flex: none; padding-inline-start: .75rem; }
    .lf-import .lf-import-upload-loaded .fi-input-wrp-content-ctn > input[type="file"] { min-width: 0; color: inherit; font-weight: inherit; }
    .dark .lf-import .lf-import-upload-loaded .fi-input-wrp-content-ctn { color: var(--color-success-400); }
    .lf-import .lf-import-file { display: flex; flex-wrap: wrap; align-items: center; gap: .875rem; padding: 1rem; border: 1px solid var(--import-border); border-radius: .75rem; background: var(--import-subtle); }
    .lf-import .lf-import-file > div { flex: 1; }
    .lf-import .lf-import-mapping-head,
    .lf-import .lf-import-mapping-row { display: grid; grid-template-columns: minmax(0, 1fr) 1.25rem minmax(0, 1fr) 7.5rem; align-items: center; gap: 1rem; }
    .lf-import .lf-import-mapping-head { padding: 0 1rem; color: var(--import-muted); font-size: .75rem; font-weight: 600; }
    .lf-import .lf-import-mapping-row { padding: 1rem; border: 1px solid var(--import-border); border-radius: .75rem; }
    .lf-import .lf-import-mapping-row > .fi-badge { justify-self: start; }
    .lf-import .lf-import-mapping-summary { padding: 1rem; border: 1px solid var(--import-border); border-radius: .75rem; background: var(--import-subtle); font-size: .8125rem; line-height: 1.6; }
    .lf-import .lf-import-preview { display: grid; gap: 1.25rem; padding: 1.5rem; min-width: 0; }
    .lf-import .lf-import-preview-card { min-width: 0; overflow: hidden; border: 1px solid var(--import-border); border-radius: .875rem; background: var(--import-surface); box-shadow: var(--lf-shadow-card); }
    .lf-import .lf-import-preview-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; padding: 1.25rem; border-bottom: 1px solid var(--import-border); }
    .lf-import .lf-import-preview-header > div { flex: 1 1 20rem; min-width: 0; }
    .lf-import .lf-import-preview-header h2 { display: flex; align-items: center; gap: .625rem; font-size: 1rem; font-weight: 700; color: var(--lf-text); }
    .lf-import .lf-import-preview-header h2 > svg,
    .lf-import .lf-import-preview-filename > svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; color: var(--lf-primary); }
    .lf-import .lf-import-preview-header p,
    .lf-import .lf-import-preview-operations,
    .lf-import .lf-import-preview-pagination,
    .lf-import .lf-import-preview-footer p { color: var(--import-muted); font-size: .8125rem; line-height: 1.6; }
    .lf-import .lf-import-preview-header p { margin-top: .375rem; }
    .lf-import .lf-import-preview-filename { display: flex; align-items: center; gap: .5rem; max-width: 100%; overflow-wrap: anywhere; padding: .5rem .75rem; border: 1px solid var(--import-border); border-radius: .5rem; background: var(--import-subtle); font-size: .75rem; color: var(--import-muted); }
    .lf-import .lf-import-preview-body { display: grid; gap: 1rem; padding: 1.25rem; }
    .lf-import .lf-import-preview-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .875rem; }
    .lf-import .lf-import-preview-stat { position: relative; display: grid; gap: .5rem; padding: 1rem; border: 1px solid var(--import-border); border-radius: .75rem; background: var(--import-subtle); }
    .lf-import .lf-import-preview-stat > svg { width: 1.375rem; height: 1.375rem; }
    .lf-import .lf-import-preview-stat dt { font-size: .75rem; color: var(--lf-text); }
    .lf-import .lf-import-preview-stat dd { font-size: 1.875rem; line-height: 1.2; font-weight: 750; }
    .lf-import .lf-import-preview-stat[data-validation-state="total"] { --import-stat-color: var(--color-primary-500); color: var(--color-primary-700); }
    .lf-import .lf-import-preview-stat[data-validation-state="valid"] { --import-stat-color: var(--color-success-500); color: var(--color-success-700); }
    .lf-import .lf-import-preview-stat[data-validation-state="error"] { --import-stat-color: var(--color-danger-500); color: var(--color-danger-700); }
    .lf-import .lf-import-preview-stat[data-validation-state="warning"] { --import-stat-color: var(--color-warning-500); color: var(--color-warning-800); }
    .lf-import .lf-import-preview-stat[data-validation-state] { background: color-mix(in srgb, var(--import-stat-color) 20%, var(--import-surface)); border-color: color-mix(in srgb, var(--import-stat-color) 45%, var(--import-border)); }
    .lf-import .lf-import-preview-filters { display: flex; flex-wrap: wrap; gap: 1rem; padding: 1.25rem; border-bottom: 1px solid var(--import-border); }
    .lf-import .lf-import-preview-filters > div { display: grid; flex: 1 1 15rem; min-width: 0; gap: .5rem; }
    .lf-import .lf-import-preview-filters label { font-size: .8125rem; font-weight: 600; color: var(--lf-text); }
    .lf-import .lf-import-preview-filters > p { flex-basis: 100%; font-size: .75rem; color: var(--import-muted); }
    .lf-import .lf-import-preview-empty { padding: 1.25rem; color: var(--import-muted); }
    .lf-import .lf-import-preview-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
    .lf-import .lf-import-preview-field { display: grid; gap: .5rem; font-size: .875rem; font-weight: 500; }
    .lf-import .lf-import-preview-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
    .lf-import .lf-import-preview-pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; padding: .875rem 1.375rem; border-top: 1px solid var(--import-border); font-size: .75rem; }
    .lf-import .lf-import-preview-pagination > p { margin: 0; }
    .lf-import .lf-import-preview-pages { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; }
    .lf-import .lf-import-preview-pages > button {
        min-width: 2.125rem; padding: .375rem .6875rem; border: 1px solid var(--import-border);
        border-radius: .375rem; background: var(--import-surface); color: var(--import-muted);
        font: inherit; line-height: 1.5; cursor: pointer;
    }
    .lf-import .lf-import-preview-pages > button:hover:not(:disabled):not([aria-current]) { background: var(--import-subtle); color: var(--lf-primary); }
    .lf-import .lf-import-preview-pages > button[aria-current="page"] { background: var(--lf-primary); border-color: var(--lf-primary); color: var(--lf-white); }
    .lf-import .lf-import-preview-pages > button:disabled { opacity: .4; cursor: default; }
    .lf-import .lf-import-preview-pages > button:focus-visible { outline: 2px solid var(--lf-primary); outline-offset: 2px; }
    .lf-import .lf-import-preview-page-gap { padding: .375rem .125rem; }
    .lf-import .lf-import-preview-footer { display: grid; gap: 1rem; padding: 1.25rem; }
    .lf-import[data-import-step="4"] .lf-import-preview-confirmation { display: grid; gap: 1.25rem; }
    .lf-import[data-import-step="4"] .lf-import-execution-confirmed { display: flex; align-items: flex-start; gap: .75rem; padding: 1rem; border: 1px solid var(--import-border); border-radius: .75rem; }
    .lf-import .lf-import-preview-footer .lf-import-preview-actions { justify-content: flex-end; }
    .lf-import .lf-import-preview-help { color: var(--import-muted); font-size: .75rem; text-align: right; }
    .lf-import .lf-import-preview-rows { min-width: 0; }
    .lf-import .lf-import-preview-rows > div { border: 0; border-radius: 0; }
    .lf-import .lf-import-preview-rows table { width: 100%; border-collapse: collapse; font-size: .8125rem; background: var(--import-surface); color: var(--lf-text); }
    .lf-import .lf-import-preview-rows caption { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
    .lf-import .lf-import-preview-rows thead { background: var(--import-subtle); }
    .lf-import .lf-import-preview-rows th,
    .lf-import .lf-import-preview-rows td { padding: 1rem; text-align: left; vertical-align: top; border-bottom: 1px solid var(--import-border); overflow-wrap: anywhere; }
    .lf-import .lf-import-preview-rows thead th { color: var(--import-muted); font-size: .6875rem; text-transform: uppercase; letter-spacing: .04em; }
    .lf-import .lf-import-preview-rows td > div { display: grid; gap: .375rem; }
    .lf-import .lf-import-preview-rows td ul { display: grid; gap: .5rem; }
    .lf-import .lf-import-preview-rows td li,
    .lf-import .lf-import-preview-rows td > span { display: flex; align-items: flex-start; gap: .5rem; }
    .lf-import .lf-import-preview-rows td li { color: var(--color-danger-600); }
    .lf-import .lf-import-preview-rows td li svg,
    .lf-import .lf-import-preview-rows td > span > svg { width: 1rem; height: 1rem; flex-shrink: 0; }
    .lf-import .lf-import-preview-rows td > button { margin-top: .75rem; }
    .lf-import-preview .lf-import-preview-table { min-width: 56rem; width: max-content; }
    .lf-import-preview .lf-import-preview-table [data-import-field] { min-width: 5rem; max-width: 22rem; }
    .lf-import-preview .lf-import-preview-table .lf-import-preview-conflicts { min-width: 18rem; max-width: 28rem; }
    .lf-import .lf-import-preview-table td > .lf-import-preview-value {
        display: block; width: 18rem; max-width: 18rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .lf-import-preview .lf-import-preview-table .lf-import-preview-before { color: var(--import-muted); }
    .lf-import-preview .lf-import-preview-table tr[data-editing] { background: var(--import-subtle); }
    .lf-import-preview .lf-import-preview-table tr[data-editing] .fi-input-wrp { min-width: 12rem; }
    .lf-import-preview .lf-import-preview-table .lf-import-row-actions { margin-top: .75rem; }
    .lf-import-preview .lf-import-preview-table .lf-import-row-actions p { font-size: .75rem; color: var(--import-muted); }
    .lf-import-preview .lf-import-preview-table-scroll:focus-visible { outline: 2px solid var(--lf-primary); outline-offset: -2px; }
    .dark .lf-import .lf-import-preview-stat[data-validation-state] { background: color-mix(in srgb, var(--import-stat-color) 25%, var(--import-surface)); }
    .dark .lf-import .lf-import-preview-stat[data-validation-state="total"] { color: var(--color-primary-300); }
    .dark .lf-import .lf-import-preview-stat[data-validation-state="valid"] { color: var(--color-success-300); }
    .dark .lf-import .lf-import-preview-stat[data-validation-state="error"] { color: var(--color-danger-300); }
    .dark .lf-import .lf-import-preview-rows td li { color: var(--color-danger-400); }
    .dark .lf-import .lf-import-preview-stat[data-validation-state="warning"] { color: var(--color-warning-300); }
    @media (max-width: 900px) {
        .lf-import .lf-import-preview-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 639px) {
        .lf-import .lf-import-preview { padding: 1rem; }
        .lf-import .lf-import-preview-header,
        .lf-import .lf-import-preview-body,
        .lf-import .lf-import-preview-footer { padding: 1rem; }
        .lf-import .lf-import-preview-fields { grid-template-columns: minmax(0, 1fr); }
        .lf-import .lf-import-preview-pagination { padding: .8125rem; }
        .lf-import .lf-import-preview-actions { align-items: stretch; flex-direction: column; }
        .lf-import .lf-import-preview-stat { padding: .75rem; }
    }
    .dark .lf-import {
        --import-border: rgb(255 255 255 / 12%);
        --import-surface: var(--color-gray-900);
        --import-subtle: rgb(255 255 255 / 4%);
        --import-muted: var(--color-gray-400);
    }
    .dark .lf-import .lf-import-intro-icon,
    .dark .lf-import .lf-import-upload-icon { background: rgb(255 255 255 / 6%); color: var(--color-primary-400); }
    @media (max-width: 767px) {
        .lf-import .lf-import-mapping-head,
        .lf-import .lf-import-mapping-arrow { display: none; }
        .lf-import .lf-import-mapping-row { grid-template-columns: minmax(0, 1fr); gap: .75rem; }
        .lf-import .lf-import-panel > .fi-callout { margin: 1rem 1rem 0; }
        .lf-import .lf-import-upload { padding: 1.5rem .75rem; }
    }
</style>
