<?php

namespace Tests\Feature;

use App\Models\HistoricalExternalIdentity;
use App\Models\Propietario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HistoricalExternalIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function identity(array $overrides = []): array
    {
        return array_replace([
            'community_scope' => 'installation',
            'source' => 'ledger',
            'movement_type' => 'charge',
            'external_id' => 'ABC-123',
            'identifiable_type' => 'App\\Models\\PartnerCharge',
            'identifiable_id' => 1,
        ], $overrides);
    }

    public function test_database_rejects_duplicate_even_for_a_different_target(): void
    {
        HistoricalExternalIdentity::query()->create($this->identity());

        $this->expectException(QueryException::class);
        HistoricalExternalIdentity::query()->create($this->identity(['identifiable_id' => 2]));
    }

    public function test_identity_is_scoped_by_all_four_key_components(): void
    {
        HistoricalExternalIdentity::query()->create($this->identity());
        HistoricalExternalIdentity::query()->create($this->identity(['community_scope' => 'other']));
        HistoricalExternalIdentity::query()->create($this->identity(['source' => 'other']));
        HistoricalExternalIdentity::query()->create($this->identity(['movement_type' => 'payment']));
        HistoricalExternalIdentity::query()->create($this->identity(['external_id' => 'ABC-124']));

        $this->assertDatabaseCount('historical_external_identities', 5);
    }

    public function test_identity_components_cannot_be_null(): void
    {
        $this->expectException(QueryException::class);
        HistoricalExternalIdentity::query()->create($this->identity(['community_scope' => null]));
    }

    public function test_identity_and_target_can_be_persisted_and_rolled_back_atomically(): void
    {
        $ownerId = null;
        try {
            DB::transaction(function () use (&$ownerId): void {
                $owner = Propietario::factory()->create();
                $ownerId = $owner->id;
                $identity = HistoricalExternalIdentity::query()->create($this->identity([
                    'identifiable_type' => Propietario::class,
                    'identifiable_id' => $owner->id,
                ]));

                $this->assertTrue($identity->identifiable->is($owner));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('historical_external_identities', 0);
        $this->assertDatabaseMissing('propietarios', ['id' => $ownerId]);
    }
}
