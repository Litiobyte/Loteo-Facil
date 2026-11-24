<?php

namespace App\Livewire;

use App\Domain\Documents\Services\DocumentAuthorizationService;
use App\Domain\Documents\Services\DocumentService;
use App\Models\Document;
use App\Models\Documentable;
use App\Models\DocumentType;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class DocumentManager extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public string $entityClass;

    #[Locked]
    public int $entityId;

    #[Locked]
    public string $moduleKey;

    #[Locked]
    public bool $multiple = true;

    #[Locked]
    public bool $required = false;

    public ?int $typeId = null;

    public array $files = [];

    public $singleFile = null;

    public $replacement = null;

    public function mount(Model $entity, string $moduleKey, bool $multiple = true, bool $required = false): void
    {
        abort_unless($entity->exists && DocumentService::moduleFor($entity::class) === $moduleKey, 404);
        $this->entityClass = $entity::class;
        $this->entityId = $entity->getKey();
        $this->moduleKey = $moduleKey;
        $this->multiple = $multiple;
        $this->required = $required;
        $this->entity();
    }

    public function upload(): void
    {
        $entity = $this->entity();
        Gate::authorize('create', Document::class);
        $rules = ['typeId' => ['required', 'integer']];
        $rules += $this->multiple
            ? ['files' => ['required', 'array', 'min:1', 'max:20'], 'files.*' => ['required', 'file']]
            : ['singleFile' => ['required', 'file']];
        $this->validate($rules);
        $type = DocumentType::query()->where('community_id', 1)->findOrFail($this->typeId);
        foreach ($this->multiple ? $this->files : [$this->singleFile] as $index => $file) {
            app(DocumentService::class)->upload(auth()->user(), $entity, $type, $file, ! $this->multiple);
            if ($this->multiple) {
                unset($this->files[$index]);
            }
        }
        $this->files = [];
        $this->singleFile = null;
    }

    public function replace(int $documentId): void
    {
        $document = $this->document($documentId);
        Gate::authorize('update', $document);
        $this->validate(['replacement' => ['required', 'file']]);
        app(DocumentService::class)->replace(auth()->user(), $document, $this->replacement);
        $this->reset('replacement');
    }

    public function remove(int $documentId): void
    {
        $entity = $this->entity();
        $document = $this->document($documentId);
        Gate::authorize('delete', $document);
        app(DocumentService::class)->unlink(auth()->user(), $document, $entity);
    }

    public function validateRequired(): bool
    {
        $entity = $this->entity();
        $linked = Documentable::query()->where('documentable_type', $entity->getMorphClass())
            ->where('documentable_id', $entity->getKey())->select('document_id');
        $hasVisibleDocument = app(DocumentAuthorizationService::class)
            ->visibleQuery(auth()->user())->whereIn('id', $linked)->exists();
        if (! $this->required || $hasVisibleDocument) {
            $this->resetErrorBag('documents');

            return true;
        }
        $this->addError('documents', 'Se requiere al menos un documento.');

        return false;
    }

    private function entity(): Model
    {
        abort_unless(DocumentService::moduleFor($this->entityClass) === $this->moduleKey, 404);
        $entity = $this->entityClass::query()->findOrFail($this->entityId);
        abort_unless(auth()->user() && app(DocumentAuthorizationService::class)->canAccessEntity(auth()->user(), $entity), 403);

        return $entity;
    }

    private function document(int $id): Document
    {
        $entity = $this->entity();
        $linked = Documentable::query()->where('documentable_type', $entity->getMorphClass())
            ->where('documentable_id', $entity->getKey())->select('document_id');

        $document = Document::query()->whereIn('id', $linked)->find($id);
        abort_unless($document, 404);

        return $document;
    }

    public function render(): View
    {
        $entity = $this->entity();
        $linked = Documentable::query()->where('documentable_type', $entity->getMorphClass())
            ->where('documentable_id', $entity->getKey())->select('document_id');
        $documents = app(DocumentAuthorizationService::class)
            ->visibleQuery(auth()->user())->with('type')->whereIn('id', $linked)
            ->latest()->paginate(10, ['*'], 'documents-'.md5($this->entityClass.':'.$this->entityId));
        $types = DocumentType::query()->where('community_id', 1)->where('is_active', true)
            ->whereHas('modules', fn ($query) => $query->where('module_key', $this->moduleKey))
            ->orderBy('name')->get();

        return view('livewire.document-manager', ['documents' => $documents, 'types' => $types]);
    }
}
