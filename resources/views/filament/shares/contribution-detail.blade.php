<div class="fi-infolist fi-infolist-1col text-sm">
    <p class="fi-field"><strong>Socio:</strong> {{ $record->propietario->nombre_completo }}</p>
    <p class="fi-field"><strong>Registro:</strong> {{ \App\Filament\Admin\Resources\ShareContributionResource::typeLabel($record->type) }}</p>
    <p class="fi-field">
        <strong>Aporte / ajuste:</strong> ${{ number_format((float) $record->amount, 0, ',', '.') }}
        @if ((float) $record->amount === 0.0)
            <span class="fi-badge fi-badge-color-info">Registrado explícitamente como $0; no es información faltante</span>
        @endif
    </p>
    <p class="fi-field"><strong>Acciones registradas (referencia):</strong> {{ $record->quantity !== null ? \App\Models\ShareHolding::displayQuantity($record->quantity) : 'Sin cambio' }}</p>
    <p class="fi-field"><strong>Fecha de referencia:</strong> {{ $record->reference_date?->format('d-m-Y') ?? 'Desconocida' }}</p>
    <p class="fi-field"><strong>Registrado:</strong> {{ $record->created_at->format('d-m-Y H:i') }} — Administrador #{{ $record->created_by }}</p>
    <p class="fi-field"><strong>Corrige aporte #:</strong> {{ $record->corrects_contribution_id ?? '—' }}</p>
    @if (($before = $record->context['amount_before'] ?? null) !== null)
        <p class="fi-field"><strong>Corrección:</strong> ${{ number_format((float) $before, 0, ',', '.') }} → ${{ number_format((float) $record->context['amount_after'], 0, ',', '.') }}</p>
    @endif
    @if (filled($record->notes))
        <p class="fi-field"><strong>Observaciones:</strong> {{ $record->notes }}</p>
    @endif
    <p class="fi-field"><strong>Comprobante:</strong> {{ $record->receipt_path !== null ? 'Adjunto (descárgalo desde el menú de acciones)' : 'Sin comprobante' }}</p>
</div>
