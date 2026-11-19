@use(App\Filament\Owner\Resources\Lotes\LoteResource)
@use(App\Models\ShareHolding)
@use(App\Support\ChileanNumber)
@use(Illuminate\Support\Carbon)

<x-filament-panels::page>
    <style>
        .mp-page {
            --mp-green: #158840; --mp-green-bg: #e9f6ed; --mp-green-ink: #168044;
            --mp-ink: #17233b; --mp-muted: #718095; --mp-line: #e6eaee;
            --mp-surface: #fff; --mp-sub: #f5f7f8;
            --mp-info-bg: #edf5ff; --mp-info-ink: #33577a;
            color: var(--mp-ink); font-size: 14px; line-height: 1.55;
            display: grid; gap: 18px; min-width: 0;
        }
        .dark .mp-page {
            --mp-ink: #e8eef4; --mp-muted: #9daebf; --mp-line: #33415a; --mp-surface: #1a2438;
            --mp-green-ink: #6ee7a0; --mp-green-bg: #1d3a2a; --mp-sub: #131c2e;
            --mp-info-ink: #93b8e8; --mp-info-bg: #1d2c47;
        }
        .mp-pill-row { display: flex; justify-content: flex-end; }
        .mp-pill { padding: 7px 12px; background: var(--mp-green-bg); color: var(--mp-green-ink); border-radius: 25px; font-weight: 700; font-size: 12px; white-space: nowrap; }
        .mp-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 15px; }
        .mp-stat { background: var(--mp-surface); border: 1px solid var(--mp-line); border-radius: 13px; padding: 19px; box-shadow: 0 2px 8px rgb(25 41 68 / 3%); min-width: 0; }
        .mp-stat-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12px; color: var(--mp-muted); font-weight: 600; }
        .mp-stat-icon { width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; background: var(--mp-green-bg); color: var(--mp-green-ink); flex-shrink: 0; }
        .mp-stat-icon svg { width: 18px; height: 18px; }
        .mp-number { font-size: 27px; letter-spacing: -1px; font-weight: 750; margin: 8px 0 2px; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .mp-small { font-size: 12px; color: var(--mp-muted); }
        .mp-notice { display: flex; gap: 10px; padding: 14px 16px; border-radius: 10px; background: var(--mp-green-bg); color: var(--mp-green-ink); font-size: 13px; line-height: 1.6; }
        .mp-notice.mp-info { background: var(--mp-info-bg); color: var(--mp-info-ink); }
        .mp-notice svg { width: 18px; height: 18px; flex-shrink: 0; }
        .mp-panel { background: var(--mp-surface); border: 1px solid var(--mp-line); border-radius: 14px; overflow: hidden; box-shadow: 0 2px 9px rgb(23 35 59 / 3%); }
        .mp-panel-head { padding: 18px 20px; border-bottom: 1px solid var(--mp-line); display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .mp-panel-head h2 { font-size: 16px; letter-spacing: -.2px; font-weight: 700; margin: 0; }
        .mp-panel-head p { margin: 4px 0 0; color: var(--mp-muted); font-size: 13px; }
        .mp-panel-body { padding: 20px; }
        .mp-kvs { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .mp-kv { background: var(--mp-sub); border: 1px solid var(--mp-line); border-radius: 11px; padding: 12px 14px; }
        .mp-kv dt { font-size: 12px; color: var(--mp-muted); }
        .mp-kv dd { margin: 4px 0 0; font-weight: 700; font-size: 16px; }
        .mp-lots { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
        .mp-lot { display: flex; gap: 12px; align-items: flex-start; border: 1px solid var(--mp-line); border-radius: 12px; padding: 14px; background: var(--mp-sub); }
        .mp-lot-icon { width: 44px; height: 44px; border-radius: 11px; display: grid; place-items: center; background: var(--mp-green-bg); color: var(--mp-green-ink); flex-shrink: 0; }
        .mp-lot-icon svg { width: 20px; height: 20px; }
        .mp-lot-info { min-width: 0; flex: 1; }
        .mp-lot-info b { font-size: 14px; }
        .mp-lot-info p { margin: 3px 0 0; color: var(--mp-muted); font-size: 12px; }
        .mp-tag { display: inline-block; margin-top: 8px; padding: 3px 9px; border-radius: 20px; background: var(--mp-green-bg); color: var(--mp-green-ink); font-size: 11px; font-weight: 700; }
        .mp-btn { align-self: center; padding: 8px 13px; border-radius: 9px; border: 1px solid var(--mp-line); background: var(--mp-surface); color: var(--mp-ink); font-size: 12px; font-weight: 650; white-space: nowrap; }
        .mp-btn:hover { background: var(--mp-sub); }
        .mp-empty { color: var(--mp-muted); }
        .mp-table-scroll { overflow-x: auto; }
        .mp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .mp-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--mp-muted); border-bottom: 1px solid var(--mp-line); padding: 8px 10px; }
        .mp-table td { padding: 9px 10px; border-bottom: 1px solid var(--mp-line); }
    </style>

    @php
        $modeLabel = $usesShares && $usesLots ? 'Acciones y lotes' : ($usesShares ? 'Solo acciones' : 'Solo lotes');
        $sharesArea = ChileanNumber::format($shares['square_meters']);
        $lotArea = ChileanNumber::format($lotSquareMeters);
    @endphp

    <div class="mp-page">
        <div class="mp-pill-row"><span class="mp-pill">{{ $modeLabel }}</span></div>

        <section class="mp-stats" aria-label="Resumen de mi participación">
            @if ($usesShares)
                <article class="mp-stat">
                    <div class="mp-stat-top"><span>Mis acciones</span><span class="mp-stat-icon"><x-filament::icon icon="heroicon-o-chart-pie" /></span></div>
                    <p class="mp-number">{{ ShareHolding::displayQuantity($shares['quantity']) }}</p>
                    <p class="mp-small">Acciones registradas</p>
                </article>
                <article class="mp-stat">
                    <div class="mp-stat-top"><span>Superficie referencial</span><span class="mp-stat-icon"><x-filament::icon icon="heroicon-o-arrows-pointing-out" /></span></div>
                    <p class="mp-number">{{ $sharesArea }} m²</p>
                    <p class="mp-small">Equivalencia informada</p>
                </article>
            @endif
            @if ($usesLots)
                <article class="mp-stat">
                    <div class="mp-stat-top"><span>Mis lotes</span><span class="mp-stat-icon"><x-filament::icon icon="heroicon-o-squares-2x2" /></span></div>
                    <p class="mp-number">{{ $lotCount }}</p>
                    <p class="mp-small">Lotes asignados</p>
                </article>
                <article class="mp-stat">
                    <div class="mp-stat-top"><span>Superficie de lotes</span><span class="mp-stat-icon"><x-filament::icon icon="heroicon-o-map" /></span></div>
                    <p class="mp-number">{{ $lotArea }} m²</p>
                    <p class="mp-small">Superficie total asignada</p>
                </article>
            @endif
            @if ($usesShares && ! $usesLots)
                <article class="mp-stat">
                    <div class="mp-stat-top"><span>Estado de asignación</span><span class="mp-stat-icon"><x-filament::icon icon="heroicon-o-clock" /></span></div>
                    <p class="mp-number">Pendiente</p>
                    <p class="mp-small">Lotes aún no distribuidos</p>
                </article>
            @endif
        </section>

        @if ($usesShares && ! $usesLots)
            <div class="mp-notice mp-info"><span><x-filament::icon icon="heroicon-o-information-circle" /></span><div><b>Distribución de lotes pendiente.</b><br>Tu participación está registrada en acciones. La equivalencia en superficie es referencial y no implica que existan lotes asignados.</div></div>
        @elseif ($usesShares)
            <div class="mp-notice mp-info"><span><x-filament::icon icon="heroicon-o-information-circle" /></span><div><b>Acciones y lotes son registros independientes.</b><br>La superficie referencial de tus acciones no debe compararse ni restarse automáticamente de los {{ $lotArea }} m² asignados en lotes.</div></div>
        @endif

        @if ($usesShares)
            <section class="mp-panel" aria-labelledby="mp-shares-title">
                <div class="mp-panel-head">
                    <div>
                        <h2 id="mp-shares-title">{{ $usesLots ? 'Mis acciones' : 'Detalle de mis acciones' }}</h2>
                        <p>{{ $usesLots ? 'Participación registrada, independiente de tus lotes.' : 'Resumen de participación en la comunidad.' }}</p>
                    </div>
                </div>
                <div class="mp-panel-body">
                    <dl class="mp-kvs">
                        <div class="mp-kv"><dt>Acciones registradas</dt><dd>{{ ShareHolding::displayQuantity($shares['quantity']) }}</dd></div>
                        <div class="mp-kv"><dt>Superficie referencial</dt><dd>{{ $sharesArea }} m²</dd></div>
                    </dl>
                </div>
            </section>
        @endif

        @if ($usesLots)
            <section class="mp-panel" aria-labelledby="mp-lots-title">
                <div class="mp-panel-head"><div><h2 id="mp-lots-title">Mis terrenos</h2><p>Lotes registrados a tu nombre. Imágenes referenciales, no planos reales.</p></div></div>
                <div class="mp-panel-body">
                    <div class="mp-lots">
                        @forelse ($lots as $lote)
                            <article class="mp-lot" wire:key="mp-lot-{{ $lote->id }}">
                                <div class="mp-lot-icon"><x-filament::icon icon="heroicon-o-map" /></div>
                                <div class="mp-lot-info">
                                    <b>{{ $lote->codigo }}</b>
                                    <p>{{ $lote->etapa?->nombre ?? 'Sin etapa' }} · {{ ChileanNumber::format($lote->metros_cuadrados) }} m²</p>
                                    <span class="mp-tag">{{ $lote->estado ?? 'Asignado' }}</span>
                                </div>
                                <a class="mp-btn" href="{{ LoteResource::getUrl('view', ['record' => $lote]) }}">Ver detalle</a>
                            </article>
                        @empty
                            <p class="mp-empty">Aún no tienes lotes asignados. La administración registrará tus terrenos cuando se realice la asignación.</p>
                        @endforelse
                    </div>
                </div>
            </section>
        @endif

        <section class="mp-panel" aria-labelledby="mp-history-title">
            <div class="mp-panel-head"><div><h2 id="mp-history-title">Historial de participación</h2><p>Cambios registrados en tu participación.</p></div></div>
            <div class="mp-panel-body">
                @if ($usesShares)
                    {{ $this->table }}
                @else
                    <div class="mp-table-scroll">
                        <table class="mp-table">
                            <thead><tr><th>Fecha</th><th>Movimiento</th><th>Tipo</th><th>Detalle</th></tr></thead>
                            <tbody>
                                @forelse ($lotHistory as $row)
                                    @if ($row->assigned_at)
                                        <tr wire:key="mp-hist-a-{{ $loop->index }}">
                                            <td>{{ Carbon::parse($row->assigned_at)->format('d/m/Y') }}</td>
                                            <td>Asignación de Lote {{ $row->codigo }}</td>
                                            <td>Lotes</td>
                                            <td>{{ ChileanNumber::format($row->metros_cuadrados) }} m² asignados</td>
                                        </tr>
                                    @endif
                                    @if ($row->unassigned_at)
                                        <tr wire:key="mp-hist-r-{{ $loop->index }}">
                                            <td>{{ Carbon::parse($row->unassigned_at)->format('d/m/Y') }}</td>
                                            <td>Retiro de Lote {{ $row->codigo }}</td>
                                            <td>Lotes</td>
                                            <td>{{ ChileanNumber::format($row->metros_cuadrados) }} m² liberados</td>
                                        </tr>
                                    @endif
                                @empty
                                    <tr><td colspan="4" class="mp-empty">Sin movimientos registrados.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>

        @if ($usesShares)
            <div class="mp-notice"><span><x-filament::icon icon="heroicon-o-information-circle" /></span><div><b>Sobre los aportes monetarios:</b> el monto aportado no determina automáticamente la cantidad de acciones. El detalle económico, si está disponible, debe mostrarse como un registro separado.</div></div>
        @endif
    </div>
</x-filament-panels::page>
