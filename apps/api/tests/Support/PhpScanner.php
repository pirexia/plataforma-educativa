<?php

namespace Tests\Support;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * ADR-056 §3.1/§3.2 punto 5, CA-056-14. Escáner de tokens ÚNICO y compartido
 * por las reglas de arquitectura que necesitan ver llamadas o literales
 * concretos (AR-08, AR-10). Trabaja sobre `PhpToken::tokenize()` — el
 * tokenizador real de PHP — y descarta espacios, comentarios y la etiqueta
 * de apertura: una mención en un comentario o dentro de una cadena nunca es
 * una llamada.
 *
 * Formas que reconoce, y las que no (límites declarados, no silenciosos):
 * - Llamada a método de instancia: `->m(`, `?->m(` (operador nullsafe, el
 *   agujero real de #167) y `->{'m'}(` / `?->{"m"}(` (nombre dinámico entre
 *   llaves como cadena constante).
 * - Declaración `function m(`.
 * - Llamada estática `Clase::m(` con la clase resuelta contra el
 *   `namespace` y los `use` del fichero (nombre sin cualificar, cualificado
 *   relativo a un `use`, o totalmente cualificado).
 * - Literal de cadena constante entre comillas simples o dobles SIN
 *   interpolación. NO ve cadenas con variables interpoladas ni heredocs.
 * - Desde 1.11-a (#414) también ve: `use` agrupado (`use A\{B, C as D};`),
 *   `namespace X { ... }` con llaves (cada bloque con sus `use`), concatenación
 *   estática de literales (`'pgsql_'.'owner'`), heredoc/nowdoc y los
 *   fragmentos de una cadena interpolada (`stringValues()`), `parent::m()`,
 *   `static::m()` y `self::m()` dentro de una clase que EXTIENDE la clase
 *   buscada, y los invocables por literal: `[Clase::class, 'm']`,
 *   `['Fqcn', 'm']` y `'Fqcn::m'` (con `call_user_func`, `array_map`…).
 *
 * Límites que ningún análisis estático supera, declarados (ADR-056 §3.4):
 * `$clase::m()` con la clase en una variable, `config($variable)` o un nombre
 * armado en tiempo de ejecución (`'pgsql_'.$sufijo`), la reflexión y el
 * `eval`. Eso lo cubre el motor, no el escáner (p. ej. `plataforma_app` no
 * puede crear objetos: `DatabaseCapabilitiesTest`).
 */
final class PhpScanner
{
    /**
     * Todos los ficheros `.php` bajo un directorio, ordenados.
     *
     * @return list<string>
     */
    public static function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Tokens significativos: sin espacios, comentarios ni etiqueta de apertura.
     *
     * @return list<PhpToken>
     */
    public static function tokens(string $source): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $t): bool => ! $t->isIgnorable() && ! $t->is(T_OPEN_TAG),
        ));
    }

    /**
     * ¿Hay una llamada de instancia `->$method(` en código real?
     */
    public static function callsMethod(string $source, string $method): bool
    {
        $tokens = self::tokens($source);

        foreach ($tokens as $i => $token) {
            if (! $token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            if ($name === null) {
                continue;
            }

            // Forma directa: `->m(` / `?->m(`
            if ($name->is(T_STRING) && $name->text === $method && self::isOpenParen($tokens[$i + 2] ?? null)) {
                return true;
            }

            // Forma dinámica: `->{'m'}(` / `?->{'m'}(`
            if ($name->text === '{'
                && ($literal = $tokens[$i + 2] ?? null) !== null
                && $literal->is(T_CONSTANT_ENCAPSED_STRING)
                && self::unquote($literal->text) === $method
                && ($tokens[$i + 3] ?? null)?->text === '}'
                && self::isOpenParen($tokens[$i + 4] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Declara el fichero `function $name(`?
     */
    public static function declaresFunction(string $source, string $name): bool
    {
        $tokens = self::tokens($source);

        foreach ($tokens as $i => $token) {
            if ($token->is(T_FUNCTION)
                && ($tokens[$i + 1] ?? null)?->is(T_STRING)
                && $tokens[$i + 1]->text === $name
                && self::isOpenParen($tokens[$i + 2] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valores (sin comillas) de todas las cadenas constantes del fichero.
     *
     * @return list<string>
     */
    public static function stringLiterals(string $source): array
    {
        $values = [];

        foreach (self::tokens($source) as $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $values[] = self::unquote($token->text);
            }
        }

        return $values;
    }

    public static function hasStringLiteral(string $source, string $literal): bool
    {
        return in_array($literal, self::stringLiterals($source), true);
    }

    /**
     * Todo texto constante que el fichero puede formar con literales (#414):
     * cada cadena constante, las **concatenaciones estáticas** de literales
     * (`'pgsql_'.'owner'` → `pgsql_owner`) y los cuerpos de heredoc/nowdoc y de
     * cadenas interpoladas (cada fragmento entre variables y todos unidos sin
     * las variables, que sobre-detecta a propósito). Para reglas de «no
     * menciones esta cadena»; `stringLiterals()` conserva su contrato exacto.
     * Límite: nada que dependa de una variable (`'pgsql_'.$x`).
     *
     * @return list<string>
     */
    public static function stringValues(string $source): array
    {
        $tokens = self::tokens($source);
        $count = count($tokens);
        $values = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $value = self::unquote($token->text);
                $values[] = $value;
                $j = $i;

                while (($tokens[$j + 1] ?? null)?->text === '.' && ($tokens[$j + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)) {
                    $value .= self::unquote($tokens[$j + 2]->text);
                    $j += 2;
                }

                if ($j > $i) {
                    $values[] = $value;
                }

                $i = $j;

                continue;
            }

            $closing = $token->is(T_START_HEREDOC) ? T_END_HEREDOC : ($token->text === '"' ? '"' : null);

            if ($closing === null) {
                continue;
            }

            $fragments = [];

            for ($j = $i + 1; $j < $count; $j++) {
                if ($closing === '"' ? $tokens[$j]->text === '"' : $tokens[$j]->is(T_END_HEREDOC)) {
                    break;
                }

                if ($tokens[$j]->is(T_ENCAPSED_AND_WHITESPACE)) {
                    $fragments[] = $tokens[$j]->text;
                }
            }

            foreach ($fragments as $fragment) {
                $values[] = $fragment;
            }

            if (count($fragments) > 1) {
                $values[] = implode('', $fragments);
            }

            $i = $j;
        }

        return $values;
    }

    /**
     * Nombres de los métodos llamados estáticamente sobre `$class`
     * (`Clase::m(`), con la referencia a la clase resuelta contra el
     * `namespace` y los `use` del fichero.
     *
     * @param  class-string|string  $class  FQCN sin barra inicial
     * @return list<string>
     */
    public static function staticCallsOn(string $source, string $class): array
    {
        $tokens = self::tokens($source);
        $analysis = self::analyze($tokens);
        $target = ltrim($class, '\\');
        $count = count($tokens);
        $methods = [];

        foreach ($tokens as $i => $token) {
            // `Clase::m(`, resuelta contra el `namespace` y los `use` vigentes en ese punto.
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC])
                && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)) {
                $method = $tokens[$i + 2] ?? null;

                if ($method !== null && $method->is(T_STRING) && self::isOpenParen($tokens[$i + 3] ?? null)) {
                    if (self::referencesTarget($token, $i, $analysis, $target)) {
                        $methods[] = $method->text;
                    }
                }

                continue;
            }

            // Invocable por literal: `[Clase::class, 'm']`.
            if ($token->text === '[' && $i + 6 < $count
                && $tokens[$i + 1]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && $tokens[$i + 2]->is(T_DOUBLE_COLON) && $tokens[$i + 3]->is(T_CLASS)
                && $tokens[$i + 4]->text === ',' && $tokens[$i + 5]->is(T_CONSTANT_ENCAPSED_STRING)
                && $tokens[$i + 6]->text === ']'
                && self::resolveAt($tokens[$i + 1], $i, $analysis) === $target) {
                $methods[] = self::unquote($tokens[$i + 5]->text);

                continue;
            }

            // Invocable por literal: `['Fqcn', 'm']`.
            if ($token->text === '[' && $i + 4 < $count
                && $tokens[$i + 1]->is(T_CONSTANT_ENCAPSED_STRING) && $tokens[$i + 2]->text === ','
                && $tokens[$i + 3]->is(T_CONSTANT_ENCAPSED_STRING) && $tokens[$i + 4]->text === ']'
                && self::normalizeClassString(self::unquote($tokens[$i + 1]->text)) === $target) {
                $methods[] = self::unquote($tokens[$i + 3]->text);

                continue;
            }

            // Invocable por cadena: `'Fqcn::m'`.
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $value = self::unquote($token->text);

                if (str_contains($value, '::')) {
                    [$left, $right] = explode('::', $value, 2);

                    if ($right !== '' && self::normalizeClassString($left) === $target) {
                        $methods[] = $right;
                    }
                }
            }
        }

        return $methods;
    }

    private static function normalizeClassString(string $value): string
    {
        return ltrim(str_replace('\\\\', '\\', $value), '\\');
    }

    /**
     * ¿El token `Nombre` (antes de `::`) designa la clase buscada? Un nombre
     * ordinario, resuelto en su ámbito; `parent`/`static`/`self` solo cuenta
     * si la clase envolvente EXTIENDE la buscada (heredó el método).
     *
     * @param  array{scopes: list<array{0: int, 1: string, 2: array<string, string>}>, classes: list<array{0: int, 1: int, 2: ?string}>}  $analysis
     */
    private static function referencesTarget(PhpToken $token, int $index, array $analysis, string $target): bool
    {
        if ($token->is(T_STATIC) || in_array(strtolower($token->text), ['self', 'parent'], true)) {
            foreach ($analysis['classes'] as [$start, $end, $extends]) {
                if ($index > $start && $index < $end && $extends === $target) {
                    return true;
                }
            }

            return false;
        }

        return self::resolveAt($token, $index, $analysis) === $target;
    }

    /**
     * Cadenas de llamadas que arrancan en una llamada estática sobre `$class`
     * (`Clase::m(...)->a(...)->b(...)`): por cada aparición, la lista
     * ordenada `[m, a, b]` de los métodos encadenados en el MISMO nivel de
     * anidamiento y la MISMA sentencia (se detiene en `;` o al cerrarse el
     * paréntesis/corchete/llave que contiene la expresión). Los argumentos y
     * los cierres pasados a una llamada no entran en la cadena. `Clase::class`
     * o una constante (sin `(`) no producen cadena. Se usa en AR-15 para
     * distinguir `Modelo::where(..)->delete()` (masivo) de
     * `Modelo::find(..)->delete()` (por instancia).
     *
     * Límites declarados: no sigue una cadena partida en varias sentencias
     * (`$q = Modelo::query(); $q->delete();`) ni una que arranca en una
     * relación o en una instancia (`$user->sessions()->delete()`).
     *
     * @param  class-string|string  $class  FQCN sin barra inicial
     * @return list<list<string>>
     */
    public static function chainsFromStaticCallOn(string $source, string $class): array
    {
        $tokens = self::tokens($source);
        $analysis = self::analyze($tokens);
        $count = count($tokens);
        $chains = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                || ! ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
                || self::resolveAt($token, $i, $analysis) !== ltrim($class, '\\')) {
                continue;
            }

            $method = $tokens[$i + 2] ?? null;

            if ($method === null || ! $method->is(T_STRING) || ! self::isOpenParen($tokens[$i + 3] ?? null)) {
                continue;
            }

            $chain = [$method->text];
            $depth = 0;

            for ($j = $i + 3; $j < $count; $j++) {
                $text = $tokens[$j]->text;

                if (in_array($text, ['(', '[', '{'], true) || $tokens[$j]->is(T_CURLY_OPEN) || $tokens[$j]->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
                    $depth++;

                    continue;
                }

                if (in_array($text, [')', ']', '}'], true)) {
                    $depth--;

                    if ($depth < 0) {
                        break;
                    }

                    continue;
                }

                if ($text === ';' && $depth === 0) {
                    break;
                }

                if ($depth === 0
                    && $tokens[$j]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                    && ($tokens[$j + 1] ?? null)?->is(T_STRING)
                    && self::isOpenParen($tokens[$j + 2] ?? null)) {
                    $chain[] = $tokens[$j + 1]->text;
                }
            }

            $chains[] = $chain;
        }

        return $chains;
    }

    /**
     * Ámbitos de nombres y clases de un fichero, por posición de token.
     *
     * - `scopes`: lista `[desde el token, namespace, imports]`; vale el último
     *   cuyo inicio es ≤ al token. Soporta `namespace A;`, `namespace A { }`
     *   (cada bloque con sus propios `use`), `use A\B;`, `use A\B as C;`,
     *   `use A\B, C\D;` y `use A\{B, C as D};`. Ignora `use function`/`use const`,
     *   el `use` de un cierre y el de un trait (no está a nivel de fichero ni de bloque).
     * - `classes`: `[token de apertura, token de cierre, extends resuelto|null]`.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{scopes: list<array{0: int, 1: string, 2: array<string, string>}>, classes: list<array{0: int, 1: int, 2: ?string}>}
     */
    private static function analyze(array $tokens): array
    {
        $count = count($tokens);
        $namespace = '';
        $imports = [];
        $scopes = [[0, '', []]];
        $classes = [];
        $depth = 0;
        $nsBlockDepth = null;
        $pendingClass = null;
        $classStack = [];

        $addImport = static function (string $name, ?string $alias) use (&$imports): void {
            $name = ltrim($name, '\\');
            $separator = strrpos($name, '\\');
            $imports[$alias ?? ($separator === false ? $name : substr($name, $separator + 1))] = $name;
        };

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                if ($pendingClass !== null && $token->text === '{') {
                    $classStack[] = [$i, $depth, $pendingClass === '' ? null : $pendingClass];
                    $pendingClass = null;
                }

                continue;
            }

            if ($token->text === '}') {
                if ($nsBlockDepth !== null && $depth === $nsBlockDepth) {
                    $namespace = '';
                    $imports = [];
                    $nsBlockDepth = null;
                    $scopes[] = [$i + 1, '', []];
                }

                if ($classStack !== [] && $classStack[array_key_last($classStack)][1] === $depth) {
                    [$open, , $extends] = array_pop($classStack);
                    $classes[] = [$open, $i, $extends];
                }

                $depth--;

                continue;
            }

            if ($token->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$i + 1]->text;
                $imports = [];
                $nsBlockDepth = ($tokens[$i + 2] ?? null)?->text === '{' ? $depth + 1 : null;
                $scopes[] = [$i, $namespace, []];

                continue;
            }

            if ($token->is([T_CLASS, T_ENUM, T_INTERFACE]) && ! ($tokens[$i - 1] ?? null)?->is([T_DOUBLE_COLON, T_NEW])) {
                // `extends X`: el primer nombre tras `extends` antes de la llave de apertura.
                $extends = null;

                for ($j = $i + 1; $j < $count && $tokens[$j]->text !== '{'; $j++) {
                    if ($tokens[$j]->is(T_EXTENDS) && ($tokens[$j + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        $extends = self::resolveWith($tokens[$j + 1], $namespace, $imports);

                        break;
                    }
                }

                // Cadena vacía = clase sin `extends` (null significaría «no hay clase pendiente»).
                $pendingClass = $extends ?? '';

                continue;
            }

            if ($token->is(T_USE) && $depth === ($nsBlockDepth ?? 0)) {
                $first = $tokens[$i + 1] ?? null;

                if ($first === null || ! $first->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    continue;
                }

                $j = $i + 1;

                // `use A\B\{C, D as E};`
                if (($tokens[$j + 1] ?? null)?->is(T_NS_SEPARATOR) && ($tokens[$j + 2] ?? null)?->text === '{') {
                    $prefix = rtrim(ltrim($first->text, '\\'), '\\').'\\';

                    for ($k = $j + 3; $k < $count && $tokens[$k]->text !== '}'; $k++) {
                        if ($tokens[$k]->is([T_STRING, T_NAME_QUALIFIED])) {
                            $alias = ($tokens[$k + 1] ?? null)?->is(T_AS) && ($tokens[$k + 2] ?? null)?->is(T_STRING) ? $tokens[$k + 2]->text : null;
                            $addImport($prefix.$tokens[$k]->text, $alias);

                            if ($alias !== null) {
                                $k += 2;
                            }
                        }
                    }

                    $scopes[] = [$k, $namespace, $imports];
                    $i = $k;

                    continue;
                }

                // `use A\B;`, `use A\B as C;`, `use A\B, C\D;`
                while ($j < $count && ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]))) {
                    $alias = ($tokens[$j + 1] ?? null)?->is(T_AS) && ($tokens[$j + 2] ?? null)?->is(T_STRING) ? $tokens[$j + 2]->text : null;
                    $addImport($tokens[$j]->text, $alias);
                    $j += $alias !== null ? 3 : 1;

                    if (($tokens[$j] ?? null)?->text === ',') {
                        $j++;

                        continue;
                    }

                    break;
                }

                $scopes[] = [$j, $namespace, $imports];
                $i = $j;
            }
        }

        return ['scopes' => $scopes, 'classes' => $classes];
    }

    /**
     * @param  array{scopes: list<array{0: int, 1: string, 2: array<string, string>}>, classes: list<array{0: int, 1: int, 2: ?string}>}  $analysis
     */
    private static function resolveAt(PhpToken $reference, int $index, array $analysis): string
    {
        $namespace = '';
        $imports = [];

        foreach ($analysis['scopes'] as [$from, $ns, $im]) {
            if ($from > $index) {
                break;
            }

            $namespace = $ns;
            $imports = $im;
        }

        return self::resolveWith($reference, $namespace, $imports);
    }

    /**
     * @param  array<string, string>  $imports
     */
    private static function resolveWith(PhpToken $reference, string $namespace, array $imports): string
    {
        $text = $reference->text;

        if ($reference->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($text, '\\');
        }

        $first = strstr($text, '\\', true);
        $first = $first === false ? $text : $first;

        if (isset($imports[$first])) {
            return $imports[$first].substr($text, strlen($first));
        }

        return ($namespace !== '' ? $namespace.'\\' : '').$text;
    }

    private static function isOpenParen(?PhpToken $token): bool
    {
        return $token !== null && $token->text === '(';
    }

    private static function unquote(string $quoted): string
    {
        return substr($quoted, 1, -1);
    }
}
