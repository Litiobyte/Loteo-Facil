<?php

namespace App\Domain\Expenses\Services;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Models\Expense;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RecurringExpenseService
{
    /**
     * Para reintentar una serie existente, enviar su identificador como head_id.
     * Los títulos se conservan sin sufijo; las fechas identifican cada instancia.
     *
     * @param  array<string, mixed>  $data
     */
    public function createRecurringSeries(array $data): Expense
    {
        return DB::transaction(function () use ($data): Expense {
            if (isset($data['head_id'])) {
                $head = $this->lockHead((int) $data['head_id']);
            } else {
                Validator::make($data, [
                    'is_recurring' => ['required', 'accepted'],
                    'recurrence_end_date' => ['required', 'date', 'after_or_equal:expense_date'],
                    'expense_date' => ['required', 'date'],
                ])->validate();

                if (! empty($data['parent_expense_id'])) {
                    throw new DomainException('La cabeza de una serie no puede tener padre.');
                }

                $head = new Expense;
                $head->fill(collect($data)->only([
                    'expense_category_id', 'title', 'description', 'amount', 'expense_date',
                    'due_date', 'distribution_type', 'created_by', 'notes', 'recurrence_end_date',
                ])->all());
                $head->is_recurring = true;
                $head->status = ExpenseStatus::Registered;
                $head->save();
            }

            $end = $head->recurrence_end_date;
            if ($end === null || $end->lt($head->expense_date)) {
                throw new DomainException('La fecha final de recurrencia es inválida.');
            }

            for ($month = 1; ; $month++) {
                $date = $head->expense_date->copy()->addMonthsNoOverflow($month);
                if ($date->gt($end)) {
                    break;
                }
                if ($month >= 12) {
                    throw new DomainException('Una serie no puede superar 12 instancias.');
                }

                if (Expense::query()->where('parent_expense_id', $head->id)->whereDate('expense_date', $date)->exists()) {
                    continue;
                }

                Expense::query()->create([
                    'expense_category_id' => $head->expense_category_id,
                    'title' => $head->title,
                    'description' => $head->description,
                    'amount' => $head->amount,
                    'expense_date' => $date,
                    'due_date' => $head->due_date?->copy()->addMonthsNoOverflow($month),
                    'distribution_type' => $head->distribution_type,
                    'status' => ExpenseStatus::Registered,
                    'created_by' => $head->created_by,
                    'notes' => $head->notes,
                    'is_recurring' => true,
                    'recurrence_end_date' => $end,
                    'parent_expense_id' => $head->id,
                ]);
            }

            return $head;
        });
    }

    public function cancelFutureInstances(Expense $head): void
    {
        DB::transaction(function () use ($head): void {
            $head = $this->lockHead($head->getKey());
            $children = Expense::query()->where('parent_expense_id', $head->id)
                ->registered()->orderBy('id')->lockForUpdate()->get();
            foreach ($children as $child) {
                $child->update(['status' => ExpenseStatus::Cancelled]);
            }
        });
    }

    /** @param array<string, mixed> $data */
    public function updateFutureInstances(Expense $head, array $data): void
    {
        if (array_diff(array_keys($data), ['amount', 'due_date', 'notes', 'distribution_type']) !== []) {
            throw new DomainException('Se intentó modificar un campo no permitido en las instancias.');
        }

        DB::transaction(function () use ($head, $data): void {
            $head = $this->lockHead($head->getKey());
            $children = Expense::query()->where('parent_expense_id', $head->id)
                ->registered()->orderBy('id')->lockForUpdate()->get();
            foreach ($children as $child) {
                $child->update($data);
            }
        });
    }

    private function lockHead(int $id): Expense
    {
        $head = Expense::query()->lockForUpdate()->findOrFail($id);
        if (! $head->is_recurring || $head->parent_expense_id !== null) {
            throw new DomainException('El gasto debe ser la cabeza de una serie recurrente.');
        }

        return $head;
    }
}
