<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Importaciones</x-slot>
        <x-slot name="description">
            Importa datos masivos desde archivos CSV o Excel. Cada módulo tiene su propia plantilla descargable.
        </x-slot>
    </x-filament::section>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <a
            href="{{ \App\Filament\Admin\Pages\Importaciones\ImportarLotes::getUrl() }}"
            class="group outline-none"
        >
            <x-filament::section class="transition duration-150 group-hover:bg-gray-50 dark:group-hover:bg-white/5">
                <div class="flex items-start gap-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-o-squares-2x2" class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="text-base font-semibold text-gray-900 dark:text-white">Importar Lotes</div>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Crea o actualiza lotes a partir de su código.
                        </p>
                    </div>
                </div>
            </x-filament::section>
        </a>

        <a
            href="{{ \App\Filament\Admin\Pages\Importaciones\ImportarPropietarios::getUrl() }}"
            class="group outline-none"
        >
            <x-filament::section class="transition duration-150 group-hover:bg-gray-50 dark:group-hover:bg-white/5">
                <div class="flex items-start gap-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-o-users" class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="text-base font-semibold text-gray-900 dark:text-white">Importar Propietarios</div>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Crea o actualiza propietarios y sus usuarios de acceso.
                        </p>
                    </div>
                </div>
            </x-filament::section>
        </a>

        <a
            href="{{ \App\Filament\Admin\Pages\Importaciones\ImportarRecaudaciones::getUrl() }}"
            class="group outline-none"
        >
            <x-filament::section class="transition duration-150 group-hover:bg-gray-50 dark:group-hover:bg-white/5">
                <div class="flex items-start gap-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-o-banknotes" class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="text-base font-semibold text-gray-900 dark:text-white">Importar Recaudaciones</div>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Registra pagos recibidos a partir del RUT del propietario.
                        </p>
                    </div>
                </div>
            </x-filament::section>
        </a>
    </div>
</x-filament-panels::page>
