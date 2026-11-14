/**
 * ADR-038 §5.2/§13.2: un valor múltiple (enumerados/identificadores) va
 * separado por comas, nunca repetido — la sintaxis repetida no funciona
 * en PHP (ADR-038 §5.1). Booleanos como cadena literal "true"/"false".
 * Un único ayudante de construcción de `query`, no uno por vista.
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

export function joinList(values: readonly string[] | undefined): string | undefined {
  return values && values.length > 0 ? values.join(',') : undefined
}
