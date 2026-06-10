@use(Illuminate\Support\Carbon)
@use(Illuminate\Support\Number)

<x-filament::section>
    <div class="space-y-3">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Estimación próximo pago</h3>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Cobros proyectados para el próximo mes.
            </p>
        </div>

        @if (! $hasCharges)
            <p class="text-sm text-gray-700 dark:text-gray-200">Sin cobros programados para el próximo mes.</p>
        @else
            <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($charges as $charge)
                        <div class="flex items-center justify-between px-3 py-2 text-sm">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white">#{{ $charge->id }} - {{ $charge->description ?: 'Cobro sin descripción' }}</p>
                                <p class="text-gray-500 dark:text-gray-400">Vence: {{ Carbon::parse($charge->due_date)->format('d/m/Y') }}</p>
                            </div>
                            <div class="font-semibold text-gray-900 dark:text-white">
                                {{ Number::currency((float) $charge->remaining_amount, 'CLP', locale: 'es_CL') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($remainingCount > 0)
                <p class="text-sm text-gray-600 dark:text-gray-300">...y {{ $remainingCount }} más.</p>
            @endif

            <div class="flex flex-col gap-1 text-sm text-gray-700 dark:text-gray-200 md:flex-row md:items-center md:justify-between">
                <span>Total estimado: <strong>{{ Number::currency($totalAmount, 'CLP', locale: 'es_CL') }}</strong></span>
                <span>Próximo vencimiento: <strong>{{ Carbon::parse($nearestDueDate)->format('d/m/Y') }}</strong></span>
            </div>

            <div>
                <x-filament::button color="primary" size="sm" :href="$chargesUrl" tag="a">
                    Ver mis cobros
                </x-filament::button>
            </div>
        @endif
    </div>
</x-filament::section>
