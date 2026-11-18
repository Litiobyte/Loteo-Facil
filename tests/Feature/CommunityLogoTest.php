<?php

namespace Tests\Feature;

use App\Domain\Owners\Services\CommunitySettingsService;
use App\Filament\Admin\Resources\CommunitySettingResource\Pages\EditCommunitySettings;
use App\Models\CommunitySetting;
use App\Models\Propietario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CommunityLogoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('propietario', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<string, mixed> */
    private function settings(array $extra = []): array
    {
        return array_merge([
            'mode' => 'lots', 'square_meters_per_share' => '800', 'share_precision' => 0,
        ], $extra);
    }

    private function upload(): CommunitySetting
    {
        return app(CommunitySettingsService::class)->update(
            $this->settings(['logo_path' => UploadedFile::fake()->image('logo.png', 200, 100)]),
            'Marca de comunidad', $this->admin,
        );
    }

    public function test_service_upload_preserve_replace_and_remove_are_audited(): void
    {
        $settings = $this->upload();
        $path = $settings->logo_path;
        Storage::disk('public')->assertExists($path);
        $this->assertMatchesRegularExpression('/^community-logos\/[a-zA-Z0-9]{40}\.png$/', $path);
        $this->assertSame(Storage::disk('public')->url($path), CommunitySetting::logoUrl());

        app(CommunitySettingsService::class)->update($this->settings(), 'Sin cambiar marca', $this->admin);
        $this->assertSame($path, CommunitySetting::current()->logo_path);
        app(CommunitySettingsService::class)->update($this->settings(['logo_path' => $path]), 'Conservar', $this->admin);
        $newPath = $this->upload()->logo_path;
        $this->assertNotSame($path, $newPath);
        app(CommunitySettingsService::class)->update($this->settings(['logo_path' => null]), 'Quitar marca', $this->admin);
        $this->assertNull(CommunitySetting::current()->logo_path);
        $this->assertSame(asset('branding/logo-full-transparent.png'), CommunitySetting::logoUrl());
        $revision = DB::table('community_setting_revisions')->latest('id')->first();
        $this->assertSame($newPath, json_decode($revision->before, true)['logo_path']);
        $this->assertNull(json_decode($revision->after, true)['logo_path']);
        $this->assertSame($this->admin->id, $revision->created_by);
    }

    public function test_settings_form_can_upload_preserve_and_remove_logo(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(EditCommunitySettings::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->image('logo.png'), 'reason' => 'Subir marca'])
            ->call('save')->assertHasNoErrors();
        $path = CommunitySetting::current()->logo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        Livewire::test(EditCommunitySettings::class)
            ->fillForm(['reason' => 'Conservar marca'])
            ->call('save')->assertHasNoErrors();
        $this->assertSame($path, CommunitySetting::current()->logo_path);
        Livewire::test(EditCommunitySettings::class)
            ->fillForm(['logo_path' => null, 'reason' => 'Quitar marca'])
            ->call('save')->assertHasNoErrors();
        $this->assertNull(CommunitySetting::current()->logo_path);
    }

    public function test_saved_logo_preview_uses_same_origin_with_real_public_disk_configuration(): void
    {
        Storage::fake('public', config('filesystems.disks.public'));
        config(['app.url' => 'http://localhost']);
        $this->actingAs($this->admin);

        $savedPage = Livewire::test(EditCommunitySettings::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->image('logo.png'), 'reason' => 'Subir marca'])
            ->call('save')->assertHasNoErrors();

        $path = CommunitySetting::current()->logo_path;
        $disk = Storage::disk('public');
        $this->assertFileExists($disk->path($path));
        $this->assertSame('image/png', $disk->mimeType($path));

        foreach ([$savedPage, Livewire::test(EditCommunitySettings::class)] as $page) {
            $field = $page->instance()->form->getComponent('logo_path');
            $files = array_values($field->getUploadedFiles());

            $this->assertCount(1, $files);
            $this->assertSame(basename($path), $files[0]['name']);
            $this->assertSame(filesize($disk->path($path)), $files[0]['size']);
            $this->assertSame('image/png', $files[0]['type']);
            $this->assertSame('/storage/'.$path, $files[0]['url']);
            $this->assertSame('/storage/'.$path, CommunitySetting::logoUrl());
        }
    }

    public function test_jpeg_and_webp_raster_logos_are_supported(): void
    {
        foreach (['jpg', 'webp'] as $extension) {
            $settings = app(CommunitySettingsService::class)->update(
                $this->settings(['logo_path' => UploadedFile::fake()->image("logo.{$extension}")]),
                'Formato raster', $this->admin,
            );
            Storage::disk('public')->assertExists($settings->logo_path);
            $this->assertSame(Storage::disk('public')->url($settings->logo_path), CommunitySetting::logoUrl());
        }
    }

    public function test_failed_revision_rolls_back_settings_and_removes_new_file(): void
    {
        $path = $this->upload()->logo_path;
        $actor = clone $this->admin->load('roles');
        $actor->id = 999999;
        try {
            app(CommunitySettingsService::class)->update(
                $this->settings(['logo_path' => UploadedFile::fake()->image('new.png')]),
                'Fallo de auditoría', $actor,
            );
            $this->fail('Debió fallar la referencia al autor de auditoría.');
        } catch (QueryException) {
            $this->assertSame($path, CommunitySetting::current()->logo_path);
        }
        $this->assertSame([$path], Storage::disk('public')->allFiles('community-logos'));
        $this->assertSame(1, DB::table('community_setting_revisions')->count());
    }

    public function test_form_rejects_svg_and_tampered_existing_paths(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(EditCommunitySettings::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 'reason' => 'SVG'])
            ->call('save')->assertHasFormErrors(['logo_path']);
        Storage::disk('public')->put('private-file.png', 'secret');
        Livewire::test(EditCommunitySettings::class)
            ->set('data.logo_path', ['tampered' => 'private-file.png'])
            ->fillForm(['reason' => 'Ruta ajena'])
            ->call('save')->assertHasFormErrors(['logo_path']);
        $this->assertNull(CommunitySetting::current()->logo_path);
        $this->assertSame(0, DB::table('community_setting_revisions')->count());
    }

    public function test_service_rejects_unsafe_files_and_paths_without_writes(): void
    {
        $invalid = [
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            UploadedFile::fake()->createWithContent('fake.png', '<?php echo "not an image";'),
            UploadedFile::fake()->image('oversize.png')->size(2049),
            UploadedFile::fake()->image('wide.png', 4097, 1),
            UploadedFile::fake()->image('executable.php'),
            '../private/secret.png', 'https://example.com/logo.png',
            'community-logos/'.str_repeat('a', 40).'.png', ['unexpected'],
        ];
        foreach ($invalid as $logo) {
            try {
                app(CommunitySettingsService::class)->update($this->settings(['logo_path' => $logo]), 'Inválido', $this->admin);
                $this->fail('Se aceptó un logotipo inválido.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('logo_path', $exception->errors());
            }
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, DB::table('community_setting_revisions')->count());
    }

    public function test_branding_and_favicons_use_logo_in_both_panels_and_logins(): void
    {
        $path = $this->upload()->logo_path;
        $url = Storage::disk('public')->url($path);
        foreach (['admin', 'owner'] as $panel) {
            $this->assertSame($url, Filament::getPanel($panel)->getFavicon());
            $this->assertStringContainsString($url, Filament::getPanel($panel)->getBrandLogo()->toHtml());
        }
        foreach (['/admin/login', '/propietario/login'] as $login) {
            $this->get($login)->assertOk()->assertSee($url, false)->assertDontSee('branding/logo-full-transparent.png');
        }
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee($url, false);
        $owner = User::factory()->create(['must_change_password' => false]);
        $owner->assignRole('propietario');
        Propietario::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($owner)->get('/propietario')->assertOk()->assertSee($url, false);
    }

    public function test_missing_and_invalid_paths_fall_back_without_exposing_urls(): void
    {
        foreach ([null, '../secret.png', 'https://example.com/logo.png', 'community-logos/'.str_repeat('a', 40).'.png'] as $path) {
            CommunitySetting::current()->update(['logo_path' => $path]);
            $this->assertSame(asset('branding/logo-full-transparent.png'), CommunitySetting::logoUrl());
            $this->assertSame(asset('branding/icon-transparent.png'), CommunitySetting::logoUrl(icon: true));
        }
        $this->get('/admin/login')->assertOk()->assertSee(asset('branding/logo-full-transparent.png'), false);
        $this->get('/propietario/login')->assertOk()->assertSee(asset('branding/logo-full-transparent.png'), false);
    }

    public function test_owner_cannot_update_logo_through_service_or_settings_page(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('propietario');
        $this->actingAs($owner)->get('/admin/community-settings')->assertForbidden();
        try {
            app(CommunitySettingsService::class)->update(
                $this->settings(['logo_path' => UploadedFile::fake()->image('logo.png')]), 'Sin permiso', $owner,
            );
            $this->fail('Se permitió actualizar sin rol administrador.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, DB::table('community_setting_revisions')->count());
    }
}
