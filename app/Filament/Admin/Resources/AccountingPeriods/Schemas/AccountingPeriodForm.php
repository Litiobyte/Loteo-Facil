<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Schemas;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AccountingPeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('year')
                    ->label('Año')
                    ->required()
                    ->numeric()
                    ->minValue(2020)
                    ->maxValue(2100),
                TextInput::make('month')
                    ->label('Mes')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(12),
                DatePicker::make('period_start')
                    ->label('Inicio de período')
                    ->native(false)
                    ->required(),
                DatePicker::make('period_end')
                    ->label('Fin de período')
                    ->native(false)
                    ->required(),
                Select::make('status')
                    ->label('Estado')
                    ->options([
                        AccountingPeriodStatus::Open->value => AccountingPeriodStatus::Open->getLabel(),
                        AccountingPeriodStatus::Closed->value => AccountingPeriodStatus::Closed->getLabel(),
                    ])
                    ->native(false)
                    ->default(AccountingPeriodStatus::Open->value)
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('close_folio')
                    ->label('Folio de cierre')
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
