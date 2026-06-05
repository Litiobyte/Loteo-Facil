<?php

namespace Database\Factories;

use App\Models\Comuna;
use App\Models\Propietario;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Propietario>
 */
class PropietarioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $region = Region::query()->inRandomOrder()->first();
        $comuna = $region
            ? Comuna::query()->where('region_id', $region->id)->inRandomOrder()->first()
            : null;

        return [
            'user_id' => User::factory(),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'rut' => fake()->unique()->numerify('########-#'),
            'telefono' => fake()->phoneNumber(),
            'direccion' => fake()->streetAddress(),
            'region_id' => $region?->id,
            'comuna_id' => $comuna?->id,
            'nacionalidad' => 'Chilena',
            'profesion' => fake()->jobTitle(),
            'estado_civil' => fake()->randomElement(['Soltero', 'Casado', 'Divorciado', 'Viudo']),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
