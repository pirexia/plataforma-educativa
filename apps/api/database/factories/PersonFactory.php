<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'given_name' => fake()->firstName(),
            'family_name_1' => fake()->lastName(),
            'family_name_2' => fake()->lastName(),
            'contact_email' => fake()->unique()->safeEmail(),
            'locale' => 'es',
        ];
    }
}
