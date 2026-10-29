/**
 * REQ-FAM-UNIT (paso 1.14-d). Ayudantes de construcción de la *query string* del módulo (`ADR-038 §5.2`): valores
 * múltiples separados por comas, booleanos como `true`/`false`, nada de claves vacías. (Módulo propio: `core` no
 * expone su `buildQuery` por la superficie pública, `INV-007`.)
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

/** Un filtro `enum` del componente de tabla llega como `a,b`: se vuelve a pasar tal cual. */
export function csv(value: string | undefined): string | undefined {
  return value === undefined || value === '' ? undefined : value
}

export function encode(publicId: string): string {
  return encodeURIComponent(publicId)
}
