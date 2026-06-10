<x-filament-panels::page>
    <x-filament::fieldset>
        <x-slot name="label">Período de consulta</x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-filament::input.wrapper>
                <x-slot name="label">Desde:</x-slot>
                <x-filament::input type="date" wire:model.live="desde" />
            </x-filament::input.wrapper>

            <x-filament::input.wrapper>
                <x-slot name="label">Hasta:</x-slot>
                <x-filament::input type="date" wire:model.live="hasta" />
            </x-filament::input.wrapper>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <x-filament::button wire:click="setQuickFilter('this_month')" :color="$quickFilter === 'this_month' ? 'primary' : 'gray'" size="sm" icon="heroicon-o-calendar">
                Este mes
            </x-filament::button>
            <x-filament::button wire:click="setQuickFilter('last_three_months')" :color="$quickFilter === 'last_three_months' ? 'primary' : 'gray'" size="sm" icon="heroicon-o-calendar-days">
                Últimos 3 meses
            </x-filament::button>
            <x-filament::button wire:click="setQuickFilter('last_six_months')" :color="$quickFilter === 'last_six_months' ? 'primary' : 'gray'" size="sm" icon="heroicon-o-calendar-days">
                Últimos 6 meses
            </x-filament::button>
            <x-filament::button wire:click="setQuickFilter('this_year')" :color="$quickFilter === 'this_year' ? 'primary' : 'gray'" size="sm" icon="heroicon-o-calendar">
                Este año
            </x-filament::button>
            <x-filament::button wire:click="setQuickFilter('all')" :color="$quickFilter === 'all' ? 'primary' : 'gray'" size="sm" icon="heroicon-o-adjustments-horizontal">
                Todo
            </x-filament::button>
        </div>
    </x-filament::fieldset>

    @if (! $statement)
        <x-filament::section>
            <p class="text-sm text-gray-700 dark:text-gray-200">No hay perfil de propietario asociado.</p>
        </x-filament::section>
    @else
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            <x-filament::section>
                <div class="flex items-center gap-4">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-12 w-12 text-danger-500" />
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Deuda Pendiente</p>
                        <p class="text-3xl font-bold text-danger-600 dark:text-danger-400">
                            ${{ number_format((float) $resumen['pendiente'], 2) }}
                        </p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-4">
                    <x-filament::icon icon="heroicon-o-wallet" class="h-12 w-12 {{ (float) $resumen['disponible'] >= 0 ? 'text-success-500' : 'text-danger-500' }}" />
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Saldo Disponible</p>
                        <p class="text-3xl font-bold {{ (float) $resumen['disponible'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                            ${{ number_format((float) $resumen['disponible'], 2) }}
                        </p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-4">
                    <x-filament::icon icon="heroicon-o-scale" class="h-12 w-12 {{ (float) $resumen['balance'] >= 0 ? 'text-success-500' : 'text-danger-500' }}" />
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Balance Neto</p>
                        <p class="text-3xl font-bold {{ (float) $resumen['balance'] >= 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                            ${{ number_format((float) $resumen['balance'], 2) }}
                        </p>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-filament::section>
                <div class="flex items-center gap-3">
                    <x-filament::icon icon="heroicon-o-document-text" class="h-6 w-6 text-gray-400" />
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Cobrado</p>
                        <p class="text-xl font-semibold text-info-600 dark:text-info-400">${{ number_format((float) $resumen['cobrado'], 2) }}</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-3">
                    <x-filament::icon icon="heroicon-o-banknotes" class="h-6 w-6 text-gray-400" />
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Pagado</p>
                        <p class="text-xl font-semibold text-info-600 dark:text-info-400">${{ number_format((float) $resumen['pagado'], 2) }}</p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-3">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-6 w-6 text-gray-400" />
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Aplicado</p>
                        <p class="text-xl font-semibold text-info-600 dark:text-info-400">${{ number_format((float) $resumen['aplicado'], 2) }}</p>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">Comparación mes a mes</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left text-sm">Mes</th>
                            <th class="px-3 py-2 text-left text-sm">Cobros</th>
                            <th class="px-3 py-2 text-left text-sm">Pagos</th>
                            <th class="px-3 py-2 text-left text-sm">Balance</th>
                            <th class="px-3 py-2 text-left text-sm">Tendencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($monthComparison as $month)
                            <tr class="border-t dark:border-gray-700">
                                <td class="px-3 py-2 text-sm">{{ $month['month'] }}</td>
                                <td class="px-3 py-2 text-sm">${{ number_format((float) $month['charges'], 2) }}</td>
                                <td class="px-3 py-2 text-sm">${{ number_format((float) $month['payments'], 2) }}</td>
                                <td class="px-3 py-2 text-sm {{ (float) $month['balance'] < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                                    ${{ number_format((float) $month['balance'], 2) }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($month['trend'] === 'down')
                                        <x-filament::icon icon="heroicon-o-arrow-trending-down" class="h-5 w-5 text-success-500" />
                                    @elseif($month['trend'] === 'up')
                                        <x-filament::icon icon="heroicon-o-arrow-trending-up" class="h-5 w-5 text-danger-500" />
                                    @else
                                        <x-filament::icon icon="heroicon-o-minus" class="h-5 w-5 text-gray-400" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Desglose por categoría</x-slot>

            @if (empty($desglose))
                <p class="text-sm text-gray-700 dark:text-gray-200">No hay deuda pendiente por categoría.</p>
            @else
                @php
                    $colors = ['primary', 'success', 'warning', 'danger', 'info', 'gray', 'purple', 'orange', 'cyan', 'pink'];
                    $colorClasses = [
                        'primary' => 'bg-primary-600',
                        'success' => 'bg-success-600',
                        'warning' => 'bg-warning-600',
                        'danger' => 'bg-danger-600',
                        'info' => 'bg-info-600',
                        'gray' => 'bg-gray-600',
                        'purple' => 'bg-purple-600',
                        'orange' => 'bg-orange-600',
                        'cyan' => 'bg-cyan-600',
                        'pink' => 'bg-pink-600',
                    ];
                @endphp

                @foreach ($desglose as $index => $categoria)
                    @php
                        $percentage = (float) $resumen['pendiente'] > 0
                            ? ((float) $categoria['monto'] / (float) $resumen['pendiente'] * 100)
                            : 0;
                        $color = $colors[$index % count($colors)];
                    @endphp

                    <div class="mb-4">
                        <div class="mb-1 flex justify-between">
                            <span class="text-sm font-medium">{{ $categoria['categoria'] }}</span>
                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                ${{ number_format((float) $categoria['monto'], 2) }} ({{ number_format($percentage, 1) }}%)
                            </span>
                        </div>
                        <div class="h-2.5 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                            <div class="h-2.5 rounded-full transition-all {{ $colorClasses[$color] }}" style="width: {{ $percentage }}%"></div>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Timeline de movimientos</x-slot>

            @if ($timeline->isEmpty())
                <p class="text-sm text-gray-700 dark:text-gray-200">Sin movimientos en el período seleccionado.</p>
            @else
                <div class="space-y-4">
                    @foreach ($timeline as $event)
                        <div class="ml-4 flex gap-4 border-l-2 pb-4 pl-4 {{ $event['type'] === 'charge' ? 'border-danger-500' : ($event['type'] === 'payment' ? 'border-success-500' : 'border-gray-400') }}">
                            <div class="-ml-8 flex-shrink-0">
                                <div class="flex h-10 w-10 items-center justify-center rounded-full {{ $event['type'] === 'charge' ? 'bg-danger-100 dark:bg-danger-900' : ($event['type'] === 'payment' ? 'bg-success-100 dark:bg-success-900' : 'bg-gray-100 dark:bg-gray-800') }}">
                                    <x-filament::icon icon="heroicon-o-{{ $event['type'] === 'charge' ? 'document-text' : ($event['type'] === 'payment' ? 'banknotes' : 'arrow-path') }}" class="h-5 w-5 {{ $event['type'] === 'charge' ? 'text-danger-600' : ($event['type'] === 'payment' ? 'text-success-600' : 'text-gray-600') }}" />
                                </div>
                            </div>

                            <div class="flex-1 rounded-lg bg-white p-4 transition-shadow hover:shadow-md dark:bg-gray-800">
                                <div class="flex items-start justify-between gap-3 text-sm">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ $event['description'] }}</p>
                                        <p class="text-gray-500 dark:text-gray-400">{{ $event['date']->format('d/m/Y H:i') }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-gray-900 dark:text-white">${{ number_format((float) $event['amount'], 2) }}</p>
                                        <p class="{{ (float) $event['balance_impact'] > 0 ? 'text-danger-600' : ((float) $event['balance_impact'] < 0 ? 'text-success-600' : 'text-gray-500') }}">
                                            {{ (float) $event['balance_impact'] > 0 ? '+' : '' }}${{ number_format((float) $event['balance_impact'], 2) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Cobros pendientes</x-slot>

            {{ $this->table }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
