<?php

namespace Tests\Feature;

use App\Domain\Imports\Services\OwnerLocationResolver;
use App\Models\Comuna;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerLocationResolverTest extends TestCase
{
    use RefreshDatabase;

    private OwnerLocationResolver $resolver;

    private int $regionValparaiso;

    private int $regionMetropolitana;

    private int $comunaValparaiso;

    private int $comunaSantiago;

    private int $comunaOsorno;

    protected function setUp(): void
    {
        parent::setUp();

        $metropolitana = Region::query()->create(['nombre' => 'Metropolitana de Santiago']);
        $valparaiso = Region::query()->create(['nombre' => 'Valparaíso']);
        $losLagos = Region::query()->create(['nombre' => 'Los Lagos']);
        Region::query()->create(['nombre' => 'Los Ríos']);

        $santiago = Comuna::query()->create(['region_id' => $metropolitana->id, 'nombre' => 'Santiago']);
        $valparaisoComuna = Comuna::query()->create(['region_id' => $valparaiso->id, 'nombre' => 'Valparaíso']);
        $osorno = Comuna::query()->create(['region_id' => $losLagos->id, 'nombre' => 'Osorno']);

        $this->regionValparaiso = $valparaiso->id;
        $this->regionMetropolitana = $metropolitana->id;
        $this->comunaValparaiso = $valparaisoComuna->id;
        $this->comunaSantiago = $santiago->id;
        $this->comunaOsorno = $osorno->id;

        $this->resolver = new OwnerLocationResolver;
    }

    public function test_matches_names_with_accents_case_and_generic_tokens(): void
    {
        $resolved = $this->resolver->resolve(['region_id' => 'valparaiso', 'comuna_id' => 'VALPARAISO']);
        $this->assertSame([], $resolved['errors']);
        $this->assertSame($this->regionValparaiso, $resolved['data']['region_id']);
        $this->assertSame($this->comunaValparaiso, $resolved['data']['comuna_id']);

        $resolved = $this->resolver->resolve(['region_id' => 'Región Santiago']);
        $this->assertSame($this->regionMetropolitana, $resolved['data']['region_id']);

        $resolved = $this->resolver->resolve(['region_id' => 'metropolitana']);
        $this->assertSame($this->regionMetropolitana, $resolved['data']['region_id']);

        $resolved = $this->resolver->resolve(['comuna_id' => 'Región de Osorno']);
        $this->assertSame([], $resolved['errors']);
        $this->assertSame($this->comunaOsorno, $resolved['data']['comuna_id']);
    }

    public function test_accepts_legacy_numeric_ids_and_rejects_unknown_ones(): void
    {
        $resolved = $this->resolver->resolve(['region_id' => (string) $this->regionMetropolitana, 'comuna_id' => (string) $this->comunaSantiago]);
        $this->assertSame([], $resolved['errors']);
        $this->assertSame($this->regionMetropolitana, $resolved['data']['region_id']);
        $this->assertSame($this->comunaSantiago, $resolved['data']['comuna_id']);

        $resolved = $this->resolver->resolve(['region_id' => '999']);
        $this->assertSame(['Región "999" no existe.'], $resolved['errors']);
    }

    public function test_comuna_within_region_scope_and_membership_errors(): void
    {
        $resolved = $this->resolver->resolve(['region_id' => 'Valparaíso', 'comuna_id' => 'Santiago']);
        $this->assertSame(['La comuna "Santiago" no pertenece a la región "Valparaíso".'], $resolved['errors']);
        $this->assertArrayNotHasKey('comuna_id', $resolved['data']);
        $this->assertSame($this->regionValparaiso, $resolved['data']['region_id']);

        $resolved = $this->resolver->resolve(['region_id' => 'valparaiso', 'comuna_id' => 'valparaiso']);
        $this->assertSame([], $resolved['errors']);
        $this->assertSame($this->regionValparaiso, $resolved['data']['region_id']);
        $this->assertSame($this->comunaValparaiso, $resolved['data']['comuna_id']);
    }

    public function test_errors_report_missing_and_ambiguous_matches(): void
    {
        $resolved = $this->resolver->resolve(['region_id' => 'Aysén']);
        $this->assertSame(['Región "Aysén" no encontrada.'], $resolved['errors']);
        $this->assertArrayNotHasKey('region_id', $resolved['data']);

        $resolved = $this->resolver->resolve(['region_id' => 'Los']);
        $this->assertStringContainsString('ambigua', $resolved['errors'][0]);
        $this->assertStringContainsString('Los Lagos', $resolved['errors'][0]);
        $this->assertStringContainsString('Los Ríos', $resolved['errors'][0]);
    }

    public function test_empty_values_omit_fields_and_never_borrow_nulls(): void
    {
        $resolved = $this->resolver->resolve(['rut' => '12.345.678-5']);
        $this->assertSame([], $resolved['errors']);
        $this->assertArrayNotHasKey('region_id', $resolved['data']);
        $this->assertArrayNotHasKey('comuna_id', $resolved['data']);

        $resolved = $this->resolver->resolve(['region_id' => '   ', 'comuna_id' => '']);
        $this->assertSame([], $resolved['errors']);
        $this->assertArrayNotHasKey('region_id', $resolved['data']);
    }

    public function test_partial_words_never_match_as_substrings(): void
    {
        $resolved = $this->resolver->resolve(['region_id' => 'valp']);
        $this->assertSame(['Región "valp" no encontrada.'], $resolved['errors']);
    }
}
