@use(Illuminate\Support\Number)

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-banknotes" icon-color="info" heading="Ultimas 7 recaudaciones" class="h-full w-full">
        <div class="space-y-3">
            @if ($rows === [])
                <p class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-6 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-300">
                    No hay recaudaciones registradas.
                </p>
            @else
                <div class="w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-auto divide-y divide-gray-200 text-sm dark:divide-gray-800">
                            <thead class="bg-gray-50/70 dark:bg-gray-800/40">
                                <tr class="text-left">
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">ID</th>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Propietario</th>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Fecha</th>
                                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Metodo</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Monto</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                            @foreach ($rows as $row)
                                <tr class="hover:bg-gray-50/70 dark:hover:bg-gray-800/30">
                                    <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900 dark:text-gray-100">#{{ $row['id'] }}</td>
                                    <td class="px-4 py-3 text-gray-900 dark:text-gray-100">{{ $row['owner_name'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['collection_date'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['collection_method'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-gray-900 dark:text-gray-100">
                                            {{ Number::currency($row['amount'], 'CLP', locale: 'es_CL') }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right">
                                            <x-filament::badge :color="$row['status_color']">
                                                {{ $row['status_label'] }}
                                            </x-filament::badge>
                                        </td>
                                    </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div>
                <x-filament::button color="info" size="sm" :href="$collectionsUrl" tag="a">
                    Ver todas las recaudaciones
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
