<?php

namespace Database\Factories\FamUnit;

use App\Models\Person;
use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use App\Modules\FamUnit\Domain\Models\FamilyUnitStudent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fuera del espacio `App` (`REQ-SEED`). Hasta `REQ-ALUM` (1.15) no hay alta de alumno por API: el alumno de prueba es
 * una persona sintética (`funcional.md §14` punto 3).
 *
 * @extends Factory<FamilyUnitStudent>
 */
class FamilyUnitStudentFactory extends Factory
{
    protected $model = FamilyUnitStudent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'family_unit_id' => FamilyUnit::factory(),
            'student_person_id' => Person::factory(),
        ];
    }
}
