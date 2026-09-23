/**
 * `docs/modulos/REQ-CORE/funcional.md` `RN-CORE-28`, `CA-CORE-091`.
 * Evita la redirección abierta (`RSEC-OWASP`): un `redirect` solo se
 * acepta si es una ruta relativa del propio origen, sin esquema, y
 * resuelve a una ruta `app` ya registrada. En cualquier otro caso, `/`.
 */
import type { Router } from 'vue-router'

const SCHEME_PREFIX = /^[a-z][a-z0-9+.-]*:/i

export function sanitizeRedirect(raw: unknown, router: Router): string {
  if (typeof raw !== 'string' || raw.length === 0) {
    return '/'
  }

  if (!raw.startsWith('/') || raw.startsWith('//') || raw.startsWith('/\\')) {
    return '/'
  }

  if (SCHEME_PREFIX.test(raw)) {
    return '/'
  }

  const resolved = router.resolve(raw)

  // El *catch-all* (`not-found`) matchea cualquier cadena: una ruta
  // «inexistente» no es un destino válido aunque técnicamente resuelva.
  if (resolved.name === undefined || resolved.name === 'not-found') {
    return '/'
  }

  if (resolved.meta.layout !== 'app') {
    return '/'
  }

  return raw
}

export function buildRedirectQuery(fullPath: string): Record<string, string> {
  return { redirect: fullPath }
}
