<?php

namespace Database\Factories\FamUnit;

use App\Models\Person;
use App\Modules\FamUnit\Domain\Models\Guardian;
use App\Modules\FamUnit\Domain\Models\GuardianStudentLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fuera del espacio `App` (`REQ-SEED`). Por defecto, un vínculo `activo` de lectura sin representación legal
 * (el valor más restrictivo, `datos.md §1.4`).
 *
 * @extends Factory<GuardianStudentLink>
 */
class GuardianStudentLinkFactory extends Factory
{
    protected $model = GuardianStudentLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guardian_id' => Guardian::factory(),
            'student_person_id' => Person::factory(),
            'relationship' => 'madre',
        ];
    }
}
