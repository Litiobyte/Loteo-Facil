<?php

namespace App\Filament\Admin\Resources\CommunitySettingResource\Pages;

use App\Domain\Owners\Services\CommunitySettingsService;
use App\Filament\Admin\Resources\CommunitySettingResource;
use App\Models\CommunitySetting;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditCommunitySettings extends EditRecord
{
    protected static string $resource = CommunitySettingResource::class;

    public function mount(int|string|null $record = null): void
    {
        parent::mount($record ?? 1);
    }

    public function resolveRecord(int|string $key): Model
    {
        return CommunitySetting::current();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canAccess(), 403);
    }

    public function getTitle(): string
    {
        return 'Configuración de comunidad';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')->label('Historial')->modalSubmitAction(false)->modalContent(
                fn () => view('filament.shares.settings-history', ['revisions' => DB::table('community_setting_revisions')->orderByDesc('id')->get()])
            ),
        ];
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $data = $this->form->getState();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        try {
            app(CommunitySettingsService::class)->update($data, $reason, auth()->user());
        } catch (DomainException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $this->getRecord()->refresh();
        $this->form->fill($this->getRecord()->attributesToArray());

        Notification::make()->success()->title('Configuración de comunidad guardada')->send();
    }
}
