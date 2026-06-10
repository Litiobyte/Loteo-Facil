<x-filament::section>
    @if (! $hasPropietario)
        <div class="text-sm text-gray-600">
            No encontramos un perfil de propietario asociado a tu usuario.
        </div>
    @else
        <div class="space-y-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                    ¡Bienvenido, {{ $propietarioNombre }}!
                </h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Este es el resumen financiero completo de tus lotes.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Mis lotes activos</p>
                    @if (count($loteCodes) === 0)
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-200">Sin lotes activos asignados.</p>
                    @else
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($loteCodes as $code)
                                <span class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800 dark:bg-gray-800 dark:text-gray-100">
                                    {{ $code }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total hectáreas activas</p>
                    <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ number_format($totalHectares, 4, ',', '.') }} ha</p>
                </div>
            </div>
        </div>
    @endif
</x-filament::section>
