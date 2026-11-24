<?php

namespace App\Domain\Imports\Services;

use App\Domain\Accounting\Services\HistoricalPeriodGuard;
use App\Domain\Accounting\Services\MonthlyClosingService;
use App\Domain\Collections\Enums\CollectionMethod;
use App\Domain\Collections\Services\CollectionApplicationService;
use App\Models\Collection;
use App\Models\CommunitySetting;
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

class FinancialCollectionImportService
{
    public function __construct(
        private readonly FinancialImportValueParser $parser,
        private readonly HistoricalPeriodGuard $guard,
        private readonly CollectionApplicationService $applications,
        private readonly MonthlyClosingService $closing,
        private readonly FinancialChargeImportService $charges,
    ) {}

    public function normalize(array $data): array
    {
        if (! empty($data['evidence']) && $data['evidence'] !== 'documented') {
            throw new DomainException('Solo se admiten recaudaciones documentadas; los pagos sin comprobante corresponden a Etapa 6.');
        }
        if (empty($data['collection_date']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $data['collection_date'])) {
            throw new DomainException('Se requiere fecha real completa AAAA-MM-DD. Para aplicación sin fecha conocida use sin aplicación; este dominio también requiere fecha completa para registrar la recaudación.');
        }
        Validator::make($data, [
            'source' => ['required', 'string', 'max:100'],
            'external_id' => ['required', 'string', 'max:191'],
            'mode' => ['required', Rule::in(['automatic', 'manual', 'unapplied'])],
            'propietario_id' => ['required', 'integer', 'exists:propietarios,id'],
            'amount' => ['required', 'string'],
            'collection_date' => ['required', 'string'],
            'collection_method' => ['required', Rule::in(array_column(CollectionMethod::cases(), 'value'))],
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
            throw new DomainException('El monto de la recaudación debe ser positivo.');
        }
        $data['collection_date'] = $this->parser->date($data['collection_date']);
        if ($data['collection_date'] > now()->toDateString()) {
            throw new DomainException('La fecha de recaudación no puede ser futura.');
        }
        $data['effective_date'] = $data['collection_date'];
        $allocations = $data['allocations'] ?? [];
        if (is_string($allocations)) {
            $allocations = trim($allocations) === '' ? [] : json_decode($allocations, true);
        }
        if (! is_array($allocations) || ! array_is_list($allocations)) {
            throw new DomainException('Las aplicaciones deben ser una lista JSON.');
        }
        if (($data['mode'] === 'manual') !== ($allocations !== [])) {
            throw new DomainException('El modo manual requiere aplicaciones; los demás modos no las admiten.');
        }
        foreach ($allocations as &$item) {
            if (! is_array($item) || empty($item['amount']) || (empty($item['charge_id']) && empty($item['charge_external_id']))) {
                throw new DomainException('Cada aplicación requiere monto e identidad externa o ID de cobro.');
            }
            $item['amount'] = $this->parser->amount((string) $item['amount']);
            if (BigDecimal::of($item['amount'])->isLessThanOrEqualTo(0)) {
                throw new DomainException('El monto de aplicación debe ser positivo.');
            }
        }
        unset($item);
        $data['allocations'] = $allocations;

        return $data;
    }

    public function create(array $data, User $actor, string $scope): Collection
    {
        abort_unless($actor->exists && $actor->hasRole('super_admin'), 403);
        if (DB::transactionLevel() === 0 || $scope !== (string) CommunitySetting::current()->id) {
            throw new DomainException('La importación requiere una transacción y la comunidad actual.');
        }
        $data = $this->normalize($data);
        $this->guard->lockAndValidate($data['collection_date']);
        $owner = Propietario::query()->lockForUpdate()->findOrFail($data['propietario_id']);
        foreach ([
            PartnerCharge::query()->where('propietario_id', $owner->id)->where('charge_type', 'opening_balance')->value('imputation_date'),
            HistoricalAdjustment::query()->where('propietario_id', $owner->id)->where('category', 'credit_opening')->value('effective_date'),
        ] as $cutoff) {
            if ($cutoff && $data['collection_date'] <= Carbon::parse($cutoff)->toDateString()) {
                throw new DomainException('La recaudación debe ser posterior al corte inclusivo de apertura.');
            }
        }
        $identity = ['community_scope' => $scope, 'source' => $data['source'], 'movement_type' => 'collection', 'external_id' => $data['external_id']];
        if (HistoricalExternalIdentity::query()->where($identity)->lockForUpdate()->exists()) {
            throw new DomainException('La identidad externa ya existe; requiere revisión manual.');
        }
        $manual = [];
        foreach ($data['allocations'] as $item) {
            $byId = ! empty($item['charge_id']) ? PartnerCharge::query()->find($item['charge_id']) : null;
            $byExternal = null;
            if (! empty($item['charge_external_id'])) {
                $external = HistoricalExternalIdentity::query()->where([
                    'community_scope' => $scope, 'source' => ($item['charge_source'] ?? null) ?: $data['source'],
                    'movement_type' => 'expense_charge', 'external_id' => $item['charge_external_id'],
                ])->first();
                if ($external && $external->identifiable_type === (new PartnerCharge)->getMorphClass()) {
                    $byExternal = PartnerCharge::query()->find($external->identifiable_id);
                }
            }
            if ((! empty($item['charge_id']) && ! $byId) || (! empty($item['charge_external_id']) && ! $byExternal) || ($byId && $byExternal && $byId->id !== $byExternal->id)) {
                throw new DomainException('La referencia de cobro no existe o es inconsistente.');
            }
            $charge = $byId ?? $byExternal;
            $manual[] = ['charge_id' => $charge->id, 'amount' => $item['amount']];
        }
        $payment = Collection::query()->create([
            'propietario_id' => $owner->id, 'amount' => $data['amount'], 'collection_date' => $data['collection_date'],
            'collection_method' => $data['collection_method'], 'reference' => $data['reference'] ?? null,
            'notes' => json_encode(['observations' => $data['observations'] ?? null, 'import_mode' => $data['mode'], 'import_allocations' => $data['allocations']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_by' => $actor->id,
        ]);
        if ($data['mode'] !== 'unapplied') {
            $charges = $data['mode'] === 'automatic'
                ? $this->applications->getAvailableCharges($payment)
                : PartnerCharge::query()->whereIn('id', array_column($manual, 'charge_id'))->get();
            if ($data['mode'] === 'automatic' && $charges->isEmpty()) {
                throw new DomainException('No hay cobros pendientes; use el modo sin aplicación.');
            }
            foreach ($charges as $charge) {
                $this->guard->lockAndValidate($this->closing->chargeImputationDate($charge));
                $this->charges->warning($charge, ['reported_paid' => null, 'reported_remaining' => null]);
            }
            $date = Carbon::parse($data['collection_date']);
            if ($data['mode'] === 'automatic') {
                $this->applications->applyCollectionAutomatically($payment, $date, $actor->id);
            } else {
                $this->applications->applyCollectionManually($payment, $manual, $date, $actor->id);
            }
        }
        HistoricalExternalIdentity::query()->create($identity + [
            'identifiable_type' => $payment->getMorphClass(), 'identifiable_id' => $payment->id,
        ]);

        return $payment;
    }

    public function validateExisting(HistoricalExternalIdentity $identity, array $data): void
    {
        $payment = $identity->identifiable_type === (new Collection)->getMorphClass()
            ? Collection::query()->lockForUpdate()->find($identity->identifiable_id) : null;
        $notes = $payment ? json_decode((string) $payment->notes, true) : null;
        if (! $payment || ! is_array($notes)
            || (int) $payment->propietario_id !== (int) $data['propietario_id']
            || $payment->amount !== $data['amount']
            || $payment->collection_date->toDateString() !== $data['collection_date']
            || $payment->collection_method->value !== $data['collection_method']
            || $payment->reference !== ($data['reference'] ?? null)
            || ($notes['observations'] ?? null) !== ($data['observations'] ?? null)
            || ($notes['import_mode'] ?? null) !== $data['mode']
            || ($notes['import_allocations'] ?? null) !== $data['allocations']) {
            throw new DomainException('La identidad externa corresponde a contenido distinto o vínculo inválido; requiere revisión manual.');
        }
    }
}
