<?php

namespace Tests\Support;

use App\Models\AuditLog;
use App\Modules\Curso\Domain\AcademicYearStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * REQ-ACAD (1.11d-b): ayudantes de los tests HTTP de escalas de calificación.
 * Métodos estáticos de una clase para no repetir nombres entre ficheros
 * (CA-060-08).
 */
final class AcadScaleHelpers
{
    /** Centro con `acad` y un curso de prueba del 2026-09-01 al 2027-08-31 (`funcional.md §13.11`). */
    public static function setup(string $slug, AcademicYearStatus $status = AcademicYearStatus::Activo): array
    {
        return AcadApiHelpers::setup($slug, $status, '2027-08-31');
    }

    /**
     * Cuerpo válido de una numérica 0-10.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function numeric(string $year, array $overrides = []): array
    {
        return $overrides + [
            'academic_year' => $year,
            'code' => 'NUM10',
            'name_translations' => ['es-ES' => 'Numérica de 0 a 10'],
            'scale_type' => 'numerica',
            'min_value' => '0',
            'max_value' => 10,
            'decimals' => 2,
            'passing_min_value' => '5',
        ];
    }

    /**
     * Cuerpo válido de una cualitativa de cinco valores (suspenso solo en `IN`).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function qualitative(string $year, array $overrides = []): array
    {
        return $overrides + [
            'academic_year' => $year,
            'code' => 'CUAL',
            'name_translations' => ['es-ES' => 'Insuficiente a Sobresaliente'],
            'scale_type' => 'cualitativa',
            'values' => [
                self::value('IN', 'Insuficiente', false),
                self::value('SU', 'Suficiente', true),
                self::value('BI', 'Bien', true),
                self::value('NT', 'Notable', true),
                self::value('SB', 'Sobresaliente', true),
            ],
        ];
    }

    /**
     * Cuerpo válido de una mixta 0-10, un decimal, umbral 5, cuatro tramos.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function mixed(string $year, array $overrides = []): array
    {
        return $overrides + [
            'academic_year' => $year,
            'code' => 'MIX10',
            'name_translations' => ['es-ES' => 'De 0 a 10 con calificación'],
            'scale_type' => 'mixta',
            'min_value' => '0',
            'max_value' => '10',
            'decimals' => 1,
            'passing_min_value' => '5',
            'values' => [
                self::band('IN', 'Insuficiente', '0', '4.9'),
                self::band('SU', 'Suficiente', '5', '6.9'),
                self::band('NT', 'Notable', '7', '8.9'),
                self::band('SB', 'Sobresaliente', '9', '10'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function value(string $code, string $label, bool $passing): array
    {
        return ['code' => $code, 'label_translations' => ['es-ES' => $label], 'is_passing' => $passing];
    }

    /** @return array<string, mixed> */
    public static function band(string $code, string $label, string $min, string $max): array
    {
        return ['code' => $code, 'label_translations' => ['es-ES' => $label], 'range_min' => $min, 'range_max' => $max];
    }

    /** @param  array<string, mixed>  $body */
    public static function post(object $tenant, object $user, array $body): TestResponse
    {
        resetSessionState();

        return test()->actingAs($user)->postJson(AcadApiHelpers::url($tenant, '/grading-scales'), $body);
    }

    /** @param  array<string, mixed>  $body */
    public static function patch(object $tenant, object $user, string $publicId, array $body): TestResponse
    {
        resetSessionState();

        return test()->actingAs($user)->patchJson(AcadApiHelpers::url($tenant, "/grading-scales/{$publicId}"), $body);
    }

    public static function get(object $tenant, object $user, string $path): TestResponse
    {
        resetSessionState();

        return test()->actingAs($user)->getJson(AcadApiHelpers::url($tenant, $path));
    }

    public static function delete(object $tenant, object $user, string $publicId): TestResponse
    {
        resetSessionState();

        return test()->actingAs($user)->deleteJson(AcadApiHelpers::url($tenant, "/grading-scales/{$publicId}"));
    }

    /** Alta que debe responder 201; devuelve el cuerpo. @return array<string, mixed> */
    public static function create(object $tenant, object $user, array $body): array
    {
        return self::post($tenant, $user, $body)->assertCreated()->json();
    }

    /** Código del primer error de un campo cuyo nombre lleva puntos (`values.2.code`). */
    public static function errorCode(TestResponse $response, string $field): ?string
    {
        return $response->json('errors')[$field][0]['code'] ?? null;
    }

    public static function scales(object $tenant, bool $alive = true): int
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): int => DB::table('grading_scales')->when($alive, fn ($q) => $q->whereNull('deleted_at'))->count());
    }

    public static function values(object $tenant, bool $alive = true): int
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): int => DB::table('grading_scale_values')->when($alive, fn ($q) => $q->whereNull('deleted_at'))->count());
    }

    /** Entradas de `audit_logs` de un tipo auditable (y evento), dentro del contexto del centro. */
    public static function audit(object $tenant, string $type, ?string $event = null): Collection
    {
        return app(TenantContext::class)->runFor($tenant->id, fn () => AuditLog::query()
            ->where('auditable_type', $type)
            ->when($event !== null, fn ($q) => $q->where('event', $event))
            ->orderBy('id')
            ->get());
    }

    public static function auditCount(object $tenant): int
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): int => AuditLog::query()->whereIn('auditable_type', ['grading_scale', 'grading_scale_value'])->count());
    }

    /** Estado persistido de la escala y sus valores (todas las filas, borradas incluidas) para comparar «nada cambia». */
    public static function snapshot(object $tenant): array
    {
        return app(TenantContext::class)->runFor($tenant->id, fn (): array => [
            DB::table('grading_scales')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('grading_scale_values')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ]);
    }
}
