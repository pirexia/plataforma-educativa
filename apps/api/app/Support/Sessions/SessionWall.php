<?php

namespace App\Support\Sessions;

use App\Models\User;

/**
 * ADR-067 §5.1: un «muro de sesión». La sesión existe, pero la API solo deja pasar unas pocas rutas hasta que el
 * usuario cumple una condición (alta de MFA, aceptación de las condiciones de uso...). Un muro **solo deniega**,
 * nunca autoriza (`INV-002`): que una ruta esté exenta no la autoriza, sigue necesitando su `permission:`.
 *
 * Cada módulo implementa el suyo y lo registra desde el `boot()` de su *ServiceProvider* en `SessionWallRegistry`.
 * El registro guarda **clases**, no instancias: `RequireSessionWalls` resuelve el muro del contenedor en cada petición
 * (ADR-067 §5.2), así que una implementación puede depender de servicios `scoped()` sin capturarlos en el arranque.
 *
 * `code()` no puede depender del estado del constructor: el registro lo lee sin pasar por el contenedor.
 */
interface SessionWall
{
    /** Código estable del muro; es el que ve la SPA en `GET /me` (`session_walls`). Ej.: `mfa-enrollment`, `terms-acceptance`. */
    public function code(): string;

    /** Slug del catálogo de ADR-038 §6.2, sin el prefijo `urn:pge:error:`. Ej.: `terms-acceptance-required`. */
    public function errorType(): string;

    /** Código del módulo dueño (descriptor de ADR-045). El muro no se evalúa si `ModuleAvailability` dice que no está activo. */
    public function moduleCode(): string;

    /**
     * Nombres de ruta exentos. Lista cerrada (ADR-067 §6).
     *
     * @return list<string>
     */
    public function exemptRouteNames(): array;

    /** ¿Está este usuario detenido por el muro ahora? Sin caché entre peticiones (ADR-067 §5.8). */
    public function blocks(User $user): bool;
}
