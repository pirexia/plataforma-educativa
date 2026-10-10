<?php

// PHPUnit aplica <env force="true"> a $_ENV/putenv(), pero NO a $_SERVER.
// Laravel\Illuminate\Support\Env mira $_SERVER, así que una variable ya
// presente en el entorno real del proceso (aquí, las de apps/api/.env vía
// env_file en compose.yaml) gana de todas formas aunque force="true" esté
// puesto en phpunit.xml. Sin esto, la suite entera corría en silencio
// contra la base de datos de desarrollo real desde el paso 0.4, no contra
// la configuración de test declarada en phpunit.xml.
foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';

/*
 * ADR-060 §4.1.3: ejecución en paralelo. Paratest pone TEST_TOKEN (1..N) en
 * cada proceso hijo; cada uno usa su propia base `plataforma_test_N` y su
 * propia base Redis de caché. Sin TEST_TOKEN (ejecución en serie, `php
 * artisan test <ruta>`, tests/Concurrency) no cambia nada: phpunit.xml manda.
 *
 * Las bases las crea fuera de la suite
 * infra/containers/postgres/bases-test-paralelo.sh (con el superusuario del
 * clúster): el rol de la aplicación no puede CREATE DATABASE, y por eso se
 * desactiva el mecanismo propio de Laravel (ParallelTesting).
 */
(static function (): void {
    $token = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? getenv('TEST_TOKEN');

    if ($token === false || $token === null || $token === '') {
        return;
    }

    $abort = static function (string $message): never {
        fwrite(STDERR, "[bootstrap de tests · ADR-060] {$message}\n");

        exit(1);
    };

    $set = static function (string $key, string $value): void {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    };

    $database = 'plataforma_test_'.$token;

    // Guarda: un token raro nunca puede apuntar a una base que no sea de test.
    if (preg_match('/^plataforma_test_[0-9]+$/', $database) !== 1) {
        $abort("TEST_TOKEN='{$token}' no produce un nombre de base válido ('{$database}'): se exige ^plataforma_test_[0-9]+\$.");
    }

    $environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');

    if ($environment !== 'testing') {
        $abort('TEST_TOKEN presente pero APP_ENV no es "testing" ('.var_export($environment, true).').');
    }

    $set('DB_DATABASE', $database);
    $set('REDIS_CACHE_DB', (string) (2 + (int) $token));
    $set('LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES', '1');

    // Si PEST_PROCESOS supera las bases creadas, mensaje claro en vez del
    // error crudo de la primera conexión. Solo se aborta con "no existe" (3D000)
    // o "sin permiso de conexión" (42501); cualquier otro fallo lo da el test.
    $host = $_SERVER['DB_HOST'] ?? $_ENV['DB_HOST'] ?? getenv('DB_HOST');
    $user = $_SERVER['DB_USERNAME'] ?? $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME');

    if (is_string($host) && $host !== '' && is_string($user) && $user !== '' && extension_loaded('pdo_pgsql')) {
        $port = $_SERVER['DB_PORT'] ?? $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '5432';
        $password = $_SERVER['DB_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';

        try {
            new PDO("pgsql:host={$host};port={$port};dbname={$database}", $user, (string) $password, [
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            // pdo_pgsql suele dar el código libpq (7), no el SQLSTATE: se mira también el texto.
            if (in_array((string) $e->getCode(), ['3D000', '42501'], true)
                || str_contains($e->getMessage(), 'does not exist')
                || str_contains($e->getMessage(), 'permission denied for database')) {
                $abort("La base '{$database}' no existe o '{$user}' no puede conectarse a ella. Ejecuta infra/containers/postgres/bases-test-paralelo.sh con N >= {$token} (SYSADMIN.md §2b, «Bases de la suite en paralelo»).");
            }
        }
    }
})();
