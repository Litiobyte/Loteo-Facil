<div class="space-y-4">
    <div class="text-sm text-gray-600">
        @if ($allocations->isEmpty())
            No hay pagos aplicados para este cobro.
        @else
            Se encontraron {{ $allocations->count() }} asignaciones de pago para este cobro.
        @endif
    </div>

    @if ($allocations->isNotEmpty())
        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-700">Fecha pago</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700">Método</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700">Monto asignado</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-700">Asignado el</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @foreach ($allocations as $allocation)
                        <tr>
                            <td class="px-3 py-2">{{ $allocation->payment?->payment_date?->format('d/m/Y') ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $allocation->payment?->payment_method?->getLabel() ?? '-' }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $allocation->amount, 2, ',', '.') }} CLP</td>
                            <td class="px-3 py-2">{{ $allocation->allocated_at?->format('d/m/Y H:i') ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
