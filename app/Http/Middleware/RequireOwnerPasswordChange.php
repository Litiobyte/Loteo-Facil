<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige el cambio de contraseña inicial a cuentas creadas por importación.
 * El panel owner ya exige autenticación; aquí se bloquea cualquier página
 * del panel hasta completar el cambio, sin bypass por Livewire.
 */
class RequireOwnerPasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeName = $request->route()?->getName() ?? '';
        $allowed = str_starts_with($routeName, 'filament.owner.auth.') || str_starts_with($routeName, 'filament.owner.logout');
        if ($user && $user->must_change_password && ! $allowed) {
            return redirect()->route('filament.owner.auth.profile');
        }

        return $next($request);
    }
}
