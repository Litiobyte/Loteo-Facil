@use(App\Filament\Owner\Resources\Collections\MyCollectionsResource)
@use(Illuminate\Support\Number)

<div class="op-list">
    <div class="op-list-head" aria-hidden="true"><span>Fecha</span><span>Método</span><span>Referencia</span><span>Monto</span><span>Aplicado</span><span>Sin aplicar</span><span>Estado</span><span></span></div>
    @foreach ($records as $payment)
        <article class="op-payment" wire:key="{{ $this->getId() }}.table.records.{{ $payment->getKey() }}">
            <div class="op-date">{{ $payment->collection_date?->format('d/m/Y') }}<small class="op-mobile-label">Pago #{{ $payment->id }}</small></div>
            <div class="op-method">{{ MyCollectionsResource::paymentMethodLabel($payment->collection_method) }}</div>
            <div class="op-reference"><span class="op-mobile-label">Ref. </span>{{ $payment->reference ?? 'Sin referencia' }}</div>
            @foreach (['amount' => 'Monto', 'applied_amount' => 'Aplicado', 'unapplied_amount' => 'Sin aplicar'] as $field => $label)
                <div class="op-money {{ $field === 'applied_amount' ? 'op-positive' : '' }}"><span class="op-mobile-label">{{ $label }}</span>{{ Number::currency($payment->{$field}, 'CLP', locale: 'es_CL') }}</div>
            @endforeach
            <div class="op-status"><span class="op-pill op-{{ MyCollectionsResource::statusColor($payment->status) }}">{{ MyCollectionsResource::statusLabel($payment->status) }}</span></div>
            <button type="button" class="op-button" wire:click="mountTableAction('detail', '{{ $payment->getKey() }}')" aria-label="Ver detalle del pago #{{ $payment->id }}">Ver detalle →</button>
        </article>
    @endforeach
</div>
