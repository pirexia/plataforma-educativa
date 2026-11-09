<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AlumTestHelpers as Alum;

// REQ-ALUM-001 (1.15-a T2), CA-ALUM-063, api.md (cabecera), RMOD-009, AR-07a: con `alum` descontratado, toda ruta del
// módulo responde 403 `urn:pge:error:module-disabled`; sin sesión, 401 aunque el módulo no esté contratado.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-ALUM-063: con alum descontratado, las seis rutas de alumnado responden 403 module-disabled y no tocan los datos; sin sesión, 401', function (): void {
    [$tenant, $admin] = Alum::setup('alum-063');
    $student = Alum::student($tenant, $admin);
    $id = $student['public_id'];

    Alum::disableModule($tenant);

    foreach ([
        ['get', '/students'], ['post', '/students'], ['get', "/students/{$id}"], ['patch', "/students/{$id}"],
        ['delete', "/students/{$id}"], ['post', "/students/{$id}/restore"],
    ] as [$method, $path]) {
        Alum::call($tenant, $admin, $method, $path, Alum::body(), ['Idempotency-Key' => (string) Str::ulid()])
            ->assertForbidden()
            ->assertJsonPath('type', 'urn:pge:error:module-disabled');

        resetSessionState();
        test()->json(strtoupper($method), coreApiUrl($tenant->slug, $path))->assertUnauthorized();
    }

    expect(Alum::row($tenant, 'students', $id)->deleted_at)->toBeNull()
        ->and(Alum::count($tenant, 'students'))->toBe(1);
});
