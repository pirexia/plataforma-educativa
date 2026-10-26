<?php

namespace Database\Factories\FamUnit;

use App\Modules\FamUnit\Domain\Models\FamilyUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fuera del espacio `App`, como las demás factorías, para que `REQ-SEED` pueda usarla sin violar AR-01/AR-02.
 * Datos sintéticos (`CLAUDE.md §11`): código de unidad ficticio.
 *
 * @extends Factory<FamilyUnit>
 */
class FamilyUnitFactory extends Factory
{
    protected $model = FamilyUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['code' => 'UF-'.fake()->unique()->numerify('######')];
    }
}
