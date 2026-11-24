<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

class CommunitySetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['square_meters_per_share' => 'decimal:4', 'authorized_shares' => 'decimal:4', 'share_precision' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->findOrFail(1);
    }

    /**
     * Nombre de la comunidad para reportes y marca; con respaldo si falta.
     */
    public static function displayName(): string
    {
        try {
            $name = trim((string) static::query()->value('community_name'));
        } catch (\Throwable) {
            $name = '';
        }

        return $name !== '' ? $name : 'Loteo Fácil';
    }

    public static function logoUrl(bool $icon = false): string
    {
        try {
            $path = static::query()->whereKey(1)->value('logo_path');
        } catch (QueryException) {
            $path = null;
        }

        if (auth()->user()?->hasAnyRole(['admin', 'super_admin', 'propietario'])
            && is_string($path) && preg_match('/\Acommunity-logos\/[a-zA-Z0-9]{40}\.(png|jpg|jpeg|webp)\z/', $path)
            && Storage::disk('documents')->exists($path)) {
            return route('community.logo');
        }

        return asset($icon ? 'branding/icon-transparent.png' : 'branding/logo-full-transparent.png');
    }

    public function usesShares(): bool
    {
        return in_array($this->mode, ['shares', 'mixed'], true);
    }

    public function usesLots(): bool
    {
        return in_array($this->mode, ['lots', 'mixed'], true);
    }
}
