@use(Illuminate\Support\Number)

<x-filament-panels::page>
    @include('filament.owner.pages.partials.mis-cobros-styles')

    <div class="owner-charges">
        <p class="oc-crumb">Mi comunidad › Finanzas › Mis cobros</p>
        <header class="oc-heading">
            <div><h1>Mis cobros</h1><p class="oc-muted">Revisa tus obligaciones, vencimientos y montos pendientes.</p></div>
            <button type="button" class="oc-button" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                <x-filament::icon icon="heroicon-o-arrow-down-tray" /> Exportar CSV
            </button>
        </header>

        <section class="oc-metrics" aria-label="Resumen de cobros">
            @foreach ([
                ['Saldo pendiente', $pendingBalance, $pendingCount.' cobros con saldo por pagar', 'heroicon-o-exclamation-circle'],
                ['Total emitido', $issuedTotal, 'Incluye cobros consolidados del historial', 'heroicon-o-document-text'],
                ['Pagado con recaudaciones', $paidTotal, 'Recaudaciones aplicadas a cobros · sin ajustes', 'heroicon-o-check'],
                ['Aplicado como ajuste no monetario', $nonMonetaryTotal, 'No representa dinero recibido ni ingreso de caja', 'heroicon-o-document-check'],
            ] as [$label, $value, $hint, $icon])
                <article class="oc-metric {{ $loop->first ? 'oc-warning' : '' }}">
                    <div class="oc-metric-top"><span>{{ $label }}</span><span class="oc-symbol"><x-filament::icon :icon="$icon" /></span></div>
                    <strong>{{ Number::currency($value, 'CLP', locale: 'es_CL') }}</strong><small>{{ $hint }}</small>
                </article>
            @endforeach
        </section>

        @if ($hasConsolidated)
            <div class="oc-note"><x-filament::icon icon="heroicon-o-information-circle" /><p><strong>Sobre tus cobros anteriores:</strong> algunos figuran como <strong>consolidados</strong>. Esto significa que su saldo fue trasladado o agrupado en otro registro; no significa necesariamente que hayan sido pagados.</p></div>
        @endif

        <section class="oc-panel" aria-label="Listado de cobros">
            <div class="oc-panel-head"><div><h2>Detalle de mis cobros</h2><p class="oc-muted">Consulta el estado y los importes de cada obligación.</p></div><span class="oc-muted">{{ $chargeCount }} cobros registrados</span></div>
            <div class="oc-tools" aria-label="Filtrar cobros">
                @foreach (['all' => 'Todos', 'pending' => 'Pendientes', 'consolidated' => 'Consolidados'] as $key => $label)
                    <button type="button" wire:click="$set('activeTab', '{{ $key }}')" aria-pressed="{{ $activeTab === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            {{ $this->table }}
            <div class="oc-footer">Los filtros afectan al listado y al CSV, no al resumen acumulado.<span>Montos en CLP</span></div>
        </section>
    </div>
</x-filament-panels::page>
