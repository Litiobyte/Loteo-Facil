<?php

namespace App\Domain\Owners\Services;

use App\Models\CommunitySetting;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShareHoldingService
{
    /** @return array{issued: string, active: string, equivalent_square_meters: string} */
    public function totals(): array
    {
        $issued = BigDecimal::zero();
        $active = BigDecimal::zero();
        $area = BigDecimal::zero();
        foreach (ShareHolding::query()->cursor() as $holding) {
            $issued = $issued->plus($holding->quantity);
            if ($holding->status === 'active') {
                $active = $active->plus($holding->quantity);
                $area = $area->plus($holding->equivalentSquareMeters());
            }
        }

        return ['issued' => (string) $issued, 'active' => (string) $active, 'equivalent_square_meters' => (string) $area];
    }

    public function acquire(Propietario $owner, string $quantity, string $date, string $key, string $reason, User $actor, bool $withContribution = false): ShareHolding
    {
        return $this->operate('acquire', ['owner' => $owner->id, 'quantity' => $quantity, 'date' => $date], $key, $reason, $actor,
            function (CommunitySetting $settings) use ($owner, $quantity, $date, $reason, $withContribution): ShareHolding {
                if (! $withContribution && $owner->shareContributions()->exists()) {
                    throw new DomainException('El socio ya tiene historial económico. Registre la adquisición con su aporte mediante ShareContributionService.');
                }
                $this->validateQuantity($quantity, $settings);
                Validator::make(['date' => $date], ['date' => ['required', 'date', 'before_or_equal:today']])->validate();
                $this->assertCapacity($settings, $quantity);

                return ShareHolding::query()->create([
                    'propietario_id' => $owner->id, 'quantity' => $quantity,
                    'square_meters_per_share' => $settings->square_meters_per_share,
                    'acquired_at' => $date, 'status' => 'active', 'notes' => $reason,
                ]);
            });
    }

    public function initializeHistorical(Propietario $owner, string $quantity, ?string $date, string $key, string $reason, User $actor): ShareHolding
    {
        return $this->operate('historical_opening', ['owner' => $owner->id, 'quantity' => $quantity, 'date' => $date], $key, $reason, $actor,
            function (CommunitySetting $settings) use ($owner, $quantity, $date, $reason): ShareHolding {
                $this->validateQuantity($quantity, $settings, true);
                Validator::make(['date' => $date], ['date' => ['nullable', 'date', 'before_or_equal:today']])->validate();
                if ($owner->shareHoldings()->exists()) {
                    throw new DomainException('Ya existen tenencias; deben conciliarse sin duplicar acciones.');
                }
                $this->assertCapacity($settings, $quantity);

                return ShareHolding::query()->create([
                    'propietario_id' => $owner->id, 'quantity' => $quantity,
                    'square_meters_per_share' => $settings->square_meters_per_share,
                    'acquired_at' => $date, 'status' => 'active', 'notes' => $reason,
                ]);
            });
    }

    public function adjust(ShareHolding $holding, string $quantity, string $status, string $key, string $reason, User $actor): ShareHolding
    {
        return $this->operate('adjust', ['holding' => $holding->id, 'quantity' => $quantity, 'status' => $status], $key, $reason, $actor,
            function (CommunitySetting $settings) use ($holding, $quantity, $status): ShareHolding {
                $this->validateQuantity($quantity, $settings, true);
                if (! in_array($status, ['active', 'inactive'], true)) {
                    throw new DomainException('Estado de participación inválido.');
                }
                $locked = ShareHolding::query()->lockForUpdate()->findOrFail($holding->id);
                $this->assertCapacity($settings, (string) BigDecimal::of($quantity)->minus($locked->quantity));
                $locked->update(['quantity' => $quantity, 'status' => $status]);

                return $locked;
            }, $holding);
    }

    public function transfer(ShareHolding $holding, Propietario $recipient, string $quantity, string $key, string $reason, User $actor): ShareHolding
    {
        return $this->operate('transfer', ['holding' => $holding->id, 'recipient' => $recipient->id, 'quantity' => $quantity], $key, $reason, $actor,
            function (CommunitySetting $settings) use ($holding, $recipient, $quantity, $reason, $key, $actor): ShareHolding {
                $this->validateQuantity($quantity, $settings);
                $source = ShareHolding::query()->lockForUpdate()->findOrFail($holding->id);
                if ($source->propietario_id === $recipient->id || $source->status !== 'active' || BigDecimal::of($source->quantity)->isLessThan($quantity)) {
                    throw new DomainException('Transferencia inválida o saldo insuficiente.');
                }
                $before = $source->quantity;
                $source->update(['quantity' => (string) BigDecimal::of($before)->minus($quantity)]);
                $target = ShareHolding::query()->create([
                    'propietario_id' => $recipient->id, 'quantity' => $quantity,
                    'square_meters_per_share' => $source->square_meters_per_share,
                    'acquired_at' => today(), 'status' => 'active', 'notes' => $reason,
                ]);
                ShareMovement::query()->create([
                    'share_holding_id' => $source->id, 'counterparty_holding_id' => $target->id,
                    'created_by' => $actor->id, 'operation_key' => $key.':out', 'type' => 'transfer_out',
                    'quantity_before' => $before, 'quantity_after' => $source->quantity, 'reason' => $reason,
                    'context' => ['recipient' => $recipient->id],
                ]);

                return $target;
            });
    }

    /** @param array<string, mixed> $context */
    private function operate(string $type, array $context, string $key, string $reason, User $actor, callable $operation, ?ShareHolding $previous = null): ShareHolding
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        Validator::make(['key' => $key, 'reason' => $reason], [
            'key' => ['required', 'string', 'max:80'], 'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($type, $context, $key, $reason, $actor, $operation, $previous): ShareHolding {
            $settings = CommunitySetting::query()->lockForUpdate()->findOrFail(1);
            $existing = ShareMovement::query()->where('operation_key', $key)->first();
            $context['reason'] = $reason;
            if ($existing) {
                $stored = $existing->context['request'] ?? [];
                ksort($stored);
                ksort($context);
                if ($existing->type !== $type || $stored !== $context) {
                    throw new DomainException('La clave ya fue utilizada para otra operación.');
                }

                return ShareHolding::query()->findOrFail($existing->share_holding_id);
            }
            if (! $settings->usesShares()) {
                throw new DomainException('La modalidad no permite nuevas operaciones de acciones.');
            }
            $original = $previous ? ShareHolding::query()->findOrFail($previous->id) : null;
            $before = $original?->quantity ?? '0';
            $result = $operation($settings);
            ShareMovement::query()->create([
                'share_holding_id' => $result->id, 'created_by' => $actor->id, 'operation_key' => $key,
                'type' => $type, 'quantity_before' => $before, 'quantity_after' => $result->quantity,
                'context' => [
                    'request' => $context, 'status_before' => $original?->status,
                    'status_after' => $result->status, 'square_meters_per_share' => $result->square_meters_per_share,
                ], 'reason' => $reason,
            ]);

            return $result;
        });
    }

    private function validateQuantity(string $quantity, CommunitySetting $settings, bool $allowZero = false): void
    {
        Validator::make(['quantity' => $quantity], [
            'quantity' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/', 'numeric', $allowZero ? 'gte:0' : 'gt:0'],
        ])->validate();
        $number = BigDecimal::of($quantity);
        if (! $number->isEqualTo($number->toScale($settings->share_precision, RoundingMode::DOWN))) {
            throw new DomainException('La cantidad excede la precisión de la comunidad.');
        }
    }

    private function assertCapacity(CommunitySetting $settings, string $increase): void
    {
        $total = BigDecimal::of($increase);
        foreach (ShareHolding::query()->get(['quantity']) as $holding) {
            $total = $total->plus($holding->quantity);
        }
        if ($settings->authorized_shares !== null && $total->isGreaterThan($settings->authorized_shares)) {
            throw new DomainException('La operación excede el total autorizado.');
        }
    }
}
