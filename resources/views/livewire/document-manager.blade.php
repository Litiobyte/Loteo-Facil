<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold">Documentos</h2>
        @if ($required)
            <p class="text-sm text-gray-600">Se requiere al menos un documento.</p>
        @endif
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-red-300 p-3 text-sm text-red-700">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @can('create', \App\Models\Document::class)
        <form wire:submit="upload" class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2">
            <label class="grid gap-1 text-sm">
                <span class="font-medium">Tipo de documento</span>
                <select wire:model="typeId" required class="rounded-lg border-gray-300">
                    <option value="">Seleccione un tipo</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium">Archivo{{ $multiple ? 's' : '' }}</span>
                @if ($multiple)
                    <input type="file" wire:model="files" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple required>
                @else
                    <input type="file" wire:model="singleFile" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                @endif
            </label>
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-primary-600 px-4 py-2 text-white sm:col-span-2">
                Subir documentos
            </button>
            <span wire:loading class="text-sm">Procesando archivos…</span>
        </form>
    @endcan

    @forelse ($documents as $document)
        <div wire:key="document-{{ $document->id }}" class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_auto]">
            <div class="min-w-0">
                <p class="break-words font-medium">{{ $document->original_name }}</p>
                <p class="text-sm text-gray-600">{{ $document->type?->name }} · {{ $document->mime_type }} · {{ number_format($document->file_size / 1024, 1, ',', '.') }} KB · {{ $document->created_at?->format('d/m/Y') }}</p>
            </div>
            <div class="flex flex-wrap gap-3 text-sm">
                <a href="{{ route('documents.file', [$document, 'preview']) }}" target="_blank" rel="noopener">Vista previa</a>
                <a href="{{ route('documents.file', [$document, 'download']) }}">Descargar</a>
                @can('delete', $document)
                    <button type="button" wire:click="remove({{ $document->id }})" wire:confirm="¿Quitar este documento?" class="text-red-700">Quitar</button>
                @endcan
            </div>
            @can('update', $document)
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                    <label class="text-sm"><span class="font-medium">Archivo de reemplazo</span>
                        <input type="file" wire:model="replacement" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    </label>
                    <button type="button" wire:click="replace({{ $document->id }})" wire:loading.attr="disabled" class="rounded-lg border px-3 py-2">Reemplazar</button>
                </div>
            @endcan
        </div>
    @empty
        <p class="text-sm text-gray-600">No hay documentos disponibles.</p>
    @endforelse
    {{ $documents->links() }}
</div>
