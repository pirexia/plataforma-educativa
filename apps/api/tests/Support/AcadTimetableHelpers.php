<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Acad\Domain\Models\TeachingAssignment;
use App\Modules\Acad\Domain\Models\TimeSlot;
use App\Modules\Acad\Domain\Models\TimetableSession;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * REQ-ACAD (1.12-a): ayudantes de los tests HTTP de espacios y marcos horarios.
 * Métodos estáticos de una clase para no repetir nombres entre ficheros
 * (CA-060-08). Las asignaciones y sesiones se crean por los modelos (sin API)
 * dentro del contexto del centro.
 */
final class AcadTimetableHelpers
{
    /**
     * Cinco franjas de lunes a viernes, 08:30-09:25 y 09:25-10:20.
     *
     * @return list<array{weekday: int, starts_at: string, ends_at: string}>
     */
    public static function week(): array
    {
        $slots = [];

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $slots[] = ['weekday' => $weekday, 'starts_at' => '08:30', 'ends_at' => '09:25'];
        }

        return $slots;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function frameBody(string $level, array $overrides = []): array
    {
        return $overrides + [
            'education_level' => $level,
            'frame_type' => 'ordinaria',
            'name_translations' => ['es-ES' => 'Horario general'],
            'slots' => self::week(),
        ];
    }

    /** @param  array<string, mixed>  $body */
    public static function postFrame(object $tenant, object $admin, array $body): TestResponse
    {
        resetSessionState();

        return test()->actingAs($admin)->postJson(AcadApiHelpers::url($tenant, '/timetable-frames'), $body);
    }

    /** @param  array<string, mixed>  $body */
    public static function patchFrame(object $tenant, object $admin, string $publicId, array $body): TestResponse
    {
        resetSessionState();

        return test()->actingAs($admin)->patchJson(AcadApiHelpers::url($tenant, "/timetable-frames/{$publicId}"), $body);
    }

    public static function deleteFrame(object $tenant, object $admin, string $publicId): TestResponse
    {
        resetSessionState();

        return test()->actingAs($admin)->deleteJson(AcadApiHelpers::url($tenant, "/timetable-frames/{$publicId}"));
    }

    public static function getFrame(object $tenant, object $admin, string $publicId): TestResponse
    {
        resetSessionState();

        return test()->actingAs($admin)->getJson(AcadApiHelpers::url($tenant, "/timetable-frames/{$publicId}"));
    }

    /** @param  array<string, mixed>  $overrides */
    public static function postFacility(object $tenant, object $admin, array $overrides = []): TestResponse
    {
        resetSessionState();

        return test()->actingAs($admin)->postJson(AcadApiHelpers::url($tenant, '/facilities'), $overrides + [
            'facility_type' => 'aula', 'code' => 'A21', 'name' => 'Aula 2.1',
        ]);
    }

    /** Código del primer error de un campo cuyo nombre lleva puntos (`slots.0.weekday`). */
    public static function errorCode(TestResponse $response, string $field): ?string
    {
        return $response->json('errors')[$field][0]['code'] ?? null;
    }

    /** Asignación docente creada por el modelo (sin API ni comprobaciones), para el usuario dado. */
    public static function assignment(object $tenant, AcademicYear $year, object $group, object $groupSubject, User $teacher, ?object $subgroup = null): TeachingAssignment
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): TeachingAssignment => TeachingAssignment::create([
            'academic_year_id' => $year->id,
            'user_id' => $teacher->id,
            'group_id' => $group->id,
            'group_subject_id' => $groupSubject->id,
            'subgroup_id' => $subgroup?->id,
        ]));
    }

    /** Sesión creada por el modelo (sin validar conflictos) en la franja de `public_id` dado. */
    public static function session(object $tenant, AcademicYear $year, string $slotPublicId, TeachingAssignment $assignment): TimetableSession
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): TimetableSession => TimetableSession::create([
            'academic_year_id' => $year->id,
            'time_slot_id' => TimeSlot::query()->where('public_id', $slotPublicId)->value('id'),
            'teaching_assignment_id' => $assignment->id,
        ]));
    }

    public static function count(object $tenant, string $table, bool $alive = true): int
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): int => DB::table($table)->when($alive, fn ($q) => $q->whereNull('deleted_at'))->count());
    }
}
