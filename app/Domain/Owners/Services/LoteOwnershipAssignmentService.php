<?php

namespace App\Domain\Owners\Services;

use App\Models\Lote;
use App\Models\Propietario;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LoteOwnershipAssignmentService
{
    public function assign(Propietario $propietario, Lote $lote, ?Carbon $assignedAt = null): void
    {
        $assignedAt ??= now();

        try {
            DB::transaction(function () use ($propietario, $lote, $assignedAt): void {
                $alreadyActive = DB::table('lote_propietario')
                    ->where('lote_id', $lote->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->exists();

                if ($alreadyActive) {
                    throw new DomainException('El lote ya tiene una asignacion activa.');
                }

                $lockedLote = Lote::query()
                    ->lockForUpdate()
                    ->findOrFail($lote->id);

                if ($lockedLote->estado !== 'disponible') {
                    throw new DomainException('Solo se pueden asignar lotes en estado disponible.');
                }

                $propietario->lotes()->attach($lockedLote->id, [
                    'assigned_at' => $assignedAt,
                    'unassigned_at' => null,
                    'status' => 'active',
                ]);

                $lockedLote->update([
                    'estado' => 'reservado',
                ]);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueActiveAssignmentViolation($exception)) {
                throw new DomainException('El lote ya tiene una asignacion activa.', previous: $exception);
            }

            throw $exception;
        }
    }

    public function unassign(Propietario $propietario, Lote $lote, ?Carbon $unassignedAt = null): void
    {
        $unassignedAt ??= now();

        DB::transaction(function () use ($propietario, $lote, $unassignedAt): void {
            $lockedLote = Lote::query()
                ->lockForUpdate()
                ->findOrFail($lote->id);

            if ($lockedLote->estado === 'vendido') {
                throw new DomainException('No se puede desasignar un lote vendido.');
            }

            if ($lockedLote->estado !== 'reservado') {
                throw new DomainException('Solo se pueden desasignar lotes en estado reservado.');
            }

            $affected = DB::table('lote_propietario')
                ->where('propietario_id', $propietario->id)
                ->where('lote_id', $lockedLote->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'unassigned',
                    'unassigned_at' => $unassignedAt,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new DomainException('No existe una asignacion activa para desasignar.');
            }

            $lockedLote->update([
                'estado' => 'disponible',
            ]);
        });
    }

    private function isUniqueActiveAssignmentViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'lote_propietario_unique_active_lote_idx')
            || str_contains($message, 'active_lote_id')
            || str_contains($message, 'unique constraint failed: lote_propietario.lote_id')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'duplicate key value violates unique constraint');
    }
}
