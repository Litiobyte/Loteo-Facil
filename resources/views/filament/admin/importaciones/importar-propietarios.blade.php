<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Importar Propietarios</x-slot>
        <x-slot name="description">
            Crea o actualiza propietarios en masa desde un archivo CSV o Excel. La columna <code>rut</code> es la clave: si el propietario ya existe se actualiza; si no, se crea con un usuario de acceso automático.
        </x-slot>

        <ul class="list-inside list-disc space-y-1 text-sm text-gray-600 dark:text-gray-300">
            <li>Descarga la plantilla para obtener un archivo de ejemplo.</li>
            <li>Al crear un propietario nuevo se genera un usuario con rol <code>propietario</code> y contraseña aleatoria (debe ser restablecida por un administrador).</li>
        </ul>
    </x-filament::section>

    @include('filament.admin.importaciones._result')
</x-filament-panels::page>
