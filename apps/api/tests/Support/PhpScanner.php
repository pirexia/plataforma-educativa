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
 * - NO resuelve `$clase::m()`, `static::m()`, `self::m()` ni `use` con
 *   llaves de grupo (`use A\{B, C}`): falsos negativos, no positivos.
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
        [$namespace, $imports] = self::namespaceAndImports($tokens);
        $methods = [];

        foreach ($tokens as $i => $token) {
            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            if (! ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)) {
                continue;
            }

            $method = $tokens[$i + 2] ?? null;

            if ($method === null || ! $method->is(T_STRING) || ! self::isOpenParen($tokens[$i + 3] ?? null)) {
                continue;
            }

            // El nombre no puede ser el final de otro acceso: `$x->Foo::m()` no existe,
            // pero `new Foo` o `use Foo` anteceden otros tokens y no llevan `::`.
            if (self::resolve($token, $namespace, $imports) === ltrim($class, '\\')) {
                $methods[] = $method->text;
            }
        }

        return $methods;
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
        [$namespace, $imports] = self::namespaceAndImports($tokens);
        $count = count($tokens);
        $chains = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                || ! ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
                || self::resolve($token, $namespace, $imports) !== ltrim($class, '\\')) {
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
     * @param  list<PhpToken>  $tokens
     * @return array{0: string, 1: array<string, string>}
     */
    private static function namespaceAndImports(array $tokens): array
    {
        $namespace = '';
        $imports = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$i + 1]->text;

                continue;
            }

            // `use` de nivel de fichero: solo `use A\B\C;` y `use A\B\C as D;`.
            // Se detiene en la primera declaración de clase/función/interfaz/trait/enum.
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION])) {
                break;
            }

            if ($token->is(T_USE) && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($tokens[$i + 1]->text, '\\');
                $separator = strrpos($name, '\\');
                $alias = $separator === false ? $name : substr($name, $separator + 1);

                if (($tokens[$i + 2] ?? null)?->is(T_AS) && ($tokens[$i + 3] ?? null)?->is(T_STRING)) {
                    $alias = $tokens[$i + 3]->text;
                }

                $imports[$alias] = $name;
            }
        }

        return [$namespace, $imports];
    }

    /**
     * @param  array<string, string>  $imports
     */
    private static function resolve(PhpToken $reference, string $namespace, array $imports): string
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
