<?php

namespace Tests\Feature;

use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Balances\Services\HistoricalReconciliationService;
use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Collections\Services\CollectionApplicationService;
use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Domain\Imports\Services\HistoricalAdjustmentService;
use App\Domain\Imports\Services\HistoricalOpeningService;
use App\Models\Collection;
use App\Models\HistoricalReconciliation;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HistoricalReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Propietario $owner;

    private HistoricalReconciliationService $service;

    private PartnerBalanceService $balance;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
        $this->owner = Propietario::factory()->create();
        $this->service = app(HistoricalReconciliationService::class);
        $this->balance = app(PartnerBalanceService::class);
    }

    public function test_ten_installments_with_six_documented_payments_and_four_recognized(): void
    {
        $charges = [];
        for ($i = 0; $i < 10; $i++) {
            $charges[] = PartnerCharge::factory()->create([
                'propietario_id' => $this->owner->id,
                'amount' => 250,
                'paid_amount' => 0,
                'remaining_amount' => 250,
                'created_at' => '2020-02-01',
            ]);
        }

        $applications = app(CollectionApplicationService::class);
        for ($i = 0; $i < 6; $i++) {
            $payment = Collection::factory()->create([
                'propietario_id' => $this->owner->id,
                'amount' => 250,
                'applied_amount' => 0,
                'unapplied_amount' => 250,
                'collection_date' => '2020-03-05',
                'collection_method' => 'transferencia',
                'created_by' => $this->admin->id,
            ]);
            $applications->applyCollectionManually($payment, [
                ['charge_id' => $charges[$i]->id, 'amount' => 250],
            ], Carbon::parse('2020-03-05'), $this->admin->id);
        }

        $result = $this->service->recognizeHistoricalPayment(
            $this->owner,
            $this->admin,
            '1000.00',
            '2021-01-15',
            [
                ['charge_id' => $charges[6]->id, 'amount' => 250],
                ['charge_id' => $charges[7]->id, 'amount' => 250],
                ['charge_id' => $charges[8]->id, 'amount' => 250],
                ['charge_id' => $charges[9]->id, 'amount' => 250],
            ],
            'Libro de administración',
            'LIBRO-2021',
            'Pagos confirmados sin comprobante por la administración.',
        );

        $this->assertSame('recognized', $result['collection']->evidence_status->value);
        $this->assertSame(0.0, $this->balance->getPendingBalance($this->owner));
        $this->assertSame(2500.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertTrue($this->balance->validateBalanceConsistency($this->owner)['valid']);
        $this->assertSame(HistoricalReconciliation::KindPaymentRecognition, $result['record']->kind);
        $this->assertSame($this->admin->id, $result['record']->authorized_by);

        $view = $this->service->breakdown($this->owner);
        $this->assertSame(1, $view['recognized_collections']['count']);
        $this->assertSame(1000.0, $view['recognized_collections']['amount']);

        $report = $this->service->report($this->owner);
        $this->assertNotEmpty($report['reconciliations']);
        $this->assertEmpty($report['incomplete_records']['pending_verification_adjustments']);
    }

    public function test_unconfirmed_difference_does_not_reduce_debt(): void
    {
        app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '200.00', '2020-01-31', ['source' => 'ledger']);

        $result = $this->service->reconcile($this->owner, $this->admin, '80.00', 'Libro 2021');

        $this->assertSame(200.0, $this->balance->getPendingBalance($this->owner));
        $this->assertSame('deuda_mayor_que_esperada', $result['breakdown']['difference_sign']);
        $this->assertSame('120.00', (string) $result['record']->difference);
        $this->assertSame('pending_verification', $result['record']->status);
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
    }

    public function test_classified_forgiveness_reduces_debt_without_cash(): void
    {
        $charge = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '200.00', '2020-01-31', ['source' => 'ledger']);

        $result = $this->service->reconcile(
            $this->owner,
            $this->admin,
            '80.00',
            'Resolución administración',
            '2020-12-31',
            [
                'category' => HistoricalAdjustmentCategory::DebtForgiveness->value,
                'charge_id' => $charge->id,
                'amount' => '120.00',
                'effective_date' => '2021-02-01',
            ],
        );

        $this->assertSame(80.0, $this->balance->getPendingBalance($this->owner));
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertSame('reconciled', $result['record']->status);
        $this->assertNotNull($result['adjustment']);
        $this->assertSame('reconciled', $result['adjustment']->evidence_status);
        $this->assertSame($this->admin->id, $result['record']->authorized_by);
    }

    public function test_year_precision_adjustments_are_reported_without_monthly_attribution(): void
    {
        app(HistoricalAdjustmentService::class)->create(
            $this->owner,
            $this->admin,
            HistoricalAdjustmentCategory::UnexplainedDifference,
            '40.00',
            '2020',
            'year',
            ['source' => 'libro anterior'],
        );

        $view = $this->service->breakdown($this->owner);
        $this->assertSame(1, $view['monthly_unattributed']['count']);
        $this->assertSame('year', $view['monthly_unattributed']['items'][0]['precision']);
        $this->assertSame(0.0, $this->balance->getPendingBalance($this->owner));
    }

    public function test_classification_exceeding_difference_is_rejected(): void
    {
        $charge = app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '200.00', '2020-01-31', ['source' => 'ledger']);

        $this->expectException(DomainException::class);
        $this->service->reconcile(
            $this->owner,
            $this->admin,
            '80.00',
            'Resolución',
            null,
            [
                'category' => HistoricalAdjustmentCategory::DebtForgiveness->value,
                'charge_id' => $charge->id,
                'amount' => '150.00',
                'effective_date' => '2021-02-01',
            ],
        );
    }

    public function test_recognition_allocations_must_sum_to_total(): void
    {
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->owner->id,
            'amount' => 250,
            'paid_amount' => 0,
            'remaining_amount' => 250,
            'created_at' => '2020-02-01',
        ]);

        $this->expectException(DomainException::class);
        $this->service->recognizeHistoricalPayment(
            $this->owner,
            $this->admin,
            '500.00',
            '2021-01-15',
            [['charge_id' => $charge->id, 'amount' => 250]],
            'Libro',
        );
    }

    public function test_recognition_before_opening_cutoff_is_rejected(): void
    {
        app(HistoricalOpeningService::class)->create($this->owner, $this->admin, '350.00', '2020-12-31', ['source' => 'ledger']);
        $charge = PartnerCharge::factory()->create([
            'propietario_id' => $this->owner->id,
            'amount' => 250,
            'paid_amount' => 0,
            'remaining_amount' => 250,
            'created_at' => '2021-02-01',
        ]);

        $this->expectException(DomainException::class);
        $this->service->recognizeHistoricalPayment(
            $this->owner,
            $this->admin,
            '250.00',
            '2020-01-15',
            [['charge_id' => $charge->id, 'amount' => 250]],
            'Libro',
        );
    }

    public function test_recognition_requires_full_date_and_authorization(): void
    {
        $this->expectException(DomainException::class);
        $this->service->recognizeHistoricalPayment(
            $this->owner,
            $this->admin,
            '250.00',
            '2021-01',
            [],
            'Libro',
        );
    }

    public function test_reconciliation_requires_super_admin(): void
    {
        $user = User::factory()->create();

        $this->expectException(DomainException::class);
        $this->service->reconcile($this->owner, $user, '80.00', 'Libro');
    }
}
