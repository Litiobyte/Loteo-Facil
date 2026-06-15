<?php

namespace App\Filament\Owner\Resources\Collections\Pages;

use App\Filament\Owner\Resources\Cobros\MisCobrosResource;
use App\Filament\Owner\Resources\Collections\MyCollectionsResource;
use App\Models\Collection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewCollection extends ViewRecord
{
    protected static string $resource = MyCollectionsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public static function buildInfolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información del Recaudacion')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('id')->label('ID')->formatStateUsing(fn (mixed $state): string => '#'.str_pad((string) $state, 3, '0', STR_PAD_LEFT)),
                        TextEntry::make('collection_date')->label('Fecha pago')->date('d/m/Y'),
                        TextEntry::make('collection_method')->label('Método de pago')->badge()->formatStateUsing(fn ($state): string => MyCollectionsResource::paymentMethodLabel($state)),
                        TextEntry::make('reference')->label('Referencia')->placeholder('-'),
                        TextEntry::make('amount')->label('Monto total')->money('CLP', locale: 'es_CL'),
                        TextEntry::make('applied_amount')->label('Aplicado')->money('CLP', locale: 'es_CL'),
                        TextEntry::make('unapplied_amount')->label('No aplicado')->money('CLP', locale: 'es_CL'),
                        TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state): string => MyCollectionsResource::statusLabel($state))->color(fn ($state): string => MyCollectionsResource::statusColor($state)),
                        TextEntry::make('notes')->label('Notas')->columnSpanFull()->placeholder('-'),
                    ]),
                Section::make('Aplicaciones (A qué se aplicó este pago)')
                    ->hidden(fn (Collection $record): bool => $record->allocations->isEmpty())
                    ->schema([
                        RepeatableEntry::make('allocations')
                            ->label('Asignaciones')
                            ->schema([
                                TextEntry::make('charge.description')
                                    ->label('Cobro')
                                    ->formatStateUsing(function (mixed $state, $record): string {
                                        $description = is_string($state) && $state !== '' ? $state : 'Cobro sin descripción';

                                        return sprintf('#%d - %s', (int) $record->partner_charge_id, $description);
                                    }),
                                TextEntry::make('amount')->label('Monto aplicado')->money('CLP', locale: 'es_CL'),
                                TextEntry::make('allocated_at')->label('Fecha aplicación')->dateTime('d/m/Y H:i'),
                                TextEntry::make('charge.status')->label('Estado del cobro')->badge()->formatStateUsing(fn ($state): string => MisCobrosResource::statusLabel($state))->color(fn ($state): string => MisCobrosResource::statusColor($state)),
                            ])
                            ->columns(4),
                        TextEntry::make('total_applied')
                            ->label('Total aplicado')
                            ->state(fn (Collection $record): float => (float) $record->allocations->sum('amount'))
                            ->money('CLP', locale: 'es_CL'),
                    ]),
            ]);
    }
}
