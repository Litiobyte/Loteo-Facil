<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\CollectionResource;
use App\Filament\Admin\Resources\Documents\Pages\ManageDocuments;
use App\Filament\Owner\Resources\Collections\MyCollectionsResource;
use App\Filament\Owner\Resources\Documents\Pages\ManageDocuments as OwnerDocuments;
use App\Livewire\DocumentManager;
use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Document;
use App\Models\Documentable;
use App\Models\DocumentType;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_center_filters_visible_documents_by_module_and_visibility(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $firstType = DocumentType::create(['name' => 'Contrato']);
        $firstType->modules()->create(['module_key' => 'acciones']);
        $secondType = DocumentType::create(['name' => 'Factura']);
        $secondType->modules()->create(['module_key' => 'finanzas']);
        $attributes = ['original_name' => 'a.pdf', 'stored_name' => 'a.pdf', 'disk' => 'documents', 'file_path' => 'a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $admin->id];
        $first = Document::create($attributes + ['document_type_id' => $firstType->id, 'visibility' => 'admin']);
        $second = Document::create($attributes + ['document_type_id' => $secondType->id, 'visibility' => 'owner']);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ManageDocuments::class)->assertOk()
            ->assertCanSeeTableRecords([$first, $second])
            ->filterTable('module', 'acciones')
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
        Livewire::test(ManageDocuments::class)
            ->filterTable('visibility', 'owner')
            ->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
        Livewire::test(ManageDocuments::class)
            ->callTableAction('edit', $first, ['description' => 'Editado'])
            ->assertHasNoTableActionErrors()
            ->mountAction('community')->assertSeeLivewire(DocumentManager::class);
        $this->assertSame('Editado', $first->fresh()->description);
    }

    public function test_owner_center_paginates_authorized_records_and_excludes_other_owners(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $type = DocumentType::create(['name' => 'Contrato']);
        $attributes = ['document_type_id' => $type->id, 'original_name' => 'a.pdf', 'stored_name' => 'a.pdf', 'disk' => 'documents', 'file_path' => 'a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $admin->id];
        $mine = Document::create($attributes + ['visibility' => 'owner']);
        $theirs = Document::create($attributes + ['visibility' => 'owner']);
        $mixed = Document::create($attributes + ['visibility' => 'owner']);
        $community = Document::create($attributes + ['visibility' => 'community']);
        Documentable::create(['document_id' => $mine->id, 'documentable_type' => Propietario::class, 'documentable_id' => $owner->id]);
        Documentable::create(['document_id' => $theirs->id, 'documentable_type' => Propietario::class, 'documentable_id' => $other->id]);
        Documentable::create(['document_id' => $mixed->id, 'documentable_type' => Propietario::class, 'documentable_id' => $owner->id]);
        Documentable::create(['document_id' => $mixed->id, 'documentable_type' => Propietario::class, 'documentable_id' => $other->id]);
        Documentable::create(['document_id' => $community->id, 'documentable_type' => CommunitySetting::class, 'documentable_id' => 1]);
        $this->actingAs($owner->user);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::test(OwnerDocuments::class)
            ->assertOk()->assertSee('Mis acciones')->assertSee('Comprobantes financieros')
            ->assertCanSeeTableRecords([$mine, $community])->assertCanNotSeeTableRecords([$theirs, $mixed])
            ->set('activeTab', 'community')
            ->assertCanSeeTableRecords([$community])->assertCanNotSeeTableRecords([$mine]);
    }

    public function test_metadata_edit_ignores_client_supplied_security_and_storage_fields(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $other = User::factory()->create();
        $type = DocumentType::create(['name' => 'Privado']);
        $document = Document::create([
            'document_type_id' => $type->id, 'original_name' => 'a.pdf', 'stored_name' => 'a.pdf',
            'disk' => 'documents', 'file_path' => 'a.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 10, 'uploaded_by' => $admin->id, 'visibility' => 'admin',
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ManageDocuments::class)->callTableAction('edit', $document, [
            'description' => 'Correcto', 'visibility' => 'community', 'uploaded_by' => $other->id,
            'community_id' => 2, 'disk' => 'public', 'file_path' => '../secret',
        ])->assertHasNoTableActionErrors();
        $document->refresh();
        $this->assertSame('Correcto', $document->description);
        $this->assertSame('admin', $document->visibility);
        $this->assertSame($admin->id, $document->uploaded_by);
        $this->assertSame(1, $document->community_id);
        $this->assertSame('documents', $document->disk);
        $this->assertSame('a.pdf', $document->file_path);
    }

    public function test_admin_can_filter_by_owner_and_entity_and_open_source(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $payment = Collection::factory()->create(['propietario_id' => $owner->id]);
        $type = DocumentType::create(['name' => 'Recibo']);
        $attributes = ['document_type_id' => $type->id, 'original_name' => 'a.pdf', 'stored_name' => 'a.pdf', 'disk' => 'documents', 'file_path' => 'a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $admin->id];
        $linked = Document::create($attributes);
        $unrelated = Document::create($attributes);
        Documentable::create(['document_id' => $linked->id, 'documentable_type' => Collection::class, 'documentable_id' => $payment->id]);
        Documentable::create(['document_id' => $unrelated->id, 'documentable_type' => Propietario::class, 'documentable_id' => $other->id]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ManageDocuments::class)
            ->filterTable('owner', $owner->id)
            ->assertCanSeeTableRecords([$linked])->assertCanNotSeeTableRecords([$unrelated])
            ->assertTableActionHasUrl('origin', CollectionResource::getUrl('view', ['record' => $payment]), $linked);
        Livewire::test(ManageDocuments::class)
            ->filterTable('entity', Collection::class)
            ->assertCanSeeTableRecords([$linked])->assertCanNotSeeTableRecords([$unrelated]);
    }

    public function test_owner_center_paginates_in_database_and_links_to_own_payment(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $payment = Collection::factory()->create(['propietario_id' => $owner->id]);
        $type = DocumentType::create(['name' => 'Recibo']);
        $attributes = ['document_type_id' => $type->id, 'original_name' => 'a.pdf', 'stored_name' => 'a.pdf', 'disk' => 'documents', 'file_path' => 'a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $admin->id, 'visibility' => 'owner'];
        for ($index = 0; $index < 15; $index++) {
            $document = Document::create($attributes);
            Documentable::create(['document_id' => $document->id, 'documentable_type' => Collection::class, 'documentable_id' => $payment->id]);
        }
        $this->actingAs($owner->user);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $component = Livewire::test(OwnerDocuments::class)->assertOk();
        $records = $component->instance()->getTableRecords();
        $this->assertCount(10, $records);
        $this->assertSame(15, $records->total());
        $component->assertTableActionHasUrl('origin', MyCollectionsResource::getUrl('view', ['record' => $payment], panel: 'owner'), $document);
    }
}
