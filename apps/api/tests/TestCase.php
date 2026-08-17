<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * ADR-033 §10: la suite corre sobre PostgreSQL real, no SQLite, porque RLS
 * es la barrera primaria y SQLite no la tiene.
 *
 * Migraciones y consultas van por conexiones distintas a propósito
 * (ADR-033 §5): las migraciones necesitan CREATE, que plataforma_app no
 * tiene; las consultas de los tests tienen que pasar por plataforma_app
 * porque es el rol que usa la API en producción — si un test corriera como
 * plataforma_owner daría verde aunque faltara un GRANT que plataforma_app
 * necesita de verdad.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /**
     * Deliberadamente solo pgsql (plataforma_app): es la conexión con la
     * que trabaja el código de negocio real y la que tiene que quedar
     * limpia entre tests. pgsql_owner (migraciones) y pgsql_platform
     * (Tenant, backoffice) son sesiones aparte a propósito (ADR-033 §5) —
     * envolverlas también en esta transacción las dejaría invisibles para
     * pgsql dentro del mismo test: PostgreSQL no deja ver escrituras sin
     * comprometer entre sesiones distintas, aunque ambas estén "en curso".
     * Los tests que crean datos por pgsql_platform (p. ej. Tenant) son
     * responsables de limpiar lo que comprometan de verdad; ver
     * tests/Feature/Tenancy/TenantTest.php.
     */
    protected $connectionsToTransact = ['pgsql'];

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$migrated) {
            $this->artisan('migrate', ['--database' => 'pgsql_owner', '--force' => true])->run();
            self::$migrated = true;
        }
    }
}
