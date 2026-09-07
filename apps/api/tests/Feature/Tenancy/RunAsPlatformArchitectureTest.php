<?php

// REQ-PERM/funcional.md §11, ADR-044 §11.4, issue #6 punto 1 (resuelto por
// este paso: los puntos 2 y 3 quedan reetiquetados a 1.6). Mismo mecanismo
// que `IsolationBatteryTest` test #9 para `withoutGlobalScope` — no lo
// reinventa: recorre `apps/api/app/` a mano y falla si `runAsPlatform`
// aparece en CÓDIGO (nunca en un comentario, donde el propio mecanismo se
// explica en prosa varias veces — `AuditActor`, `AuditRecorder`,
// `BelongsToTenant`) fuera de una lista de excepciones enumerada fichero a
// fichero, verificada una a una (funcional.md §11 punto 2), no por patrón
// de carpeta.
//
// CA-PERM-092.

/**
 * ¿Este fichero **usa** `runAsPlatform()` — lo llama o lo declara — fuera
 * de comentarios y de cadenas de texto? Vía el tokenizador real de PHP, no
 * una heurística de texto plano. Necesario porque este mecanismo se
 * documenta en prosa (comentarios) y en un mensaje de excepción (cadena)
 * en ficheros que no lo usan — `AuditActor`, `BelongsToTenant` lo
 * mencionan al explicar por qué existe; `AuditRecorder::record()` cita
 * `TenantContext::runAsPlatform()` **dentro del texto** de una
 * `RuntimeException` para explicar por qué él mismo se niega a escribir en
 * modo plataforma. Ninguno de los tres es una excepción real que declarar
 * en la lista: ninguno invoca el método.
 */
function usesRunAsPlatform(string $path): bool
{
    $tokens = token_get_all(file_get_contents($path));

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'runAsPlatform') {
            continue;
        }

        // Busca hacia atrás el token significativo anterior (saltando
        // espacios en blanco y comentarios): una llamada real es
        // `->runAsPlatform(` o una declaración `function runAsPlatform(`.
        for ($j = $i - 1; $j >= 0; $j--) {
            $previous = $tokens[$j];

            if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $isCall = is_array($previous) && $previous[0] === T_OBJECT_OPERATOR;
            $isDeclaration = is_array($previous) && $previous[0] === T_FUNCTION;

            if ($isCall || $isDeclaration) {
                return true;
            }

            break;
        }
    }

    return false;
}

test('CA-PERM-092: runAsPlatform() no aparece en código de app/ fuera de su lista de excepciones', function (): void {
    // Los tres usos legítimos, verificados uno a uno el 2026-09-04
    // (funcional.md §11):
    //   - TenantContext.php: la propia definición del método.
    //   - RunsPerTenant.php: listar los tenants activos para comandos por
    //     tenant — el uso que el propio issue #6 reconoce.
    //   - PurgeExpiredIdempotencyKeys.php: purga de mantenimiento sin
    //     sujeto y sin salida de datos, desde código de módulo — el
    //     patrón que el issue temía, admitido explícitamente aquí.
    $allowlist = [
        base_path('app/Support/Tenancy/TenantContext.php'),
        base_path('app/Support/Tenancy/RunsPerTenant.php'),
        base_path('app/Modules/Core/Infrastructure/Jobs/PurgeExpiredIdempotencyKeys.php'),
    ];

    $appPath = base_path('app');
    $checked = 0;
    $foundOutsideAllowlist = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $checked++;
        $usesIt = usesRunAsPlatform($path);

        if (in_array($path, $allowlist, true)) {
            expect($usesIt)->toBeTrue("excepción declarada pero no usada en código real en {$path} — retirar de la lista");

            continue;
        }

        if ($usesIt) {
            $foundOutsideAllowlist[] = $path;
        }
    }

    expect($checked)->toBeGreaterThan(0);
    expect($foundOutsideAllowlist)->toBe([], 'runAsPlatform() encontrado en código fuera de la lista de excepciones: '.implode(', ', $foundOutsideAllowlist));
});
