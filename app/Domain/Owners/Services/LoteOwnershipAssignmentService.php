<?php

namespace App\Domain\Owners\Services;

use App\Models\Lote;
use App\Models\Propietario;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LoteOwnershipAssignmentService
{
    public function assign(Propietario $propietario, Lote $lote, ?Carbon $assignedAt = null): void
    {
        $assignedAt ??= now();

        DB::transaction(function () use ($propietario, $lote, $assignedAt): void {
            $alreadyActive = DB::table('lote_propietario')
                ->where('lote_id', $lote->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();

            if ($alreadyActive) {
                throw new DomainException('El lote ya tiene una asignacion activa.');
            }

            $lote->refresh();

            if ($lote->estado !== 'disponible') {
                throw new DomainException('Solo se pueden asignar lotes en estado disponible.');
            }

            $propietario->lotes()->attach($lote->id, [
                'assigned_at' => $assignedAt,
                'unassigned_at' => null,
                'status' => 'active',
            ]);

            $lote->update([
                'estado' => 'reservado',
            ]);
        });
    }

    public function unassign(Propietario $propietario, Lote $lote, ?Carbon $unassignedAt = null): void
    {
        $unassignedAt ??= now();

        DB::transaction(function () use ($propietario, $lote, $unassignedAt): void {
            $affected = DB::table('lote_propietario')
                ->where('propietario_id', $propietario->id)
                ->where('lote_id', $lote->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'unassigned',
                    'unassigned_at' => $unassignedAt,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new DomainException('No existe una asignacion activa para desasignar.');
            }

            $lote->update([
                'estado' => 'disponible',
            ]);
        });
    }
}
