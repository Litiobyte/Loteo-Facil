<?php

namespace App\Domain\Imports\Services;

use App\Domain\Imports\DataTransferObjects\ImportResult;
use App\Domain\Imports\DataTransferObjects\ParsedFile;
use App\Domain\Imports\Support\StringNormalizer;
use App\Models\Comuna;
use App\Models\Propietario;
use App\Models\Region;
use App\Models\User;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use DomainException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

final class PropietarioImportService
{
    /**
     * @var list<string>
     */
    public const HEADERS = ['rut', 'nombre', 'apellido', 'email', 'telefono', 'direccion', 'region', 'comuna', 'nacionalidad', 'profesion', 'estado_civil'];

    /**
     * @var list<string>
     */
    private const ESTADOS_CIVILES = ['Soltero', 'Casado', 'Divorciado', 'Viudo', 'Union civil'];

    /**
     * @var SupportCollection<int, array{id: int, nombre: string}>|null
     */
    private ?SupportCollection $regionesCache = null;

    /**
     * @var SupportCollection<int, array{id: int, region_id: int, nombre: string}>|null
     */
    private ?SupportCollection $comunasCache = null;

    public function import(ParsedFile $file): ImportResult
    {
        $result = new ImportResult;

        $missing = $file->missingHeaders(self::HEADERS);

        if ($missing !== []) {
            throw new DomainException('El archivo debe contener las columnas: '.implode(', ', $missing).'.');
        }

        foreach ($file->rows as $row) {
            $rowNumber = $row['row_number'];
            $data = $row['data'];

            $validator = Validator::make($data, [
                'rut' => ['required', new ValidChileanRut],
                'nombre' => ['required', 'string', 'max:255'],
                'apellido' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'telefono' => ['nullable', 'string', 'max:50'],
                'direccion' => ['nullable', 'string', 'max:255'],
                'region' => ['required', 'string', 'max:255'],
                'comuna' => ['required', 'string', 'max:255'],
                'nacionalidad' => ['nullable', 'string', 'max:120'],
                'profesion' => ['nullable', 'string', 'max:120'],
                'estado_civil' => ['required', Rule::in(self::ESTADOS_CIVILES)],
            ], [
                'required' => 'El campo :attribute es obligatorio.',
                'email' => 'El campo :attribute no es un correo válido.',
                'max' => 'El campo :attribute supera el largo máximo.',
                'in' => 'El campo :attribute tiene un valor no permitido.',
            ], [
                'rut' => 'rut',
                'nombre' => 'nombre',
                'apellido' => 'apellido',
                'email' => 'email',
                'telefono' => 'telefono',
                'direccion' => 'direccion',
                'region' => 'region',
                'comuna' => 'comuna',
                'nacionalidad' => 'nacionalidad',
                'profesion' => 'profesion',
                'estado_civil' => 'estado civil',
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $fieldMessages) {
                    $result->errors[] = [
                        'row' => $rowNumber,
                        'field' => $field,
                        'message' => $fieldMessages[0],
                    ];
                }

                continue;
            }

            try {
                $this->importRow($result, $data, $rowNumber);
            } catch (Throwable $exception) {
                $result->errors[] = [
                    'row' => $rowNumber,
                    'field' => null,
                    'message' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo guardar el propietario.',
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string|int|float|null>  $data
     */
    private function importRow(ImportResult $result, array $data, int $rowNumber): void
    {
        $rut = ChileanRut::normalize(trim((string) $data['rut']));
        $email = mb_strtolower(trim((string) $data['email']));
        $nombre = trim((string) $data['nombre']);
        $apellido = trim((string) $data['apellido']);

        [$regionId, $comunaId] = $this->resolveUbicacion($data['region'] ?? null, $data['comuna'] ?? null);

        $profile = [
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => $email,
            'telefono' => ($data['telefono'] ?? null) ?: null,
            'direccion' => ($data['direccion'] ?? null) ?: null,
            'region_id' => $regionId,
            'comuna_id' => $comunaId,
            'nacionalidad' => ($data['nacionalidad'] ?? null) ?: null,
            'profesion' => ($data['profesion'] ?? null) ?: null,
            'estado_civil' => ($data['estado_civil'] ?? null) ?: null,
        ];

        $propietario = Propietario::query()->where('rut', $rut)->first();

        if ($propietario) {
            $propietario->update($profile);

            if ($propietario->user) {
                $propietario->user->update([
                    'name' => trim($nombre.' '.$apellido),
                    'email' => $email,
                ]);
            }

            $result->updated++;

            return;
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new DomainException('El email '.$email.' ya está asociado a un usuario existente.');
        }

        DB::transaction(function () use ($profile, $rut, $email, $nombre, $apellido): void {
            $user = User::query()->create([
                'name' => trim($nombre.' '.$apellido),
                'email' => $email,
                'password' => Hash::make(Str::password()),
            ]);

            $user->assignRole('propietario');

            Propietario::query()->create([
                ...$profile,
                'user_id' => $user->id,
                'rut' => $rut,
            ]);
        });

        $result->created++;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveUbicacion(mixed $regionName, mixed $comunaName): array
    {
        $regionId = null;
        $comunaId = null;

        if ($regionName !== null && trim((string) $regionName) !== '') {
            $region = $this->regiones()->first(fn (array $region): bool => StringNormalizer::fold($region['nombre']) === StringNormalizer::fold((string) $regionName));

            if ($region === null) {
                throw new DomainException('La region "'.$regionName.'" no existe.');
            }

            $regionId = $region['id'];
        }

        if ($comunaName !== null && trim((string) $comunaName) !== '') {
            $comuna = $this->comunas()
                ->when($regionId !== null, fn (SupportCollection $comunas): SupportCollection => $comunas->where('region_id', $regionId))
                ->first(fn (array $comuna): bool => StringNormalizer::fold($comuna['nombre']) === StringNormalizer::fold((string) $comunaName));

            if ($comuna === null) {
                throw new DomainException('La comuna "'.$comunaName.'" no existe.'.($regionId !== null ? ' Verifica que pertenezca a la region indicada.' : ''));
            }

            $comunaId = $comuna['id'];
        }

        return [$regionId, $comunaId];
    }

    /**
     * @return SupportCollection<int, array{id: int, nombre: string}>
     */
    private function regiones(): SupportCollection
    {
        return $this->regionesCache ??= Region::query()
            ->get(['id', 'nombre'])
            ->map(fn (Region $region): array => ['id' => $region->id, 'nombre' => $region->nombre]);
    }

    /**
     * @return SupportCollection<int, array{id: int, region_id: int, nombre: string}>
     */
    private function comunas(): SupportCollection
    {
        return $this->comunasCache ??= Comuna::query()
            ->get(['id', 'region_id', 'nombre'])
            ->map(fn (Comuna $comuna): array => ['id' => $comuna->id, 'region_id' => $comuna->region_id, 'nombre' => $comuna->nombre]);
    }
}
