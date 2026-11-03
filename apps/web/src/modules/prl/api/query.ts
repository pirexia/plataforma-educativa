/**
 * `REQ-PRL` (`1.14b-c2`). Ayudantes de la *query string* (`ADR-038 §5.2`): nada de claves vacías. (Módulo propio:
 * `prl` no importa los internos de otro módulo, `INV-007`.)
 */
export function buildQuery(
  params: Record<string, string | number | boolean | undefined | null>,
): string {
  const search = new URLSearchParams()

  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') {
      continue
    }

    search.set(key, String(value))
  }

  const query = search.toString()

  return query ? `?${query}` : ''
}

export function encode(publicId: string): string {
  return encodeURIComponent(publicId)
}
