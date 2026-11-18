@once
    <style>
        .lote-detail-layout { display: grid; gap: 1.5rem; min-width: 0; }
        .lote-detail-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; margin: 0; }
        .lote-detail-summary.lote-detail-summary-single { grid-template-columns: minmax(0, 1fr); }
        .lote-detail-summary dt, .lote-detail-muted { color: var(--lf-text-muted, #6b7280); font-size: .875rem; line-height: 1.5; }
        .lote-detail-summary dd { margin: .5rem 0 0; font-size: 1rem; font-weight: 600; overflow-wrap: anywhere; }
        .lote-detail-summary dd .fi-badge { width: fit-content; }
        .lote-detail-columns { display: grid; gap: 1.5rem; min-width: 0; align-items: start; }
        .lote-detail-columns > * { min-width: 0; }
        .lote-detail-grid { display: grid; gap: 1.5rem; min-width: 0; align-items: start; }
        .lote-detail-grid > * { min-width: 0; }
        .lote-detail-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--lf-border, #e5e7eb); }
        .lote-detail-owners { display: grid; gap: 1rem; margin-top: 1.5rem; }
        .lote-detail-owner { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; border: 1px solid var(--lf-border, #e5e7eb); border-radius: var(--lf-radius-lg, .75rem); padding: 1rem; }
        .lote-detail-owner > div { min-width: 0; overflow-wrap: anywhere; }
        .lote-detail-owner p { margin-top: .5rem; }
        .lote-detail-empty { display: grid; justify-items: center; gap: .75rem; padding: 2.5rem 1.5rem; text-align: center; border: 1px dashed #d1d5db; border-radius: var(--lf-radius-lg, .75rem); }
        .lote-detail-map { min-width: 0; overflow: hidden; }
        .lote-detail-map [x-ref="map"] { height: clamp(320px, 50vh, 480px) !important; }
        .lote-detail-map [x-ref="map"]:fullscreen,
        .lote-detail-map [x-ref="map"].maplibregl-pseudo-fullscreen { height: 100% !important; width: 100% !important; border-radius: 0 !important; }
        .lote-detail-legend { display: flex; align-items: center; gap: .5rem; margin-top: 1rem; }
        .lote-detail-legend::before { content: ''; width: 1rem; height: .75rem; border: 3px solid #f97316; border-radius: .2rem; background: #ffedd5; flex-shrink: 0; }
        .lote-detail-notes { white-space: pre-line; overflow-wrap: anywhere; line-height: 1.75; }
        .dark .lote-detail-muted, .dark .lote-detail-summary dt { color: #9ca3af; }
        .dark .lote-detail-owner, .dark .lote-detail-actions { border-color: #374151; }
        .dark .lote-detail-empty { border-color: #4b5563; }
        @media (min-width: 1024px) { .lote-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    </style>
@endonce
