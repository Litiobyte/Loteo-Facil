<?php

namespace App\Filament\Resources\Lotes\Pages;

use App\Domain\Owners\Services\LoteOwnershipAssignmentService;
use App\Filament\Resources\Lotes\LoteResource;
use App\Models\MapLayer;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class ViewLote extends ViewRecord
{
    protected static string $resource = LoteResource::class;

    protected string $view = 'filament.admin.pages.view-lote';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->form->fill($this->getRecord()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return static::getResource()::form($schema)
            ->disabled(false)
            ->operation('edit');
    }

    public function save(): void
    {
        Gate::authorize('update', $this->getRecord());

        $data = $this->form->getState();

        $this->getRecord()->update($data);
        $this->getRecord()->refresh();
        $this->form->fill($this->getRecord()->attributesToArray());

        Notification::make()->title('Lote actualizado correctamente')->success()->send();
    }

    public function getFormActions(): array
    {
        return [];
    }

    public function desasignarAction(): Action
    {
        return Action::make('desasignar')
            ->label('Desasignar')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Desasignar lote')
            ->modalDescription('Se cerrará la asignación activa y el lote volverá a estar disponible. El historial se conservará.')
            ->action(function (array $arguments): void {
                Gate::authorize('update', $this->getRecord());

                // Resolver desde la relación activa evita desasignar propietarios ajenos al lote.
                $propietario = $this->getRecord()->propietarios()
                    ->wherePivot('status', 'active')
                    ->find($arguments['propietarioId'] ?? null);

                abort_if($propietario === null, 404);

                Gate::authorize('update', $propietario);

                try {
                    app(LoteOwnershipAssignmentService::class)->unassign($propietario, $this->getRecord());
                } catch (DomainException $exception) {
                    Notification::make()
                        ->title('No se pudo desasignar')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                // Refrescar solo el estado modificado; conservar cambios pendientes en otros campos.
                $this->getRecord()->refresh();
                $this->refreshFormData(['estado']);

                Notification::make()->title('Lote desasignado correctamente')->success()->send();
            });
    }

    protected function getViewData(): array
    {
        $lote = $this->getRecord()->load(['etapa', 'propietarios.user']);

        return [
            'lote' => $lote,
            'layerList' => MapLayer::query()->where('etapa_id', $lote->etapa_id)->orderBy('sort_order')->get(),
            'adminView' => true,
            'showFinancial' => true,
        ];
    }
}
