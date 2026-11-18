<?php

namespace App\Domain\Imports\Services;

use App\Domain\Owners\Services\ShareContributionService;
use App\Domain\Owners\Services\ShareHoldingService;
use App\Models\CommunitySetting;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\Propietario;
use App\Models\ShareContribution;
use App\Models\ShareMovement;
use App\Models\User;
use App\Rules\ValidChileanRut;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ShareOpeningImportService
{
    /** @param array<string, mixed> $data
     * @return array{action: string, target_id: ?int, fingerprint: string, changes: array<string, mixed>, errors: array<int, string>}
     */
    public function inspect(array $data): array
    {
        $settings = CommunitySetting::current();
        $errors = Validator::make($data, [
            'rut' => ['required', 'string', new ValidChileanRut],
            'quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/', 'numeric', 'gte:0'],
            'amount' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'numeric', 'gte:0'],
            'reference_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->errors()->all();
        $owner = Propietario::query()->where('rut', $data['rut'] ?? '')->first();
        if (! $owner) {
            $errors[] = 'El RUT debe pertenecer a un propietario existente. Importe primero los propietarios.';
        }
        if (! $settings->usesShares()) {
            $errors[] = 'La modalidad no admite acciones.';
        }
        $holdings = $owner?->shareHoldings()->orderBy('id')->get() ?? collect();
        $contributions = $owner?->shareContributions()->orderBy('id')->get() ?? collect();
        $fingerprint = hash('sha256', json_encode([
            $owner?->id, $owner?->rut, $settings->only(['mode', 'share_precision', 'square_meters_per_share']),
            $holdings->toArray(), $contributions->toArray(),
        ], JSON_THROW_ON_ERROR));
        $action = 'create';
        if ($errors === []) {
            $quantity = BigDecimal::of($data['quantity']);
            if (! $quantity->isEqualTo($quantity->toScale($settings->share_precision, RoundingMode::DOWN))) {
                $errors[] = 'La cantidad excede la precisión de la comunidad.';
            }
            $key = $this->key($owner);
            $opening = ShareContribution::query()->where('operation_key', $key)->first();
            $historical = ShareMovement::query()->where('operation_key', $key)->first();
            $request = $this->request($data);
            if ($opening) {
                $stored = $opening->context['request'];
                ksort($stored);
                ksort($request);
                $action = $stored === $request && isset($data['amount']) ? 'skip' : 'error';
                if ($action === 'error') {
                    $errors[] = 'El saldo ya fue importado con otros datos. Use ajustes auditados.';
                }
            } elseif ($historical && ! isset($data['amount'])) {
                $stored = $historical->context['request'];
                $same = BigDecimal::of($stored['quantity'])->isEqualTo($quantity)
                    && $stored['date'] === $request['date']
                    && $stored['reason'] === ($request['notes'] ?? 'Saldo histórico importado');
                $action = $same ? 'skip' : 'error';
                if (! $same) {
                    $errors[] = 'El saldo histórico ya fue importado con otros datos.';
                }
            } elseif ($contributions->isNotEmpty()) {
                $errors[] = 'Ya existe historial de aportes. No se sobrescribe mediante importación.';
            } elseif ($holdings->isNotEmpty()) {
                $total = BigDecimal::zero();
                foreach ($holdings as $holding) {
                    $total = $total->plus($holding->quantity);
                }
                if (! isset($data['amount']) || ! $total->isEqualTo($quantity)) {
                    $errors[] = 'Ya existen tenencias: solo se admite conciliar un aporte conocido con la misma cantidad total (incluidas inactivas).';
                }
            }
            if ($action === 'skip') {
                $currentQuantity = BigDecimal::zero();
                foreach ($holdings as $holding) {
                    $currentQuantity = $currentQuantity->plus($holding->quantity);
                }
                $amountChanged = isset($data['amount'])
                    && ! BigDecimal::of(app(ShareContributionService::class)->summary($owner)['recorded_amount'])->isEqualTo($data['amount']);
                if (! $currentQuantity->isEqualTo($quantity) || $amountChanged) {
                    $errors[] = 'La solicitud ya fue procesada, pero el saldo actual cambió. Concilie mediante ajustes auditados.';
                }
            }
            if ($action === 'create' && $holdings->isEmpty() && $settings->authorized_shares !== null) {
                $issued = app(ShareHoldingService::class)->totals()['issued'];
                if (BigDecimal::of($issued)->plus($quantity)->isGreaterThan($settings->authorized_shares)) {
                    $errors[] = 'La operación excede el total autorizado.';
                }
            }
        }

        return [
            'action' => $errors === [] ? $action : 'error', 'target_id' => $owner?->id,
            'fingerprint' => $fingerprint, 'changes' => array_map(fn (mixed $value): array => ['before' => null, 'after' => $value], $data),
            'errors' => $errors,
        ];
    }

    public function executeRow(DataImportRow $row, User $actor): void
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        DB::transaction(function () use ($row, $actor): void {
            CommunitySetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $this->applyRow($row, $actor);
        });
    }

    public function validateBatchCapacity(DataImport $import): int
    {
        $settings = CommunitySetting::current();
        if ($settings->authorized_shares === null) {
            return 0;
        }
        $issued = BigDecimal::of(app(ShareHoldingService::class)->totals()['issued']);
        $rejected = 0;
        foreach ($import->rows()->where('action', 'create')->orderBy('id')->cursor() as $row) {
            if (Propietario::query()->findOrFail($row->target_id)->shareHoldings()->exists()) {
                continue;
            }
            $next = $issued->plus($row->data['quantity']);
            if ($next->isGreaterThan($settings->authorized_shares)) {
                $row->update(['action' => 'error', 'errors' => ['El conjunto de filas excede el total autorizado.']]);
                $rejected++;
            } else {
                $issued = $next;
            }
        }

        return $rejected;
    }

    private function applyRow(DataImportRow $row, User $actor): void
    {
        $plan = $this->inspect($row->data);
        if ($plan['errors'] !== []) {
            throw ValidationException::withMessages(['row' => $plan['errors']]);
        }
        if ($plan['target_id'] !== $row->target_id || $plan['fingerprint'] !== $row->fingerprint || $plan['action'] !== $row->action) {
            throw ValidationException::withMessages(['row' => 'El propietario, sus acciones o la configuración cambiaron desde la previsualización. Vuelva a importar.']);
        }
        if ($row->action === 'skip') {
            return;
        }
        $owner = Propietario::query()->findOrFail($row->target_id);
        $data = $row->data;
        if (isset($data['amount'])) {
            app(ShareContributionService::class)->recordOpening(
                $owner, $data['quantity'], $data['amount'], $data['reference_date'] ?? null,
                $data['notes'] ?? null, $this->key($owner), $actor
            );
        } else {
            app(ShareHoldingService::class)->initializeHistorical(
                $owner, $data['quantity'], $data['reference_date'] ?? null,
                $this->key($owner), $data['notes'] ?? 'Saldo histórico importado', $actor
            );
        }
    }

    private function key(Propietario $owner): string
    {
        return 'share-opening-import:'.$owner->id;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function request(array $data): array
    {
        return [
            'quantity' => (string) BigDecimal::of($data['quantity'])->toScale(4),
            'amount' => (string) BigDecimal::of($data['amount'] ?? '0')->toScale(2),
            'date' => $data['reference_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
