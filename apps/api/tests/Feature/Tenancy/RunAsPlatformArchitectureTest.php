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
 *
 * Hallazgo de `security-reviewer` (issue #167, 2026-09-07): la primera
 * versión solo reconocía `->runAsPlatform(` (T_OBJECT_OPERATOR), dejando
 * pasar sin detectar dos formas reales de invocarlo: el operador nullsafe
 * (`$context?->runAsPlatform(...)`, T_NULLSAFE_OBJECT_OPERATOR — un token
 * distinto de `->` desde PHP 8) y la llamada dinámica por nombre entre
 * llaves (`$context->{'runAsPlatform'}(...)`), donde el nombre del método
 * es una cadena, no un T_STRING. Ambas cubiertas ahora.
 */
function usesRunAsPlatform(string $path): bool
{
    $tokens = token_get_all(file_get_contents($path));

    $tokenId = static fn (mixed $t): int|string => is_array($t) ? $t[0] : $t;
    $tokenText = static fn (mixed $t): string => is_array($t) ? $t[1] : $t;

    $isInsignificant = static fn (mixed $t): bool => is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);

    // Devuelve el ÍNDICE del token significativo anterior, o null — nunca
    // el valor, porque un token de un solo carácter (`{`) es una cadena
    // plana en `token_get_all()`, no un array, y no se puede reencontrar
    // de forma fiable con `array_search()` sobre el array de tokens.
    $previousSignificantIndex = static function (array $tokens, int $from) use ($isInsignificant): ?int {
        for ($j = $from; $j >= 0; $j--) {
            if (! $isInsignificant($tokens[$j])) {
                return $j;
            }
        }

        return null;
    };

    $isAccessOperator = static fn (mixed $t): bool => $t !== null && in_array($tokenId($t), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);

    foreach ($tokens as $i => $token) {
        // Forma directa: `->runAsPlatform(`, `?->runAsPlatform(`, o la
        // declaración `function runAsPlatform(`.
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'runAsPlatform') {
            $previousIndex = $previousSignificantIndex($tokens, $i - 1);
            $previous = $previousIndex === null ? null : $tokens[$previousIndex];
            $isCall = $isAccessOperator($previous);
            $isDeclaration = $previous !== null && $tokenId($previous) === T_FUNCTION;

            if ($isCall || $isDeclaration) {
                return true;
            }
        }

        // Forma dinámica: `->{'runAsPlatform'}(` o `?->{'runAsPlatform'}(`
        // — el nombre viaja como cadena entre llaves, no como identificador.
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $name = substr($token[1], 1, -1);

            if ($name !== 'runAsPlatform') {
                continue;
            }

            $braceIndex = $previousSignificantIndex($tokens, $i - 1);

            if ($braceIndex === null || $tokenText($tokens[$braceIndex]) !== '{') {
                continue;
            }

            $operatorIndex = $previousSignificantIndex($tokens, $braceIndex - 1);
            $operator = $operatorIndex === null ? null : $tokens[$operatorIndex];

            if ($isAccessOperator($operator)) {
                return true;
            }
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
