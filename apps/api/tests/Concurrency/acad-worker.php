<?php

/**
 * Proceso hijo de `tests/Concurrency/AcadConcurrencyTest.php` (REQ-ACAD,
 * 1.11-b; CA-ACAD-004). No es un test: arranca la aplicación en su propio
 * proceso y, por tanto, en su propia conexión a PostgreSQL, y ejecuta UNA
 * operación llamando al mismo servicio que la API. Imprime una línea JSON.
 *
 * Uso: php acad-worker.php <tenantId> <operación> [argumentos…]
 *   create-group <coursePublicId> <code>
 *
 * 1.11d-a (períodos lectivos, CA-ACAD-069, CA-CURSO-115, -120, -122): cada
 * operación llama al mismo servicio que la API. `<gate>` es una clave de
 * bloqueo consultivo (`-` si no hay): el hijo espera ese bloqueo, que retiene
 * el test, justo antes de ESCRIBIR (evento `creating`/`updating`/`deleting`
 * del modelo), es decir, con la etapa y el curso ya bloqueados y las reglas ya
 * validadas, y lo suelta en cuanto el test lo libera.
 *   create-period <levelPublicId> <startsOn> <endsOn> <type|-> <gate|->
 *   update-period <periodPublicId> <startsOn|-> <endsOn|-> <gate|->
 *   delete-period <periodPublicId> <gate|->
 *   delete-level  <levelPublicId> <gate|->
 *   create-course <levelPublicId> <code> <gate|->
 *   patch-year    <yearPublicId> <startsOn|-> <endsOn|-> <gate|->   (PATCH de fechas con la
 *                 validación REAL de acad; con gate, una validación de prueba posterior a la
 *                 de acad espera el bloqueo con el FOR UPDATE del curso retenido)
 */

use App\Models\User;
use App\Modules\Acad\Application\AcademicPeriodService;
use App\Modules\Acad\Application\CourseService;
use App\Modules\Acad\Application\EducationLevelService;
use App\Modules\Acad\Application\GroupService;
use App\Modules\Acad\Domain\Models\AcademicPeriod;
use App\Modules\Acad\Domain\Models\Course;
use App\Modules\Acad\Domain\Models\EducationLevel;
use App\Modules\Curso\Application\AcademicYearAdministration;
use App\Modules\Curso\Domain\AcademicYearDateChange;
use App\Modules\Curso\Domain\AcademicYearDateChangeCheck;
use App\Modules\Curso\Domain\AcademicYearDateChangeFailure;
use App\Modules\Curso\Domain\AcademicYearDateChangeRegistry;
use App\Modules\Curso\Domain\Models\AcademicYear;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

[, $tenantId, $operation] = $argv;
$args = array_slice($argv, 3);

foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Escribe de verdad: solo contra la base de test.
$database = DB::connection()->selectOne('select current_database() as name')->name;

if (! $app->environment('testing') || $database !== 'plataforma_test') {
    fwrite(STDERR, "acad-worker.php aborta: entorno «{$app->environment()}» y base «{$database}», se esperaba «testing» y «plataforma_test».\n");
    exit(2);
}

