<?php

namespace Database\Factories\Alum;

use App\Modules\Alum\Domain\Models\Student;
use App\Modules\Alum\Domain\Models\StudentPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * REQ-ALUM/datos.md §1.4 (1.15-c). Fuera del espacio `App`, como las demás factorías (AR-01/AR-02). Fila de la tabla, sin
 * objeto en el almacenamiento. Datos ficticios (`REQ-SEED-005`).
 *
 * @extends Factory<StudentPhoto>
 */
class StudentPhotoFactory extends Factory
{
    protected $model = StudentPhoto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'object_key' => 'tenants/ficticio/alum/student-photos/'.Str::ulid().'.jpg',
            'content_type' => 'image/jpeg',
            'byte_size' => 20480,
        ];
    }
}
