<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Owners\Services\ShareContributionService;
use App\Filament\Admin\Resources\ShareContributionResource\Pages\ListShareContributions;
use App\Filament\Forms\ChileanNumberInput;
use App\Models\CommunitySetting;
use App\Models\ShareContribution;
use App\Models\ShareHolding;
use App\Support\AdminTableColumns;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ShareContributionResource extends Resource
{
    protected static ?string $model = ShareContribution::class;

    protected static ?string $navigationLabel = 'Aportes por acciones';

    protected static ?string $modelLabel = 'Aporte por acciones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && CommunitySetting::current()->usesShares();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('propietario');
    }

    public static function typeLabel(string $state): string
    {
        return match ($state) {
            'opening' => 'Saldo histórico inicial', 'acquisition' => 'Adquisición', 'correction' => 'Corrección de aporte',
        };
    }

    public static function receiptAction(): Action
    {
        return Action::make('receipt')->label('Descargar comprobante')
            ->authorize(fn (ShareContribution $record): bool => auth()->user()?->can('view', $record) ?? false)
            ->visible(fn (ShareContribution $record): bool => $record->receipt_path !== null)
            ->action(fn (ShareContribution $record) => app(ShareContributionService::class)->downloadReceipt($record, auth()->user()));
    }

    public static function detailAction(): Action
    {
        return Action::make('detail')->label('Ver detalle')->icon('heroicon-o-document-magnifying-glass')
            ->modalSubmitAction(false)
            ->modalContent(fn (ShareContribution $record): View => view('filament.shares.contribution-detail', ['record' => $record]));
    }

    public static function correctAction(): Action
    {
        return Action::make('correct')->label('Corregir aporte')->requiresConfirmation()
            ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false)
            ->visible(fn (ShareContribution $record): bool => $record->type !== 'correction')
            ->modalDescription('Ingrese el monto total correcto de este aporte. Se registra la diferencia como movimiento auditado; no cambia las acciones ni el registro original.')
            ->schema([
                ChileanNumberInput::make('corrected_amount')->label('Monto total correcto')->required()->rules(['gte:0']),
                Textarea::make('reason')->label('Motivo de corrección')->required()->maxLength(1000),
                Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid()),
            ])
            ->action(fn (ShareContribution $record, array $data) => ShareHoldingResource::performOperation(
                fn () => app(ShareContributionService::class)->correct($record, (string) $data['corrected_amount'], $data['reason'], $data['operation_key'], auth()->user())
            ));
    }

    public static function readOnlyTable(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('propietario.nombre_completo')->label('Socio')->searchable(['nombre', 'apellido']),
            TextColumn::make('type')->label('Registro')->formatStateUsing(fn (string $state): string => static::typeLabel($state)),
            TextColumn::make('quantity')->label('Acciones registradas (referencia)')
                ->formatStateUsing(fn (string $state): string => ShareHolding::displayQuantity($state))
                ->placeholder('Sin cambio'),
            TextColumn::make('amount')->label('Aporte / ajuste')->money('CLP', locale: 'es_CL')->sortable(),
            TextColumn::make('reference_date')->label('Fecha de referencia')->date()->placeholder('Desconocida')->sortable(),
            TextColumn::make('notes')->label('Observaciones')->wrap(),
            TextColumn::make('corrects_contribution_id')->label('Corrige aporte #')->placeholder('-'),
            TextColumn::make('created_by')->label('Administrador #'),
            TextColumn::make('created_at')->label('Registrado')->dateTime(),
        ]);
    }

    /** Tabla compacta de la página «Aportes por acciones»: pocas columnas, detalle en el menú de acciones. */
    public static function historyTable(Table $table): Table
    {
        $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('propietario.nombre_completo')->label('Socio')->searchable(['nombre', 'apellido'])->sortable(['nombre', 'apellido']),
            TextColumn::make('type')->label('Registro')->badge()
                ->formatStateUsing(fn (string $state): string => static::typeLabel($state))
                ->color(fn (string $state): string => match ($state) {
                    'opening' => 'gray', 'acquisition' => 'success', default => 'warning',
                }),
            TextColumn::make('amount')->label('Aporte / ajuste')->money('CLP', locale: 'es_CL')->sortable()
                ->color(fn (ShareContribution $record): ?string => (float) $record->amount === 0.0 ? 'info' : null)
                ->description(fn (ShareContribution $record): ?string => (float) $record->amount === 0.0 ? 'Registrado explícitamente como $0' : null),
            TextColumn::make('reference_date')->label('Fecha de referencia')->date()->placeholder('Desconocida')->sortable(),
            TextColumn::make('created_at')->label('Registrado')->date(),
        ]);
        AdminTableColumns::contentSized($table->getColumns());

        return $table->filters([
            SelectFilter::make('propietario_id')->label('Socio')->relationship('propietario', 'nombre'),
            SelectFilter::make('type')->label('Tipo de registro')->options([
                'opening' => 'Saldo histórico inicial', 'acquisition' => 'Adquisición', 'correction' => 'Corrección de aporte',
            ]),
            SelectFilter::make('informacion')->label('Estado de información')
                ->options(['desconocida' => 'Historial desconocido', 'completa' => 'Historial completo'])
                ->query(function (Builder $query, array $data): Builder {
                    if (blank($data['value'] ?? null)) {
                        return $query;
                    }
                    $unknownIds = app(ShareContributionService::class)->ownerIdsWithUnknownHistory();

                    return $data['value'] === 'desconocida' ? $query->whereIn('propietario_id', $unknownIds) : $query->whereNotIn('propietario_id', $unknownIds);
                }),
        ])->recordActions([
            ActionGroup::make([
                static::detailAction(),
                static::receiptAction(),
                static::correctAction(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return static::readOnlyTable($table)->filters([
            SelectFilter::make('propietario_id')->label('Socio')->relationship('propietario', 'nombre'),
            SelectFilter::make('type')->options(['opening' => 'Saldo inicial', 'acquisition' => 'Adquisición', 'correction' => 'Corrección']),
        ])->recordActions([
            static::receiptAction(),
            static::correctAction(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShareContributions::route('/')];
    }
}
