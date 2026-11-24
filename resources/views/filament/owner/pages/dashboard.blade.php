@use(App\Filament\Owner\Pages\EstadoDeCuenta)
@use(App\Filament\Owner\Pages\MapaLoteo)
@use(App\Filament\Owner\Resources\Cobros\MisCobrosResource)
@use(App\Filament\Owner\Resources\Collections\MyCollectionsResource)
@use(App\Filament\Owner\Resources\Lotes\LoteResource)
@use(App\Filament\Owner\Resources\ShareHoldingResource)
@use(App\Models\ShareHolding)
@use(Illuminate\Support\Number)

<x-filament-panels::page>
    @if ($owner && $communitySettings)
        <x-filament::section heading="Documentos comunitarios">
            @livewire('document-manager', ['entity' => $communitySettings, 'moduleKey' => 'comunidad'], key('community-documents'))
        </x-filament::section>
    @endif
    @include('filament.owner.pages.partials.dashboard-styles')

    <div class="owner-dashboard">
        <header class="od-top">
            <div>
                <p class="od-kicker">Inicio / Mi comunidad</p>
                <div class="od-title-line">
                    <span class="od-title-icon"><x-filament::icon icon="heroicon-o-user-group" /></span>
                    <div>
                        <h1>Mi comunidad</h1>
                        <p class="od-muted">Hola, {{ $owner?->nombre ?? auth()->user()?->name }}. Este es el resumen de tu participación.</p>
                    </div>
                </div>
            </div>
            <div class="od-controls">
                @if (\App\Models\CommunitySetting::logoUrl() === asset('branding/logo-full-transparent.png'))
                    <span class="od-context">{{ $communityName }}</span>
                @endif
                <span class="od-context">{{ $usesShares && $usesLots ? 'Acciones y lotes' : ($usesShares ? 'Solo acciones' : 'Solo lotes') }}</span>
                <span class="od-tag od-success">Portal del propietario</span>
            </div>
        </header>

        @if (! $owner)
            <x-filament::section>
                No tienes un propietario asociado. Contacta a la administración para consultar tu participación.
            </x-filament::section>
        @else
            <section class="od-stats" aria-label="Resumen de mi situación">
                <article class="od-stat">
                    <div class="od-stat-top"><span>Saldo pendiente</span><span class="od-stat-icon od-warning"><x-filament::icon icon="heroicon-o-wallet" /></span></div>
                    <p class="od-number">{{ Number::currency($summary['pending_balance'], 'CLP', locale: 'es_CL') }}</p>
                    <p class="od-small">{{ $pendingCount }} cobros por pagar</p>
                </article>
                <article class="od-stat">
                    <div class="od-stat-top"><span>Total pagado</span><span class="od-stat-icon od-success"><x-filament::icon icon="heroicon-o-check-circle" /></span></div>
                    <p class="od-number">{{ Number::currency($summary['total_collections'], 'CLP', locale: 'es_CL') }}</p>
                    <p class="od-small">Recaudaciones registradas, sin anuladas</p>
                </article>
                @if ($summary['total_non_monetary_applied'] > 0)
                    <article class="od-stat">
                        <div class="od-stat-top"><span>Aplicado como ajuste no monetario</span><span class="od-stat-icon"><x-filament::icon icon="heroicon-o-document-check" /></span></div>
                        <p class="od-number">{{ Number::currency($summary['total_non_monetary_applied'], 'CLP', locale: 'es_CL') }}</p>
                        <p class="od-small">No es pago con recaudaciones ni ingreso de caja</p>
                    </article>
                @endif
                @if ($usesShares)
                    <article class="od-stat">
                        <div class="od-stat-top"><span>Mis acciones</span><span class="od-stat-icon"><x-filament::icon icon="heroicon-o-chart-pie" /></span></div>
                        <p class="od-number">{{ ShareHolding::displayQuantity($shares['quantity']) }}</p>
                        <p class="od-small">Participación activa registrada</p>
                    </article>
                @endif
                @if ($usesLots)
                    <article class="od-stat">
                        <div class="od-stat-top"><span>Mis lotes</span><span class="od-stat-icon"><x-filament::icon icon="heroicon-o-map" /></span></div>
                        <p class="od-number">{{ $lots->count() }}</p>
                        <p class="od-small">Asignaciones activas</p>
                    </article>
                @endif
                @if (! ($usesShares && $usesLots))
                    <article class="od-stat">
                        <div class="od-stat-top"><span>Saldo a favor</span><span class="od-stat-icon od-success"><x-filament::icon icon="heroicon-o-banknotes" /></span></div>
                        <p class="od-number">{{ Number::currency($summary['credit_balance'], 'CLP', locale: 'es_CL') }}</p>
                        <p class="od-small">Disponible para aplicar a cobros</p>
                    </article>
                @endif
            </section>

            @livewire(\App\Filament\Owner\Widgets\OverdueAlertsWidget::class)

            <div class="od-main">
                <div class="od-column">
                    <section class="od-panel" aria-labelledby="od-due-title">
                        <div class="od-panel-head">
                            <div><h2 id="od-due-title">Próximos vencimientos</h2><p>Revisa tus cobros y fechas de pago</p></div>
                            <a class="od-link" href="{{ MisCobrosResource::getUrl() }}">Ver mis cobros →</a>
                        </div>
                        <div class="od-panel-body">
                            @forelse ($pendingCharges as $charge)
                                <a class="od-due" href="{{ MisCobrosResource::getUrl('view', ['record' => $charge]) }}">
                                    <div class="od-due-left">
                                        <div class="od-date-tile">
                                            <span>{{ $charge->due_date?->locale('es')->translatedFormat('M') ?? 'Sin' }}</span>
                                            <strong>{{ $charge->due_date?->format('d') ?? '—' }}</strong>
                                        </div>
                                        <div><strong>{{ $charge->description ?: 'Cobro #'.$charge->id }}</strong><p class="od-small">{{ $charge->due_date ? 'Vence el '.$charge->due_date->format('d/m/Y') : 'Sin fecha de vencimiento' }}</p></div>
                                    </div>
                                    <div class="od-due-right">
                                        <strong>{{ Number::currency((float) $charge->remaining_amount, 'CLP', locale: 'es_CL') }}</strong>
                                        <span class="od-tag {{ $charge->due_date?->isBefore(today()) ? 'od-danger' : 'od-warning' }}">{{ $charge->due_date?->isBefore(today()) ? 'Vencido' : ($charge->status->value === 'partial' ? 'Aplicación parcial' : 'Pendiente') }}</span>
                                    </div>
                                </a>
                            @empty
                                <p class="od-empty">No tienes cobros pendientes.</p>
                            @endforelse
                            <p class="od-note">Saldo a favor: <strong>{{ Number::currency($summary['credit_balance'], 'CLP', locale: 'es_CL') }}</strong> · Balance neto: <strong>{{ Number::currency($summary['net_balance'], 'CLP', locale: 'es_CL') }}</strong></p>
                        </div>
                    </section>

                    <section class="od-panel" aria-labelledby="od-movements-title">
                        <div class="od-panel-head">
                            <div><h2 id="od-movements-title">Mis movimientos</h2><p>Historial de cobros, pagos y aplicaciones</p></div>
                            <a class="od-link" href="{{ EstadoDeCuenta::getUrl() }}">Estado de cuenta →</a>
                        </div>
                        <div class="od-panel-body">
                            <div class="od-toolbar">
                                <div class="od-filters" role="group" aria-label="Filtrar movimientos">
                                    @foreach (['all' => 'Todos', 'charge' => 'Cobros', 'payment' => 'Pagos', 'allocation' => 'Aplicaciones'] as $key => $label)
                                        <button type="button" wire:click="filterMovements('{{ $key }}')" aria-pressed="{{ $movementFilter === $key ? 'true' : 'false' }}">{{ $label }}</button>
                                    @endforeach
                                </div>
                                <span class="od-small">{{ $movementCount }} movimientos</span>
                            </div>
                            <div class="od-table-scroll">
                                <table class="od-table">
                                    <thead><tr><th>Fecha</th><th>Concepto</th><th>Tipo</th><th class="od-sum">Monto</th></tr></thead>
                                    <tbody>
                                        @forelse ($movements as $movement)
                                            <tr wire:key="movement-{{ $movement['type'] }}-{{ $movement['related_id'] }}">
                                                <td class="od-date">{{ $movement['date_label'] ?? $movement['date']?->format('d/m/Y') ?? 'Desconocida' }}</td>
                                                <td>{{ $movement['description'] }}</td>
                                                <td><span class="od-tag {{ $movement['type'] === 'payment' ? 'od-success' : '' }}">{{ ['charge' => 'Cobro', 'payment' => 'Pago', 'allocation' => 'Aplicación', 'historical_adjustment' => 'Ajuste histórico'][$movement['type']] }}</span></td>
                                                <td class="od-sum">{{ Number::currency($movement['amount'], 'CLP', locale: 'es_CL') }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="od-empty">No hay movimientos para este filtro.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <div class="od-footer">
                                <span>Mostrando {{ $movements->count() }} de {{ $movementCount }} movimientos</span>
                                @if ($movementCount > $movements->count())
                                    <button type="button" class="od-link" wire:click="showMoreMovements">Mostrar más →</button>
                                @endif
                            </div>
                        </div>
                    </section>
                    @livewire(\App\Filament\Owner\Widgets\NextCollectionEstimateWidget::class)
                </div>

                <div class="od-column">
                    <section class="od-panel" aria-labelledby="od-participation-title">
                        <div class="od-panel-head"><div><h2 id="od-participation-title">Mi participación</h2><p>Derechos y asignaciones registrados</p></div></div>
                        <div class="od-panel-body">
                            <div class="od-holdings">
                                @if ($usesShares)
                                    <a class="od-holding" href="{{ ShareHoldingResource::getUrl() }}"><div><strong>Acciones</strong><p class="od-small">Participación registrada</p></div><b>{{ ShareHolding::displayQuantity($shares['quantity']) }}</b></a>
                                    <div class="od-holding"><div><strong>Superficie referencial</strong><p class="od-small">Según equivalencias de adquisición</p></div><b>{{ Number::format((float) $shares['square_meters'] / 10000, maxPrecision: 4, locale: 'es_CL') }} ha</b></div>
                                @endif
                                @if ($usesLots)
                                    <a class="od-holding" href="{{ LoteResource::getUrl() }}"><div><strong>Lotes asignados</strong><p class="od-small">{{ $lots->isEmpty() ? 'Sin asignaciones actuales' : $lots->pluck('lot_code')->join(', ') }}</p></div><b>{{ $lots->count() }}</b></a>
                                    <div class="od-holding"><div><strong>Superficie de lotes</strong><p class="od-small">Asignaciones activas</p></div><b>{{ Number::format($summary['total_hectares'], maxPrecision: 4, locale: 'es_CL') }} ha</b></div>
                                @endif
                            </div>
                            <p class="od-note">{{ $usesShares ? 'Las acciones no equivalen automáticamente a lotes asignados.' : 'Se muestran tus asignaciones activas registradas por la administración.' }}</p>
                        </div>
                    </section>
                    <section class="od-panel" aria-labelledby="od-quick-title">
                        <div class="od-panel-head"><div><h2 id="od-quick-title">Accesos rápidos</h2><p>Lo que más puedes necesitar</p></div></div>
                        <div class="od-panel-body od-quick">
                            <a href="{{ EstadoDeCuenta::getUrl() }}"><span><x-filament::icon icon="heroicon-o-document-text" /></span>Estado de cuenta</a>
                            <a href="{{ MyCollectionsResource::getUrl() }}"><span><x-filament::icon icon="heroicon-o-receipt-percent" /></span>Mis comprobantes</a>
                            @if ($usesLots)
                                <a href="{{ MapaLoteo::getUrl() }}"><span><x-filament::icon icon="heroicon-o-map" /></span>Mapa del proyecto</a>
                            @endif
                            <a href="{{ MisCobrosResource::getUrl() }}"><span><x-filament::icon icon="heroicon-o-banknotes" /></span>Mis cobros</a>
                        </div>
                    </section>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
