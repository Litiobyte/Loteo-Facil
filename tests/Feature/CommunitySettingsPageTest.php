<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\CommunitySettingResource\Pages\EditCommunitySettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommunitySettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_index_shows_configuration_form_directly_with_icon(): void
    {
        $this->get('/admin/community-settings')
            ->assertOk()
            ->assertSee('Configuración de comunidad')
            ->assertSee('Motivo del cambio')
            ->assertSee('Historial');
    }

    public function test_owner_cannot_access_community_settings(): void
    {
        Role::findOrCreate('propietario', 'web');
        $user = User::factory()->create();
        $user->assignRole('propietario');
        $this->actingAs($user)->get('/admin/community-settings')->assertForbidden();
    }

    public function test_saving_form_updates_settings_and_creates_revision(): void
    {
        Livewire::test(EditCommunitySettings::class, ['record' => 1])
            ->set('data.mode', 'mixed')
            ->set('data.square_meters_per_share', '800')
            ->set('data.share_precision', '4')
            ->set('data.reason', 'Cambio desde ficha')
            ->call('save');

        $this->assertDatabaseHas('community_settings', ['id' => 1, 'mode' => 'mixed']);
        $this->assertSame(1, DB::table('community_setting_revisions')->count());
    }

    public function test_save_without_reason_shows_validation_error(): void
    {
        Livewire::test(EditCommunitySettings::class, ['record' => 1])
            ->set('data.mode', 'mixed')
            ->set('data.square_meters_per_share', '800')
            ->call('save')
            ->assertHasErrors(['data.reason']);
        $this->assertSame(0, DB::table('community_setting_revisions')->count());
    }
}
