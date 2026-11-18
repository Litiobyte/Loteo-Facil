@once
    <style>
        .map-import-page { display: grid; gap: 1.5rem; min-width: 0; }
        .map-import-toolbar, .map-import-upload, .map-layer-options, .map-review-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .map-import-toolbar > div { flex: 1 1 15rem; min-width: 0; }
        .map-import-upload { flex: 2 1 20rem; align-items: end; min-width: 0; }
        .map-import-upload > div { flex: 1 1 12rem; min-width: 0; }
        .map-file-input { width: 100%; max-width: 100%; font-size: .875rem; padding-block: .5rem; }
        .map-field-label { display: block; margin-block: .35rem; font-size: .75rem; font-weight: 500; }
        .map-workspace { display: grid; gap: 1.5rem; min-width: 0; }
        .map-workspace-map, .map-workspace-sidebar { min-width: 0; }
        .map-workspace-map .fi-section-content > [wire\:key] { display: flex; flex-direction: column; min-height: 0; height: 100%; }
        .map-workspace-map [x-ref="map"] { height: 55dvh !important; min-height: 20rem; }
        .map-workspace-map .fi-section-content > [wire\:key] > :first-child { max-height: 8rem; overflow-y: auto; flex-shrink: 0; font-size: .875rem; }
        .map-workspace-sidebar { display: flex; flex-direction: column; gap: 1rem; min-height: 0; }
        .map-workspace-sidebar > .fi-tabs { flex-shrink: 0; }
        .map-panel-scroll { min-width: 0; min-height: 0; }
        .map-selected-card { margin-bottom: 1rem; }
        .map-feature-details { display: grid; gap: .5rem; margin-block: .75rem; font-size: .875rem; }
        .map-feature-details dt { color: var(--lf-text-muted, #6b7280); }
        .map-feature-details dd { overflow-wrap: anywhere; }
        .map-panel-caption { margin-bottom: .75rem; overflow-wrap: anywhere; font-size: .875rem; }
        .map-review-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(5rem, 1fr)); gap: .5rem; margin-bottom: 1rem; }
        .map-stat-card, .map-layer-card { border: 1px solid var(--lf-border, #e5e7eb); border-radius: var(--lf-radius-md, .5rem); padding: .75rem; }
        .map-stat-card strong, .map-stat-card span { display: block; }
        .map-stat-card strong { font-size: 1.125rem; }
        .map-stat-card span { font-size: .75rem; color: var(--lf-text-muted, #6b7280); }
        .map-review-actions { margin-block: .75rem 1rem; }
        .map-review-table-wrap { overflow-x: auto; border: 1px solid var(--lf-border, #e5e7eb); border-radius: var(--lf-radius-md, .5rem); }
        .map-review-table { width: 100%; table-layout: fixed; text-align: left; font-size: .875rem; border-collapse: collapse; }
        .map-review-table th, .map-review-table td { padding: .75rem; vertical-align: top; overflow-wrap: anywhere; }
        .map-review-table th { background: #f9fafb; font-size: .75rem; font-weight: 600; }
        .map-review-table th:first-child { width: 42%; }
        .map-review-table tbody tr { border-top: 1px solid var(--lf-border, #e5e7eb); }
        .map-review-table .fi-badge { margin-top: .5rem; width: fit-content; max-width: 100%; }
        .map-review-table .map-row-selected { background: var(--lf-surface-subtle, #fffbeb); }
        .map-element-link { display: block; color: var(--primary-600, #d97706); text-align: left; text-decoration: underline; font-weight: 600; overflow-wrap: anywhere; }
        .map-checkbox-label { display: flex; align-items: center; gap: .5rem; margin-top: .75rem; font-size: .875rem; }
        .map-layer-list, .map-history-list { display: grid; gap: .75rem; }
        .map-layer-options { margin-top: .75rem; font-size: .875rem; }
        .map-layer-options label { display: flex; align-items: center; gap: .5rem; }
        .map-layer-options input[type="number"] { width: 5rem; border: 1px solid #9ca3af; border-radius: .5rem; padding: .35rem; }
        .dark .map-stat-card, .dark .map-layer-card, .dark .map-review-table-wrap, .dark .map-review-table tbody tr { border-color: #374151; }
        .dark .map-review-table th { background: #1f2937; }
        .dark .map-review-table .map-row-selected { background: #422006; }
        .dark .map-stat-card span, .dark .map-feature-details dt { color: #9ca3af; }
        @media (min-width: 1024px) {
            .map-workspace { grid-template-columns: minmax(0, 1.25fr) minmax(24rem, 1fr); height: var(--map-workspace-height, max(20rem, calc(100dvh - 22rem))); }
            .map-workspace-map { display: flex; flex-direction: column; min-height: 0; }
            .map-workspace-map > .fi-section-content-ctn, .map-workspace-map .fi-section-content { display: flex; flex-direction: column; flex: 1; min-height: 0; }
            .map-workspace-map [x-ref="map"] { flex: 1; height: 100% !important; min-height: 0; }
            .map-panel-scroll { flex: 1; overflow-y: auto; overscroll-behavior-y: contain; scrollbar-gutter: stable; padding: .125rem .25rem .25rem .125rem; }
        }
    </style>
@endonce
