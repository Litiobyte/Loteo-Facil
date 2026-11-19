@use(Illuminate\Support\Number)

<x-filament-panels::page>
    @include('filament.owner.pages.partials.mis-pagos-styles')
    <div class="owner-payments">
        <p class="op-crumb">Mi comunidad / Finanzas / Mis pagos</p>
        <header class="op-heading">
            <div><h1>Mis pagos</h1><p class="op-muted">Revisa tus pagos, su aplicación y las referencias registradas.</p></div>
            <button type="button" class="op-button" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv"><x-filament::icon icon="heroicon-o-arrow-down-tray" /> Exportar CSV</button>
        </header>
        <section class="op-metrics" aria-label="Resumen de pagos">
            @foreach ([
                ['Total pagado', $paidTotal, 'Pagos registrados · excluye cancelados', 'heroicon-o-arrow-down-left'],
                ['Aplicado a cobros', $appliedTotal, 'Dinero asignado a obligaciones', 'heroicon-o-check'],
                ['Sin aplicar', $availableTotal, 'Disponible para futuras aplicaciones', 'heroicon-o-banknotes'],
            ] as [$label, $value, $hint, $icon])
                <article class="op-metric">
                    <div class="op-metric-top"><span>{{ $label }}</span><span class="op-symbol"><x-filament::icon :icon="$icon" /></span></div>
                    <strong>{{ Number::currency($value, 'CLP', locale: 'es_CL') }}</strong><small>{{ $hint }}</small>
                </article>
            @endforeach
        </section>
        <section class="op-panel" aria-label="Historial de pagos">
            <div class="op-panel-head"><div><h2>Historial de pagos</h2><p class="op-muted">Consulta el detalle de cada transferencia o abono.</p></div><span class="op-muted">{{ $paymentCount }} pagos registrados</span></div>
            <div class="op-tools" role="group" aria-label="Filtrar pagos">
                @foreach (['all' => 'Todos', 'applied' => 'Aplicados', 'available' => 'Sin aplicar'] as $key => $label)
                    <button type="button" wire:click="$set('activeTab', '{{ $key }}')" aria-pressed="{{ $activeTab === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            {{ $this->table }}
            <div class="op-footer">Los filtros afectan al listado y al CSV, no al resumen acumulado.<span>Montos en CLP</span></div>
        </section>
        <aside class="op-hint"><x-filament::icon icon="heroicon-o-information-circle" /><p><strong>¿Qué significa «aplicado»?</strong><br>Un pago puede registrarse y luego asignarse a uno o varios cobros. El monto sin aplicar es el dinero que aún no se ha destinado a un cobro. Que un pago esté aplicado no significa necesariamente que todos tus cobros estén al día.</p></aside>
    </div>
</x-filament-panels::page>
