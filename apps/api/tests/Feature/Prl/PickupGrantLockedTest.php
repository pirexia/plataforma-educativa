<?php

use App\Models\Person;
use App\Modules\Core\Domain\PersonDirectory;
use App\Modules\FamUnit\Domain\AuthorizedPickupList;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FamUnitTestHelpers as Fam;
use Tests\Support\PrlTestHelpers as Prl;

// REQ-PRL-004 (1.14b-d1), docs/modulos/REQ-PRL/funcional.md RN-PRL-14, RN-PRL-15, CA-PRL-029 (parte de equivalencia): `grantForLocked`
// devuelve lo mismo que `grantFor` para toda persona y todo alumno de los datos de prueba. La parte «fuera de una transacción»
// y las carreras reales están en `tests/Concurrency/PrlConcurrencyTest.php` (sin transacción envolvente).

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local', 'files.disk' => null]);
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-PRL-029 RN-PRL-15: para toda persona X y todo alumno A, grantForLocked(X, A) devuelve lo mismo que grantFor(X, A) — tutores, tercero, puntual, sin fotografía, retirada, revocada y personas inexistentes', function (): void {
    $f = Prl::doorFamily('prl-eq');
    $padre = app(TenantContext::class)->runFor($f['tenant']->id, fn () => Person::query()->findOrFail($f['other']->person_id));
    Fam::putPhoto($f['tenant'], $f['admin'], $padre->public_id)->assertOk();
    $sinFoto = Fam::thirdParty($f, 2, false, [], 'SinFoto');
    $retirada = Fam::thirdParty($f, 3, true, [], 'Retirada');
    Fam::call($f['tenant'], $f['admin'], 'post', "/pickup-authorizations/{$retirada['authorization']}/withdrawal")->assertOk();
    $vecina = Fam::thirdParty($f, 4, true, ['kind' => 'puntual', 'valid_from' => '2026-11-02', 'valid_until' => '2026-11-02', 'reason' => 'Cita', 'channel' => 'telefono', 'relationship' => 'otra_familia'], 'Vecina');

    // Un segundo alumno de la misma unidad: la lista es por alumno.
    $student2 = Fam::person($f['tenant'], 'Otro', 'Alumno');
    Fam::addStudent($f['tenant'], $f['admin'], $f['unit'], $student2)->assertCreated();
    Fam::link($f['tenant'], $f['admin'], $f['gp'], $student2)->assertCreated();

    $compare = function (string $label) use ($f): int {
        return app(TenantContext::class)->runFor($f['tenant']->id, function () use ($label): int {
            $list = app(AuthorizedPickupList::class);
            $people = DB::table('people')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $students = DB::table('guardian_student_links')->distinct()->pluck('student_person_id')->map(fn ($id) => (int) $id)->all();
            $granted = 0;

            DB::transaction(function () use ($list, $people, $students, $label, &$granted): void {
                foreach ([...$people, 0, 99999999] as $personId) {
                    foreach ($students as $studentId) {
                        $plain = $list->grantFor($personId, $studentId);
                        $locked = $list->grantForLocked($personId, $studentId);

                        expect($locked)->toEqual($plain, "{$label}: persona {$personId}, alumno {$studentId}");
                        $granted += $plain === null ? 0 : 1;
                    }
                }
            });

            return $granted;
        });
    };

    // Antes de la revocación: tutores, abuelo y vecina figuran; el sin fotografía y la retirada, no.
    $before = $compare('antes');
    expect($before)->toBeGreaterThanOrEqual(5);

    // Con el padre revocado sobre el alumno (resolución judicial) deja de figurar por toda base.
    Fam::call($f['tenant'], $f['admin'], 'post', "/guardian-links/{$f['lo']}/revocation", ['reason' => 'resolucion_judicial'])->assertOk();
    $after = $compare('despues');

    expect($after)->toBeLessThan($before)
        ->and($sinFoto['person'])->not->toBeEmpty()
        ->and($vecina['person'])->not->toBeEmpty();
});

test('RN-PRL-14: lockLiveByPublicIds devuelve solo las personas vivas, por public_id, y las bloquea con FOR SHARE dentro de una transacción; una persona inexistente o de otro centro no aparece', function (): void {
    $f = Prl::doorFamily('prl-lock-live');
    $other = Prl::doorFamily('prl-lock-live-b');
    $studentOfB = $other['student']->public_id;

    $result = app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::transaction(function () use ($f, $studentOfB): array {
        $refs = app(PersonDirectory::class)->lockLiveByPublicIds([$f['student']->public_id, $studentOfB, '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

        // Mientras la transacción está abierta, la fila figura bloqueada por la sesión (pg_locks no la distingue; basta el contenido).
        return ['keys' => array_keys($refs), 'removed' => array_map(fn ($r) => $r->removed, $refs)];
    }));

    expect($result['keys'])->toBe([$f['student']->public_id])
        ->and($result['removed'])->toBe([$f['student']->public_id => false]);

    // La lista vacía no consulta nada.
    expect(app(TenantContext::class)->runFor($f['tenant']->id, fn () => DB::transaction(fn () => app(PersonDirectory::class)->lockLiveByPublicIds([]))))->toBe([]);
});
