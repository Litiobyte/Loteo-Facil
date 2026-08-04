<?php

namespace Tests\Feature;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use App\Filament\Admin\Resources\CollectionResource;
use App\Filament\Admin\Resources\ExpenseCategoryResource;
use App\Filament\Admin\Resources\ExpenseFundingPayments\ExpenseFundingPaymentResource;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ViewExpense;
use App\Filament\Admin\Resources\ExpenseResource\RelationManagers\FundingCollectionsRelationManager;
use App\Filament\Admin\Resources\PartnerChargeResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PartnerCharge;
use App\Models\User;
use Filament\PanelRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentExpenseResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_resources_are_restricted_to_admin_and_super_admin_roles(): void
    {
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = User::factory()->create();
        $owner->assignRole('propietario');

        $this->actingAs($superAdmin);
        $this->assertTrue(ExpenseCategoryResource::canAccess());
        $this->assertTrue(ExpenseResource::canAccess());

        $this->actingAs($admin);
        $this->assertTrue(ExpenseCategoryResource::canAccess());
        $this->assertTrue(ExpenseResource::canAccess());

        $this->actingAs($owner);
        $this->assertFalse(ExpenseCategoryResource::canAccess());
        $this->assertFalse(ExpenseResource::canAccess());
    }

    public function test_only_registered_expenses_can_be_edited_or_deleted_from_resource(): void
    {
        Role::findOrCreate('admin', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $registeredExpense = Expense::factory()->registered()->create();
        $distributedExpense = Expense::factory()->distributed()->create();
        $cancelledExpense = Expense::factory()->cancelled()->create();

        $this->actingAs($admin);

        $this->assertTrue(ExpenseResource::canEdit($registeredExpense));
        $this->assertTrue(ExpenseResource::canDelete($registeredExpense));

        $this->assertFalse(ExpenseResource::canEdit($distributedExpense));
        $this->assertFalse(ExpenseResource::canDelete($distributedExpense));

        $this->assertFalse(ExpenseResource::canEdit($cancelledExpense));
        $this->assertFalse(ExpenseResource::canDelete($cancelledExpense));
    }

    public function test_category_delete_is_blocked_when_it_has_associated_expenses(): void
    {
        Role::findOrCreate('admin', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $categoryWithExpense = ExpenseCategory::factory()->create();
        Expense::factory()->create([
            'expense_category_id' => $categoryWithExpense->id,
            'status' => ExpenseStatus::Registered,
        ]);

        $emptyCategory = ExpenseCategory::factory()->create();

        $this->actingAs($admin);

        $this->assertFalse(ExpenseCategoryResource::canDelete($categoryWithExpense));
        $this->assertTrue(ExpenseCategoryResource::canDelete($emptyCategory));
    }

    public function test_admin_panel_registers_expense_resources(): void
    {
        $resources = app(PanelRegistry::class)->get('admin')->getResources();

        $this->assertContains(
            ExpenseCategoryResource::class,
            $resources,
        );
        $this->assertContains(
            ExpenseResource::class,
            $resources,
        );
        $this->assertContains(
            ExpenseFundingPaymentResource::class,
            $resources,
        );
    }

    public function test_financial_navigation_groups_and_order_are_configured_as_expected(): void
    {
        $this->assertSame('Gastos Comunes', PartnerChargeResource::getNavigationGroup());
        $this->assertSame('Gastos Comunes', ExpenseCategoryResource::getNavigationGroup());
        $this->assertSame('Gastos Comunes', ExpenseResource::getNavigationGroup());
        $this->assertSame('Gastos Comunes', CollectionResource::getNavigationGroup());
        $this->assertSame('Gastos Comunes', ExpenseFundingPaymentResource::getNavigationGroup());

        $this->assertSame(10, PartnerChargeResource::getNavigationSort());
        $this->assertSame(20, ExpenseCategoryResource::getNavigationSort());
        $this->assertSame(30, ExpenseResource::getNavigationSort());
        $this->assertSame(40, CollectionResource::getNavigationSort());
        $this->assertSame(50, ExpenseFundingPaymentResource::getNavigationSort());

        $this->assertSame('Finanzas', AccountingPeriodResource::getNavigationGroup());
        $this->assertSame(10, AccountingPeriodResource::getNavigationSort());
    }

    public function test_view_expense_page_includes_funding_collections_relation_manager(): void
    {
        $page = app(ViewExpense::class);
        $relationManagers = $page->getRelationManagers();

        $this->assertContains(FundingCollectionsRelationManager::class, $relationManagers);
    }

    public function test_can_duplicate_a_registered_expense(): void
    {
        $expense = Expense::factory()->registered()->create([
            'title' => 'Contador mensual',
            'amount' => 100000,
            'expense_date' => Carbon::parse('2026-05-05'),
            'due_date' => Carbon::parse('2026-05-20'),
        ]);

        $duplicate = ExpenseResource::duplicateExpense($expense, [
            'expense_date' => '2026-06-05',
            'due_date' => '2026-06-20',
            'amount' => 100000,
        ]);

        $this->assertModelExists($duplicate);
        $this->assertNotSame($expense->id, $duplicate->id);
        $this->assertSame('Contador mensual', $duplicate->title);
        $this->assertSame(ExpenseStatus::Registered, $duplicate->status);
        $this->assertSame('2026-06-05', $duplicate->expense_date->toDateString());
        $this->assertSame('2026-06-20', $duplicate->due_date?->toDateString());
    }

    public function test_can_duplicate_a_distributed_expense(): void
    {
        $expense = Expense::factory()->distributed()->create([
            'title' => 'Licencia software anualizada',
            'amount' => 250000,
            'expense_date' => Carbon::parse('2026-04-10'),
            'due_date' => Carbon::parse('2026-05-10'),
        ]);

        $duplicate = ExpenseResource::duplicateExpense($expense, [
            'expense_date' => '2026-05-10',
            'due_date' => null,
            'amount' => 270000,
        ]);

        $this->assertModelExists($duplicate);
        $this->assertNotSame($expense->id, $duplicate->id);
        $this->assertSame(ExpenseStatus::Registered, $duplicate->status);
        $this->assertSame('2026-05-10', $duplicate->expense_date->toDateString());
        $this->assertSame('270000.00', $duplicate->amount);
    }

    public function test_bulk_duplication_creates_three_expenses(): void
    {
        $targetMonth = now()->startOfMonth();
        $sourceMonth = $targetMonth->copy()->subMonthNoOverflow();

        $records = Collection::make([
            Expense::factory()->registered()->create([
                'title' => 'Contador',
                'amount' => 100000,
                'expense_date' => $sourceMonth->copy()->setDay(1),
                'due_date' => null,
            ]),
            Expense::factory()->distributed()->create([
                'title' => 'Administración',
                'amount' => 200000,
                'expense_date' => $sourceMonth->copy()->setDay(2),
                'due_date' => null,
            ]),
            Expense::factory()->cancelled()->create([
                'title' => 'Software',
                'amount' => 300000,
                'expense_date' => $sourceMonth->copy()->setDay(3),
                'due_date' => null,
            ]),
        ]);

        $result = ExpenseResource::duplicateExpensesForMonth($records, [
            'target_month' => $targetMonth->toDateString(),
            'amount_mode' => 'keep',
        ]);

        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['warnings']);

        $this->assertSame(6, Expense::query()->count());
        $this->assertSame(4, Expense::query()->where('status', ExpenseStatus::Registered)->count());
        $this->assertDatabaseHas('expenses', [
            'title' => 'Contador',
            'expense_date' => $targetMonth->copy()->setDay(1)->format('Y-m-d 00:00:00'),
            'status' => ExpenseStatus::Registered->value,
        ]);
    }

    public function test_admin_can_view_expenses_index_page_with_charges_count(): void
    {
        Role::findOrCreate('super_admin', 'web');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $expense = Expense::factory()->distributed()->create();
        PartnerCharge::factory()->create(['expense_id' => $expense->id]);

        $this->actingAs($superAdmin);

        $this->get('/admin/expenses')->assertOk();
    }
}
