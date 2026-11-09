<?php

namespace Database\Factories\Alum;

use App\Models\Person;
use App\Modules\Alum\Domain\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ADR-056 §3.5 (generado por `php artisan make:module --entity=Student`).
 * Fuera del espacio `App`, como las demás factorías, para que `REQ-SEED` pueda
 * usarla sin violar AR-01/AR-02.
 *
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
        ];
    }
}
