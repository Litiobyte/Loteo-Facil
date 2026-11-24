<?php

namespace App\Domain\Imports\Services;

use App\Domain\Accounting\Services\HistoricalPeriodGuard;
use App\Domain\Charges\Enums\ChargeStatus;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\CommunitySetting;
use App\Models\Expense;
use App\Models\HistoricalAdjustment;
use App\Models\HistoricalExternalIdentity;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FinancialChargeImportService
{
    public function __construct(
        private readonly FinancialImportValueParser $parser,
        private readonly HistoricalPeriodGuard $guard,
        private readonly HistoricalOpeningService $openings,
        private readonly FinancialExpenseImportService $expenses,
    ) {}

    public function normalize(array $data): array
    {
        Validator::make($data, [
            'source' => ['required', 'string', 'max:100'],
            'external_id' => ['required', 'string', 'max:191'],
            'mode' => ['required', Rule::in(['linked', 'backing', 'opening'])],
            'propietario_id' => ['required', 'integer', 'exists:propietarios,id'],
            'amount' => ['required', 'string'],
            'origin_date' => ['required', 'string'],
            'due_date' => ['nullable', 'string'],
            'expense_id' => ['nullable', 'integer'],
            'expense_external_id' => ['nullable', 'string', 'max:191'],
            'expense_source' => ['nullable', 'string', 'max:100'],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'title' => ['nullable', 'string', 'max:200'],
            'reported_paid' => ['nullable', 'string'],
            'reported_remaining' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ])->validate();
        foreach (['source', 'external_id'] as $key) {
            $data[$key] = trim($data[$key]);
            if ($data[$key] === '') {
                throw new DomainException('La fuente y la identidad no pueden estar vacías.');
            }
        }
        $data['amount'] = $this->parser->amount($data['amount']);
        if (BigDecimal::of($data['amount'])->isLessThanOrEqualTo(0)) {
            throw new DomainException('El monto del cobro debe ser positivo.');
        }
        $data['origin_date'] = $this->parser->date($data['origin_date']);
        $data['due_date'] = ! empty($data['due_date']) ? $this->parser->date($data['due_date']) : null;
        if ($data['due_date'] !== null && $data['due_date'] < $data['origin_date']) {
            throw new DomainException('El vencimiento no puede preceder la fecha de origen.');
        }
        $data['effective_date'] = $data['due_date'] ?? $data['origin_date'];
        foreach (['reported_paid', 'reported_remaining'] as $key) {
            $data[$key] = isset($data[$key]) && trim((string) $data[$key]) !== '' ? $this->parser->amount($data[$key]) : null;
            if ($data[$key] !== null && BigDecimal::of($data[$key])->isLessThan(0)) {
                throw new DomainException('Los importes informados no pueden ser negativos.');
            }
        }
        if ($data['mode'] === 'linked' && empty($data['expense_id']) && empty($data['expense_external_id'])) {
            throw new DomainException('Indique la identidad externa o el ID de referencia del gasto.');
        }
        if ($data['mode'] === 'backing' && (empty($data['expense_external_id']) || empty($data['expense_category_id']) || empty($data['title']))) {
            throw new DomainException('El respaldo requiere identidad externa, categoría y título del gasto.');
        }
        if ($data['mode'] === 'opening' && (! empty($data['expense_id']) || ! empty($data['expense_external_id']) || $data['due_date'] !== null)) {
            throw new DomainException('La apertura usa solo la fecha de corte y no admite gasto ni vencimiento.');
        }

        return $data;
    }

    public function resolveExpense(array $data, string $scope, bool $lock = false): Expense
    {
        $byId = null;
        $byExternal = null;
        if (! empty($data['expense_id'])) {
            $byId = Expense::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($data['expense_id']);
            if (! $byId) {
                throw new DomainException('No existe el gasto de referencia.');
            }
        }
        if (! empty($data['expense_external_id'])) {
            $identity = HistoricalExternalIdentity::query()->where([
                'community_scope' => $scope, 'source' => ($data['expense_source'] ?? null) ?: $data['source'],
                'movement_type' => 'expense', 'external_id' => $data['expense_external_id'],
            ])->when($lock, fn ($q) => $q->lockForUpdate())->first();
            if ($identity && $identity->identifiable_type === (new Expense)->getMorphClass()) {
                $byExternal = Expense::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($identity->identifiable_id);
            }
            if (! $byExternal) {
                throw new DomainException('La identidad externa del gasto no existe o tiene vínculo inválido.');
            }
        }
        if ($byId && $byExternal && $byId->id !== $byExternal->id) {
            throw new DomainException('Las referencias apuntan a gastos distintos.');
        }

        return $byId ?? $byExternal;
    }

    public function create(array $data, User $actor, string $scope): PartnerCharge
    {
        abort_unless($actor->exists && $actor->hasRole('super_admin'), 403);
        if (DB::transactionLevel() === 0 || $scope !== (string) CommunitySetting::current()->id) {
            throw new DomainException('La importación requiere una transacción y la comunidad actual.');
        }
        $data = $this->normalize($data);
        if (HistoricalExternalIdentity::query()->where([
            'community_scope' => $scope, 'source' => $data['source'],
            'movement_type' => 'expense_charge', 'external_id' => $data['external_id'],
        ])->lockForUpdate()->exists()) {
            throw new DomainException('La identidad externa del cobro ya existe; requiere revisión manual.');
        }
        $this->guard->lockAndValidate($data['effective_date']);
        $owner = Propietario::query()->lockForUpdate()->findOrFail($data['propietario_id']);
        if ($data['mode'] === 'opening') {
            $charge = $this->openings->create($owner, $actor, $data['amount'], $data['effective_date'], [
                'source' => $data['source'], 'reference' => $data['reference'] ?? null,
                'observations' => $data['observations'] ?? null, 'from_import' => true,
            ]);

            return $this->identify($charge, $data, $scope);
        }
        foreach ([
            PartnerCharge::query()->where('propietario_id', $owner->id)->where('charge_type', 'opening_balance')->value('imputation_date'),
            HistoricalAdjustment::query()->where('propietario_id', $owner->id)->where('category', 'credit_opening')->value('effective_date'),
        ] as $cutoff) {
            if ($cutoff && $data['effective_date'] <= Carbon::parse($cutoff)->toDateString()) {
                throw new DomainException('El cobro debe ser posterior al corte inclusivo de apertura.');
            }
        }
        if ($data['mode'] === 'backing') {
            if (HistoricalExternalIdentity::query()->where([
                'community_scope' => $scope, 'source' => ($data['expense_source'] ?? null) ?: $data['source'],
                'movement_type' => 'expense', 'external_id' => $data['expense_external_id'],
            ])->lockForUpdate()->exists()) {
                throw new DomainException('La identidad externa del gasto de respaldo ya existe; requiere revisión manual.');
            }
            $expense = $this->expenses->create([
                'source' => ($data['expense_source'] ?? null) ?: $data['source'],
                'external_id' => $data['expense_external_id'], 'title' => $data['title'],
                'expense_category_id' => $data['expense_category_id'], 'expense_date' => $data['origin_date'],
                'due_date' => $data['due_date'], 'amount' => $data['amount'],
                'distribution_type' => ExpenseDistributionType::Manual->value, 'mode' => 'register_only',
                'reference' => $data['reference'] ?? null, 'observations' => $data['observations'] ?? null,
            ], $actor, $scope);
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
        } else {
            $expense = $this->resolveExpense($data, $scope, true);
            if ($expense->status === ExpenseStatus::Registered && ! $expense->historical_distribution_blocked) {
                throw new DomainException('El gasto está pendiente de distribución automática; resuélvalo antes de vincular el cobro.');
            }
        }
        if ($expense->status === ExpenseStatus::Cancelled) {
            throw new DomainException('No se pueden registrar cobros sobre un gasto cancelado.');
        }
        if (PartnerCharge::query()->where('expense_id', $expense->id)->where('propietario_id', $owner->id)->exists()) {
            throw new DomainException('Ya existe un cobro para este propietario y gasto; revise su identidad antes de importar.');
        }
        $total = $expense->charges()->lockForUpdate()->get(['amount'])->reduce(
            fn (BigDecimal $sum, PartnerCharge $charge): BigDecimal => $sum->plus($charge->amount),
            BigDecimal::of($data['amount']),
        );
        if ($total->isGreaterThan($expense->amount)) {
            throw new DomainException('Los cobros del gasto exceden el monto total del gasto.');
        }

        $charge = PartnerCharge::query()->create([
            'propietario_id' => $owner->id, 'expense_id' => $expense->id,
            'amount' => $data['amount'], 'imputation_date' => $data['effective_date'],
            'due_date' => $data['due_date'], 'description' => $data['title'] ?? $expense->title,
            'calculation_type' => ExpenseDistributionType::Manual,
            'calculation_notes' => json_encode([
                'from_charge_import' => true, 'origin_date' => $data['origin_date'],
                'reference' => $data['reference'] ?? null, 'observations' => $data['observations'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return $this->identify($charge, $data, $scope);
    }

    private function identify(PartnerCharge $charge, array $data, string $scope): PartnerCharge
    {
        HistoricalExternalIdentity::query()->create([
            'community_scope' => $scope, 'source' => $data['source'],
            'movement_type' => 'expense_charge', 'external_id' => $data['external_id'],
            'identifiable_type' => $charge->getMorphClass(), 'identifiable_id' => $charge->id,
        ]);

        return $charge;
    }

    public function validateExisting(HistoricalExternalIdentity $identity, array $data, string $scope): PartnerCharge
    {
        $charge = $identity->identifiable_type === (new PartnerCharge)->getMorphClass()
            ? PartnerCharge::query()->with('expense')->lockForUpdate()->find($identity->identifiable_id) : null;
        if (! $charge) {
            throw new DomainException('Identidad externa con vínculo inexistente; requiere revisión manual.');
        }
        $expense = null;
        if ($data['mode'] !== 'opening') {
            $expense = $this->resolveExpense($data, $scope, true);
        }
        $actualDate = $charge->imputation_date ?? $charge->expense?->due_date ?? $charge->created_at;
        $notes = json_decode((string) $charge->calculation_notes, true);
        $originDate = ($notes['from_charge_import'] ?? false) ? ($notes['origin_date'] ?? null) : $charge->expense?->expense_date?->toDateString();
        if ($data['mode'] === 'opening') {
            $originDate = $actualDate?->toDateString();
        }
        if ((int) $charge->propietario_id !== (int) $data['propietario_id']
            || $charge->amount !== $data['amount']
            || $originDate !== $data['origin_date']
            || $actualDate?->toDateString() !== $data['effective_date']
            || $charge->due_date?->toDateString() !== $data['due_date']
            || $charge->expense_id !== $expense?->id
            || ($data['mode'] === 'opening' && $charge->charge_type !== 'opening_balance')) {
            throw new DomainException('La identidad externa corresponde a contenido distinto; requiere revisión manual.');
        }
        if ($data['mode'] === 'backing') {
            $evidence = json_decode((string) $expense->notes, true);
            if ((int) $expense->expense_category_id !== (int) $data['expense_category_id']
                || $expense->title !== $data['title']
                || $expense->amount !== $data['amount']
                || $expense->expense_date?->toDateString() !== $data['origin_date']
                || $expense->due_date?->toDateString() !== $data['due_date']
                || $expense->distribution_type !== ExpenseDistributionType::Manual
                || ! is_array($evidence)
                || ($evidence['mode'] ?? null) !== 'register_only'
                || ($evidence['reference'] ?? null) !== ($data['reference'] ?? null)
                || ($evidence['observations'] ?? null) !== ($data['observations'] ?? null)) {
                throw new DomainException('La identidad externa del gasto de respaldo corresponde a contenido distinto; requiere revisión manual.');
            }
        }

        return $charge;
    }

    public function warning(PartnerCharge $charge, array $data): string
    {
        $cash = DB::table('collection_allocations')->join('collections', 'collections.id', '=', 'collection_allocations.collection_id')
            ->where('collection_allocations.partner_charge_id', $charge->id)->where('collections.status', '!=', 'cancelled')
            ->pluck('collection_allocations.amount');
        $adjustments = DB::table('historical_adjustment_allocations')->where('partner_charge_id', $charge->id)->pluck('amount');
        $applied = $cash->concat($adjustments)->reduce(
            fn (BigDecimal $total, $amount): BigDecimal => $total->plus((string) $amount),
            BigDecimal::zero(),
        );
        $remaining = BigDecimal::of($charge->amount)->minus($applied);
        if ($remaining->isLessThan(0)) {
            throw new DomainException('Las aplicaciones efectivas exceden el monto del cobro.');
        }
        $status = $remaining->isZero() ? ChargeStatus::Paid : ($applied->isZero() ? ChargeStatus::Pending : ChargeStatus::Partial);
        if (! in_array($charge->status, [ChargeStatus::Cancelled, ChargeStatus::Consolidated], true)
            && ($charge->status !== $status || ! BigDecimal::of($charge->paid_amount)->isEqualTo($applied))) {
            throw new DomainException('El estado del cobro no coincide con sus aplicaciones efectivas; requiere revisión.');
        }
        if (($data['reported_paid'] !== null && ! BigDecimal::of($data['reported_paid'])->isEqualTo($applied))
            || ($data['reported_remaining'] !== null && ! BigDecimal::of($data['reported_remaining'])->isEqualTo($remaining))) {
            return 'Advertencia: los importes informados difieren de las aplicaciones efectivas; no se registraron pagos.';
        }

        return '';
    }
}
