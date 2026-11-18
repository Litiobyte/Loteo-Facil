<?php

namespace App\Filament\Admin\Resources\ExpenseResource\Pages;

use App\Domain\Expenses\Services\RecurringExpenseService;
use App\Filament\Admin\Resources\ExpenseResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['created_by'] = auth()->id();

        if ($data['is_recurring'] ?? false) {
            try {
                return app(RecurringExpenseService::class)->createRecurringSeries($data);
            } catch (\DomainException $exception) {
                Notification::make()->title('No se pudo crear la serie')->body($exception->getMessage())->danger()->send();

                throw new Halt;
            }
        }

        $data['recurrence_end_date'] = null;

        return parent::handleRecordCreation($data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        if ($this->record->is_recurring) {
            return sprintf('%d instancias creadas', $this->record->children()->count() + 1);
        }

        return 'Gasto creado';
    }
}
