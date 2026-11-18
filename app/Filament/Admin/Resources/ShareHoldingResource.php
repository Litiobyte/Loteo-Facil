<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Owners\Services\ShareContributionService;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Filament\Admin\Resources\ShareHoldingResource\Pages\ManageShareHoldings;
use App\Filament\Forms\ChileanNumberInput;
use App\Models\CommunitySetting;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Support\AdminTableColumns;
use App\Support\ChileanNumber;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ShareHoldingResource extends Resource
{
    protected static ?string $model = ShareHolding::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Acciones';

    protected static ?string $modelLabel = 'Participación';

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

    /** @return array<mixed> */
    public static function operationFields(): array
    {
        return [
            TextInput::make('quantity')->label('Cantidad de acciones')->required()->rules(['numeric', 'gte:0']),
            Textarea::make('reason')->label('Motivo / observaciones')->required()->maxLength(1000),
            Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    public static function ownerField(string $name = 'propietario_id'): Select
    {
        return Select::make($name)->label('Propietario')->required()->searchable()->options(
            fn (): array => Propietario::query()->orderBy('id')->get()->mapWithKeys(fn (Propietario $owner): array => [$owner->id => $owner->nombre_completo])->all()
        );
    }

    public static function historyAction(): Action
    {
        return Action::make('history')->label('Historial')->modalSubmitAction(false)
            ->modalContent(function (ShareHolding $record): View {
                auth()->user()->can('view', $record) || abort(403);

                return view('filament.shares.history', ['movements' => $record->movements()->orderBy('id')->get()]);
            });
    }

    /** @return array<mixed> */
    public static function registrationFields(): array
    {
        return [
            TextInput::make('community')->label('Comunidad')->default('Comunidad de esta instalación')->disabled()->dehydrated(false),
            TextInput::make('quantity')->label('Cantidad de acciones')->required()->rules(['numeric', 'gte:0']),
            ChileanNumberInput::make('amount')->label('Monto total aportado')->required()->rules(['gte:0'])
                ->helperText('Independiente de la cantidad de acciones. No se calcula desde un precio unitario.'),
            Textarea::make('reason')->label('Observaciones (opcional)')->maxLength(1000),
            Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    public static function performOperation(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DomainException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return null;
        }
    }

    public static function table(Table $table): Table
    {
        $table = static::readOnlyTable($table);
        AdminTableColumns::contentSized($table->getColumns());
        $table->getColumn('propietario.nombre_completo')
            ->searchable(['nombre', 'apellido'])
            ->sortable(['nombre', 'apellido'])
            ->toggleable();
        $table->getColumn('quantity')->sortable()->toggleable();
        $table->getColumn('square_meters_per_share')->sortable()->toggleable();
        $table->getColumn('equivalent_area')->toggleable();
        $table->getColumn('acquired_at')->sortable()->toggleable();
        $table->getColumn('status')->sortable()->toggleable();

        return $table->headerActions([
            Action::make('opening')->label('Saldo histórico consolidado')->requiresConfirmation()
                ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false)
                ->visible(fn (): bool => CommunitySetting::current()->usesShares())
                ->modalDescription('Si ya existen acciones, la cantidad debe coincidir con el total actual (incluidas inactivas). Se reutilizarán sin duplicarlas. Registre el saldo inicial antes de nuevas adquisiciones con aporte.')
                ->schema([static::ownerField(), DatePicker::make('reference_date')->label('Fecha de referencia (opcional)')->maxDate(today()), ...static::registrationFields()])
                ->action(fn (array $data) => static::performOperation(fn () => app(ShareContributionService::class)->recordOpening(
                    Propietario::query()->findOrFail($data['propietario_id']), (string) $data['quantity'], (string) $data['amount'],
                    $data['reference_date'] ?? null, $data['reason'] ?? null, $data['operation_key'], auth()->user()
                ))),
            Action::make('acquire')->label('Nueva adquisición y aporte')
                ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false)
                ->visible(fn (): bool => CommunitySetting::current()->usesShares())
                ->schema([
                    static::ownerField(), DatePicker::make('acquired_at')->label('Adquisición')->default(today())->maxDate(today())->required(),
                    ...static::registrationFields(),
                    FileUpload::make('receipt')->label('Comprobante (opcional)')->disk('local')->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(5120)
                        ->storeFiles(false)->preventFilePathTampering()->previewable(false)->openable(false)->downloadable(false),
                ])
                ->action(fn (array $data) => static::performOperation(fn () => app(ShareContributionService::class)->acquire(
                    Propietario::query()->findOrFail($data['propietario_id']), (string) $data['quantity'], (string) $data['amount'],
                    $data['acquired_at'], $data['reason'] ?? null, $data['operation_key'], auth()->user(), $data['receipt'] ?? null
                ))),
        ])->recordActions([
            static::historyAction(),
            Action::make('adjust')->label('Ajustar')->requiresConfirmation()
                ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false)
                ->visible(fn (): bool => CommunitySetting::current()->usesShares())
                ->schema([...static::operationFields(), Select::make('status')->options(['active' => 'Activa', 'inactive' => 'Inactiva'])->required()])
                ->fillForm(fn (ShareHolding $record): array => ['quantity' => $record->quantity, 'status' => $record->status, 'operation_key' => (string) Str::uuid()])
                ->action(fn (ShareHolding $record, array $data) => static::performOperation(fn () => app(ShareHoldingService::class)->adjust(
                    $record, (string) $data['quantity'], $data['status'], $data['operation_key'], $data['reason'], auth()->user()
                ))),
            Action::make('transfer')->label('Transferir')->requiresConfirmation()
                ->authorize(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false)
                ->visible(fn (): bool => CommunitySetting::current()->usesShares())
                ->schema([static::ownerField('recipient_id'), ...static::operationFields()])
                ->action(fn (ShareHolding $record, array $data) => static::performOperation(fn () => app(ShareHoldingService::class)->transfer(
                    $record, Propietario::query()->findOrFail($data['recipient_id']), (string) $data['quantity'],
                    $data['operation_key'], $data['reason'], auth()->user()
                ))),
        ]);
    }

    public static function readOnlyTable(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('propietario.nombre_completo')->label('Propietario'),
            TextColumn::make('quantity')->label('Acciones')
                ->formatStateUsing(fn (string $state): string => ShareHolding::displayQuantity($state)),
            TextColumn::make('square_meters_per_share')->label('m² por acción (adquisición)')->formatStateUsing(fn (mixed $state): ?string => ChileanNumber::format($state)),
            TextColumn::make('equivalent_area')->label('Superficie equivalente m²')->state(fn (ShareHolding $record): string => $record->equivalentSquareMeters())->formatStateUsing(fn (mixed $state): ?string => ChileanNumber::format($state)),
            TextColumn::make('acquired_at')->label('Adquisición')->date()->placeholder('Desconocida'),
            TextColumn::make('status')->label('Estado')->badge(),
        ])->filters([
            SelectFilter::make('propietario_id')->label('Propietario')->relationship('propietario', 'nombre'),
            SelectFilter::make('status')->options(['active' => 'Activa', 'inactive' => 'Inactiva']),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageShareHoldings::route('/')];
    }
}
