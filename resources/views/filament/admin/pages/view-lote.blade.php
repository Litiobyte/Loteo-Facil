<x-filament-panels::page>
    <x-filament::section heading="Documentos del lote">
        @livewire('document-manager', ['entity' => $this->getRecord(), 'moduleKey' => 'lotes'], key('lot-documents-'.$this->getRecord()->getKey()))
    </x-filament::section>
    <div class="lote-detail-columns">

        {{-- Resumen: ancho completo --}}
        <div class="lote-detail-header">
            @include('filament.maps.lote-summary')
        </div>

        {{-- Distribución en dos columnas --}}
        <div class="lote-detail-grid">

            {{-- Columna izquierda --}}
            <div class="lote-detail-left">

                <x-filament::section
                    heading="Ficha del lote"
                    icon="heroicon-o-pencil-square"
                >
                    <x-slot name="description">
                        Actualiza los datos del terreno y guarda los cambios.
                    </x-slot>

                    <form wire:submit="save">
                        {{ $this->form }}

                        <div class="lote-detail-actions">
                            <x-filament::button
                                type="submit"
                                icon="heroicon-o-check"
                                wire:loading.attr="disabled"
                                wire:target="save"
                            >
                                Guardar cambios
                            </x-filament::button>
                        </div>
                    </form>
                </x-filament::section>

                {{-- Propietarios y finanzas --}}
                <x-filament::section
                    heading="Propietarios y datos financieros"
                    icon="heroicon-o-users"
                >
                    <x-slot name="description">
                        Asignaciones activas y valor registrado del terreno.
                    </x-slot>

                    <dl class="lote-detail-summary lote-detail-summary-single">
                        <div>
                            <dt>Valor del lote</dt>
                            <dd>
                                ${{ number_format((float) $lote->valor_lote, 0, ',', '.') }}
                            </dd>
                        </div>
                    </dl>

                    <div class="lote-detail-owners">
                        @forelse ($lote->propietarios->where('pivot.status', 'active') as $propietario)
                            <div class="lote-detail-owner">
                                <div>
                                    @can('update', $propietario)
                                        <x-filament::link
                                            icon="heroicon-o-arrow-top-right-on-square"
                                            :href="\App\Filament\Resources\Propietarios\PropietarioResource::getUrl('edit', ['record' => $propietario])"
                                        >
                                            {{ $propietario->nombre_completo ?: $propietario->user?->name }}
                                        </x-filament::link>
                                    @else
                                        <span>
                                            {{ $propietario->nombre_completo ?: $propietario->user?->name }}
                                        </span>
                                    @endcan

                                    <p class="lote-detail-muted">Asignación activa</p>
                                </div>

                                @can('update', $lote)
                                    @can('update', $propietario)
                                        @if ($lote->estado === 'reservado')
                                            {{ ($this->desasignarAction)(['propietarioId' => $propietario->id]) }}
                                        @endif
                                    @endcan
                                @endcan
                            </div>
                        @empty
                            <p class="lote-detail-empty lote-detail-muted">
                                Sin propietario asignado actualmente.
                            </p>
                        @endforelse
                    </div>

                    @if ($lote->estado === 'vendido')
                        <p class="lote-detail-actions lote-detail-muted">
                            Los lotes vendidos no pueden desasignarse.
                        </p>
                    @endif
                </x-filament::section>

            </div>

            {{-- Columna derecha --}}
            <div class="lote-detail-right">
                @include('filament.maps.lote-map-section')
            </div>

        </div>
    </div>
</x-filament-panels::page>
