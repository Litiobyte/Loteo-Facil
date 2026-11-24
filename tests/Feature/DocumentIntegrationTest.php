<?php

namespace Tests\Feature;

use App\Domain\Documents\Services\DocumentService;
use App\Filament\Admin\Resources\CollectionResource\Pages\ViewCollection;
use App\Filament\Admin\Resources\CommunitySettingResource\Pages\EditCommunitySettings;
use App\Filament\Admin\Resources\ExpenseFundingPayments\Pages\ViewExpenseFundingPayment;
use App\Filament\Admin\Resources\ExpenseResource\Pages\ViewExpense;
use App\Filament\Admin\Resources\ShareHoldingResource\Pages\ManageShareHoldings;
use App\Filament\Owner\Pages\Dashboard;
use App\Filament\Owner\Resources\Collections\Pages\ViewCollection as OwnerViewCollection;
use App\Filament\Resources\Lotes\Pages\ViewLote;
use App\Livewire\DocumentManager;
use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Document;
use App\Models\Documentable;
use App\Models\DocumentType;
use App\Models\Expense;
use App\Models\ExpenseFundingPayment;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
    }

    public function test_financial_pages_embed_manager_and_upload_does_not_change_financial_status(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $expense = Expense::factory()->create();
        $payment = ExpenseFundingPayment::factory()->create(['expense_id' => $expense->id]);
        $collection = Collection::factory()->create();
        $type = DocumentType::create(['name' => 'Factura']);
        $type->modules()->create(['module_key' => 'finanzas']);
        $unrelated = Propietario::factory()->create();
        $unrelated->user->assignRole('propietario');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([[ViewExpense::class, $expense], [ViewExpenseFundingPayment::class, $payment], [ViewCollection::class, $collection]] as [$page, $record]) {
            Livewire::test($page, ['record' => $record->id])->assertOk()->assertSeeLivewire(DocumentManager::class);
            $before = $record->fresh()->getAttributes();
            Livewire::test(DocumentManager::class, ['entity' => $record, 'moduleKey' => 'finanzas'])
                ->set('typeId', $type->id)
                ->set('files', [UploadedFile::fake()->createWithContent('prueba.pdf', "%PDF-1.4\nfile")])
                ->call('upload')->assertHasNoErrors();
            $this->assertSame($before, $record->fresh()->getAttributes());
            $link = Documentable::where('documentable_type', $record->getMorphClass())->where('documentable_id', $record->id)->firstOrFail();
            $url = route('documents.file', [$link->document_id, 'download']);
            $this->actingAs($admin)->get($url)->assertOk();
            $this->actingAs($unrelated->user)->get($url)->assertForbidden();
            $this->actingAs($admin);
        }
    }

    public function test_collection_receipt_is_accessible_only_to_related_owner_and_administration(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $other->user->assignRole('propietario');
        $collection = Collection::factory()->create(['propietario_id' => $owner->id]);
        $type = DocumentType::create(['name' => 'Comprobante', 'default_visibility' => 'owner']);
        $type->modules()->create(['module_key' => 'finanzas']);
        $document = app(DocumentService::class)->upload($admin, $collection, $type, UploadedFile::fake()->createWithContent('pago.pdf', "%PDF-1.4\nfile"));
        $url = route('documents.file', [$document, 'download']);
        $this->actingAs($admin)->get($url)->assertOk();
        $this->actingAs($owner->user)->get($url)->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::test(OwnerViewCollection::class, ['record' => $collection->id])->assertOk()->assertSeeLivewire(DocumentManager::class);
        $this->actingAs($other->user)->get($url)->assertForbidden();
    }

    public function test_community_documents_are_visible_to_owners_but_admin_only_documents_are_not(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $community = CommunitySetting::current();
        $publicType = DocumentType::create(['name' => 'Reglamento', 'default_visibility' => 'community']);
        $publicType->modules()->create(['module_key' => 'comunidad']);
        $privateType = DocumentType::create(['name' => 'Interno', 'default_visibility' => 'admin']);
        $privateType->modules()->create(['module_key' => 'comunidad']);
        $service = app(DocumentService::class);
        $public = $service->upload($admin, $community, $publicType, UploadedFile::fake()->createWithContent('reglamento.pdf', "%PDF-1.4\nfile"));
        $private = $service->upload($admin, $community, $privateType, UploadedFile::fake()->createWithContent('interno.pdf', "%PDF-1.4\nfile"));
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditCommunitySettings::class)->assertOk()->assertSeeLivewire(DocumentManager::class);
        $this->actingAs($owner->user);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::test(Dashboard::class)->assertOk()->assertSeeLivewire(DocumentManager::class);
        Livewire::test(DocumentManager::class, ['entity' => $community, 'moduleKey' => 'comunidad'])
            ->assertSee('reglamento.pdf')->assertDontSee('interno.pdf');
        $this->get(route('documents.file', [$public, 'download']))->assertOk();
        $this->get(route('documents.file', [$private, 'download']))->assertForbidden();
    }

    public function test_share_movement_documents_belong_only_to_the_historic_source_holder(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $source = Propietario::factory()->create();
        $target = Propietario::factory()->create();
        $source->user->assignRole('propietario');
        $target->user->assignRole('propietario');
        $holding = ShareHolding::create(['propietario_id' => $source->id, 'quantity' => 1, 'square_meters_per_share' => 800, 'acquired_at' => today(), 'status' => 'active']);
        $targetHolding = ShareHolding::create(['propietario_id' => $target->id, 'quantity' => 1, 'square_meters_per_share' => 800, 'acquired_at' => today(), 'status' => 'active']);
        $movement = ShareMovement::create(['share_holding_id' => $holding->id, 'counterparty_holding_id' => $targetHolding->id, 'created_by' => $admin->id, 'operation_key' => 'doc-test', 'type' => 'transfer', 'quantity_before' => 2, 'quantity_after' => 1, 'context' => [], 'reason' => 'Transferencia']);
        $type = DocumentType::create(['name' => 'Contrato', 'default_visibility' => 'owner']);
        $type->modules()->create(['module_key' => 'acciones']);
        $document = app(DocumentService::class)->upload($admin, $movement, $type, UploadedFile::fake()->createWithContent('contrato.pdf', "%PDF-1.4\nfile"));
        $url = route('documents.file', [$document, 'download']);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ManageShareHoldings::class)->mountTableAction('history', $holding)->assertSeeLivewire(DocumentManager::class);
        $this->actingAs($source->user)->get($url)->assertOk();
        Livewire::test(DocumentManager::class, ['entity' => $movement, 'moduleKey' => 'acciones'])->assertSee('contrato.pdf');
        $this->actingAs($target->user)->get($url)->assertForbidden();
        Livewire::test(DocumentManager::class, ['entity' => $movement, 'moduleKey' => 'acciones'])->assertForbidden();
        $this->actingAs($admin)->get($url)->assertOk();
    }

    public function test_lot_detail_embeds_manager_for_admin(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $lot = Lote::factory()->create();
        $lot->propietarios()->attach($owner, ['status' => 'active', 'assigned_at' => now()]);
        $type = DocumentType::create(['name' => 'Plano']);
        $type->modules()->create(['module_key' => 'lotes']);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewLote::class, ['record' => $lot->id])->assertOk()->assertSeeLivewire(DocumentManager::class);
        Livewire::test(DocumentManager::class, ['entity' => $lot, 'moduleKey' => 'lotes'])
            ->set('typeId', $type->id)
            ->set('files', [UploadedFile::fake()->createWithContent('plano.pdf', "%PDF-1.4\nfile")])
            ->call('upload')->assertHasNoErrors();
        $document = Document::firstOrFail();
        $this->actingAs($owner->user)->get(route('documents.file', [$document, 'download']))->assertForbidden();
        Livewire::test(DocumentManager::class, ['entity' => $lot, 'moduleKey' => 'lotes'])->assertForbidden();
    }
}
