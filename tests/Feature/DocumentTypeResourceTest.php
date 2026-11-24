<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentTypeResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_edits_and_deactivates_a_type(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $form = [
            'name' => 'Contrato', 'default_visibility' => 'admin', 'is_active' => true,
            'settings' => ['allowed_mimes' => ['application/pdf'], 'max_files' => 3, 'max_size_kb' => 1000, 'required' => false],
            'modules' => [['module_key' => 'acciones'], ['module_key' => 'lotes']],
        ];
        Livewire::test(ManageDocumentTypes::class)->callAction('create', $form)->assertHasNoActionErrors();
        $type = DocumentType::where('name', 'Contrato')->firstOrFail();
        $this->assertSame(2, $type->modules()->count());
        $form['name'] = 'Contrato editado';
        $form['is_active'] = false;
        unset($form['modules']);
        Livewire::test(ManageDocumentTypes::class)->callTableAction('edit', $type, $form)->assertHasNoTableActionErrors();
        $this->assertFalse($type->fresh()->is_active);
        $this->assertSame('Contrato editado', $type->fresh()->name);
    }

    public function test_only_administrators_may_manage_types_and_linked_types_cannot_be_deleted(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = User::factory()->create()->assignRole('propietario');
        $type = DocumentType::create(['name' => 'Factura']);
        Document::create([
            'document_type_id' => $type->id, 'original_name' => 'x.pdf', 'stored_name' => 'x.pdf',
            'disk' => 'documents', 'file_path' => 'x.pdf', 'mime_type' => 'application/pdf',
            'file_size' => 10, 'uploaded_by' => $admin->id,
        ]);
        $this->assertTrue($admin->can('update', $type));
        $this->assertFalse($admin->can('delete', $type));
        $this->assertFalse($owner->can('viewAny', DocumentType::class));
        $this->assertFalse($owner->can('create', DocumentType::class));
        $this->assertFalse($owner->can('update', $type));
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ManageDocumentTypes::class)->assertTableActionDisabled('delete', $type);
    }

    public function test_type_resource_is_inaccessible_to_owners_and_anonymous_users(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $owner = User::factory()->create()->assignRole('propietario');
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertFalse(ManageDocumentTypes::canAccess());
        auth()->logout();
        $this->assertFalse(ManageDocumentTypes::canAccess());
    }

    public function test_table_filters_by_module_and_status(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $active = DocumentType::create(['name' => 'Activo', 'is_active' => true]);
        $active->modules()->create(['module_key' => 'acciones']);
        $inactive = DocumentType::create(['name' => 'Inactivo', 'is_active' => false]);
        $inactive->modules()->create(['module_key' => 'finanzas']);

        Livewire::test(ManageDocumentTypes::class)
            ->filterTable('module_key', 'acciones')
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
        Livewire::test(ManageDocumentTypes::class)
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
    }
}
