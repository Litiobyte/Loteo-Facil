<?php

namespace App\Filament\Owner\Resources\Users\Pages;

use App\Filament\Owner\Resources\Users\UserResource;
use App\Models\Comuna;
use App\Models\Propietario;
use App\Models\Region;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ManageUsers extends Page
{
    protected static string $resource = UserResource::class;

    protected string $view = 'filament.owner.pages.mi-perfil';

    /** @var array<string, mixed> */
    public array $data = [];

    public bool $saved = false;

    /** @return array<string, string> */
    public function profileFields(): array
    {
        return [
            'nombre' => 'Nombres',
            'apellido' => 'Apellidos',
            'rut' => 'RUT',
            'profesion' => 'Profesión u ocupación',
            'nacionalidad' => 'Nacionalidad',
            'email' => 'Correo de contacto',
            'telefono' => 'Teléfono',
            'direccion' => 'Dirección',
            'region_id' => 'Región',
            'comuna_id' => 'Comuna',
        ];
    }

    public function mount(): void
    {
        abort_unless(UserResource::canViewAny(), 403);
        $owner = $this->owner();
        $this->data = $owner?->only(array_keys($this->profileFields())) ?? [];
    }

    public function getTitle(): string
    {
        return 'Mi perfil';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function updatedData(mixed $value, string $key): void
    {
        $this->saved = false;
        if ($key === 'region_id') {
            $this->data['comuna_id'] = null;
        }
    }

    public function saveProfile(): void
    {
        $owner = $this->owner();
        abort_unless($owner, 403);
        Gate::authorize('updateProfile', $owner);

        foreach (array_keys($this->profileFields()) as $field) {
            $value = $this->data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $this->data[$field] = $value === '' ? null : $value;
        }
        if (is_string($this->data['rut'])) {
            $this->data['rut'] = ChileanRut::normalize($this->data['rut']);
        }

        $validated = $this->validate([
            'data.nombre' => ['required', 'string', 'max:255'],
            'data.apellido' => ['required', 'string', 'max:255'],
            'data.rut' => ['required', 'string', 'max:20', new ValidChileanRut, Rule::unique('propietarios', 'rut')->ignore($owner)],
            'data.profesion' => ['nullable', 'string', 'max:120'],
            'data.nacionalidad' => ['nullable', 'string', 'max:120'],
            'data.email' => ['required', 'email', 'max:255', Rule::unique('propietarios', 'email')->ignore($owner)],
            'data.telefono' => ['nullable', 'string', 'max:50'],
            'data.direccion' => ['nullable', 'string', 'max:255'],
            'data.region_id' => ['nullable', 'integer', Rule::exists('regiones', 'id')],
            'data.comuna_id' => ['nullable', 'integer', Rule::exists('comunas', 'id')->where('region_id', $this->data['region_id'])],
        ], [], collect($this->profileFields())->mapWithKeys(fn (string $label, string $field): array => ["data.{$field}" => $label])->all());

        $owner->update($validated['data']);
        $this->saved = true;
        Notification::make()->success()->title('Perfil actualizado')->send();
    }

    protected function owner(): ?Propietario
    {
        abort_unless(Auth::user()?->hasRole('propietario'), 403);

        return Propietario::query()->where('user_id', Auth::id())->first();
    }

    protected function getViewData(): array
    {
        $owner = $this->owner();
        $fields = $this->profileFields();
        $missing = array_filter($fields, fn (string $field): bool => blank($this->data[$field] ?? null), ARRAY_FILTER_USE_KEY);
        $completed = count($fields) - count($missing);

        return [
            'owner' => $owner,
            'accountEmail' => Auth::user()->email,
            'displayName' => $owner?->nombre_completo ?: Auth::user()->name,
            'fields' => $fields,
            'missing' => $missing,
            'completed' => $completed,
            'percentage' => (int) round($completed / count($fields) * 100),
            'regions' => Region::query()->orderBy('nombre')->pluck('nombre', 'id'),
            'communes' => filled($this->data['region_id'] ?? null)
                ? Comuna::query()->where('region_id', $this->data['region_id'])->orderBy('nombre')->pluck('nombre', 'id')
                : collect(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
