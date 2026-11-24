<x-filament-panels::page>
    <x-filament::section heading="Consulta por propietario">
        <p>Comunidad: {{ $community->community_name }}. Resumen acumulado actual; la fecha de corte se registra como antecedente, no reconstruye un saldo retrospectivo.</p>
        {{ $this->selectOwnerAction }}
    </x-filament::section>

    @if ($report)
        <x-filament::section :heading="$owner->nombre_completo">
            <dl>
                @foreach ([
                    'total_charges' => 'Total de cobros emitidos',
                    'total_applied' => 'Recaudaciones aplicadas',
                    'total_non_monetary_applied' => 'Aplicaciones no monetarias (sin caja)',
                    'unapplied_collections' => 'Recaudaciones sin aplicar',
                    'available_credit' => 'Crédito disponible',
                    'calculated_debt' => 'Deuda calculada',
                    'expected_debt' => 'Deuda esperada',
                    'difference' => 'Diferencia (calculada menos esperada)',
                ] as $key => $label)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $report['breakdown'][$key] === null ? 'No informada' : '$ '.number_format($report['breakdown'][$key], 2, ',', '.') }}</dd>
                @endforeach
                <dt>Signo de la diferencia</dt>
                <dd>{{ match ($report['breakdown']['difference_sign']) {
                    'deuda_mayor_que_esperada' => 'Deuda mayor que la esperada',
                    'deuda_menor_que_esperada' => 'Deuda menor que la esperada',
                    'balanced' => 'Sin diferencia',
                    default => 'Sin deuda esperada informada',
                } }}</dd>
                @foreach (['pending_verification' => 'Pendientes de verificación', 'recognized_collections' => 'Pagos reconocidos sin comprobante', 'unexplained_differences' => 'Diferencias no explicadas'] as $key => $label)
                    <dt>{{ $label }}</dt>
                    <dd>{{ $report['breakdown'][$key]['count'] }} registros · $ {{ number_format($report['breakdown'][$key]['amount'], 2, ',', '.') }}</dd>
                @endforeach
            </dl>
            <p>Impacta caja: un pago queda reconocido sin comprobante. La clasificación es una decisión manual del administrador, nunca automática; una diferencia no explicada no reduce deuda.</p>
            {{ $this->recognizePaymentAction }}
            {{ $this->classifyDifferenceAction }}
        </x-filament::section>

        <x-filament::section heading="Ajustes sin imputación mensual">
            <p>No se asigna un mes arbitrario a fechas de precisión anual o desconocida.</p>
            @forelse ($report['breakdown']['monthly_unattributed']['items'] as $item)
                <p>#{{ $item['id'] }} · {{ $item['source'] }} · $ {{ $item['amount'] }} · {{ $item['year'] ?? 'Año desconocido' }} · {{ $item['precision'] === 'year' ? 'Precisión anual' : 'Precisión desconocida' }}</p>
            @empty
                <p>No hay ajustes sin imputación mensual.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="Historial de decisiones">
            @forelse ($report['reconciliations'] as $record)
                <p>#{{ $record['id'] }} · {{ $record['kind'] === 'payment_recognition' ? 'Reconocimiento de pago' : 'Clasificación de diferencia' }} · {{ $record['source'] }} · Autorizante #{{ $record['authorized_by'] }} · {{ $record['cutoff_date'] ?? 'Sin corte informado' }} · {{ $record['status'] === 'reconciled' ? 'Conciliado' : 'Pendiente de verificación' }}</p>
            @empty
                <p>No hay decisiones registradas.</p>
            @endforelse
        </x-filament::section>
    @endif
</x-filament-panels::page>
