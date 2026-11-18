<div class="lote-detail-columns">
    <div class="lote-detail-layout">
        @include('filament.maps.lote-summary')

        @if (! empty($showFinancial) && $showFinancial)
            <x-filament::section heading="Datos financieros" icon="heroicon-o-banknotes">
                <dl class="lote-detail-summary lote-detail-summary-single">
                    <div>
                        <dt>Valor del lote</dt>
                        <dd>${{ number_format((float) $lote->valor_lote, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </x-filament::section>
        @endif

        <x-filament::section heading="Notas" icon="heroicon-o-document-text">
            <p class="lote-detail-notes">{{ filled($lote->notas) ? $lote->notas : 'Sin notas registradas.' }}</p>
        </x-filament::section>
    </div>
    @include('filament.maps.lote-map-section')
</div>
