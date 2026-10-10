<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Variante de `TestCase` **sin** transacción envolvente: lo que el test
 * escribe se confirma de verdad, para que otras sesiones (procesos hijo)
 * lo vean. Solo para los tests de concurrencia real (issue #351); quien la
 * usa limpia lo que crea (`afterEach` borra el tenant y su cascada).
 */
abstract class ConcurrentTestCase extends TestCase
{
    protected $connectionsToTransact = [];

    /**
     * Esta clase confirma datos de verdad y su `afterEach` borra TODOS los
     * tenants con un rol que se salta RLS: nunca debe correr contra una base
     * que no sea la de test (issue #360).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->selectOne('select current_database() as name')->name;

        if (! $this->app->environment('testing') || $database !== 'plataforma_test') {
            throw new RuntimeException("ConcurrentTestCase aborta: entorno «{$this->app->environment()}» y base «{$database}», se esperaba «testing» y «plataforma_test».");
        }
    }
}
