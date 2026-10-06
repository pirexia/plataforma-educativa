<?php

namespace Tests;

/**
 * Variante de `TestCase` **sin** transacción envolvente: lo que el test
 * escribe se confirma de verdad, para que otras sesiones (procesos hijo)
 * lo vean. Solo para los tests de concurrencia real (issue #351); quien la
 * usa limpia lo que crea (`afterEach` borra el tenant y su cascada).
 */
abstract class ConcurrentTestCase extends TestCase
{
    protected $connectionsToTransact = [];
}
