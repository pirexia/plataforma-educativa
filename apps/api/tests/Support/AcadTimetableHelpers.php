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
 * REQ-ACAD (1.12-a y 1.12-b): ayudantes de los tests HTTP de espacios, marcos horarios y reservas.
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

    /**
     * Escenario completo por la API: centro con `acad`, administrador, curso,
     * árbol (etapa, curso de etapa, grupo con subgrupo y asignatura asignada),
     * marco ordinario con la semana de `week()` y un aula; y dos profesores
     * activos.
     *
     * @return array{tenant: object, admin: User, year: AcademicYear, api: array<string, string>, frame: array<string, mixed>, slots: list<string>, facility: string, t1: User, t2: User}
     */
    public static function scenario(string $slug): array
    {
        resetSessionState();

        [$tenant, $admin, $year] = AcadApiHelpers::setup($slug);
        $api = AcadApiHelpers::tree($tenant, $admin, $year);

        resetSessionState();
        test()->actingAs($admin)->putJson(AcadApiHelpers::url($tenant, "/groups/{$api['group']}/subjects"), ['subjects' => [$api['subject']]])->assertOk();

        $frame = self::postFrame($tenant, $admin, self::frameBody($api['level']))->assertCreated()->json();
        $facility = self::postFacility($tenant, $admin)->assertCreated()->json('public_id');

        return [
            'tenant' => $tenant, 'admin' => $admin, 'year' => $year, 'api' => $api, 'frame' => $frame,
            'slots' => array_column($frame['slots'], 'public_id'), 'facility' => $facility,
            't1' => AcadApiHelpers::userWith($tenant, $admin, 'prof1', ['horarios.leer']),
            't2' => AcadApiHelpers::userWith($tenant, $admin, 'prof2', ['horarios.leer']),
        ];
    }

    /** @param  array<string, mixed>  $body */
    public static function send(object $tenant, User $user, string $method, string $path, array $body = []): TestResponse
    {
        resetSessionState();

        return test()->actingAs($user)->json($method, AcadApiHelpers::url($tenant, $path), $body);
    }

    /** Alta de asignación por la API. */
    public static function postAssignment(object $tenant, User $admin, User|string $teacher, string $group, string $subject, ?string $subgroup = null): TestResponse
    {
        return self::send($tenant, $admin, 'POST', '/teaching-assignments', [
            'teacher' => $teacher instanceof User ? $teacher->public_id : $teacher,
            'group' => $group, 'subject' => $subject, 'subgroup' => $subgroup,
        ]);
    }

    /**
     * Cuerpo de una reserva (1.12-b); por defecto, el jueves 2026-11-12 de 10:00 a 11:00.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function bookingBody(string $year, string $facility, array $overrides = []): array
    {
        return $overrides + ['academic_year' => $year, 'facility' => $facility, 'booked_on' => '2026-11-12', 'starts_at' => '10:00', 'ends_at' => '11:00'];
    }

    /** Alta de reserva por la API (1.12-b). */
    public static function postBooking(object $tenant, User $user, string $year, string $facility, array $overrides = []): TestResponse
    {
        return self::send($tenant, $user, 'POST', '/facility-bookings', self::bookingBody($year, $facility, $overrides));
    }

    /** Alta de sesión por la API. */
    public static function postSession(object $tenant, User $admin, string $slot, string $assignment, ?string $facility = null): TestResponse
    {
        return self::send($tenant, $admin, 'POST', '/timetable-sessions', array_filter([
            'time_slot' => $slot, 'teaching_assignment' => $assignment, 'facility' => $facility,
        ], static fn ($v): bool => $v !== null));
    }
}
