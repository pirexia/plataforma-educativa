<?php

namespace Database\Factories\Alum;

use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Acad\Domain\Models\Group;
use App\Modules\Alum\Domain\Models\Enrollment;
use App\Modules\Alum\Domain\Models\Student;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * REQ-ALUM/datos.md §1.2 (1.15-b). Fuera del espacio `App`, como las demás factorías (AR-01/AR-02). Por defecto crea
 * reutiliza (o crea) el curso académico en `planificacion` del centro y crea la etapa y el curso de etapa, sin grupo; `inGroup()` añade un grupo del curso.
 * Para colgar la matrícula de una estructura existente, se pasan `academic_year_id`, `course_id` y `group_id`.
 * Datos ficticios (`REQ-SEED-005`).
 *
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_year_id' => fn (): int => self::yearId(AcademicYearStatus::Planificacion),
            'course_id' => function (array $attributes): int {
                $level = EducationLevel::create([
                    'academic_year_id' => $attributes['academic_year_id'],
                    'code' => 'L'.Str::upper(Str::random(8)),
                    'name_translations' => ['es-ES' => 'Etapa ficticia'],
                    'stage' => 'eso',
                    'regime' => 'concertado',
                    'position' => 1,
                ]);

                return Course::create([
                    'academic_year_id' => $attributes['academic_year_id'],
                    'education_level_id' => $level->id,
                    'code' => 'C'.Str::upper(Str::random(8)),
                    'name_translations' => ['es-ES' => 'Curso ficticio'],
                    'position' => 1,
                ])->id;
            },
            'group_id' => null,
            'status' => 'activa',
            'enrolled_on' => '2027-09-08',
            'withdrawn_on' => null,
            'withdrawal_reason' => null,
        ];
    }

    /** Matrícula en el curso académico `activo` del centro (se crea si no existe). */
    public function inActiveYear(): static
    {
        return $this->state(fn (array $attributes): array => [
            'academic_year_id' => fn (): int => self::yearId(AcademicYearStatus::Activo),
        ]);
    }

    /**
     * Id del curso académico del centro en ese estado, creándolo si no existe: el centro admite como mucho uno
     * `planificacion` y uno `activo` (`academic_years_tenant_status_unique`).
     */
    private static function yearId(AcademicYearStatus $status): int
    {
        $existing = AcademicYear::query()->where('status', $status)->first();

        if ($existing !== null) {
            return $existing->id;
        }

        $planning = $status === AcademicYearStatus::Planificacion;

        return AcademicYear::create([
            'code' => $planning ? '2027-2028' : '2026-2027',
            'starts_on' => $planning ? '2027-09-01' : '2026-09-01',
            'ends_on' => $planning ? '2028-06-30' : '2027-06-30',
            'status' => $status,
        ])->id;
    }

    /** Matrícula en un grupo nuevo del curso de etapa. */
    public function inGroup(): static
    {
        return $this->state(fn (array $attributes): array => [
            'group_id' => fn (array $resolved): int => Group::create([
                'academic_year_id' => $resolved['academic_year_id'],
                'course_id' => $resolved['course_id'],
                'code' => 'G'.Str::upper(Str::random(8)),
                'name' => 'Grupo ficticio',
                'position' => 1,
            ])->id,
        ]);
    }

    /** Matrícula dada de baja (`RN-ALUM-22`). */
    public function withdrawn(string $reason = 'traslado', string $on = '2027-12-01'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'baja',
            'withdrawn_on' => $on,
            'withdrawal_reason' => $reason,
        ]);
    }
}
