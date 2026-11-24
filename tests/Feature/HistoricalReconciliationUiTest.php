<?php

namespace Tests\Feature;

use App\Domain\Balances\Services\CashBalanceService;
use App\Domain\Balances\Services\PartnerBalanceService;
use App\Domain\Imports\Enums\HistoricalAdjustmentCategory;
use App\Domain\Imports\Services\HistoricalAdjustmentService;
use App\Domain\Imports\Services\HistoricalOpeningService;
use App\Filament\Admin\Pages\HistoricalReconciliation;
use App\Models\Collection;
use App\Models\HistoricalAdjustment;
use App\Models\HistoricalReconciliation as ReconciliationRecord;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HistoricalReconciliationUiTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Propietario $owner;

    private PartnerCharge $charge;

    protected function setUp(): void
    {
        parent::setUp();
        Repeater::fake();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('super_admin', 'web');
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');
        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->owner = Propietario::factory()->create();
        $this->charge = app(HistoricalOpeningService::class)->create(
            $this->owner, $this->superAdmin, '200.00', '2020-01-31', ['source' => 'Libro inicial'],
        );
    }

    public function test_access_is_exclusive_to_super_admin(): void
    {
        $this->assertTrue(HistoricalReconciliation::canAccess());
        $this->get(HistoricalReconciliation::getUrl())->assertOk();
        $this->page()->assertSee($this->owner->nombre_completo);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->assertFalse(HistoricalReconciliation::canAccess());
        $this->get(HistoricalReconciliation::getUrl())->assertForbidden();
        Livewire::test(HistoricalReconciliation::class)->assertForbidden();
    }

    public function test_breakdown_comes_from_report_and_shows_unattributed_adjustments(): void
    {
        app(HistoricalAdjustmentService::class)->create(
            $this->owner, $this->superAdmin, HistoricalAdjustmentCategory::UnexplainedDifference,
            '20.00', '2021', 'year', ['source' => 'Libro anual'],
        );
        $this->page('80.00')->assertSee('Deuda calculada')->assertSee('200,00')->assertSee('80,00')
            ->assertSee('120,00')->assertSee('Deuda mayor que la esperada')
            ->assertSee('Recaudaciones aplicadas')->assertSee('Aplicaciones no monetarias')
            ->assertSee('Recaudaciones sin aplicar')->assertSee('Crédito disponible')
            ->assertSee('Pendientes de verificación')->assertSee('Pagos reconocidos sin comprobante')
            ->assertSee('Diferencias no explicadas')->assertSee('Libro anual')->assertSee('Precisión anual');
    }

    public function test_payment_recognition_updates_cash_evidence_audit_and_closes_form(): void
    {
        $page = $this->page()->mountAction('recognizePayment')
            ->assertSee('Impacta caja')->assertSee('reconocido sin comprobante')
            ->setActionData($this->paymentData())->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('Pago reconocido y registrado en caja.')
            ->assertActionNotMounted();

        $collection = Collection::query()->sole();
        $this->assertSame('recognized', $collection->evidence_status->value);
        $this->assertSame('2021-02-01', $collection->collection_date->toDateString());
        $this->assertSame($this->superAdmin->id, $collection->created_by);
        $this->assertSame(50.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertSame(150.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
        $record = ReconciliationRecord::query()->sole();
        $this->assertSame(ReconciliationRecord::KindPaymentRecognition, $record->kind);
        $this->assertSame($this->superAdmin->id, $record->authorized_by);
        $page->assertSee('150,00')->mountAction('recognizePayment')
            ->assertActionDataSet(['amount' => null, 'source' => null]);
    }

    public function test_wrong_application_sum_is_visible_and_retry_keeps_form_without_writes(): void
    {
        $page = $this->page()->mountAction('recognizePayment')
            ->setActionData($this->paymentData('40.00'))->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('La suma de las aplicaciones debe coincidir con el monto del pago.')
            ->assertActionMounted('recognizePayment')
            ->assertActionDataSet(['amount' => '50.00', 'source' => 'Libro de administración']);
        $this->assertSame(0, Collection::query()->count());
        $this->assertSame(0, ReconciliationRecord::query()->count());

        $page->setActionData($this->paymentData())->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('Pago reconocido y registrado en caja.')
            ->assertActionNotMounted();
        $this->assertSame(1, Collection::query()->count());
    }

    public function test_forgiveness_reduces_debt_without_cash_and_records_authorizer(): void
    {
        $this->page()->mountAction('classifyDifference')
            ->assertSee('decisión manual del administrador, nunca automática')
            ->setActionData($this->classificationData())->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified('Decisión de conciliación registrada.')
            ->assertActionNotMounted()->assertSet('expectedDebt', '80.00')->assertSee('Sin diferencia');
        $this->assertSame(80.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertSame(0, Collection::query()->count());
        $this->assertSame('debt_forgiveness', HistoricalAdjustment::query()->sole()->category->value);
        $record = ReconciliationRecord::query()->sole();
        $this->assertSame(ReconciliationRecord::KindDifferenceClassification, $record->kind);
        $this->assertSame($this->superAdmin->id, $record->authorized_by);
        $this->assertSame('2021-01-31', $record->cutoff_date->toDateString());
    }

    public function test_excess_classification_is_visible_and_can_be_corrected(): void
    {
        $page = $this->page()->mountAction('classifyDifference')
            ->setActionData($this->classificationData('150.00'))->callMountedAction()
            ->assertNotified('El ajuste no puede exceder la diferencia conciliada.')
            ->assertActionMounted('classifyDifference')->assertActionDataSet(['amount' => '150.00']);
        $this->assertSame(0, ReconciliationRecord::query()->count());
        $this->assertSame(0, HistoricalAdjustment::query()->count());
        $this->assertSame(200.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
        $page->setActionData($this->classificationData())->callMountedAction()
            ->assertNotified('Decisión de conciliación registrada.')->assertActionNotMounted();
    }

    public function test_optional_classification_and_unexplained_difference_do_not_reduce_debt(): void
    {
        $this->page()->callAction('classifyDifference', [
            'expected_debt' => '80.00', 'source' => 'Comparación manual',
        ])->assertHasNoActionErrors()->assertNotified('Decisión de conciliación registrada.');
        $this->assertSame(0, HistoricalAdjustment::query()->count());
        $data = $this->classificationData('120.00');
        $data['category'] = HistoricalAdjustmentCategory::UnexplainedDifference->value;
        unset($data['charge_id']);
        $this->page()->callAction('classifyDifference', $data)
            ->assertHasNoActionErrors()->assertNotified('Decisión de conciliación registrada.');
        $this->assertSame(200.0, app(PartnerBalanceService::class)->getPendingBalance($this->owner));
        $this->assertSame(0.0, app(CashBalanceService::class)->getTotalIncome());
        $this->assertSame(2, ReconciliationRecord::query()->count());
    }

    public function test_inputs_require_source_full_date_and_decimal_format(): void
    {
        $data = $this->paymentData();
        $data['amount'] = '1.000,00';
        $data['payment_date'] = '2021-02';
        $data['source'] = '';
        $this->page()->callAction('recognizePayment', $data)
            ->assertHasActionErrors(['amount', 'payment_date', 'source']);
        $this->assertSame(0, Collection::query()->count());
    }

    public function test_actions_are_reauthorized_after_role_revocation(): void
    {
        $paymentPage = $this->page()->mountAction('recognizePayment')->setActionData($this->paymentData());
        $this->superAdmin->syncRoles(['admin']);
        $paymentPage->callMountedAction()->assertForbidden();
        $this->superAdmin->syncRoles(['super_admin']);
        $classificationPage = $this->page()->mountAction('classifyDifference')->setActionData($this->classificationData());
        $this->superAdmin->syncRoles(['admin']);
        $classificationPage->callMountedAction()->assertForbidden();
        $this->assertSame(0, ReconciliationRecord::query()->count());
        $this->assertSame(0, Collection::query()->count());
    }

    private function page(?string $expectedDebt = null): Testable
    {
        return Livewire::test(HistoricalReconciliation::class)->callAction('selectOwner', [
            'owner_id' => $this->owner->id, 'expected_debt' => $expectedDebt,
        ])->assertHasNoActionErrors();
    }

    private function paymentData(string $applied = '50.00'): array
    {
        return [
            'amount' => '50.00', 'payment_date' => '2021-02-01',
            'allocations' => [['charge_id' => $this->charge->id, 'amount' => $applied]],
            'source' => 'Libro de administración', 'reference' => 'LIBRO-1', 'observations' => 'Sin comprobante.',
        ];
    }

    private function classificationData(string $amount = '120.00'): array
    {
        return [
            'expected_debt' => '80.00', 'source' => 'Resolución administración',
            'cutoff_date' => '2021-01-31', 'category' => HistoricalAdjustmentCategory::DebtForgiveness->value,
            'charge_id' => $this->charge->id, 'amount' => $amount, 'effective_date' => '2021-02-01',
            'reference' => 'ACTA-1', 'observations' => 'Decisión expresa.',
        ];
    }
}
