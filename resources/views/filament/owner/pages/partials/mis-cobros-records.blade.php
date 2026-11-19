@use(App\Domain\Charges\Enums\ChargeStatus)
@use(App\Filament\Owner\Resources\Cobros\MisCobrosResource)
@use(Illuminate\Support\Number)

<div class="oc-list">
    <div class="oc-list-head" aria-hidden="true"><span>Concepto</span><span>Vencimiento</span><span>Monto</span><span>Pagado</span><span>Pendiente</span><span>Estado</span><span></span></div>
    @foreach ($records as $charge)
        @php
            $consolidated = $charge->status === ChargeStatus::Consolidated;
            $pending = in_array($charge->status, [ChargeStatus::Pending, ChargeStatus::Partial], true) && $charge->remaining_amount > 0;
            $overdue = $pending && $charge->due_date?->isBefore(today());
            $consolidation = MisCobrosResource::consolidationLabel($charge);
        @endphp
        <article class="oc-charge" wire:key="{{ $this->getId() }}.table.records.{{ $charge->getKey() }}">
            <div class="oc-concept"><h3>{{ $charge->description ?? $charge->expense?->title ?? 'Cobro' }}</h3><p class="oc-muted">Cobro #{{ str_pad((string) $charge->id, 3, '0', STR_PAD_LEFT) }}@if ($consolidation) · {{ $consolidation }}@endif</p></div>
            <div class="oc-due"><span class="oc-mobile-label">Vence: </span>{{ $charge->due_date?->format('d/m/Y') ?? 'Sin vencimiento' }}</div>
            <div class="oc-money"><span class="oc-mobile-label">Monto</span>{{ Number::currency($charge->amount, 'CLP', locale: 'es_CL') }}</div>
            <div class="oc-money"><span class="oc-mobile-label">Pagado</span>{{ Number::currency($charge->paid_amount, 'CLP', locale: 'es_CL') }}</div>
            <div class="oc-money {{ $pending ? 'oc-danger' : '' }}"><span class="oc-mobile-label">Pendiente</span>{{ Number::currency($consolidated ? 0 : $charge->remaining_amount, 'CLP', locale: 'es_CL') }}</div>
            <div class="oc-status"><span class="oc-pill {{ $pending ? 'oc-pill-due' : '' }}">{{ $overdue ? 'Vencido · ' : '' }}{{ MisCobrosResource::statusLabel($charge->status) }}</span></div>
            <a class="oc-button" href="{{ MisCobrosResource::getUrl('view', ['record' => $charge]) }}" aria-label="Ver detalle del cobro #{{ $charge->id }}">Ver detalle →</a>
        </article>
    @endforeach
</div>
