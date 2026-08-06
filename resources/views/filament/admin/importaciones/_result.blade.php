@php
    $importResult = $this->resultData;
    $totalRows = ($importResult['created'] ?? 0) + ($importResult['updated'] ?? 0) + count($importResult['errors'] ?? []);
    $errorCount = count($importResult['errors'] ?? []);
@endphp

@if (! empty($importResult))
    <x-filament::section>
        <x-slot name="heading">Resultado de la importación</x-slot>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Total de filas</div>
                <div class="mt-1 text-2xl font-semibold">{{ $totalRows }}</div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Creados</div>
                <div class="mt-1 text-2xl font-semibold text-green-600 dark:text-green-400">{{ $importResult['created'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Actualizados</div>
                <div class="mt-1 text-2xl font-semibold">{{ $importResult['updated'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <div class="text-sm text-gray-500 dark:text-gray-400">Con errores</div>
                <div class="mt-1 text-2xl font-semibold text-red-600 dark:text-red-400">{{ $errorCount }}</div>
            </div>
        </div>

        @if ($errorCount > 0)
            <div class="mt-6">
                <div class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Detalle de errores</div>
                <div class="overflow-hidden overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 font-medium">Fila</th>
                                <th class="px-4 py-2 font-medium">Campo</th>
                                <th class="px-4 py-2 font-medium">Error</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($importResult['errors'] ?? [] as $error)
                                <tr>
                                    <td class="px-4 py-2">{{ $error['row'] }}</td>
                                    <td class="px-4 py-2">{{ $error['field'] ?? '-' }}</td>
                                    <td class="px-4 py-2">{{ $error['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </x-filament::section>
@endif
