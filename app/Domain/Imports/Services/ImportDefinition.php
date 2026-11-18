<?php

namespace App\Domain\Imports\Services;

use App\Domain\Owners\Services\PropietarioEligibleUserService;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Propietario;
use App\Models\User;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportDefinition
{
    private ?OwnerLocationResolver $locations = null;

    /** @return array<string, string> */
    public function fields(string $type): array
    {
        return match ($type) {
            'owners' => [
                'rut' => 'RUT', 'nombre' => 'Nombre', 'apellido' => 'Apellido', 'email' => 'Correo electrónico',
                'user_id' => 'ID de cuenta propietario existente (deje vacío para crear cuenta)',
                'telefono' => 'Teléfono', 'direccion' => 'Dirección', 'region_id' => 'Región',
                'comuna_id' => 'Comuna', 'nacionalidad' => 'Nacionalidad', 'profesion' => 'Profesión',
                'estado_civil' => 'Estado civil',
            ],
            'lots' => ['codigo' => 'Código', 'metros_cuadrados' => 'Superficie m² (solo creación)', 'estado' => 'Estado (solo creación)', 'notas' => 'Notas'],
            'shares' => ['rut' => 'RUT del propietario existente', 'quantity' => 'Cantidad total de acciones', 'amount' => 'Total aportado (vacío = desconocido)', 'reference_date' => 'Fecha de referencia (AAAA-MM-DD, opcional)', 'notes' => 'Observaciones'],
            default => throw ValidationException::withMessages(['type' => 'Tipo no soportado.']),
        };
    }

    /** @param array<int, string> $headers
     * @return array<string, int|string>
     */
    public function autoMap(string $type, array $headers): array
    {
        $mapping = array_fill_keys(array_keys($this->fields($type)), '');
        $aliases = ['correo_electronico' => 'email', 'correo' => 'email', 'telefono' => 'telefono', 'identificador' => 'codigo', 'numero_lote' => 'codigo', 'superficie' => 'metros_cuadrados', 'superficie_m2' => 'metros_cuadrados', 'apellidos' => 'apellido', 'region' => 'region_id', 'comuna' => 'comuna_id'];
        foreach ($headers as $index => $header) {
            $key = Str::slug($header, '_');
            $key = $aliases[$key] ?? $key;
            if (array_key_exists($key, $mapping) && $mapping[$key] === '') {
                $mapping[$key] = $index;
            }
        }

        return $mapping;
    }

    /** @return class-string<Model> */
    public function model(string $type): string
    {
        return match ($type) {
            'owners' => Propietario::class,
            'lots' => Lote::class,
            default => throw ValidationException::withMessages(['type' => 'Tipo no soportado.']),
        };
    }

    /** @return array<string, string> */
    public function templateHeaders(string $type): array
    {
        $headers = [];
        foreach ($this->fields($type) as $field => $label) {
            $headers[$field] = match ($field) {
                'region_id' => 'region', 'comuna_id' => 'comuna', default => $field,
            };
        }

        return $headers;
    }

    /** @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, errors: array<int, string>}
     */
    public function resolveLocations(array $data): array
    {
        return $this->locations()->resolve($data);
    }

    private function locations(): OwnerLocationResolver
    {
        return $this->locations ??= new OwnerLocationResolver;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(string $type, array $data): array
    {
        foreach (['user_id', 'region_id', 'comuna_id'] as $field) {
            if (isset($data[$field]) && ctype_digit($data[$field])) {
                $data[$field] = (string) (int) $data[$field];
            }
        }
        if ($type === 'shares' && isset($data['rut'])) {
            $data['rut'] = ChileanRut::normalize($data['rut']);
        }
        if ($type === 'owners') {
            if (isset($data['rut'])) {
                $data['rut'] = ChileanRut::normalize($data['rut']);
            }
            if (
                trim((string) ($data['email'] ?? '')) === ''
                && ChileanRut::isValid($data['rut'] ?? null)
                && ! Propietario::query()->where('rut', $data['rut'])->exists()
            ) {
                $data['email'] = str_replace(['.', '-'], '', $data['rut']).'@loteofacil.cl';
            }
            if (isset($data['email'])) {
                $data['email'] = mb_strtolower($data['email']);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     * @return array<int, string>
     */
    public function keys(string $type, array $data): array
    {
        $fields = match ($type) {
            'owners' => ['rut', 'email', 'user_id'], 'shares' => ['rut'], default => ['codigo'],
        };

        return array_values(array_map(fn (string $key): string => $key.':'.mb_strtolower((string) $data[$key]), array_filter($fields, fn (string $key): bool => isset($data[$key]))));
    }

    /** @param array<string, mixed> $data
     * @return array{0: ?Model, 1: array<int, string>}
     */
    public function resolve(string $type, array $data): array
    {
        $query = $this->model($type)::query();
        if ($type === 'owners') {
            $query->where(function (Builder $query) use ($data): void {
                $query->whereRaw('1 = 0');
                if (isset($data['rut'])) {
                    $query->orWhere('rut', $data['rut']);
                }
                if (isset($data['email'])) {
                    $query->orWhereRaw('LOWER(email) = ?', [$data['email']]);
                }
            });
        } else {
            $query->whereRaw('LOWER(codigo) = ?', [mb_strtolower($data['codigo'] ?? '')]);
        }
        $matches = $query->limit(2)->get();
        if ($matches->count() > 1) {
            return [null, ['Los identificadores apuntan a registros distintos.']];
        }
        $target = $matches->first();
        if ($type === 'owners' && $target && isset($data['rut']) && $data['rut'] !== $target->rut) {
            return [null, ['El correo pertenece a otro RUT. No se asocian coincidencias ambiguas.']];
        }

        return [$target, []];
    }

    /** @param array<string, mixed> $data
     * @return array<int, string>
     */
    public function errors(string $type, array $data, ?Model $target, ?int $etapaId): array
    {
        $merged = array_replace($target?->getAttributes() ?? [], $data);
        $rules = $type === 'owners' ? [
            'rut' => ['required', 'string', new ValidChileanRut],
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'telefono' => ['nullable', 'string', 'max:255'], 'direccion' => ['nullable', 'string', 'max:255'],
            'nacionalidad' => ['nullable', 'string', 'max:120'], 'profesion' => ['nullable', 'string', 'max:120'],
            'estado_civil' => ['nullable', Rule::in(['Soltero', 'Casado', 'Divorciado', 'Viudo', 'Union civil'])],
        ] : [
            'codigo' => ['required', 'string', 'max:255'],
            'metros_cuadrados' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'estado' => ['required', Rule::in(['disponible', 'reservado', 'vendido'])],
            'notas' => ['nullable', 'string', 'max:1000'],
        ];
        if ($type === 'lots' && ! $target) {
            $merged['estado'] ??= 'disponible';
        }
        if ($target) {
            $rules = array_intersect_key($rules, $data);
        }
        $errors = Validator::make($merged, $rules)->errors()->all();
        if ($type === 'owners') {
            $errors = [...$errors, ...$this->locations()->resolve($merged)['errors']];
            if (! isset($data['rut'])) {
                $errors[] = 'Debe incluir RUT para identificar al propietario.';
            }
            if (! $target && isset($data['user_id']) && ! app(PropietarioEligibleUserService::class)->eligibleUsersQuery(User::query())->whereKey($data['user_id'])->exists()) {
                $errors[] = 'La cuenta debe tener rol propietario y no estar vinculada a otro perfil.';
            }            if ($target && isset($data['user_id']) && (int) $data['user_id'] !== (int) $target->user_id) {
                $errors[] = 'No se permite cambiar la cuenta vinculada.';
            }
        } else {
            if (! Etapa::query()->whereKey($etapaId)->exists()) {
                $errors[] = 'Seleccione una etapa existente.';
            }
            if ($target && (int) $target->etapa_id !== $etapaId) {
                $errors[] = 'El código ya existe en otra etapa; su unicidad es global.';
            }
            foreach (['codigo', 'metros_cuadrados', 'estado'] as $field) {
                if ($target && isset($data[$field]) && (string) $data[$field] !== (string) $target->$field) {
                    $errors[] = "No se permite cambiar {$field} de lotes existentes por importación para proteger la cobranza.";
                }
            }
        }

        return $errors;
    }

    public function fingerprint(Model $model): string
    {
        return hash('sha256', json_encode($model->getAttributes(), JSON_THROW_ON_ERROR));
    }
}
