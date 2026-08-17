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
