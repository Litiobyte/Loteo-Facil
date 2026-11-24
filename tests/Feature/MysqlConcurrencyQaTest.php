<?php

namespace Tests\Feature;

use App\Domain\Balances\Services\PartnerBalanceService;
use App\Models\Collection;
use App\Models\HistoricalExternalIdentity;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * QA Etapa 7 sobre MySQL real: carrera de procesos independientes
 * sobre el mismo pago (bloqueo FOR UPDATE revalidado en transacción)
 * y comportamiento de colación de la unicidad de identidad externa.
 * Se omite en la suite SQLite.
 */
class MysqlConcurrencyQaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || ! in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('QA de concurrencia y colación solo se ejecuta sobre MySQL (Etapa 7).');
        }

        // Sin RefreshDatabase ni DatabaseMigrations: los procesos hijos necesitan
        // datos confirmados, y los down() protegidos no permiten rollbacks.
        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        if (config('database.default') === 'mysql') {
            $this->artisan('db:wipe', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        }

        parent::tearDown();
    }

    public function test_concurrent_manual_applications_cannot_overapply_a_payment(): void
    {
        $owner = Propietario::factory()->create();
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 100,
            'paid_amount' => 0,
            'remaining_amount' => 100,
            'due_date' => null,
            'imputation_date' => null,
        ]);
        $payment = Collection::factory()->create([
            'propietario_id' => $owner->id,
            'amount' => 100,
            'applied_amount' => 0,
            'unapplied_amount' => 100,
            'collection_date' => now()->toDateString(),
            'collection_method' => 'transferencia',
            'created_by' => null,
        ]);

        [$first, $second] = $this->race($payment->id, $charge->id);

        $successes = collect([$first, $second])->filter(fn (string $output): bool => str_contains($output, 'SUCCESS'))->count();
        $this->assertSame(1, $successes, "Dos procesos aplicaron el mismo pago: [{$first}] [{$second}]");

        $payment->refresh();
        $this->assertSame(100.0, round((float) $payment->applied_amount, 2));
        $this->assertSame(0.0, round((float) $payment->unapplied_amount, 2));
        $this->assertSame(1, $payment->allocations()->count());
        $charge->refresh();
        $this->assertSame(100.0, round((float) $charge->paid_amount, 2));
        $this->assertSame(0.0, round((float) $charge->remaining_amount, 2));
        $this->assertTrue(app(PartnerBalanceService::class)->validateBalanceConsistency($owner)['valid']);
    }

    public function test_external_identity_uniqueness_rejects_case_insensitive_duplicate(): void
    {
        HistoricalExternalIdentity::query()->create([
            'community_scope' => 1,
            'source' => 'ledger',
            'movement_type' => 'expense_charge',
            'external_id' => 'ABC',
            'identifiable_type' => PartnerCharge::class,
            'identifiable_id' => 1,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        HistoricalExternalIdentity::query()->create([
            'community_scope' => 1,
            'source' => 'ledger',
            'movement_type' => 'expense_charge',
            'external_id' => 'abc',
            'identifiable_type' => PartnerCharge::class,
            'identifiable_id' => 2,
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function race(int $paymentId, int $chargeId): array
    {
        $code = <<<'PHP'
            <?php

            require __DIR__.'/../../vendor/autoload.php';
            $app = require __DIR__.'/../../bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

            try {
                $payment = App\Models\Collection::query()->findOrFail($argv[1]);
                app(App\Domain\Collections\Services\CollectionApplicationService::class)
                    ->applyCollectionManually($payment, [['charge_id' => (int) $argv[2], 'amount' => 100.0]], now(), null);
                echo 'SUCCESS';
            } catch (Throwable $e) {
                echo 'FAILED: '.$e->getMessage();
            }
            PHP;

        $script = storage_path('framework/qa_concurrency_child.php');
        File::put($script, $code);

        try {
            $first = new Process(['php', $script, (string) $paymentId, (string) $chargeId], base_path(), null, null, 120);
            $second = new Process(['php', $script, (string) $paymentId, (string) $chargeId], base_path(), null, null, 120);
            $first->start();
            $second->start();
            $first->wait();
            $second->wait();

            return [$first->getOutput(), $second->getOutput()];
        } finally {
            File::delete($script);
        }
    }
}
