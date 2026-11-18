<?php

namespace App\Filament\Owner\Resources\Cobros\Pages;

use App\Domain\Charges\Enums\ChargeStatus;
use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Models\PartnerCharge;
use App\Support\ChileanNumber;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewCobro extends ViewRecord
{
    protected static string $resource = MisCobrosResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public static function buildInfolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información del Cobro')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('id')->label('ID')->formatStateUsing(fn (mixed $state): string => '#'.str_pad((string) $state, 3, '0', STR_PAD_LEFT)),
                        TextEntry::make('consolidation')->label('Consolidación')->badge()->color('gray')->state(fn (PartnerCharge $record): ?string => MisCobrosResource::consolidationLabel($record)),
                        TextEntry::make('description')->label('Descripción')->placeholder('-'),
                        TextEntry::make('amount')->label('Monto total')->money('CLP', locale: 'es_CL'),
                        TextEntry::make('paid_amount')->label('Pagado')->money('CLP', locale: 'es_CL'),
                        TextEntry::make('remaining_amount')->label('Pendiente')->state(fn (PartnerCharge $record): string => $record->status === ChargeStatus::Consolidated ? '0' : $record->remaining_amount)->money('CLP', locale: 'es_CL'),
                        TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state): string => MisCobrosResource::statusLabel($state))->color(fn ($state): string => MisCobrosResource::statusColor($state)),
                        TextEntry::make('due_date')->label('Fecha vencimiento')->date('d/m/Y'),
                        TextEntry::make('created_at')->label('Fecha creación')->dateTime('d/m/Y H:i'),
                    ]),
                Section::make('Origen del Cobro')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('expense.title')->label('Título del gasto'),
                        TextEntry::make('expense.category.name')->label('Categoría'),
                        TextEntry::make('expense.expense_date')->label('Fecha del gasto')->date('d/m/Y'),
                        TextEntry::make('expense.amount')->label('Monto total del gasto')->money('CLP', locale: 'es_CL'),
                        TextEntry::make('expense.distribution_type')->label('Tipo de distribución')->badge()->formatStateUsing(fn ($state): string => MisCobrosResource::calculationTypeLabel($state)),
                    ]),
                Section::make('Cálculo Aplicado')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('calculation_type')->label('Tipo de cálculo')->badge()->formatStateUsing(fn ($state): string => MisCobrosResource::calculationTypeLabel($state)),
                        TextEntry::make('expense.amount')->label('Gasto total')->money('CLP', locale: 'es_CL'),
                        TextEntry::make('partner_hectares_at_moment')->label('Mis hectáreas al momento')->numeric(decimalPlaces: 4),
                        TextEntry::make('total_hectares_at_moment')->label('Total hectáreas al momento')->numeric(decimalPlaces: 4),
                        TextEntry::make('percentage_applied')->label('Porcentaje aplicado')->suffix('%')->numeric(decimalPlaces: 2),
                        TextEntry::make('amount')->label('Mi cobro calculado')->money('CLP', locale: 'es_CL'),
                        TextEntry::make('calculation_notes')->label('Notas de cálculo')->columnSpanFull()->placeholder('-'),
                        TextEntry::make('distribution_snapshot.unit')->label('Unidad de distribución')->placeholder('-'),
                        TextEntry::make('distribution_snapshot.base')->label('Mi base congelada')->placeholder('-'),
                        TextEntry::make('distribution_snapshot.total_base')->label('Total elegible congelado')->placeholder('-'),
                        TextEntry::make('distribution_snapshot.quantity')->label('Mis acciones al emitir')->placeholder('-'),
                        TextEntry::make('distribution_snapshot.equivalent_square_meters')->label('Mi superficie equivalente al emitir (m²)')->formatStateUsing(fn (mixed $state): ?string => ChileanNumber::format($state))->placeholder('-'),
                    ]),
                Section::make('Orígenes del saldo consolidado')
                    ->visible(fn (PartnerCharge $record): bool => $record->charge_type === 'balance_consolidation')
                    ->schema([
                        RepeatableEntry::make('consolidationLinks')
                            ->label('Cobros de origen')
                            ->schema([
                                TextEntry::make('source_charge_id')->label('Cobro original'),
                                TextEntry::make('transferred_amount')->label('Saldo transferido')->money('CLP', locale: 'es_CL'),
                            ])->columns(2),
                    ]),
                Section::make('Historial de Recaudaciones')
                    ->hidden(fn (PartnerCharge $record): bool => $record->allocations->isEmpty())
                    ->schema([
                        RepeatableEntry::make('allocations')
                            ->label('Aplicaciones')
                            ->schema([
                                TextEntry::make('payment.collection_date')->label('Fecha pago')->date('d/m/Y'),
                                TextEntry::make('payment.collection_method')->label('Método')->badge()->formatStateUsing(fn ($state): string => $state?->getLabel() ?? '-'),
                                TextEntry::make('amount')->label('Monto aplicado')->money('CLP', locale: 'es_CL'),
                                TextEntry::make('allocated_at')->label('Fecha aplicación')->dateTime('d/m/Y H:i'),
                            ])
                            ->columns(4),
                    ]),
            ]);
    }
}
