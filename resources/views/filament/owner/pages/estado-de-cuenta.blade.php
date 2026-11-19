@use(App\Filament\Owner\Resources\Cobros\MisCobrosResource)
@use(App\Models\CommunitySetting)
@use(Illuminate\Support\Number)

<x-filament-panels::page>
    @include('filament.owner.pages.partials.estado-de-cuenta-styles')

    <div class="owner-statement">
        <header class="os-header">
            <div>
                <p class="os-crumb">Mi comunidad / Finanzas / Estado de cuenta</p>
                <div class="os-heading">
                    <span class="os-heading-icon"><x-filament::icon icon="heroicon-o-document-text" /></span>
                    <div><h1>Mi estado de cuenta</h1><p class="os-muted">{{ CommunitySetting::displayName() }} · Consulta tus cobros, pagos y saldos</p></div>
                </div>
            </div>
            <button type="button" class="os-button os-print" x-on:click="window.print()">Imprimir / PDF</button>
        </header>

        <section class="os-panel os-filterbar" aria-label="Filtros de período">
            <div>
                <h2 class="os-filter-title">Período de movimientos</h2>
                <div class="os-ranges">
                    @foreach (['this_month' => 'Este mes', 'last_three_months' => 'Últimos 3 meses', 'last_six_months' => 'Últimos 6 meses', 'this_year' => 'Este año', 'all' => 'Todo'] as $key => $label)
                        <button type="button" wire:click="setQuickFilter('{{ $key }}')" aria-pressed="{{ $quickFilter === $key ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div class="os-dates">
                <label for="statement-from">Desde<input id="statement-from" type="date" wire:model.live="desde"></label>
                <label for="statement-to">Hasta<input id="statement-to" type="date" wire:model.live="hasta"></label>
            </div>
        </section>

        @if (! $statement)
            <p class="os-empty">No hay perfil de propietario asociado.</p>
        @else
            @php
                $overdue = $pendingCharges->filter(fn ($charge) => $charge->due_date?->isBefore(today()));
                $partialCount = $pendingCharges->where('status.value', 'partial')->count();
            @endphp
            <section class="os-cards" aria-label="Resumen de saldos">
                @foreach ([
                    ['Saldo pendiente', Number::currency($resumen['pendiente'], 'CLP', locale: 'es_CL'), $pendingCharges->count().' cobros con saldo por pagar', 'heroicon-o-arrow-trending-up'],
                    ['Saldo a favor', Number::currency($resumen['disponible'], 'CLP', locale: 'es_CL'), 'Disponible para futuros cobros', 'heroicon-o-wallet'],
                    ['Pagado en el período', Number::currency($periodPaid, 'CLP', locale: 'es_CL'), 'Pagos registrados en el filtro, sin anulados', 'heroicon-o-check'],
                    ['Cobros pendientes', $pendingCharges->count(), $overdue->count().' vencidos · '.$partialCount.' con abono parcial', 'heroicon-o-document-text'],
                ] as [$label, $value, $hint, $icon])
                    <article class="os-panel os-metric {{ $loop->first ? 'os-main-metric' : '' }}">
                        <div class="os-metric-top"><span>{{ $label }}</span><span class="os-symbol"><x-filament::icon :icon="$icon" /></span></div>
                        <p class="os-number">{{ $value }}</p><p class="os-small">{{ $hint }}</p>
                    </article>
                @endforeach
            </section>

            <div class="os-mainlayout">
                <div class="os-column">
                    <section class="os-panel">
                        <div class="os-section-head"><div><h2>Cobros por pagar</h2><p>Las obligaciones que aún tienen un saldo pendiente</p></div><a class="os-link" href="{{ MisCobrosResource::getUrl() }}">Ver todos los cobros →</a></div>
                        <div class="os-tablewrap">{{ $this->table }}</div>
                        <div class="os-footer"><span>Estos saldos son actuales y no cambian al filtrar el historial.</span><strong>Total por pagar: {{ Number::currency($resumen['pendiente'], 'CLP', locale: 'es_CL') }}</strong></div>
                    </section>
                    <section class="os-panel">
                        <div class="os-section-head"><div><h2>Mis movimientos</h2><p>Detalle cronológico de cobros, pagos y aplicaciones</p></div></div>
                        <div class="os-tablewrap">
                            <table class="os-table">
                                <thead><tr><th>Fecha</th><th>Movimiento</th><th>Tipo</th><th class="os-money">Monto</th><th class="os-money">Impacto en balance</th></tr></thead>
                                <tbody>
                                    @forelse ($timeline as $event)
                                        <tr>
                                            <td class="os-date">{{ $event['date']->format('d/m/Y') }}<div class="os-small">{{ $event['date']->format('H:i') }}</div></td>
                                            <td><strong>{{ $event['description'] }}</strong></td>
                                            <td><span class="os-badge {{ $event['type'] === 'payment' ? 'os-good' : ($event['type'] === 'charge' ? 'os-warn' : '') }}">{{ ['charge' => 'Cobro', 'payment' => 'Pago', 'allocation' => 'Aplicación'][$event['type']] }}</span></td>
                                            <td class="os-money">{{ Number::currency($event['amount'], 'CLP', locale: 'es_CL') }}</td>
                                            <td class="os-money">{{ Number::currency($event['balance_impact'], 'CLP', locale: 'es_CL') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="os-empty">Sin movimientos en el período seleccionado.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="os-footer">Mostrando {{ $timeline->count() }} de {{ $statement['timeline']->count() }} movimientos · Hasta 20 movimientos recientes del período.</div>
                    </section>
                    <section class="os-panel">
                        <div class="os-section-head"><div><h2>Comparación mes a mes</h2><p>Últimos tres meses · Independiente del filtro de movimientos</p></div></div>
                        <div class="os-tablewrap">
                            <table class="os-table">
                                <thead><tr><th>Mes</th><th>Cobros</th><th>Recaudaciones</th><th>Balance</th><th>Tendencia</th></tr></thead>
                                <tbody>
                                    @foreach ($monthComparison as $month)
                                        <tr><td>{{ $month['month'] }}</td><td>{{ Number::currency($month['charges'], 'CLP', locale: 'es_CL') }}</td><td>{{ Number::currency($month['collections'], 'CLP', locale: 'es_CL') }}</td><td>{{ Number::currency($month['balance'], 'CLP', locale: 'es_CL') }}</td><td>{{ ['up' => 'En aumento', 'down' => 'En descenso', 'neutral' => 'Sin variación'][$month['trend']] }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
                <aside class="os-column">
                    <section class="os-panel">
                        <div class="os-section-head"><div><h2>¿Cómo se calcula mi saldo?</h2><p>Resumen acumulado de tu cuenta hasta hoy</p></div></div>
                        <div class="os-summary">
                            @foreach (['cobrado' => 'Total de cobros registrados', 'pagado' => 'Total pagado', 'aplicado' => 'Pagos aplicados a cobros', 'disponible' => 'Saldo a favor disponible', 'balance' => 'Balance neto'] as $key => $label)
                                <div class="os-sumline"><span>{{ $label }}</span><strong>{{ Number::currency($resumen[$key], 'CLP', locale: 'es_CL') }}</strong></div>
                            @endforeach
                            <div class="os-sumline os-final"><span>Saldo pendiente actual</span><strong>{{ Number::currency($resumen['pendiente'], 'CLP', locale: 'es_CL') }}</strong></div>
                            <p class="os-note">Los pagos registrados pueden diferir de los pagos aplicados. Este resumen es acumulado; el filtro de fechas solo afecta a «Pagado en el período» y a la tabla de movimientos.</p>
                        </div>
                    </section>
                    @if ($overdue->isNotEmpty())
                        <section class="os-notice">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                            <div><strong>Tienes {{ $overdue->count() }} cobros vencidos</strong><p>Tu vencimiento más antiguo fue el {{ $overdue->first()->due_date->format('d/m/Y') }}.</p><a class="os-link" href="{{ MisCobrosResource::getUrl('view', ['record' => $overdue->first()]) }}">Ver cobro vencido →</a></div>
                        </section>
                    @endif
                    <section class="os-panel">
                        <div class="os-section-head"><div><h2>Desglose por categoría</h2><p>Deuda pendiente actual</p></div></div>
                        <div class="os-summary">
                            @forelse ($desglose as $categoria)
                                <div class="os-sumline"><span>{{ $categoria['categoria'] }}</span><strong>{{ Number::currency($categoria['monto'], 'CLP', locale: 'es_CL') }}</strong></div>
                            @empty
                                <p class="os-small">No hay deuda pendiente por categoría.</p>
                            @endforelse
                        </div>
                    </section>
                    <section class="os-panel">
                        <div class="os-section-head"><div><h2>¿Necesitas ayuda?</h2><p>Entiende los conceptos de tu estado de cuenta</p></div></div>
                        <div class="os-summary os-help"><p><strong>Cobro:</strong> monto que debes pagar.</p><p><strong>Pago:</strong> dinero recibido y registrado.</p><p><strong>Aplicación:</strong> parte de un pago utilizada para cubrir un cobro.</p><p><strong>Saldo a favor:</strong> dinero recibido aún no aplicado.</p></div>
                    </section>
                </aside>
            </div>
        @endif
    </div>
</x-filament-panels::page>
