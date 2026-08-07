@php
    $importResult = $this->resultData;
    $created = $importResult['created'] ?? 0;
    $updated = $importResult['updated'] ?? 0;
    $errors = $importResult['errors'] ?? [];
    $errorCount = count($errors);
    $totalRows = $created + $updated + $errorCount;
@endphp

@if (! empty($importResult))
    <x-filament::section>
        <x-slot name="heading">Resultado de la importación</x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                    <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                </div>
                <div>
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total de filas</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $totalRows }}</div>
                </div>
            </div>

            <div class="flex items-center gap-4 rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-500/20 dark:bg-green-500/10">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-500/20 dark:text-green-400">
                    <x-filament::icon icon="heroicon-o-plus-circle" class="h-5 w-5" />
                </div>
                <div>
                    <div class="text-sm font-medium text-green-700 dark:text-green-300">Creados</div>
                    <div class="mt-1 text-2xl font-semibold text-green-700 dark:text-green-300">{{ $created }}</div>
                </div>
            </div>

            <div class="flex items-center gap-4 rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-500/20 dark:bg-sky-500/10">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-400">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5" />
                </div>
                <div>
                    <div class="text-sm font-medium text-sky-700 dark:text-sky-300">Actualizados</div>
                    <div class="mt-1 text-2xl font-semibold text-sky-700 dark:text-sky-300">{{ $updated }}</div>
                </div>
            </div>

            <div class="flex items-center gap-4 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-500/20 dark:bg-red-500/10">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-400">
                    <x-filament::icon icon="heroicon-o-x-circle" class="h-5 w-5" />
                </div>
                <div>
                    <div class="text-sm font-medium text-red-700 dark:text-red-300">Con errores</div>
                    <div class="mt-1 text-2xl font-semibold text-red-700 dark:text-red-300">{{ $errorCount }}</div>
                </div>
            </div>
        </div>

        @if ($errorCount > 0)
            <div class="mt-6">
                <div class="mb-3 flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-red-500" />
                    Detalle de errores
                    <x-filament::badge color="danger">{{ $errorCount }}</x-filament::badge>
                </div>
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
                            @foreach ($errors as $error)
                                <tr class="align-top">
                                    <td class="px-4 py-2.5">
                                        <x-filament::badge color="gray">Fila {{ $error['row'] }}</x-filament::badge>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @if (($error['field'] ?? null) !== null)
                                            <x-filament::badge color="warning">{{ $error['field'] }}</x-filament::badge>
                                        @else
                                            <span class="text-gray-400">-</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">{{ $error['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </x-filament::section>
@endif
