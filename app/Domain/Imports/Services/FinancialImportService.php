<?php

namespace App\Domain\Imports\Services;

use App\Domain\Accounting\Services\HistoricalPeriodGuard;
use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Models\CommunitySetting;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\HistoricalAdjustment;
use App\Models\HistoricalExternalIdentity;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FinancialImportService
{
    public function __construct(
        private readonly FinancialImportValueParser $parser,
        private readonly HistoricalPeriodGuard $guard,
        private readonly HistoricalOpeningService $openings,
        private readonly HistoricalAdjustmentService $adjustments,
        private readonly FinancialExpenseImportService $expenses,
        private readonly FinancialChargeImportService $charges,
        private readonly FinancialCollectionImportService $collections,
        private readonly FinancialOpeningImportService $initialOpenings,
    ) {}

    /**
     * La entrada son filas ya mapeadas en DataImportRow.data, con type=financial.
     *
     * @return array{valid: bool, rows: array<int, array{row_id: int, number: int, status: string, message: string, data: array}>}
     */
    public function preview(DataImport $batch, User $actor, bool $failedOnly = false): array
    {
        $batch = DataImport::query()->findOrFail($batch->id);
        $this->authorize($batch, $actor);

        return $this->inspect($batch->rows()->when($failedOnly, fn ($query) => $query->where('result', 'failed'))->orderBy('number')->get());
    }

    /**
     * @return array{committed: bool, rows: array<int, array{row_id: int, number: int, status: string, message: string}>}
     */
    public function execute(DataImport $batch, User $actor, bool $failedOnly = false): array
    {
        $preview = $this->preview($batch, $actor, $failedOnly);
        if (! $preview['valid']) {
            $result = $this->failedBatch($preview['rows'], 'El lote contiene filas inválidas; no se registró ningún movimiento.');
            $this->persistFailed($batch, $actor, $preview['rows'], $result['rows']);

            return $result;
        }

        $failedRow = null;
        try {
            return DB::transaction(function () use ($batch, $actor, $failedOnly, &$failedRow): array {
                $batch = DataImport::query()->lockForUpdate()->findOrFail($batch->id);
                $this->authorize($batch, $actor);
                $rows = $batch->rows()->when($failedOnly, fn ($query) => $query->where('result', 'failed'))->orderBy('number')->lockForUpdate()->get();
                $inspection = $this->inspect($rows);
                if (! $inspection['valid']) {
                    throw new DomainException('Los datos o períodos cambiaron; vuelva a previsualizar.');
                }
                $dates = [];
                foreach ($inspection['rows'] as $row) {
                    $dates[] = $row['data']['effective_date'];
                    if (($row['data']['movement_type'] ?? null) === 'expense' && $row['data']['mode'] !== 'register_only') {
                        $dates[] = $row['data']['charge_date'];
                    }
                    if (($row['data']['movement_type'] ?? null) === 'expense_charge' && $row['data']['mode'] === 'backing') {
                        $dates[] = $row['data']['origin_date'];
                    }
                }
                $dates = array_unique($dates);
                sort($dates);
                foreach ($dates as $date) {
                    $this->guard->lockAndValidate($date);
                }

                $results = [];
                foreach ($inspection['rows'] as $row) {
                    $failedRow = $row['row_id'];
                    $data = $row['data'];
                    $identity = HistoricalExternalIdentity::query()->where([
                        'community_scope' => (string) $batch->community_setting_id,
                        'source' => $data['source'],
                        'movement_type' => $data['movement_type'],
                        'external_id' => $data['external_id'],
                    ])->lockForUpdate()->first();
                    if ($identity) {
                        if ($data['movement_type'] === 'expense') {
                            $this->expenses->validateExisting($identity, $data, (string) $batch->community_setting_id);
                        } elseif ($data['movement_type'] === 'expense_charge') {
                            $charge = $this->charges->validateExisting($identity, $data, (string) $batch->community_setting_id);
                        } elseif ($data['movement_type'] === 'collection') {
                            $this->collections->validateExisting($identity, $data);
                        } elseif ($data['movement_type'] === 'initial_opening') {
                            $this->initialOpenings->validateExisting($identity, $data);
                        } else {
                            $this->validateExistingIdentity($identity, $data);
                        }
                        $warning = isset($charge) && $data['movement_type'] === 'expense_charge' ? $this->charges->warning($charge, $data) : '';
                        $results[] = $this->result($row, 'skipped', trim('Identidad externa existente. '.$warning));

                        continue;
                    }

                    if ($data['movement_type'] === 'expense') {
                        $movement = $this->expenses->create($data, $actor, (string) $batch->community_setting_id);
                    } elseif ($data['movement_type'] === 'expense_charge') {
                        $movement = $this->charges->create($data, $actor, (string) $batch->community_setting_id);
                    } elseif ($data['movement_type'] === 'collection') {
                        $movement = $this->collections->create($data, $actor, (string) $batch->community_setting_id);
                    } elseif ($data['movement_type'] === 'initial_opening') {
                        $movement = $this->initialOpenings->create($data, $actor);
                    } else {
                        $owner = Propietario::query()->findOrFail($data['propietario_id']);
                        $metadata = ['source' => $data['source'], 'reference' => $data['reference'] ?? null, 'observations' => $data['observations'] ?? null, 'from_import' => true];
                        $movement = $data['movement_type'] === 'opening_balance'
                            ? $this->openings->create($owner, $actor, $data['amount'], $data['effective_date'], $metadata)
                            : $this->adjustments->create($owner, $actor, HistoricalAdjustmentCategory::from($data['movement_type']), $data['amount'], $data['effective_date'], 'day', $metadata, isset($data['charge_id']) ? (int) $data['charge_id'] : null);
                    }
                    if ($movement === null) {
                        $results[] = $this->result($row, 'skipped', 'Saldo cero: no se generó movimiento.');

                        continue;
                    }
                    if (! in_array($data['movement_type'], ['expense', 'expense_charge', 'collection'], true)) {
                        HistoricalExternalIdentity::query()->create([
                            'community_scope' => (string) $batch->community_setting_id,
                            'source' => $data['source'],
                            'movement_type' => $data['movement_type'],
                            'external_id' => $data['external_id'],
                            'identifiable_type' => $movement->getMorphClass(),
                            'identifiable_id' => $movement->getKey(),
                        ]);
                    }
                    $warning = $data['movement_type'] === 'expense_charge' ? $this->charges->warning($movement, $data) : '';
                    $results[] = $this->result($row, 'created', trim('Movimiento registrado. '.$warning));
                }

                foreach ($results as $result) {
                    $row = $rows->firstWhere('id', $result['row_id']);
                    if (! in_array($row->result, ['created', 'skipped'], true)) {
                        $this->persistRow($row, $result);
                    }
                }

                return ['committed' => true, 'rows' => $results];
            }, 3);
        } catch (\Throwable $exception) {
            if (! $exception instanceof DomainException && ! $exception instanceof ValidationException) {
                report($exception);
            }

            $message = $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo confirmar la fila.';
            $rows = array_map(fn (array $row): array => $this->result(
                $row,
                $row['row_id'] === $failedRow && in_array(($row['data']['movement_type'] ?? null), ['expense', 'expense_charge', 'collection', 'initial_opening'], true) && str_contains(mb_strtolower($message), 'identidad') ? 'conflict' : 'failed',
                $row['row_id'] === $failedRow ? $message : 'Se revirtió el lote completo.',
            ), $preview['rows']);

            $this->persistFailed($batch, $actor, $preview['rows'], $rows);

            return ['committed' => false, 'rows' => $rows];
        }
    }

    private function authorize(DataImport $batch, User $actor): void
    {
        abort_unless($actor->exists && $actor->hasRole('super_admin'), 403);
        abort_unless($batch->type === 'financial' && $batch->community_setting_id === CommunitySetting::current()->id && $batch->user_id === $actor->id, 403);
    }

    private function validateExistingIdentity(HistoricalExternalIdentity $identity, array $data): void
    {
        $movement = match ($identity->identifiable_type) {
            (new PartnerCharge)->getMorphClass() => PartnerCharge::query()->lockForUpdate()->find($identity->identifiable_id),
            (new HistoricalAdjustment)->getMorphClass() => HistoricalAdjustment::query()->lockForUpdate()->find($identity->identifiable_id),
            default => null,
        };
        if ($movement === null) {
            throw new DomainException('Identidad externa con vínculo inexistente o no admitido; requiere revisión manual.');
        }

        $metadata = $movement instanceof PartnerCharge
            ? json_decode((string) $movement->calculation_notes, true)
            : ['source' => $movement->source, 'reference' => $movement->reference, 'observations' => $movement->observations];
        if (! is_array($metadata)) {
            throw new DomainException('No se puede verificar el origen de la identidad; requiere revisión manual.');
        }
        $isCreditOpening = $movement instanceof HistoricalAdjustment && $movement->category === HistoricalAdjustmentCategory::CreditOpening;
        $actualAmount = ($isCreditOpening ? '-' : '').$movement->amount;
        $category = $movement instanceof PartnerCharge ? $movement->charge_type : ($isCreditOpening ? 'opening_balance' : $movement->category->value);
        $date = $movement instanceof PartnerCharge ? $movement->imputation_date : $movement->effective_date;
        $chargeId = $movement instanceof HistoricalAdjustment && ! $isCreditOpening
            ? $movement->allocations()->value('partner_charge_id')
            : null;

        if ((string) $movement->propietario_id !== (string) $data['propietario_id']
            || $category !== $data['movement_type']
            || $actualAmount !== $data['amount']
            || $date?->toDateString() !== $data['effective_date']
            || ($metadata['source'] ?? null) !== $data['source']
            || ($metadata['reference'] ?? null) !== ($data['reference'] ?? null)
            || ($metadata['observations'] ?? null) !== ($data['observations'] ?? null)
            || (string) ($chargeId ?? '') !== (string) ($data['charge_id'] ?? '')) {
            throw new DomainException('La identidad externa corresponde a contenido distinto; requiere revisión manual.');
        }
    }

    /** @return array{valid: bool, rows: array} */
    private function inspect(Collection $rows): array
    {
        $result = [];
        $keys = [];
        foreach ($rows as $row) {
            $data = $row->data ?? [];
            try {
                if (($data['movement_type'] ?? null) === 'initial_opening') {
                    $data = $this->initialOpenings->normalize($data);
                    $this->guard->validate($data['effective_date']);
                    $key = json_encode([$data['source'], 'initial_opening', $data['external_id']], JSON_THROW_ON_ERROR);
                    if (isset($keys[$key])) {
                        throw new DomainException('Identidad externa duplicada en el lote.');
                    }
                    $keys[$key] = true;
                    $result[] = ['row_id' => $row->id, 'number' => $row->number, 'status' => 'ready', 'message' => '', 'data' => $data, 'source_hash' => $this->sourceHash($row->data)];

                    continue;
                }
                if (($data['movement_type'] ?? null) === 'collection') {
                    $data = $this->collections->normalize($data);
                    $this->guard->validate($data['effective_date']);
                    $key = json_encode([$data['source'], 'collection', $data['external_id']], JSON_THROW_ON_ERROR);
                    if (isset($keys[$key])) {
                        throw new DomainException('Identidad externa duplicada en el lote.');
                    }
                    $keys[$key] = true;
                    $result[] = ['row_id' => $row->id, 'number' => $row->number, 'status' => 'ready', 'message' => '', 'data' => $data, 'source_hash' => $this->sourceHash($row->data)];

                    continue;
                }
                if (($data['movement_type'] ?? null) === 'expense_charge') {
                    $data = $this->charges->normalize($data);
                    $this->guard->validate($data['effective_date']);
                    if ($data['mode'] === 'backing') {
                        $this->guard->validate($data['origin_date']);
                    }
                    $key = json_encode([$data['source'], 'expense_charge', $data['external_id']], JSON_THROW_ON_ERROR);
                    if (isset($keys[$key])) {
                        throw new DomainException('Identidad externa duplicada en el lote.');
                    }
                    $keys[$key] = true;
                    $result[] = ['row_id' => $row->id, 'number' => $row->number, 'status' => 'ready', 'message' => '', 'data' => $data, 'source_hash' => $this->sourceHash($row->data)];

                    continue;
                }
                if (($data['movement_type'] ?? null) === 'expense') {
                    Validator::make($data, [
                        'source' => ['required', 'string', 'max:100'],
                        'external_id' => ['required', 'string', 'max:191'],
                    ])->validate();
                    $data['source'] = trim($data['source']);
                    $data['external_id'] = trim($data['external_id']);
                    if ($data['source'] === '' || $data['external_id'] === '') {
                        throw new DomainException('La fuente y la identidad no pueden estar vacías.');
                    }
                    $data = $this->expenses->normalize($data);
                    $this->guard->validate($data['expense_date']);
                    if ($data['mode'] !== 'register_only') {
                        $this->guard->validate($data['charge_date']);
                    }
                    $key = json_encode([$data['source'], 'expense', $data['external_id']], JSON_THROW_ON_ERROR);
                    if (isset($keys[$key])) {
                        throw new DomainException('Identidad externa duplicada en el lote.');
                    }
                    $keys[$key] = true;
                    $result[] = ['row_id' => $row->id, 'number' => $row->number, 'status' => 'ready', 'message' => $data['mode'] === 'distribute' ? 'La participación histórica puede diferir de la actual; reconstrucción aproximada.' : '', 'data' => $data, 'source_hash' => $this->sourceHash($row->data)];

                    continue;
                }
                Validator::make($data, [
                    'source' => ['required', 'string', 'max:100'],
                    'movement_type' => ['required', 'in:opening_balance,debt_correction,debt_forgiveness,unexplained_difference'],
                    'external_id' => ['required', 'string', 'max:191'],
                    'propietario_id' => ['required', 'integer', 'exists:propietarios,id'],
                    'amount' => ['required', 'string'],
                    'effective_date' => ['required', 'string'],
                    'charge_id' => ['nullable', 'integer', 'exists:partner_charges,id'],
                    'reference' => ['nullable', 'string', 'max:255'],
                    'observations' => ['nullable', 'string'],
                ])->validate();
                $data['source'] = trim($data['source']);
                $data['external_id'] = trim($data['external_id']);
                if ($data['source'] === '' || $data['external_id'] === '') {
                    throw new DomainException('La fuente y la identidad no pueden estar vacías.');
                }
                $data['amount'] = $this->parser->amount($data['amount']);
                $data['effective_date'] = $this->parser->date($data['effective_date']);
                $this->guard->validate($data['effective_date']);
                $key = json_encode([$data['source'], $data['movement_type'], $data['external_id']], JSON_THROW_ON_ERROR);
                if (isset($keys[$key])) {
                    throw new DomainException('Identidad externa duplicada en el lote.');
                }
                $keys[$key] = true;
                $result[] = ['row_id' => $row->id, 'number' => $row->number, 'status' => 'ready', 'message' => '', 'data' => $data, 'source_hash' => $this->sourceHash($row->data)];
            } catch (ValidationException $exception) {
                $result[] = ['row_id' => $row->id, 'number' => $row->number, 'status' => 'failed', 'message' => implode(' ', $exception->errors()[array_key_first($exception->errors())]), 'data' => [], 'source_hash' => $this->sourceHash($row->data)];
            } catch (DomainException $exception) {
                $result[] = ['row_id' => $row->id, 'number' => $row->number, 'status' => 'failed', 'message' => $exception->getMessage(), 'data' => [], 'source_hash' => $this->sourceHash($row->data)];
            }
        }

        return ['valid' => $result !== [] && ! collect($result)->contains('status', 'failed'), 'rows' => $result];
    }

    private function result(array $row, string $status, string $message): array
    {
        return ['row_id' => $row['row_id'], 'number' => $row['number'], 'status' => $status, 'message' => $message];
    }

    private function failedBatch(array $rows, string $message): array
    {
        return ['committed' => false, 'rows' => array_map(fn (array $row): array => $this->result($row, 'failed', $row['status'] === 'failed' ? $row['message'] : $message), $rows)];
    }

    private function sourceHash(?array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function persistFailed(DataImport $batch, User $actor, array $previewRows, array $results): void
    {
        DB::transaction(function () use ($batch, $actor, $previewRows, $results): void {
            $batch = DataImport::query()->lockForUpdate()->findOrFail($batch->id);
            $this->authorize($batch, $actor);
            $original = collect($previewRows)->keyBy('row_id');
            foreach ($results as $result) {
                $row = $batch->rows()->whereKey($result['row_id'])->lockForUpdate()->first();
                if ($row && ! in_array($row->result, ['created', 'skipped'], true) && $this->sourceHash($row->data) === $original[$row->id]['source_hash']) {
                    $this->persistRow($row, $result);
                }
            }
        });
    }

    private function persistRow(DataImportRow $row, array $result): void
    {
        $changes = $row->changes ?? [];
        $changes['result_message'] = ['before' => null, 'after' => $result['message']];
        $row->update([
            'result' => $result['status'],
            'errors' => in_array($result['status'], ['failed', 'conflict'], true) ? [$result['message']] : [],
            'changes' => $changes,
        ]);
    }
}