$result = app(TenantContext::class)->runFor((int) $tenantId, function () use ($operation, $args): array {
    try {
        return AuditActor::actingAs('console', function () use ($operation, $args): array {
            // Espera (y suelta) el bloqueo consultivo que retiene el test; `-` = sin puerta.
            $wait = static function (string $gate): void {
                if ($gate !== '-') {
                    DB::select('select pg_advisory_lock(?)', [(int) $gate]);
                    DB::select('select pg_advisory_unlock(?)', [(int) $gate]);
                }
            };
            $actor = static fn (): User => User::query()->where('email', 'admin@example.com')->firstOrFail();

            switch ($operation) {
                case 'create-period':
                    [$level, $startsOn, $endsOn, $type, $gate] = $args;
                    AcademicPeriod::creating(static function () use ($wait, $gate): void {
                        $wait($gate);
                    });
                    $period = app(AcademicPeriodService::class)->create([
                        'education_level' => $level,
                        'period_type' => $type === '-' ? 'trimestre' : $type,
                        'name_translations' => ['es-ES' => "Período {$startsOn}"],
                        'starts_on' => $startsOn,
                        'ends_on' => $endsOn,
                        'position' => null,
                    ], $actor());

                    return ['outcome' => 'ok', 'public_id' => $period->public_id];

                case 'update-period':
                    [$publicId, $startsOn, $endsOn, $gate] = $args;
                    AcademicPeriod::updating(static function () use ($wait, $gate): void {
                        $wait($gate);
                    });
                    $input = array_filter(['starts_on' => $startsOn, 'ends_on' => $endsOn], static fn (string $v): bool => $v !== '-');
                    app(AcademicPeriodService::class)->update($publicId, $input, $actor());

                    return ['outcome' => 'ok'];

                case 'delete-period':
                    [$publicId, $gate] = $args;
                    AcademicPeriod::deleting(static function () use ($wait, $gate): void {
                        $wait($gate);
                    });
                    app(AcademicPeriodService::class)->delete($publicId, $actor());

                    return ['outcome' => 'ok'];

                case 'delete-level':
                    [$publicId, $gate] = $args;
                    EducationLevel::deleting(static function () use ($wait, $gate): void {
                        $wait($gate);
                    });
                    app(EducationLevelService::class)->delete($publicId, $actor());

                    return ['outcome' => 'ok'];

                case 'create-course':
                    [$level, $code, $gate] = $args;
                    Course::creating(static function () use ($wait, $gate): void {
                        $wait($gate);
                    });
                    $course = app(CourseService::class)->create(['education_level' => $level, 'code' => $code, 'name_translations' => ['es-ES' => "Curso {$code}"], 'position' => null], $actor());

                    return ['outcome' => 'ok', 'public_id' => $course->public_id];

                case 'patch-year':
                    [$publicId, $startsOn, $endsOn, $gate] = $args;

                    if ($gate !== '-') {
                        // Después de la validación real de `acad` (registrada en el arranque), con el FOR UPDATE del curso retenido.
                        app(AcademicYearDateChangeRegistry::class)->register(new class($gate, $wait) implements AcademicYearDateChangeCheck
                        {
                            public function __construct(private readonly string $gate, private readonly Closure $wait) {}

                            public function moduleCode(): string
                            {
                                return 'curso';
                            }

                            public function check(AcademicYearDateChange $change): ?AcademicYearDateChangeFailure
                            {
                                ($this->wait)($this->gate);

                                return null;
                            }
                        });
                    }

                    $input = array_filter(['starts_on' => $startsOn, 'ends_on' => $endsOn], static fn (string $v): bool => $v !== '-');
                    app(AcademicYearAdministration::class)->update(AcademicYear::query()->where('public_id', $publicId)->firstOrFail(), $input);

                    return ['outcome' => 'ok'];
            }

            if ($operation === 'create-group') {
                $actor = User::query()->where('email', 'admin@example.com')->firstOrFail();
                $group = app(GroupService::class)->create(['course' => $args[0], 'code' => $args[1], 'name' => 'Grupo '.$args[1], 'position' => null], $actor);

                return ['outcome' => 'ok', 'public_id' => $group->public_id];
            }

            return ['outcome' => 'unknown_operation'];
        });
    } catch (ApiException $e) {
        $first = collect($e->errors)->flatten(1)->first();

        return ['outcome' => 'api_error', 'status' => $e->status, 'code' => $first['code'] ?? $e->detailKey];
    } catch (QueryException $e) {
        return ['outcome' => 'sql_error', 'sqlstate' => $e->errorInfo[0] ?? null];
    }
});

echo json_encode($result), "\n";
