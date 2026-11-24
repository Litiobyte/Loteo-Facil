<?php

namespace App\Domain\Owners\Services;

use App\Models\CommunitySetting;
use App\Models\Propietario;
use App\Models\ShareContribution;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ShareContributionService
{
    public function __construct(private readonly ShareHoldingService $holdings) {}

    public function recordOpening(Propietario $owner, string $quantity, string $amount, ?string $date, ?string $notes, string $key, User $actor): ShareContribution
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        $request = $this->validateRegistration($quantity, $amount, $date, $notes, false);

        return $this->record('opening', $owner, $request, $key, $actor, function () use ($owner, $request, $key, $actor): array {
            if ($owner->shareContributions()->exists()) {
                throw new DomainException('El saldo inicial debe registrarse antes de nuevas adquisiciones con aporte. Corrija el historial existente mediante ajustes auditados.');
            }
            $holdings = $owner->shareHoldings()->orderBy('id')->get();
            if ($holdings->isEmpty()) {
                $holding = $this->holdings->initializeHistorical(
                    $owner, $request['quantity'], $request['date'], $this->movementKey($key),
                    $request['notes'] ?? 'Saldo histórico consolidado', $actor
                );
                $holdings = collect([$holding]);
            } else {
                $existingQuantity = BigDecimal::zero();
                foreach ($holdings as $holding) {
                    $existingQuantity = $existingQuantity->plus($holding->quantity);
                }
                if (! $existingQuantity->isEqualTo($request['quantity'])) {
                    throw new DomainException('La cantidad histórica no coincide con las acciones existentes (incluidas inactivas). Concilie primero mediante un ajuste auditado; no se agregarán acciones duplicadas.');
                }
            }

            return [
                'opening_marker' => true,
                'context' => ['holdings' => $holdings->map(fn (ShareHolding $holding): array => $holding->only([
                    'id', 'quantity', 'status', 'square_meters_per_share', 'acquired_at',
                ]))->all()],
            ];
        });
    }

    public function acquire(Propietario $owner, string $quantity, string $amount, string $date, ?string $notes, string $key, User $actor, ?UploadedFile $receipt = null): ShareContribution
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        $request = $this->validateRegistration($quantity, $amount, $date, $notes, true);
        Validator::make(['receipt' => $receipt], ['receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']])->validate();
        $request['receipt_sha256'] = $receipt ? hash_file('sha256', $receipt->getRealPath()) : null;

        return $this->record('acquisition', $owner, $request, $key, $actor, function () use ($owner, $request, $key, $actor): array {
            if ($this->summary($owner->fresh())['has_unknown_history']) {
                throw new DomainException('Registre primero el saldo histórico de las acciones existentes cuyo aporte se desconoce.');
            }
            $holding = $this->holdings->acquire(
                $owner, $request['quantity'], $request['date'], $this->movementKey($key),
                $request['notes'] ?? 'Nueva adquisición de acciones', $actor, withContribution: true
            );

            return ['share_holding_id' => $holding->id];
        }, $receipt);
    }

    public function correct(ShareContribution $original, string $correctedAmount, string $reason, string $key, User $actor): ShareContribution
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        $amount = $this->validateAmount($correctedAmount);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']])->validate();
        $request = ['original_id' => $original->id, 'corrected_amount' => $amount, 'reason' => $reason];

        return $this->record('correction', $original->propietario, $request, $key, $actor, function () use ($original, $amount, $reason): array {
            $original = ShareContribution::query()->lockForUpdate()->findOrFail($original->id);
            if (! in_array($original->type, ['opening', 'acquisition'], true)) {
                throw new DomainException('La corrección debe referir al aporte original, no a otra corrección.');
            }
            $current = BigDecimal::of($original->amount);
            foreach (ShareContribution::query()->where('corrects_contribution_id', $original->id)->get() as $correction) {
                $current = $current->plus($correction->amount);
            }

            return [
                'corrects_contribution_id' => $original->id, 'amount' => (string) BigDecimal::of($amount)->minus($current),
                'quantity' => null, 'notes' => $reason,
                'context' => ['amount_before' => (string) $current, 'amount_after' => $amount],
            ];
        });
    }

    /** @return array{recorded_amount: string, has_unknown_history: bool} */
    public function summary(Propietario $owner): array
    {
        $total = BigDecimal::zero();
        $covered = [];
        $contributions = $owner->relationLoaded('shareContributions') ? $owner->shareContributions : $owner->shareContributions()->get();
        foreach ($contributions as $contribution) {
            $total = $total->plus($contribution->amount);
            if ($contribution->share_holding_id !== null) {
                $covered[] = $contribution->share_holding_id;
            }
            foreach ($contribution->context['holdings'] ?? [] as $holding) {
                $covered[] = $holding['id'];
            }
        }
        $holdings = $owner->relationLoaded('shareHoldings') ? $owner->shareHoldings : $owner->shareHoldings()->get();
        $unknown = $holdings->whereNotIn('id', $covered)->pluck('id');
        $transferred = ShareMovement::query()->where('type', 'transfer')->whereIn('share_holding_id', $unknown)->pluck('share_holding_id');

        return [
            'recorded_amount' => (string) $total->toScale(2),
            'has_unknown_history' => $unknown->diff($transferred)->isNotEmpty(),
        ];
    }

    /** Ids de socios con acciones cuyo aporte histórico no está informado. @return array<int> */
    public function ownerIdsWithUnknownHistory(): array
    {
        return Propietario::query()
            ->where(fn ($query) => $query->whereHas('shareHoldings')->orWhereHas('shareContributions'))
            ->with(['shareHoldings', 'shareContributions'])
            ->get()
            ->filter(fn (Propietario $owner): bool => $this->summary($owner)['has_unknown_history'])
            ->pluck('id')
            ->all();
    }

    public function downloadReceipt(ShareContribution $contribution, User $actor): StreamedResponse
    {
        abort_unless($actor->can('view', $contribution), 403);
        $path = $contribution->receipt_path;
        abort_unless(is_string($path) && str_starts_with($path, 'share-contributions/') && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'comprobante-aporte-'.$contribution->id.'.'.pathinfo($path, PATHINFO_EXTENSION));
    }

    /** @param array<string, mixed> $request */
    private function record(string $type, Propietario $owner, array $request, string $key, User $actor, callable $operation, ?UploadedFile $receipt = null): ShareContribution
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:80']])->validate();
        $storedFile = null;
        if ($receipt && DB::transactionLevel() > 0) {
            DB::afterRollBack(function () use (&$storedFile): void {
                if (is_string($storedFile)) {
                    Storage::disk('local')->delete($storedFile);
                }
            });
        }
        try {
            return DB::transaction(function () use ($type, $owner, $request, $key, $actor, $operation, $receipt, &$storedFile): ShareContribution {
                $settings = CommunitySetting::query()->lockForUpdate()->findOrFail(1);
                $existing = ShareContribution::query()->where('operation_key', $key)->first();
                if ($existing) {
                    $storedRequest = $existing->context['request'];
                    $expected = $request;
                    ksort($storedRequest);
                    ksort($expected);
                    if ($existing->type !== $type || $existing->propietario_id !== $owner->id || $storedRequest !== $expected) {
                        throw new DomainException('La clave ya fue utilizada para otro aporte.');
                    }

                    return $existing;
                }
                if ($type !== 'correction' && ! $settings->usesShares()) {
                    throw new DomainException('La modalidad no admite nuevos registros de acciones.');
                }
                $data = $operation();
                if ($receipt) {
                    $storedFile = $receipt->store('share-contributions', 'local');
                    if (! is_string($storedFile) || ! Storage::disk('local')->exists($storedFile)) {
                        throw new DomainException('No fue posible guardar el comprobante.');
                    }
                }
                $data['context'] = [...($data['context'] ?? []), 'request' => $request];

                return ShareContribution::query()->create([
                    'community_setting_id' => $settings->id, 'propietario_id' => $owner->id,
                    'type' => $type, 'amount' => $request['amount'] ?? '0',
                    'quantity' => $request['quantity'] ?? null, 'reference_date' => $request['date'] ?? null,
                    'notes' => $request['notes'] ?? null, 'operation_key' => $key, 'created_by' => $actor->id,
                    'receipt_path' => $storedFile, ...$data,
                ]);
            });
        } catch (Throwable $exception) {
            if (is_string($storedFile)) {
                Storage::disk('local')->delete($storedFile);
            }
            throw $exception;
        }
    }

    /** @return array{quantity: string, amount: string, date: ?string, notes: ?string} */
    private function validateRegistration(string $quantity, string $amount, ?string $date, ?string $notes, bool $isAcquisition): array
    {
        Validator::make(compact('quantity', 'date', 'notes'), [
            'quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/', 'numeric', $isAcquisition ? 'gt:0' : 'gte:0'],
            'date' => [$isAcquisition ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return [
            'quantity' => (string) BigDecimal::of($quantity)->toScale(4), 'amount' => $this->validateAmount($amount),
            'date' => $date === null ? null : Carbon::parse($date)->toDateString(),
            'notes' => filled($notes) ? $notes : null,
        ];
    }

    private function validateAmount(string $amount): string
    {
        Validator::make(['amount' => $amount], ['amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'numeric', 'gte:0']])->validate();

        return (string) BigDecimal::of($amount)->toScale(2);
    }

    private function movementKey(string $key): string
    {
        $internalKey = 'contribution:'.hash('sha256', $key);
        if (ShareMovement::query()->where('operation_key', $internalKey)->exists()) {
            throw new DomainException('La clave reservada de movimiento ya existe; concilie antes de registrar el aporte.');
        }

        return $internalKey;
    }
}
