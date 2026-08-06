<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Importar Lotes</x-slot>
        <x-slot name="description">
            Crea o actualiza lotes en masa desde un archivo CSV o Excel. La columna <code>codigo</code> es la clave: si el lote ya existe se actualiza; si no, se crea.
        </x-slot>

        <ul class="list-inside list-disc space-y-1 text-sm text-gray-600 dark:text-gray-300">
            <li>Usa el botón "Descargar plantilla" para obtener un archivo de ejemplo.</li>
            <li>La primera fila del archivo debe contener los nombres de las columnas.</li>
            <li>Estados válidos: <code>disponible</code>, <code>reservado</code>, <code>vendido</code>.</li>
            <li>La columna <code>etapa</code> acepta el número o el nombre de una etapa existente.</li>
        </ul>
    </x-filament::section>

    @include('filament.admin.importaciones._result')
</x-filament-panels::page>
