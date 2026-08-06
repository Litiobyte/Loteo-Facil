<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Importar Recaudaciones</x-slot>
        <x-slot name="description">
            Registra recaudaciones en masa desde un archivo CSV o Excel. Solo se crean pagos nuevos (no se actualizan registros existentes).
        </x-slot>

        <ul class="list-inside list-disc space-y-1 text-sm text-gray-600 dark:text-gray-300">
            <li>Usa el botón "Descargar plantilla" para obtener un archivo de ejemplo.</li>
            <li>La primera fila del archivo debe contener los nombres de las columnas.</li>
            <li>El <code>rut_propietario</code> debe corresponder a un propietario ya registrado en el sistema.</li>
            <li>La fecha puede ir como texto (AAAA-MM-DD o DD/MM/AAAA) o como fecha de Excel.</li>
            <li>No se registran pagos en períodos contables cerrados: esas filas se reportan como errores.</li>
        </ul>
    </x-filament::section>

    @include('filament.admin.importaciones._result')
</x-filament-panels::page>
