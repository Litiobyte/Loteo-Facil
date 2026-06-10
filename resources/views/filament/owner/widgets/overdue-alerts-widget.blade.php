@use(Illuminate\Support\Carbon)
@use(Illuminate\Support\Number)

<div>
    @if ($hasAlert)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <div class="space-y-3">
                <div>
                    <h3 class="text-base font-semibold text-danger-700 dark:text-danger-400">Tienes cobros vencidos</h3>
                    <p class="text-sm text-gray-700 dark:text-gray-200">
                        Se detectaron <strong>{{ $count }}</strong> cobros vencidos por un total pendiente de
                        <strong>{{ Number::currency($totalAmount, 'CLP', locale: 'es_CL') }}</strong>.
                    </p>
                </div>

                <div class="text-sm text-gray-700 dark:text-gray-200">
                    Vencimiento más antiguo:
                    <strong>{{ Carbon::parse($oldestDueDate)->format('d/m/Y') }}</strong>
                </div>

                <div>
                    <x-filament::button color="danger" size="sm" :href="$overdueUrl" tag="a">
                        Ver mis cobros vencidos
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif
</div>
