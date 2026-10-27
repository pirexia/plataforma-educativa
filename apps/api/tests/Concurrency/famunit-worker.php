<?php

/**
 * Proceso hijo de `tests/Concurrency/FamUnitConcurrencyTest.php` (REQ-FAM-UNIT, 1.14-a y 1.14-b; CA-FAM-UNIT-022,
 * RN-FAM-UNIT-01, RN-FAM-UNIT-11, RN-FAM-UNIT-30, CA-FAM-UNIT-058, CA-FAM-UNIT-099). No es un test: arranca la aplicación en su propio proceso y, por tanto, en su propia
 * conexión a PostgreSQL, y ejecuta UNA operación llamando al mismo servicio que la API. Imprime una línea JSON.
 *
 * Uso: php famunit-worker.php <tenantId> <operación> [argumentos…]
 *   create-unit <code|->                                  (`-` = código generado por el sistema)
 *   create-guardian <userPublicId> <unitPublicId>
 *   create-link <guardianPublicId> <studentPersonPublicId> <emergency 0|1>
 *   record-decision <userPublicId> <studentPersonPublicId> <level> <decision>   (1.14-b, CA-FAM-UNIT-058: el propio tutor, canal portal)
 *   publish-text <termsVersionPublicId>                                          (1.14-b, CA-FAM-UNIT-099: publicar un borrador)
 */

use App\Models\User;
use App\Modules\FamUnit\Application\FamilyUnitService;
use App\Modules\FamUnit\Application\GuardianLinkService;
use App\Modules\FamUnit\Application\GuardianService;
use App\Modules\FamUnit\Application\ImageConsentService;
use App\Modules\FamUnit\Application\TermsVersionService;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditActor;
use App\Support\Authorization\PermissionResolver;
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
    fwrite(STDERR, "famunit-worker.php aborta: entorno «{$app->environment()}» y base «{$database}», se esperaba «testing» y «plataforma_test».\n");
    exit(2);
}

$result = app(TenantContext::class)->runFor((int) $tenantId, function () use ($operation, $args): array {
    try {
        return AuditActor::actingAs('console', function () use ($operation, $args): array {
            $actor = static fn (): User => User::query()->where('email', 'admin@example.com')->firstOrFail();

            switch ($operation) {
                case 'create-unit':
                    $unit = app(FamilyUnitService::class)->create($args[0] === '-' ? null : $args[0]);

                    return ['outcome' => 'ok', 'public_id' => $unit->public_id, 'code' => $unit->code];

                case 'create-guardian':
                    $guardian = app(GuardianService::class)->create($args[0], $args[1]);

                    return ['outcome' => 'ok', 'public_id' => $guardian->public_id];

                case 'create-link':
                    $link = app(GuardianLinkService::class)->create([
                        'guardian' => $args[0],
                        'student_person' => $args[1],
                        'relationship' => 'madre',
                        'is_legal_guardian' => false,
                        'access_level' => 'lectura',
                        'restricted_categories' => [],
                        'is_emergency_contact' => $args[2] === '1',
                    ], $actor());

                    return ['outcome' => 'ok', 'public_id' => $link->public_id];

                case 'record-decision':
                    $user = User::query()->where('public_id', $args[0])->firstOrFail();
                    $decision = app(PermissionResolver::class)->decide($user, 'consentimiento_imagen.actualizar');
                    $recorded = app(ImageConsentService::class)->record(
                        ['student_person' => $args[1], 'level' => $args[2], 'decision' => $args[3], 'on_behalf_of' => null, 'channel' => null, 'consent_text_version' => null],
                        $user,
                        $decision,
                        ['ip' => '127.0.0.1', 'user_agent' => 'famunit-worker'],
                    );

                    return ['outcome' => 'ok', 'public_id' => $recorded['decision']->public_id, 'state' => $recorded['state']['state']];

                case 'publish-text':
                    $version = app(TermsVersionService::class)->publish($args[0], false);

                    return ['outcome' => 'ok', 'public_id' => $version->public_id, 'status' => $version->status];
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
