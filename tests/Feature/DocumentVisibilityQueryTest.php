<?php

namespace Tests\Feature;

use App\Domain\Documents\Services\DocumentAuthorizationService;
use App\Models\Collection;
use App\Models\CommunitySetting;
use App\Models\Document;
use App\Models\Documentable;
use App\Models\DocumentType;
use App\Models\Propietario;
use App\Models\ShareHolding;
use App\Models\ShareMovement;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Policies\DocumentTypePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentVisibilityQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_visible_query_matches_policy_for_every_document_including_multiple_links(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $type = DocumentType::create(['name' => 'Test']);
        $documents = collect();
        foreach (['own', 'other', 'multi', 'community', 'admin', 'missing', 'no-links'] as $name) {
            $visibility = match ($name) {
                'community' => 'community', 'admin' => 'admin', default => 'owner',
            };
            $document = Document::create([
                'document_type_id' => $type->id, 'original_name' => "$name.pdf", 'stored_name' => "$name.pdf",
                'disk' => 'documents', 'file_path' => "$name.pdf", 'mime_type' => 'application/pdf',
                'file_size' => 5, 'uploaded_by' => $admin->id, 'visibility' => $visibility,
            ]);
            $documents->put($name, $document);
            if ($name !== 'no-links') {
                Documentable::create([
                    'document_id' => $document->id,
                    'documentable_type' => $name === 'community' ? CommunitySetting::class : Propietario::class,
                    'documentable_id' => match ($name) {
                        'community' => 1, 'other' => $other->id, 'missing' => 999999, default => $owner->id,
                    },
                ]);
            }
        }
        Documentable::create(['document_id' => $documents['multi']->id, 'documentable_type' => Propietario::class, 'documentable_id' => $other->id]);
        $authorization = app(DocumentAuthorizationService::class);
        foreach ([$admin, $owner->user, $other->user] as $user) {
            $visible = $authorization->visibleQuery($user)->pluck('id')->all();
            foreach ($documents as $document) {
                $this->assertSame(Gate::forUser($user)->allows('view', $document), in_array($document->id, $visible, true), $document->original_name);
            }
        }
        $visible = $authorization->visibleQuery($owner->user)->pluck('id')->all();
        $this->assertContains($documents['own']->id, $visible);
        $this->assertContains($documents['community']->id, $visible);
        $this->assertNotContains($documents['multi']->id, $visible);

        $this->assertSame(1, $authorization->visibleQuery($owner->user)->paginate(1)->perPage());
    }

    public function test_query_matches_policy_for_collections_movements_and_other_communities(): void
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $owner = Propietario::factory()->create();
        $other = Propietario::factory()->create();
        $owner->user->assignRole('propietario');
        $other->user->assignRole('propietario');
        $holding = ShareHolding::create(['propietario_id' => $owner->id, 'quantity' => 1, 'square_meters_per_share' => 800, 'acquired_at' => today(), 'status' => 'active']);
        $movement = ShareMovement::create(['share_holding_id' => $holding->id, 'created_by' => $admin->id, 'operation_key' => 'visible-query', 'type' => 'adjustment', 'quantity_before' => 0, 'quantity_after' => 1, 'context' => [], 'reason' => 'Prueba']);
        $collection = Collection::factory()->create(['propietario_id' => $owner->id]);
        $type = DocumentType::create(['name' => 'Test']);
        $attributes = ['document_type_id' => $type->id, 'original_name' => 'a.pdf', 'stored_name' => 'a.pdf', 'disk' => 'documents', 'file_path' => 'a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'uploaded_by' => $admin->id, 'visibility' => 'owner'];
        $documents = collect([$collection, $movement, $holding])->map(function ($entity) use ($attributes): Document {
            $document = Document::create($attributes);
            Documentable::create(['document_id' => $document->id, 'documentable_type' => $entity::class, 'documentable_id' => $entity->id]);

            return $document;
        });
        $foreign = Document::create($attributes);
        $foreign->forceFill(['community_id' => 2])->save();
        $documents->push($foreign);
        foreach ([$admin, $owner->user, $other->user] as $user) {
            $visible = app(DocumentAuthorizationService::class)->visibleQuery($user)->pluck('id')->all();
            foreach ($documents as $document) {
                $this->assertSame(Gate::forUser($user)->allows('view', $document), in_array($document->id, $visible, true));
            }
        }
    }

    public function test_document_policy_is_registered_in_the_gate(): void
    {
        $policy = Gate::getPolicyFor(Document::class);
        $this->assertNotNull($policy);
        $this->assertInstanceOf(DocumentPolicy::class, $policy);
        $this->assertInstanceOf(DocumentTypePolicy::class, Gate::getPolicyFor(DocumentType::class));
    }
}
