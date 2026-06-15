@use(Illuminate\Support\Number)

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-user-group" icon-color="warning" heading="Top morosos" class="h-full w-full">
        <div class="space-y-3">
            @if ($rows === [])
                <p class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-300">
                    No hay propietarios con deuda vencida actualmente.
                </p>
            @else
                <div class="w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50/70 text-left dark:border-gray-800 dark:bg-gray-800/40">
                                <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">Propietario</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-200">Cobros vencidos</th>
                                <th class="px-4 py-3 text-right font-semibold text-gray-700 dark:text-gray-200">Saldo vencido</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50/70 dark:border-gray-800 dark:hover:bg-gray-800/30">
                                    <td class="px-4 py-3 align-top text-gray-900 dark:text-gray-100">
                                        <div class="font-medium">{{ $row['propietario_nombre'] }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-amber-700 dark:text-amber-300">
                                        {{ $row['overdue_count'] }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold text-danger-700 dark:text-danger-300">
                                        {{ Number::currency($row['overdue_total'], 'CLP', locale: 'es_CL') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div>
                <x-filament::button color="warning" size="sm" :href="$chargesUrl" tag="a">
                    Revisar todos los cobros
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
