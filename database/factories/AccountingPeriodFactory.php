<?php

namespace Database\Factories;

use App\Domain\Accounting\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AccountingPeriod>
 */
class AccountingPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = Carbon::create(
            year: fake()->numberBetween((int) now()->subYears(1)->format('Y'), (int) now()->addYears(1)->format('Y')),
            month: fake()->numberBetween(1, 12),
            day: 1,
        );

        return [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('m'),
            'period_start' => $date->copy()->startOfMonth()->toDateString(),
            'period_end' => $date->copy()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::Open,
            'close_folio' => null,
            'closed_at' => null,
            'closed_by' => null,
            'reopened_at' => null,
            'reopened_by' => null,
            'reopen_reason' => null,
        ];
    }

    public function closed(?User $user = null): static
    {
        return $this->state(function (array $attributes) use ($user): array {
            $year = (int) ($attributes['year'] ?? now()->format('Y'));
            $month = (int) ($attributes['month'] ?? now()->format('m'));

            return [
                'status' => AccountingPeriodStatus::Closed,
                'close_folio' => sprintf('CIERRE-%d%02d-0001', $year, $month),
                'closed_at' => now(),
                'closed_by' => $user?->id ?? User::factory(),
            ];
        });
    }
}
