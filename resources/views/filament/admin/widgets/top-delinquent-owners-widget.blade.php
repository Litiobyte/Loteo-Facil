@use(Illuminate\Support\Number)

<x-filament::section icon="heroicon-o-user-group" icon-color="warning" heading="Top morosos">
    <div class="space-y-4">
        @if ($rows === [])
            <p class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-300">
                No hay propietarios con deuda vencida actualmente.
            </p>
        @else
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 bg-gray-50/70 text-left dark:border-gray-800 dark:bg-gray-800/40">
                            <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">Propietario</th>
                            <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">Detalle de morosidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                <td class="px-4 py-3 align-top text-gray-900 dark:text-gray-100">
                                    <div class="font-medium">{{ $row['propietario_nombre'] }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">
                                            <p class="text-xs font-medium uppercase tracking-wide">Cobros vencidos</p>
                                            <p class="mt-1 text-sm font-semibold">{{ $row['overdue_count'] }}</p>
                                        </div>
                                        <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-rose-900 dark:border-rose-700/50 dark:bg-rose-900/20 dark:text-rose-200">
                                            <p class="text-xs font-medium uppercase tracking-wide">Dias max.</p>
                                            <p class="mt-1 text-sm font-semibold">{{ $row['max_days_overdue'] }}</p>
                                        </div>
                                        <div class="rounded-lg border border-danger-200 bg-danger-50 px-3 py-2 text-danger-800 dark:border-danger-700/50 dark:bg-danger-900/20 dark:text-danger-200 md:col-span-2">
                                            <p class="text-xs font-medium uppercase tracking-wide">Saldo vencido</p>
                                            <p class="mt-1 text-sm font-bold">
                                                {{ Number::currency($row['overdue_total'], 'CLP', locale: 'es_CL') }}
                                            </p>
                                        </div>
                                    </div>
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
