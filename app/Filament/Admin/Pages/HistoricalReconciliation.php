<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Balances\Services\HistoricalReconciliationService;
use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Models\CommunitySetting;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Attributes\Locked;

class HistoricalReconciliation extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Importaciones';

    protected static ?string $navigationLabel = 'Conciliación histórica';

    protected static ?string $title = 'Conciliación histórica';

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected string $view = 'filament.admin.pages.historical-reconciliation';

    #[Locked]
    public ?int $ownerId = null;

    #[Locked]
    public ?string $expectedDebt = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function selectOwnerAction(): Action
    {
        return Action::make('selectOwner')->label('Consultar propietario')
            ->schema([
                Select::make('owner_id')->label('Propietario')->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search): array => Propietario::query()
                        ->where(fn ($query) => $query->where('nombre', 'like', "%{$search}%")->orWhere('apellido', 'like', "%{$search}%")->orWhere('rut', 'like', "%{$search}%"))
                        ->limit(50)->get()->mapWithKeys(fn (Propietario $owner): array => [$owner->id => "{$owner->nombre_completo} · {$owner->rut}"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => Propietario::find($value)?->nombre_completo),
                $this->amountField('expected_debt', 'Deuda esperada (opcional)')->required(false),
            ])
            ->action(function (array $data): void {
                abort_unless(static::canAccess(), 403);
                Propietario::query()->findOrFail($data['owner_id']);
                $this->ownerId = (int) $data['owner_id'];
                $this->expectedDebt = $data['expected_debt'] ?? null;
            });
    }

    public function recognizePaymentAction(): Action
    {
        return Action::make('recognizePayment')->label('Reconocer pago')
            ->visible(fn (): bool => $this->ownerId !== null && static::canAccess())
            ->requiresConfirmation()
            ->modalDescription('Impacta caja: registre solo dinero efectivamente recibido. Quedará reconocido sin comprobante. Las aplicaciones deben sumar exactamente el monto del pago.')
            ->modalSubmitActionLabel('Confirmar reconocimiento')
            ->schema([
                $this->amountField('amount', 'Monto del pago'),
                DatePicker::make('payment_date')->label('Fecha del pago (AAAA-MM-DD)')->native()->required()->rules(['date_format:Y-m-d']),
                Repeater::make('allocations')->label('Cobros cubiertos')->minItems(1)->required()->schema([
                    Select::make('charge_id')->label('Cobro destino')->options(fn (): array => $this->chargeOptions())->required()->distinct(),
                    $this->amountField('amount', 'Monto aplicado al cobro'),
                ])->helperText('La suma debe coincidir con el pago. El servicio valida esta regla antes de registrar movimientos.'),
                ...$this->evidenceFields(),
            ])
            ->action(function (array $data, Action $action): void {
                $owner = $this->selectedOwner();
                try {
                    app(HistoricalReconciliationService::class)->recognizeHistoricalPayment(
                        $owner, auth()->user(), $data['amount'], $data['payment_date'],
                        $data['allocations'], $data['source'], $data['reference'] ?? null, $data['observations'] ?? null,
                    );
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Pago reconocido y registrado en caja.')->send();
            });
    }

    public function classifyDifferenceAction(): Action
    {
        $reducesDebt = fn (Get $get): bool => in_array($get('category'), [
            HistoricalAdjustmentCategory::DebtCorrection->value,
            HistoricalAdjustmentCategory::DebtForgiveness->value,
        ], true);

        return Action::make('classifyDifference')->label('Clasificar diferencia')
            ->visible(fn (): bool => $this->ownerId !== null && static::canAccess())
            ->requiresConfirmation()
            ->modalDescription('La clasificación es una decisión manual del administrador, nunca automática. Sin clasificación se registra la comparación sin reducir deuda. Una diferencia no explicada tampoco reduce deuda.')
            ->modalSubmitActionLabel('Confirmar decisión manual')
            ->schema([
                $this->amountField('expected_debt', 'Deuda esperada')->default(fn (): ?string => $this->expectedDebt),
                DatePicker::make('cutoff_date')->label('Fecha de corte (opcional)')->rules(['nullable', 'date_format:Y-m-d']),
                Select::make('category')->label('Clasificación (opcional)')->live()->options([
                    HistoricalAdjustmentCategory::DebtCorrection->value => 'Corrección de deuda (sin caja)',
                    HistoricalAdjustmentCategory::DebtForgiveness->value => 'Condonación (sin caja)',
                    HistoricalAdjustmentCategory::UnexplainedDifference->value => 'Diferencia no explicada (no reduce deuda)',
                ]),
                $this->amountField('amount', 'Monto a clasificar')->visible(fn (Get $get): bool => filled($get('category'))),
                Select::make('charge_id')->label('Cobro destino')->options(fn (): array => $this->chargeOptions())->visible($reducesDebt)->required($reducesDebt),
                DatePicker::make('effective_date')->label('Fecha efectiva diaria (AAAA-MM-DD)')
                    ->visible(fn (Get $get): bool => filled($get('category')))->required()->rules(['date_format:Y-m-d']),
                ...$this->evidenceFields(),
            ])
            ->action(function (array $data, Action $action): void {
                $owner = $this->selectedOwner();
                $classification = empty($data['category']) ? null : [
                    'category' => $data['category'], 'amount' => $data['amount'],
                    'charge_id' => $data['charge_id'] ?? null, 'effective_date' => $data['effective_date'],
                ];
                try {
                    app(HistoricalReconciliationService::class)->reconcile(
                        $owner, auth()->user(), $data['expected_debt'], $data['source'],
                        $data['cutoff_date'] ?? null, $classification, $data['reference'] ?? null, $data['observations'] ?? null,
                    );
                } catch (DomainException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();
                }
                $this->expectedDebt = $data['expected_debt'];
                Notification::make()->success()->title('Decisión de conciliación registrada.')->send();
            });
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'community' => CommunitySetting::current(),
            'owner' => $this->ownerId === null ? null : $this->selectedOwner(),
            'report' => $this->ownerId === null ? null : app(HistoricalReconciliationService::class)->report($this->selectedOwner(), $this->expectedDebt),
        ];
    }

    private function selectedOwner(): Propietario
    {
        abort_unless(static::canAccess(), 403);

        return Propietario::query()->findOrFail($this->ownerId);
    }

    private function chargeOptions(): array
    {
        $owner = $this->selectedOwner();

        return PartnerCharge::query()->where('propietario_id', $owner->id)
            ->whereIn('status', ['pending', 'partial'])->orderBy('id')->get()
            ->mapWithKeys(fn (PartnerCharge $charge): array => [$charge->id => "Cobro #{$charge->id} · pendiente: {$charge->remaining_amount}"])->all();
    }

    private function amountField(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label($label)->required()
            ->rules(['regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D'])
            ->validationMessages(['regex' => 'Use un monto sin separadores de miles y con hasta dos decimales, por ejemplo 1200.50.'])
            ->helperText('Sin separadores de miles; punto para decimales.');
    }

    private function evidenceFields(): array
    {
        return [
            TextInput::make('source')->label('Fuente de información')->required()->maxLength(255)->rules(['not_regex:/^\s*$/u']),
            TextInput::make('reference')->label('Referencia (opcional)')->maxLength(255),
            Textarea::make('observations')->label('Observaciones (opcional)')->maxLength(5000),
        ];
    }
}
