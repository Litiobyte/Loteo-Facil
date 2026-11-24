<?php

namespace Tests\Feature;

use App\Domain\Documents\Services\PrivateStorageService;
use App\Models\CommunitySetting;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PrivateStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_storage_configuration_does_not_expose_public_urls_or_request_r2_acl(): void
    {
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.documents-local.root'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.documents'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.r2'));
        $this->assertArrayNotHasKey('visibility', config('filesystems.disks.r2'));
    }

    public function test_r2_upload_sets_mime_without_sending_acl(): void
    {
        config(['filesystems.disks.documents.bucket' => 'private-bucket']);
        $client = Mockery::mock(S3Client::class);
        $client->shouldReceive('putObject')->once()->withArgs(function (array $options): bool {
            return $options['Bucket'] === 'private-bucket'
                && $options['Key'] === 'community-logos/logo.png'
                && $options['ContentType'] === 'image/png'
                && ! array_key_exists('ACL', $options);
        });
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $disk->shouldReceive('getClient')->once()->andReturn($client);
        Storage::shouldReceive('disk')->once()->with('documents')->andReturn($disk);
        $stream = fopen('php://temp', 'w+');

        try {
            app(PrivateStorageService::class)->putStream('community-logos/logo.png', $stream);
        } finally {
            fclose($stream);
        }
    }

    public function test_logo_requires_authorized_user_and_does_not_expose_public_url(): void
    {
        Storage::fake('documents');
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create()->assignRole('admin');
        $other = User::factory()->create();
        $path = 'community-logos/'.str_repeat('a', 40).'.png';
        Storage::disk('documents')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/aX8AAAAASUVORK5CYII='));
        CommunitySetting::current()->update(['logo_path' => $path]);

        $this->getJson(route('community.logo'))->assertUnauthorized();
        $this->actingAs($other)->get(route('community.logo'))->assertForbidden();
        $this->actingAs($admin)->get(route('community.logo'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(route('community.logo'), CommunitySetting::logoUrl());
    }

    public function test_copy_is_dry_run_idempotent_and_does_not_overwrite_conflicts(): void
    {
        Storage::fake('public');
        Storage::fake('documents-local');
        $path = 'community-logos/'.str_repeat('a', 40).'.png';
        CommunitySetting::current()->update(['logo_path' => $path]);
        Storage::disk('public')->put($path, 'original');

        $this->artisan('private-files:copy')->assertSuccessful();
        Storage::disk('documents-local')->assertMissing($path);
        $this->artisan('private-files:copy', ['--execute' => true])->assertSuccessful();
        $this->artisan('private-files:copy', ['--execute' => true])->assertSuccessful();
        $this->assertSame('original', Storage::disk('documents-local')->get($path));
        Storage::disk('documents-local')->put($path, 'conflict');
        $this->artisan('private-files:copy', ['--execute' => true])->assertFailed();
        $this->assertSame('conflict', Storage::disk('documents-local')->get($path));
        $this->assertSame('original', Storage::disk('public')->get($path));
    }
}
