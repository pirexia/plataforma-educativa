<?php

namespace Database\Factories\Alum;

use App\Models\User;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Modules\Alum\Domain\Models\GroupChangeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * REQ-ALUM/datos.md §1.3 (1.15-c). Fuera del espacio `App`, como las demás factorías (AR-01/AR-02). Por defecto crea una
 * matrícula con grupo, un segundo grupo del mismo curso de etapa y una solicitud `pendiente` de la primera al segundo; el
 * curso académico es el de la matrícula. Para colgarla de una matrícula existente se pasa `enrollment_id` (con `from_group_id`
 * y `to_group_id` si hace falta). Datos ficticios (`REQ-SEED-005`).
 *
 * @extends Factory<GroupChangeRequest>
 */
class GroupChangeRequestFactory extends Factory
{
    protected $model = GroupChangeRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => fn (): int => Enrollment::factory()->inGroup()->create()->id,
            'academic_year_id' => fn (array $attributes): int => Enrollment::query()->findOrFail($attributes['enrollment_id'])->academic_year_id,
            'from_group_id' => fn (array $attributes): int => (int) Enrollment::query()->findOrFail($attributes['enrollment_id'])->group_id,
            'to_group_id' => function (array $attributes): int {
                $enrollment = Enrollment::query()->findOrFail($attributes['enrollment_id']);

                return Group::create([
                    'academic_year_id' => $enrollment->academic_year_id,
                    'course_id' => $enrollment->course_id,
                    'code' => 'G'.Str::upper(Str::random(8)),
                    'name' => 'Grupo ficticio destino',
                    'position' => 2,
                ])->id;
            },
            'reason' => 'Motivo ficticio del cambio de grupo',
            'status' => 'pendiente',
            'requested_by' => User::factory(),
            'decided_by' => null,
            'decided_at' => null,
            'decision_note' => null,
        ];
    }

    /** Solicitud resuelta (`aprobada`, `rechazada` o `cancelada`) por otro usuario, con su instante (`datos.md §1.3`). */
    public function decided(string $status = 'aprobada'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'decided_by' => User::factory(),
            'decided_at' => now(),
        ]);
    }
}
