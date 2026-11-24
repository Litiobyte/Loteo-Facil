<?php

namespace App\Domain\Owners\Services;

use App\Domain\Documents\Services\PrivateStorageService;
use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\CommunitySetting;
use App\Models\Expense;
use App\Models\ShareHolding;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommunitySettingsService
{
    /** @param array<string, mixed> $data */
    public function update(array $data, string $reason, User $actor): CommunitySetting
    {
        abort_unless($actor->hasAnyRole(['admin', 'super_admin']), 403);
        $data = Validator::make($data, [
            'community_name' => ['nullable', 'string', 'max:120'],
            'logo_path' => ['nullable'],
            'mode' => ['required', Rule::in(['lots', 'shares', 'mixed'])],
            'square_meters_per_share' => ['required', 'regex:/^\d{1,10}(\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'share_precision' => ['required', 'integer', 'between:0,4'],
            'authorized_shares' => ['nullable', 'regex:/^\d{1,10}(\.\d{1,4})?$/', 'numeric', 'gte:0'],
        ])->validate();
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:1000']])->validate();

        $storedLogo = null;

        try {
            return DB::transaction(function () use ($data, $reason, $actor, &$storedLogo): CommunitySetting {
                $settings = CommunitySetting::query()->lockForUpdate()->findOrFail(1);
                if (array_key_exists('logo_path', $data)) {
                    $logo = $data['logo_path'];
                    if ($logo instanceof UploadedFile) {
                        Validator::make(['logo_path' => $logo], [
                            'logo_path' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'extensions:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
                        ])->validate();
                    } elseif ($logo !== null && (! is_string($logo) || $logo !== $settings->logo_path)) {
                        throw ValidationException::withMessages(['logo_path' => 'Suba una imagen nueva o conserve el logotipo actual.']);
                    }
                }
                $disabledTypes = match ($data['mode']) {
                    'lots' => [ExpenseDistributionType::ProportionalByShares->value, ExpenseDistributionType::ProportionalByEquivalentArea->value],
                    'shares' => [ExpenseDistributionType::ProportionalByHectares->value],
                    default => [],
                };
                if (Expense::query()->where('status', ExpenseStatus::Registered)->whereIn('distribution_type', $disabledTypes)->exists()) {
                    throw new DomainException('Distribuya o cancele primero los gastos registrados cuyo criterio quedaría deshabilitado.');
                }
                $total = BigDecimal::zero();
                foreach (ShareHolding::query()->get() as $holding) {
                    $total = $total->plus($holding->quantity);
                    $quantity = BigDecimal::of($holding->quantity);
                    if (! $quantity->isEqualTo($quantity->toScale((int) $data['share_precision'], RoundingMode::DOWN))) {
                        throw new DomainException('La precisión excluiría participaciones existentes.');
                    }
                }
                if (isset($data['authorized_shares']) && $total->isGreaterThan($data['authorized_shares'])) {
                    throw new DomainException('El total autorizado no puede ser menor al emitido.');
                }
                $before = $settings->only(array_keys($data));
                if (($data['logo_path'] ?? null) instanceof UploadedFile) {
                    $storedLogo = 'community-logos/'.$data['logo_path']->hashName();
                    $stream = fopen($data['logo_path']->getRealPath(), 'rb');
                    if ($stream === false) {
                        throw ValidationException::withMessages(['logo_path' => 'No fue posible leer el logotipo.']);
                    }
                    try {
                        app(PrivateStorageService::class)->putStream($storedLogo, $stream);
                    } finally {
                        fclose($stream);
                    }
                    $data['logo_path'] = $storedLogo;
                }
                $settings->update($data);
                DB::table('community_setting_revisions')->insert([
                    'created_by' => $actor->id, 'before' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after' => json_encode($settings->only(array_keys($data)), JSON_THROW_ON_ERROR),
                    'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
                ]);

                return $settings;
            });
        } catch (Throwable $exception) {
            if ($storedLogo) {
                Storage::disk('documents')->delete($storedLogo);
            }

            throw $exception;
        }
    }
}
