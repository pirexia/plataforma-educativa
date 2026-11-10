<?php

use App\Modules\Acad\Domain\StructureDeletionVeto;
use App\Modules\Acad\Domain\StructureDeletionVetoRegistry;
use App\Modules\Acad\Domain\StructureEntity;
use App\Modules\Acad\Domain\VetoFailure;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcadApiHelpers as Api;

// RN-ACAD-21 (vetos de borrado de otros módulos), RN-ALUM-26, CA-ALUM-027,
// INV-007, INV-015. El doble de test hace de `alum` (T1b registra el real).

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** Veto de prueba: bloquea mientras `$blocked` contenga la entidad; cuenta las veces que se ejecuta. */
function vetoDouble(string $module, StructureEntity $entity, bool $blocks = true): object
{
    return new class($module, $entity, $blocks) implements StructureDeletionVeto
    {
        public int $calls = 0;

        public function __construct(private string $module, private StructureEntity $entity, private bool $blocks) {}

        public function moduleCode(): string
        {
            return $this->module;
        }

        public function appliesTo(): StructureEntity
        {
            return $this->entity;
        }

        public function check(int $internalId): ?VetoFailure
        {
            $this->calls++;

            return $this->blocks ? new VetoFailure('enrollments', 3) : null;
        }
    };
}

function vetoDelete(object $tenant, object $admin, string $path)
{
    resetSessionState();

    return test()->actingAs($admin)->deleteJson(Api::url($tenant, $path));
}

test('RN-ACAD-21: el registro de vetos de borrado es un singleton, vacío por defecto', function (): void {
    $registry = app(StructureDeletionVetoRegistry::class);

    expect($registry->all())->toBe([])
        ->and(app(StructureDeletionVetoRegistry::class))->toBe($registry);

    $registry->register(vetoDouble('alum', StructureEntity::Group));

    expect($registry->all())->toHaveCount(1);
});

test('CA-ALUM-027 RN-ALUM-26 RN-ACAD-21: un veto de curso de etapa bloquea su borrado con enrollments y no el del grupo; son independientes', function (): void {
    [$tenant, $admin, $year] = Api::setup('veto-course');
    contractModule($tenant, 'alum');
    $tree = Api::tree($tenant, $admin, $year);
    $veto = vetoDouble('alum', StructureEntity::Course);
    app(StructureDeletionVetoRegistry::class)->register($veto);

    // El grupo no tiene veto propio (y sus subgrupos lo bloquean antes: se quitan).
    vetoDelete($tenant, $admin, "/subgroups/{$tree['subgroup']}")->assertNoContent();
    vetoDelete($tenant, $admin, "/subjects/{$tree['subject']}")->assertNoContent();
    vetoDelete($tenant, $admin, "/groups/{$tree['group']}")->assertNoContent();
    expect($veto->calls)->toBe(0);

    $response = vetoDelete($tenant, $admin, "/courses/{$tree['course']}")->assertStatus(409);

    expect($response->json('errors.dependents.0.code'))->toBe('acad.conflict.has_dependents')
        ->and($response->json('errors.dependents.0.params.dependents'))->toBe(['enrollments'])
        ->and($response->json('errors.dependents.0.message'))->toContain('matrículas')
        ->and($veto->calls)->toBe(1)
        ->and(Api::row($tenant, 'courses', $tree['course'])->deleted_at)->toBeNull();
});

test('CA-ALUM-027 RN-ALUM-26 RN-ACAD-21: un veto de grupo se fusiona con los dependientes de acad y bloquea el borrado del grupo, no el del curso', function (): void {
    [$tenant, $admin, $year] = Api::setup('veto-group');
    contractModule($tenant, 'alum');
    $tree = Api::tree($tenant, $admin, $year);
    app(StructureDeletionVetoRegistry::class)->register(vetoDouble('alum', StructureEntity::Group));

    // Con subgrupo vivo: dependientes propios primero, el del veto al final.
    expect(vetoDelete($tenant, $admin, "/groups/{$tree['group']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))
        ->toBe(['subgroups', 'enrollments']);

    vetoDelete($tenant, $admin, "/subgroups/{$tree['subgroup']}")->assertNoContent();
    expect(vetoDelete($tenant, $admin, "/groups/{$tree['group']}")->assertStatus(409)->json('errors.dependents.0.params.dependents'))
        ->toBe(['enrollments'])
        ->and(Api::row($tenant, 'groups', $tree['group'])->deleted_at)->toBeNull();
});

test('RN-ACAD-21 RN-CURSO-60: el veto de un módulo no contratado en el centro no se ejecuta', function (): void {
    [$tenant, $admin, $year] = Api::setup('veto-module');
    $tree = Api::tree($tenant, $admin, $year);
    $veto = vetoDouble('alum', StructureEntity::Group);
    app(StructureDeletionVetoRegistry::class)->register($veto);

    vetoDelete($tenant, $admin, "/subgroups/{$tree['subgroup']}")->assertNoContent();
    vetoDelete($tenant, $admin, "/groups/{$tree['group']}")->assertNoContent();

    expect($veto->calls)->toBe(0)
        ->and(Api::row($tenant, 'groups', $tree['group'])->deleted_at)->not->toBeNull();
});

test('RN-ACAD-21: sin fallo en el veto (matrículas inexistentes) el borrado procede', function (): void {
    [$tenant, $admin, $year] = Api::setup('veto-free');
    contractModule($tenant, 'alum');
    $tree = Api::tree($tenant, $admin, $year);
    $groupVeto = vetoDouble('alum', StructureEntity::Group, blocks: false);
    $courseVeto = vetoDouble('alum', StructureEntity::Course, blocks: false);
    app(StructureDeletionVetoRegistry::class)->register($groupVeto);
    app(StructureDeletionVetoRegistry::class)->register($courseVeto);

    vetoDelete($tenant, $admin, "/subgroups/{$tree['subgroup']}")->assertNoContent();
    vetoDelete($tenant, $admin, "/subjects/{$tree['subject']}")->assertNoContent();
    vetoDelete($tenant, $admin, "/groups/{$tree['group']}")->assertNoContent();
    vetoDelete($tenant, $admin, "/courses/{$tree['course']}")->assertNoContent();

    expect($groupVeto->calls)->toBe(1)->and($courseVeto->calls)->toBe(1);
});
