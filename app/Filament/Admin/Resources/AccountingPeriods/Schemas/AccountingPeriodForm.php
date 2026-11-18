<?php

namespace App\Filament\Admin\Resources\AccountingPeriods\Schemas;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconSize;
use Illuminate\Support\Carbon;

class AccountingPeriodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Información del cierre')
                    ->description('Completa los datos del período contable.')
                    ->icon('heroicon-o-calendar-days')
                    ->iconColor('success')
                    ->iconSize(IconSize::Large)
                    ->columns(2)
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->schema([
                        $schema->getOperation() === 'create'
                            ? Select::make('year')
                                ->label('Año')
                                ->options(fn (): array => array_combine(range(now()->year, now()->year - 5), range(now()->year, now()->year - 5)))
                                ->default(fn (): int => now()->year)
                                ->required()
                                ->rules(fn (): array => ['integer', 'min:'.(now()->year - 5), 'max:'.now()->year])
                                ->validationMessages(['max' => 'No se pueden crear cierres para el futuro'])
                                ->live()
                                ->afterStateUpdated(static fn (Get $get, Set $set): mixed => self::syncPeriodDates($get, $set))
                            : TextInput::make('year')
                                ->label('Año')
                                ->required()
                                ->numeric()
                                ->minValue(2020)
                                ->maxValue(2100),
                        $schema->getOperation() === 'create'
                            ? Select::make('month')
                                ->label('Mes')
                                ->options([
                                    1 => 'Enero',
                                    2 => 'Febrero',
                                    3 => 'Marzo',
                                    4 => 'Abril',
                                    5 => 'Mayo',
                                    6 => 'Junio',
                                    7 => 'Julio',
                                    8 => 'Agosto',
                                    9 => 'Septiembre',
                                    10 => 'Octubre',
                                    11 => 'Noviembre',
                                    12 => 'Diciembre',
                                ])
                                ->default(fn (): int => now()->month)
                                ->required()
                                ->rules([
                                    'integer',
                                    'between:1,12',
                                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                        $year = (int) $get('year');
                                        $currentDate = now();

                                        if ($year > $currentDate->year || ($year === $currentDate->year && (int) $value > $currentDate->month)) {
                                            $fail('No se pueden crear cierres para el futuro');
                                        }
                                    },
                                ])
                                ->live()
                                ->afterStateUpdated(static fn (Get $get, Set $set): mixed => self::syncPeriodDates($get, $set))
                            : TextInput::make('month')
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
                            ->hiddenOn('create')
                            ->label('Folio de cierre')
                            ->helperText('Se asigna al cerrar el período.')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                Group::make([
                    Callout::make('¿Qué es un cierre contable?')
                        ->description('Permite consolidar y bloquear la información financiera de un período, evitando modificaciones posteriores. Crear el período lo deja abierto; el cierre se ejecuta después desde el listado.')
                        ->icon('heroicon-o-information-circle')
                        ->color('success'),
                    Section::make('Validaciones')
                        ->icon('heroicon-o-check-circle')
                        ->iconColor('success')
                        ->schema([
                            Text::make('Año entre 2020 y 2100; mes entre 1 y 12.')
                                ->icon('heroicon-o-check'),
                            Text::make('Las fechas se autocompletan al elegir año o mes y puedes ajustarlas manualmente. Al guardar, se ajustan al inicio y fin del mes seleccionado.')
                                ->icon('heroicon-o-check'),
                            Text::make('Solo se permite un período por año y mes.')
                                ->icon('heroicon-o-check'),
                            Text::make('El estado y el folio se gestionan desde el proceso de cierre.')
                                ->icon('heroicon-o-check'),
                        ]),
                ]),
            ]);
    }

    /**
     * Recalcula el inicio y fin de período a partir del año y mes seleccionados,
     * sin bloquear su edición manual.
     */
    public static function syncPeriodDates(Get $get, Set $set): void
    {
        $year = (int) ($get('year') ?: now()->year);
        $month = (int) ($get('month') ?: now()->month);
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        $set('period_start', $start->toDateString());
        $set('period_end', $start->copy()->endOfMonth()->toDateString());
    }
}
