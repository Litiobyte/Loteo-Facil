<?php

namespace Tests\Feature;

use App\Domain\Owners\Services\CommunitySettingsService;
use App\Filament\Admin\Resources\CommunitySettingResource\Pages\EditCommunitySettings;
use App\Models\CommunitySetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommunityNameTest extends TestCase
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

    public function test_community_name_is_editable_in_settings_form(): void
    {
        Livewire::test(EditCommunitySettings::class, ['record' => 1])
            ->set('data.community_name', 'Fundo Los Aromos')
            ->set('data.mode', 'lots')
            ->set('data.square_meters_per_share', '800')
            ->set('data.share_precision', '0')
            ->set('data.reason', 'Ajuste de nombre')
            ->call('save');

        $this->assertSame('Fundo Los Aromos', CommunitySetting::displayName());
    }

    public function test_fallback_name_when_not_set(): void
    {
        $this->assertSame('Loteo Fácil', CommunitySetting::displayName());
    }

    public function test_brand_shows_community_name_next_to_logo(): void
    {
        app(CommunitySettingsService::class)->update([
            'community_name' => 'Fundo Los Aromos', 'mode' => 'lots',
            'square_meters_per_share' => '800', 'share_precision' => 0,
        ], 'Nombre', auth()->user());

        $this->get('/admin')->assertOk()->assertSee('Fundo Los Aromos');
        $this->get('/admin/community-settings')->assertOk()->assertSee('Nombre de la comunidad');
    }
}
