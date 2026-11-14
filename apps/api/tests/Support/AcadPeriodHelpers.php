<?php

namespace Tests\Support;

use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * REQ-ACAD (1.11d-a): ayudantes de los tests HTTP de períodos lectivos.
 * Métodos estáticos de una clase para no repetir nombres entre ficheros
 * (CA-060-08).
 */
final class AcadPeriodHelpers
{
    /** Centro con `acad` y un curso de prueba del 2026-09-01 al 2027-08-31 (`funcional.md §13.11`). */
    public static function setup(string $slug, AcademicYearStatus $status = AcademicYearStatus::Activo): array
    {
        return AcadApiHelpers::setup($slug, $status, '2027-08-31');
    }

    /**
     * Cuerpo válido de un alta (primer trimestre del curso de prueba).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function body(string $level, array $overrides = []): array
    {
        return $overrides + [
            'education_level' => $level,
            'period_type' => 'trimestre',
            'name_translations' => ['es-ES' => 'Primer trimestre'],
            'starts_on' => '2026-09-08',
            'ends_on' => '2026-12-22',
        ];
    }

    /** @param  array<string, mixed>  $body */
    public static function post(object $tenant, object $admin, array $body): TestResponse
    {
        resetSessionState();

        return test()->actingAs($admin)->postJson(AcadApiHelpers::url($tenant, '/academic-periods'), $body);
    }

    /** @param  array<string, mixed>  $body */
    public static function patch(object $tenant, object $admin, string $publicId, array $body): TestResponse
    {
        resetSessionState();

        return test()->actingAs($admin)->patchJson(AcadApiHelpers::url($tenant, "/academic-periods/{$publicId}"), $body);
    }

    /** Código del primer error de un campo cuyo nombre lleva puntos (`name_translations.en`). */
    public static function errorCode(TestResponse $response, string $field): ?string
    {
        return $response->json('errors')[$field][0]['code'] ?? null;
    }

    public static function count(object $tenant, bool $alive = true): int
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): int => DB::table('academic_periods')->when($alive, fn ($q) => $q->whereNull('deleted_at'))->count());
    }

    /** Etapa creada por el modelo (sin API ni módulo contratado), dentro del contexto del centro. */
    public static function level(object $tenant, AcademicYear $year, string $code = 'ESO'): EducationLevel
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): EducationLevel => EducationLevel::create([
            'academic_year_id' => $year->id,
            'code' => $code,
            'name_translations' => ['es-ES' => "Etapa {$code}"],
            'stage' => 'eso',
            'regime' => 'concertado',
            'position' => 1,
        ]));
    }

    /** Período creado por el modelo (sin validar), vivo o borrado lógicamente. */
    public static function period(object $tenant, AcademicYear $year, EducationLevel $level, string $startsOn, string $endsOn, bool $deleted = false): AcademicPeriod
    {
        return app(TenantContext::class)->runFor($tenant->id, function () use ($year, $level, $startsOn, $endsOn, $deleted): AcademicPeriod {
            $period = AcademicPeriod::create([
                'academic_year_id' => $year->id,
                'education_level_id' => $level->id,
                'period_type' => 'trimestre',
                'name_translations' => ['es-ES' => "Período {$startsOn}"],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'position' => 1,
            ]);

            if ($deleted) {
                $period->delete();
            }

            return $period;
        });
    }
}
