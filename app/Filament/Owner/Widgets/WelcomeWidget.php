<?php

namespace App\Filament\Owner\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class WelcomeWidget extends Widget
{
    protected static ?int $sort = 10;

    protected string $view = 'filament.owner.widgets.welcome-widget';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $propietario = Auth::user()?->propietario;

        if (! $propietario) {
            return [
                'hasPropietario' => false,
            ];
        }

        $lotes = $propietario->lotes()
            ->wherePivot('status', 'active')
            ->orderBy('codigo')
            ->get(['lotes.id', 'lotes.codigo', 'lotes.metros_cuadrados']);

        $totalHectares = round((float) $lotes->sum('metros_cuadrados') / 10000, 4);

        return [
            'hasPropietario' => true,
            'propietarioNombre' => $propietario->nombre_completo,
            'loteCodes' => $lotes->pluck('codigo')->all(),
            'totalHectares' => $totalHectares,
        ];
    }
}
