<?php

namespace Database\Factories\FamUnit;

use App\Models\Person;
use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use App\Modules\FamUnit\Domain\Models\Guardian;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fuera del espacio `App` (`REQ-SEED`). Crea la persona y la unidad si no se indican.
 *
 * @extends Factory<Guardian>
 */
class GuardianFactory extends Factory
{
    protected $model = Guardian::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'family_unit_id' => FamilyUnit::factory(),
        ];
    }
}
