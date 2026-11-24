<?php

namespace App\Domain\Documents\Services;

use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Document;
use App\Models\Documentable;
use App\Models\DocumentType;
use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use App\Models\Lote;
use App\Models\PartnerCharge;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class DocumentService
{
    private const MODULES = [
        Propietario::class => 'acciones',
        ShareHolding::class => 'acciones',
        ShareMovement::class => 'acciones',
        Expense::class => 'finanzas',
        ExpenseFundingPayment::class => 'finanzas',
        Collection::class => 'finanzas',
        PartnerCharge::class => 'finanzas',
        Lote::class => 'lotes',
        CommunitySetting::class => 'comunidad',
    ];

    public function __construct(private DocumentStorageService $storage) {}

    public static function moduleFor(string $model): ?string
    {
        return self::MODULES[$model] ?? null;
    }

    public function upload(User $user, Model $entity, DocumentType $type, UploadedFile $file, bool $single = false): Document
    {
        Gate::forUser($user)->authorize('create', Document::class);
        $this->assertOutsideTransaction();
        $module = self::MODULES[$entity::class] ?? null;
        if ($module === null || ! $entity->exists) {
            throw ValidationException::withMessages(['entity' => 'Entidad documental no admitida.']);
        }
        $stored = null;
        try {
            return DB::transaction(function () use ($user, $entity, $type, $file, $module, $single, &$stored): Document {
                $entity = $entity->newQuery()->whereKey($entity->getKey())->lockForUpdate()->firstOrFail();
                $type = DocumentType::query()->whereKey($type->getKey())->lockForUpdate()->firstOrFail();
                if ($single && Documentable::query()->where('documentable_type', $entity->getMorphClass())
                    ->where('documentable_id', $entity->getKey())->whereHas('document')->exists()) {
                    throw ValidationException::withMessages(['file' => 'Esta entidad solo permite un documento.']);
                }
                $visibility = $type->default_visibility;
                if ((int) $type->community_id !== 1 || ! $type->is_active
                    || ! $type->modules()->where('module_key', $module)->exists()
                    || ! $this->visibilitySupported($visibility, $entity)) {
                    throw ValidationException::withMessages(['type' => 'El tipo no está disponible para esta entidad.']);
                }
                $maxFiles = (int) ($type->settings['max_files'] ?? 0);
                if ($maxFiles > 0 && Documentable::query()->where('documentable_type', $entity->getMorphClass())
                    ->where('documentable_id', $entity->getKey())
                    ->whereHas('document', fn ($query) => $query->where('document_type_id', $type->id))->count() >= $maxFiles) {
                    throw ValidationException::withMessages(['file' => 'Se alcanzó el máximo de archivos para este tipo.']);
                }
                $stored = $this->storage->store($file, $type);
                $document = Document::query()->create($stored + [
                    'document_type_id' => $type->getKey(), 'uploaded_by' => $user->getKey(), 'visibility' => $visibility,
                ]);
                Documentable::query()->create([
                    'document_id' => $document->getKey(), 'documentable_type' => $entity->getMorphClass(),
                    'documentable_id' => $entity->getKey(),
                ]);

                return $document;
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                $this->storage->delete($stored['file_path']);
            }
            throw $exception;
        }
    }

    public function delete(User $user, Document $document): void
    {
        Gate::forUser($user)->authorize('delete', $document);
        $document->delete();
    }

    public function unlink(User $user, Document $document, Model $entity): void
    {
        Gate::forUser($user)->authorize('delete', $document);
        if (! isset(self::MODULES[$entity::class]) || ! $entity->exists) {
            throw ValidationException::withMessages(['document' => 'Entidad documental no admitida.']);
        }

        DB::transaction(function () use ($user, $document, $entity): void {
            $document = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('delete', $document);
            $links = Documentable::query()->where('document_id', $document->id)->lockForUpdate()->get();
            $link = $links->first(fn (Documentable $item): bool => $item->documentable_type === $entity->getMorphClass()
                && (string) $item->documentable_id === (string) $entity->getKey());
            if (! $link) {
                throw ValidationException::withMessages(['document' => 'El documento no está asociado a esta entidad.']);
            }
            if ($links->count() === 1) {
                $document->delete();

                return;
            }
            if ($document->visibility === 'owner') {
                $owners = $links->map(function (Documentable $item): mixed {
                    $related = $item->documentable;

                    return match (true) {
                        $related instanceof Propietario => $related->getKey(),
                        $related instanceof ShareHolding, $related instanceof Collection, $related instanceof PartnerCharge => $related->propietario_id,
                        $related instanceof ShareMovement => ShareHolding::query()->whereKey($related->share_holding_id)->value('propietario_id'),
                        default => null,
                    };
                });
                if ($owners->contains(null) || $owners->unique()->count() !== 1) {
                    throw ValidationException::withMessages(['document' => 'No se puede desvincular: podría ampliar el acceso al documento.']);
                }
            } elseif ($document->visibility === 'community') {
                throw ValidationException::withMessages(['document' => 'No se puede desvincular un documento comunitario con múltiples relaciones.']);
            }
            $link->delete();
        });
    }

    public function replace(User $user, Document $document, UploadedFile $file): Document
    {
        Gate::forUser($user)->authorize('update', $document);
        $this->assertOutsideTransaction();
        $stored = null;
        $oldPath = null;
        try {
            $updated = DB::transaction(function () use ($user, $document, $file, &$stored, &$oldPath): Document {
                $document = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
                Gate::forUser($user)->authorize('update', $document);
                if ($document->disk !== 'documents') {
                    throw new LogicException('Disco documental inválido.');
                }
                $this->storage->assertValidPath($document->file_path);
                $oldPath = $document->file_path;
                $stored = $this->storage->store($file, $document->type);
                $document->fill($stored)->save();

                return $document;
            });
        } catch (Throwable $exception) {
            if ($stored !== null) {
                $this->storage->delete($stored['file_path']);
            }
            throw $exception;
        }

        try {
            $this->storage->delete($oldPath);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $updated;
    }

    private function visibilitySupported(string $visibility, Model $entity): bool
    {
        return match ($visibility) {
            'admin' => true,
            'community' => $entity instanceof CommunitySetting && (int) $entity->getKey() === 1,
            'owner' => $entity instanceof Propietario || $entity instanceof ShareHolding
                || $entity instanceof ShareMovement || $entity instanceof Collection || $entity instanceof PartnerCharge,
            default => false,
        };
    }

    private function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('No se permite subir documentos dentro de otra transacción.');
        }
    }
}
