<?php

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * Conexiones PDO propias, sin contenedor de Laravel, para los `afterAll()`
 * de los ficheros de test (ADR-060 §4.2.2).
 *
 * Un `afterAll()` corre después de que el último test del fichero haya
 * destruido la aplicación (`tearDown()`): `DB::connection()` ya no existe
 * ("Target class [config] does not exist") y Collision convierte ese error
 * en un `TypeError` (`AfterLastTestMethodErrored`) que aborta la suite
 * entera. Con paratest, que ejecuta cada fichero en el proceso que le toca
 * y destruye la aplicación al terminarlo, ocurre siempre; los dos
 * `afterAll()` del repositorio llevaban así desde que existen.
 *
 * Los datos de conexión salen del entorno del proceso, el mismo que usa
 * `config/database.php` (incluida la base `plataforma_test_N` que fija
 * `tests/bootstrap.php`).
 */
final class StandaloneDatabase
{
    public static function owner(): PDO
    {
        return self::connect('DB_OWNER_USERNAME', 'plataforma_owner', 'DB_OWNER_PASSWORD');
    }

    public static function platform(): PDO
    {
        return self::connect('DB_PLATFORM_USERNAME', 'plataforma_platform', 'DB_PLATFORM_PASSWORD');
    }

    private static function connect(string $userKey, string $userDefault, string $passwordKey): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            self::env('DB_HOST', '127.0.0.1'),
            self::env('DB_PORT', '5432'),
            self::testDatabase(),
            self::env('DB_SSLMODE', 'prefer'),
        );

        return new PDO($dsn, self::env($userKey, $userDefault), self::env($passwordKey, ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * security-reviewer, ADR-060, B-1: sin caída a una base que no sea de
     * test.
     */
    private static function testDatabase(): string
    {
        $database = self::env('DB_DATABASE', '');

        if (preg_match('/^plataforma_test(_[0-9]+)?$/', $database) !== 1) {
            throw new RuntimeException("StandaloneDatabase solo trabaja sobre bases de test; DB_DATABASE='{$database}'.");
        }

        return $database;
    }

    private static function env(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
