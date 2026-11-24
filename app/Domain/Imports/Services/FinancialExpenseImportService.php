<?php

namespace App\Domain\Imports\Services;

use App\Domain\Accounting\Services\HistoricalPeriodGuard;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Services\ChargeGenerationService;
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

class FinancialExpenseImportService
{
    public function __construct(
        private readonly FinancialImportValueParser $parser,
        private readonly ChargeGenerationService $generator,
        private readonly HistoricalPeriodGuard $guard,
    ) {}

    public function normalize(array $data): array
    {
        Validator::make($data, [
            'source' => ['required', 'string', 'max:100'],
            'external_id' => ['required', 'string', 'max:191'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'expense_date' => ['required', 'string'],
            'due_date' => ['nullable', 'string'],
            'amount' => ['required', 'string'],
            'distribution_type' => ['required', Rule::in(array_column(ExpenseDistributionType::cases(), 'value'))],
            'mode' => ['required', Rule::in(['register_only', 'distribute', 'explicit'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'observations' => ['nullable', 'string'],
        ])->validate();
        $data['source'] = trim($data['source']);
        $data['external_id'] = trim($data['external_id']);
        if ($data['source'] === '' || $data['external_id'] === '') {
            throw new DomainException('La fuente y la identidad no pueden estar vacías.');
        }
        $data['expense_date'] = $this->parser->date($data['expense_date']);
        $data['due_date'] = ! empty($data['due_date']) ? $this->parser->date($data['due_date']) : null;
        $data['amount'] = $this->parser->amount($data['amount']);
        if (BigDecimal::of($data['amount'])->isLessThanOrEqualTo(0)) {
            throw new DomainException('El monto del gasto debe ser positivo.');
        }
        if ($data['expense_date'] > now()->toDateString() || ($data['due_date'] !== null && $data['due_date'] < $data['expense_date'])) {
            throw new DomainException('La fecha del gasto o su vencimiento es inválido.');
        }
        if ($data['mode'] === 'distribute' && ! in_array(mb_strtolower(trim((string) ($data['distribuir_con_datos_actuales'] ?? ''))), ['sí', 'si'], true)) {
            throw new DomainException('La participación histórica puede diferir de la actual. Confirme explícitamente o use solo registrar o cobros explícitos.');
        }
        $data['charges'] = $data['charges'] ?? [];
        if (is_string($data['charges'])) {
            $data['charges'] = json_decode($data['charges'], true);
        }
        if (! is_array($data['charges']) || ! array_is_list($data['charges'])) {
            throw new DomainException('Los cobros explícitos deben ser una lista JSON.');
        }
        if (($data['mode'] === 'explicit') !== ($data['charges'] !== [])) {
            throw new DomainException('Indique cobros solo para la modalidad explícita y al menos uno en ella.');
        }
        $total = BigDecimal::zero();
        $owners = [];
        $ids = [];
        foreach ($data['charges'] as &$charge) {
            if (! is_array($charge)) {
                throw new DomainException('Cobro explícito inválido.');
            }
            Validator::make($charge, [
                'external_id' => ['required', 'string', 'max:191'],
                'propietario_id' => ['required', 'integer', 'exists:propietarios,id'],
                'amount' => ['required', 'string'],
            ])->validate();
            $charge['external_id'] = trim($charge['external_id']);
            $charge['propietario_id'] = (int) $charge['propietario_id'];
            $charge['amount'] = $this->parser->amount($charge['amount']);
            if ($charge['external_id'] === '' || isset($ids[$charge['external_id']]) || isset($owners[$charge['propietario_id']]) || BigDecimal::of($charge['amount'])->isLessThanOrEqualTo(0)) {
                throw new DomainException('Los cobros requieren identidad y propietario únicos e importe positivo.');
            }
            $ids[$charge['external_id']] = true;
            $owners[$charge['propietario_id']] = true;
            $total = $total->plus($charge['amount']);
        }
        unset($charge);
        if ($total->isGreaterThan($data['amount'])) {
            throw new DomainException('Los cobros explícitos exceden el monto del gasto.');
        }
        $data['effective_date'] = $data['expense_date'];
        $data['charge_date'] = $data['due_date'] ?? $data['expense_date'];

        return $data;
    }

    public function create(array $data, User $actor, string $scope): Expense
    {
        abort_unless($actor->exists && $actor->hasRole('super_admin'), 403);
        if (DB::transactionLevel() === 0 || $scope !== (string) CommunitySetting::current()->id) {
            throw new DomainException('La importación requiere una transacción y la comunidad actual.');
        }
        $data = $this->normalize($data);
        $dates = [$data['expense_date']];
        if ($data['mode'] !== 'register_only') {
            $dates[] = $data['charge_date'];
        }
        sort($dates);
        foreach (array_unique($dates) as $date) {
            $this->guard->lockAndValidate($date);
        }
        $evidence = $this->evidence($data);
        $expense = Expense::query()->create([
            'expense_category_id' => $data['expense_category_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'due_date' => $data['due_date'],
            'distribution_type' => $data['distribution_type'],
            'created_by' => $actor->id,
            'notes' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'is_recurring' => false,
            'historical_distribution_blocked' => $data['mode'] === 'register_only',
        ]);
        if ($data['mode'] === 'distribute') {
            $charges = $this->generator->generateChargesFromExpense($expense, $data['charge_date']);
            foreach ($charges as $charge) {
                $this->assertAfterOpening($charge->propietario_id, $data['charge_date']);
                $this->identity($scope, $data['source'], $this->generatedId($data['external_id'], $charge->propietario_id), $charge);
            }
        } elseif ($data['mode'] === 'explicit') {
            foreach ($data['charges'] as $item) {
                $this->assertAfterOpening($item['propietario_id'], $data['charge_date']);
                $charge = PartnerCharge::query()->create([
                    'propietario_id' => $item['propietario_id'], 'expense_id' => $expense->id,
                    'amount' => $item['amount'], 'imputation_date' => $data['charge_date'],
                    'due_date' => $data['due_date'], 'description' => $data['title'],
                    'calculation_type' => ExpenseDistributionType::Manual,
                    'calculation_notes' => 'Cobro histórico explícito importado; sin redistribución.',
                ]);
                $this->identity($scope, $data['source'], $item['external_id'], $charge);
            }
            $expense->update(['status' => ExpenseStatus::Distributed, 'distributed_at' => now()]);
        }

        HistoricalExternalIdentity::query()->create([
            'community_scope' => $scope, 'source' => $data['source'], 'movement_type' => 'expense',
            'external_id' => $data['external_id'], 'identifiable_type' => $expense->getMorphClass(), 'identifiable_id' => $expense->id,
        ]);

        return $expense;
    }

    public function distributeRegisteredExpense(Expense $expense, User $actor, bool $confirmed): void
    {
        abort_unless($actor->exists && $actor->hasRole('super_admin'), 403);
        if (! $confirmed) {
            throw new DomainException('Confirme expresamente la reconstrucción aproximada con datos vigentes.');
        }

        DB::transaction(function () use ($expense, $actor): void {
            $current = Expense::query()->findOrFail($expense->id);
            $expenseDate = $current->expense_date->toDateString();
            $chargeDate = ($current->due_date ?? $current->expense_date)->toDateString();
            $dates = array_unique([$expenseDate, $chargeDate]);
            sort($dates);
            foreach ($dates as $date) {
                $this->guard->lockAndValidate($date);
            }
            $current = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            if ($current->expense_date->toDateString() !== $expenseDate
                || ($current->due_date ?? $current->expense_date)->toDateString() !== $chargeDate) {
                throw new DomainException('Las fechas cambiaron; vuelva a confirmar la distribución.');
            }
            $evidence = json_decode((string) $current->notes, true);
            if (! $current->historical_distribution_blocked || $current->status !== ExpenseStatus::Registered
                || ! is_array($evidence) || ($evidence['from_import'] ?? false) !== true
                || ($evidence['mode'] ?? null) !== 'register_only' || empty($evidence['source']) || empty($evidence['external_id'])
                || $current->charges()->exists()) {
                throw new DomainException('El gasto no admite esta distribución histórica.');
            }
            $scope = (string) CommunitySetting::current()->id;
            $identity = HistoricalExternalIdentity::query()->where([
                'community_scope' => $scope, 'source' => $evidence['source'], 'movement_type' => 'expense',
                'external_id' => $evidence['external_id'], 'identifiable_type' => $current->getMorphClass(),
                'identifiable_id' => $current->id,
            ])->lockForUpdate()->first();
            if (! $identity) {
                throw new DomainException('La identidad histórica del gasto no es verificable.');
            }
            $evidence['subsequent_distribution'] = [
                'authorized_by' => $actor->id,
                'description' => 'Distribución reconstruida con datos vigentes; aproximación no verificada históricamente.',
                'charge_date' => $chargeDate,
                'registered_at' => now()->toIso8601String(),
            ];
            DB::table('expenses')->where('id', $current->id)->update([
                'notes' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'historical_distribution_blocked' => false,
            ]);
            $charges = $this->generator->generateChargesFromExpense($current, $chargeDate);
            foreach ($charges as $charge) {
                $this->assertAfterOpening($charge->propietario_id, $chargeDate);
                $this->identity($scope, $evidence['source'], $this->generatedId($evidence['external_id'], $charge->propietario_id), $charge);
            }
            DB::table('expenses')->where('id', $current->id)->update(['historical_distribution_blocked' => true]);
        }, 3);
    }

    public function validateExisting(HistoricalExternalIdentity $identity, array $data, string $scope): void
    {
        $expense = $identity->identifiable_type === (new Expense)->getMorphClass()
            ? Expense::query()->lockForUpdate()->find($identity->identifiable_id) : null;
        if (! $expense) {
            throw new DomainException('Identidad externa con vínculo inexistente; requiere revisión manual.');
        }
        $evidence = json_decode((string) $expense->notes, true);
        $subsequentDistribution = is_array($evidence) ? ($evidence['subsequent_distribution'] ?? null) : null;
        if (is_array($evidence)) {
            unset($evidence['subsequent_distribution']);
        }
        if ($evidence !== $this->evidence($data)
            || $expense->title !== $data['title']
            || $expense->description !== ($data['description'] ?? null)
            || (string) $expense->expense_category_id !== (string) $data['expense_category_id']
            || (string) $expense->supplier_id !== (string) ($data['supplier_id'] ?? '')
            || $expense->amount !== $data['amount']
            || $expense->expense_date->toDateString() !== $data['expense_date']
            || $expense->due_date?->toDateString() !== $data['due_date']
            || $expense->distribution_type->value !== $data['distribution_type']) {
            throw new DomainException('La identidad externa corresponde a contenido distinto; requiere revisión manual.');
        }
        $charges = $expense->charges()->get();
        $independentCharges = $charges->filter(function (PartnerCharge $charge) use ($scope): bool {
            $notes = json_decode((string) $charge->calculation_notes, true);

            return ($notes['from_charge_import'] ?? false) === true
                && HistoricalExternalIdentity::query()->where([
                    'community_scope' => $scope, 'movement_type' => 'expense_charge',
                    'identifiable_type' => $charge->getMorphClass(), 'identifiable_id' => $charge->id,
                ])->exists();
        });
        if (($data['mode'] === 'register_only' && $subsequentDistribution === null && $charges->count() !== $independentCharges->count())
            || ($data['mode'] === 'register_only' && $subsequentDistribution !== null && $charges->isEmpty())
            || ($data['mode'] === 'explicit' && $charges->count() !== count($data['charges']))
            || ($data['mode'] === 'distribute' && $charges->isEmpty())) {
            throw new DomainException('Los cobros de la identidad cambiaron; requiere revisión manual.');
        }
        foreach ($charges as $charge) {
            if ($data['mode'] === 'register_only' && $subsequentDistribution === null && $independentCharges->contains($charge)) {
                continue;
            }
            $item = collect($data['charges'])->firstWhere('propietario_id', $charge->propietario_id);
            $externalId = $data['mode'] === 'explicit' ? ($item['external_id'] ?? '') : $this->generatedId($data['external_id'], $charge->propietario_id);
            $exists = HistoricalExternalIdentity::query()->where([
                'community_scope' => $scope, 'source' => $data['source'], 'movement_type' => 'expense_charge',
                'external_id' => $externalId, 'identifiable_type' => $charge->getMorphClass(), 'identifiable_id' => $charge->id,
            ])->exists();
            if (! $exists || $charge->imputation_date?->toDateString() !== $data['charge_date']
                || ($data['mode'] === 'explicit' && (! $item || $charge->amount !== $item['amount']))) {
                throw new DomainException('Los cobros de la identidad cambiaron; requiere revisión manual.');
            }
        }
    }

    private function evidence(array $data): array
    {
        return [
            'from_import' => true, 'source' => $data['source'], 'external_id' => $data['external_id'],
            'mode' => $data['mode'], 'reference' => $data['reference'] ?? null,
            'observations' => $data['observations'] ?? null,
            'distribution_evidence' => $data['mode'] === 'distribute' ? 'Distribución reconstruida con datos vigentes; aproximación no verificada históricamente.' : null,
            'charges' => $data['charges'],
        ];
    }

    private function identity(string $scope, string $source, string $externalId, PartnerCharge $charge): void
    {
        if (HistoricalExternalIdentity::query()->where(['community_scope' => $scope, 'source' => $source, 'movement_type' => 'expense_charge', 'external_id' => $externalId])->exists()) {
            throw new DomainException('Identidad externa de cobro ya existente; requiere revisión manual.');
        }
        HistoricalExternalIdentity::query()->create([
            'community_scope' => $scope, 'source' => $source, 'movement_type' => 'expense_charge',
            'external_id' => $externalId, 'identifiable_type' => $charge->getMorphClass(), 'identifiable_id' => $charge->id,
        ]);
    }

    private function generatedId(string $expenseId, int $ownerId): string
    {
        return 'generated:'.hash('sha256', json_encode([$expenseId, $ownerId], JSON_THROW_ON_ERROR));
    }

    private function assertAfterOpening(int $ownerId, string $date): void
    {
        Propietario::query()->lockForUpdate()->findOrFail($ownerId);
        $debit = PartnerCharge::query()->where('propietario_id', $ownerId)->where('charge_type', 'opening_balance')->value('imputation_date');
        $credit = HistoricalAdjustment::query()->where('propietario_id', $ownerId)->where('category', 'credit_opening')->value('effective_date');
        foreach ([$debit, $credit] as $cutoff) {
            if ($cutoff && $date <= Carbon::parse($cutoff)->toDateString()) {
                throw new DomainException('El cobro debe ser posterior al corte inclusivo de apertura.');
            }
        }
    }
}
