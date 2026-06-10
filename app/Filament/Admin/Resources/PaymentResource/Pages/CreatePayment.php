<?php

namespace App\Filament\Admin\Resources\PaymentResource\Pages;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Filament\Admin\Resources\PaymentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = PaymentStatus::PendingApplication;
        $data['applied_amount'] = 0;
        $data['unapplied_amount'] = (float) ($data['amount'] ?? 0);
        $data['created_by'] = auth()->id();

        return $data;
    }
}
