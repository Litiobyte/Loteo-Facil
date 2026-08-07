<?php

namespace Database\Seeders;

use App\Domain\Expenses\Enums\ExpenseDistributionType;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ExpenseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = ExpenseCategory::query()->pluck('id', 'name');

        if ($categories->isEmpty()) {
            return;
        }

        $adminUserId = User::query()->where('email', 'admin@loteofacil.cl')->value('id');

        $seedExpenses = [
            ['title' => 'Honorarios contables mayo', 'category' => 'Contador', 'amount' => 450000, 'days_ago' => 20, 'due_in' => 10, 'distribution' => ExpenseDistributionType::EqualByPartner, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Gestión notarial compraventa', 'category' => 'Trámites', 'amount' => 780000, 'days_ago' => 35, 'due_in' => 20, 'distribution' => ExpenseDistributionType::Manual, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Recaudacion contribuciones primer semestre', 'category' => 'Impuestos', 'amount' => 1550000, 'days_ago' => 45, 'due_in' => 15, 'distribution' => ExpenseDistributionType::ProportionalByHectares, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Informe jurídico servidumbres', 'category' => 'Legal', 'amount' => 920000, 'days_ago' => 60, 'due_in' => null, 'distribution' => ExpenseDistributionType::EqualByPartner, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Gastos administrativos oficina', 'category' => 'Administración', 'amount' => 310000, 'days_ago' => 12, 'due_in' => 12, 'distribution' => ExpenseDistributionType::EqualByPartner, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Mantención de acceso principal', 'category' => 'Mantención', 'amount' => 2100000, 'days_ago' => 75, 'due_in' => 25, 'distribution' => ExpenseDistributionType::ProportionalByHectares, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Regularización expediente municipal', 'category' => 'Trámites', 'amount' => 680000, 'days_ago' => 30, 'due_in' => 30, 'distribution' => ExpenseDistributionType::Manual, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Actualización tributaria anual', 'category' => 'Impuestos', 'amount' => 1240000, 'days_ago' => 90, 'due_in' => null, 'distribution' => ExpenseDistributionType::ProportionalByHectares, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Asesoría legal contratos servicios', 'category' => 'Legal', 'amount' => 530000, 'days_ago' => 22, 'due_in' => 8, 'distribution' => ExpenseDistributionType::EqualByPartner, 'status' => ExpenseStatus::Cancelled],
            ['title' => 'Compra insumos administrativos', 'category' => 'Administración', 'amount' => 230000, 'days_ago' => 18, 'due_in' => 14, 'distribution' => ExpenseDistributionType::EqualByPartner, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Reparación de señalética interna', 'category' => 'Mantención', 'amount' => 840000, 'days_ago' => 50, 'due_in' => 20, 'distribution' => ExpenseDistributionType::ProportionalByHectares, 'status' => ExpenseStatus::Registered, 'funded_amount' => 0],
            ['title' => 'Recaudacion patente municipal', 'category' => 'Impuestos', 'amount' => 460000, 'days_ago' => 28, 'due_in' => 7, 'distribution' => ExpenseDistributionType::EqualByPartner, 'status' => ExpenseStatus::Cancelled],
        ];

        foreach ($seedExpenses as $seedExpense) {
            $expenseDate = now()->subDays($seedExpense['days_ago'])->toDateString();
            $dueDate = $seedExpense['due_in'] === null
                ? null
                : now()->subDays($seedExpense['days_ago'])->addDays($seedExpense['due_in'])->toDateString();

            Expense::query()->create([
                'expense_category_id' => $categories[$seedExpense['category']],
                'title' => $seedExpense['title'],
                'description' => null,
                'amount' => $seedExpense['amount'],
                'expense_date' => $expenseDate,
                'due_date' => $dueDate,
                'distribution_type' => $seedExpense['distribution']->value,
                'status' => $seedExpense['status']->value,
                'funded_amount' => $seedExpense['funded_amount'] ?? 0,
                'created_by' => $adminUserId,
                'notes' => null,
            ]);
        }
    }
}
