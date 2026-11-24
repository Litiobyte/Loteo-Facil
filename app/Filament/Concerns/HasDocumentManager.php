<?php

namespace App\Filament\Concerns;

use App\Domain\Documents\Services\DocumentService;
use App\Livewire\DocumentManager;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

trait HasDocumentManager
{
    public function content(Schema $schema): Schema
    {
        $schema = parent::content($schema);

        return $schema->components([
            ...$schema->getComponents(),
            Section::make('Documentos y comprobantes')->schema([
                Livewire::make(DocumentManager::class, fn (): array => [
                    'entity' => $this->getRecord(),
                    'moduleKey' => DocumentService::moduleFor($this->getRecord()::class),
                    'required' => false,
                ]),
            ])->columnSpanFull(),
        ]);
    }
}
