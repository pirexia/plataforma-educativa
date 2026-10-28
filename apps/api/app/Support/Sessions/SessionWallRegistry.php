<?php

namespace App\Support\Sessions;

use LogicException;
use ReflectionClass;

/**
 * ADR-067 §5.2: el registro de muros de sesión del núcleo, junto a `ScopeResolverRegistry`. *Singleton*
 * (`AuthorizationServiceProvider`); cada módulo registra el suyo desde el `boot()` de su *ServiceProvider*.
 *
 * - **Registra la clase, no una instancia** (`INV-001`): un muro que dependa de un servicio `scoped()` recibe la
 *   instancia de cada petición, nunca una capturada en el arranque. Por eso el código del muro se lee sin pasar por el
 *   contenedor (`newInstanceWithoutConstructor()`): `SessionWall::code()` no puede depender del constructor.
 * - **Orden fijo y cerrado** (`ORDER`, ampliación L6): registrar un código que no esté en la lista, o el mismo dos
 *   veces, lanza `LogicException` en el arranque. Añadir un muro exige añadir su código aquí, y eso exige aprobación.
 */
final class SessionWallRegistry
{
    /** L6 (ADR-067 §6): primero el alta de MFA, después las condiciones de uso (§5.4). */
    public const ORDER = ['mfa-enrollment', 'terms-acceptance'];

    /** @var array<string, class-string<SessionWall>> código => clase */
    private array $walls = [];

    /**
     * @param  list<string>  $order  el orden cerrado; solo los tests lo sustituyen
     */
    public function __construct(private readonly array $order = self::ORDER) {}

    /**
     * @param  class-string<SessionWall>  $wall
     */
    public function register(string $wall): void
    {
        $code = (new ReflectionClass($wall))->newInstanceWithoutConstructor()->code();

        if (! in_array($code, $this->order, true)) {
            throw new LogicException("El muro de sesión «{$code}» ({$wall}) no está en el orden cerrado de ADR-067 §6 (L6): añadirlo exige aprobación.");
        }

        if (isset($this->walls[$code])) {
            throw new LogicException("El muro de sesión «{$code}» ya está registrado ({$this->walls[$code]}).");
        }

        $this->walls[$code] = $wall;
    }

    /**
     * Las clases de los muros registrados, en el orden cerrado.
     *
     * @return list<class-string<SessionWall>>
     */
    public function ordered(): array
    {
        $ordered = [];

        foreach ($this->order as $code) {
            if (isset($this->walls[$code])) {
                $ordered[] = $this->walls[$code];
            }
        }

        return $ordered;
    }
}
