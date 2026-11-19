@use(App\Filament\Owner\Resources\Collections\MyCollectionsResource)
@use(Illuminate\Support\Number)

@include('filament.owner.pages.partials.mis-pagos-styles')
<div class="owner-payments op-detail">
    <p class="op-muted">Pago #{{ $payment->id }} · {{ $payment->collection_date?->format('d/m/Y') }}</p>
    <div class="op-received"><small>Monto recibido</small><strong>{{ Number::currency($payment->amount, 'CLP', locale: 'es_CL') }}</strong></div>
    <dl class="op-detail-grid">
        @foreach ([
            'Método' => MyCollectionsResource::paymentMethodLabel($payment->collection_method),
            'Referencia' => $payment->reference ?? 'Sin referencia',
            'Aplicado' => Number::currency($payment->applied_amount, 'CLP', locale: 'es_CL'),
            'Sin aplicar' => Number::currency($payment->unapplied_amount, 'CLP', locale: 'es_CL'),
            'Estado' => MyCollectionsResource::statusLabel($payment->status),
            'Notas' => $payment->notes ?? 'Sin notas adicionales',
        ] as $label => $value)
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <section class="op-applications" aria-label="Aplicación del pago">
        <h2>Aplicación del pago</h2>
        @forelse ($payment->allocations as $allocation)
            <div class="op-application"><span>{{ $allocation->charge?->description ?? 'Cobro #'.$allocation->partner_charge_id }}<small class="op-muted">{{ $allocation->allocated_at?->format('d/m/Y H:i') }}</small></span><strong>{{ Number::currency($allocation->amount, 'CLP', locale: 'es_CL') }}</strong></div>
        @empty
            <p class="op-muted">No tiene aplicaciones registradas.</p>
        @endforelse
    </section>
</div>
