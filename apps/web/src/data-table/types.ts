/**
 * `docs/modulos/REQ-CORE/funcional.md §13.4`/`§13.5`, `ADR-054 §1.2`.
 * Tipos propios de `src/data-table`: un módulo consumidor declara columnas
 * y fuente de datos **solo** con estos tipos, nunca con los de TanStack
 * (`RN-CORE-37`, `RNF-MANT-007`).
 */

/** `ADR-038 §4.2`: `page` para catálogos de entidades, `cursor` para flujos de eventos. */
export type DataTableMode = 'page' | 'cursor'

/** `§13.4`, campo `card`: papel de la columna en la vista de tarjetas. */
export type DataTableCardRole = 'title' | 'subtitle' | 'field' | 'actions' | 'omit'

export type DataTableCellValue = string | number | null | undefined

export interface DataTableColumn<Row> {
  /**
   * Igual al nombre del parámetro de consulta con el que se filtra u
   * ordena (`ADR-038 §13.3`): se serializa sin tabla de correspondencias.
   * Si la columna no filtra ni ordena, cualquier `snake_case` único.
   */
  id: string
  /** Clave de traducción de la cabecera (`INV-009`): nunca texto. */
  headerKey: string
  /** Valor de la celda como texto. Por defecto, `row[id]`. `null`/vacío pinta el valor vacío común. */
  value?: (row: Row) => DataTableCellValue
  /** Solo si `id` está en el `enum` de `sort` del *endpoint* en OpenAPI (`ADR-038 §5.3`). */
  sortable?: boolean
  /** Exactamente una columna por tabla: se pinta como `th scope="row"` (`RN-CORE-44`). */
  rowHeader?: boolean
  /** Por defecto `true`. `false` obligatorio para la columna `rowHeader` y la de acciones. */
  hideable?: boolean
  /** Oculta por defecto (antes de cualquier configuración del usuario). */
  defaultHidden?: boolean
  /** Obligatorio en tablas con vista de tarjetas (`RN-CORE-55`). */
  card?: DataTableCardRole
  align?: 'start' | 'end'
}

/** `§13.7`: tipos de filtro admitidos, cerrados. `q` (búsqueda) no es un filtro declarado: es la prop `searchable`. */
export interface DataTableEnumFilter {
  type: 'enum'
  /** Nombre del parámetro (`ADR-038 §5.2`): `<id>=a,b`. */
  id: string
  labelKey: string
  /**
   * `labelKey` ausente o sin traducción ⇒ se muestra el código en crudo (`ADR-038 §7.3`).
   * `label` (ampliación aditiva de 1.9b): texto ya traducido por el servidor
   * (p. ej. el nombre de un rol de `GET /roles`, `RN-CORE-63`), que no es una
   * clave de traducción del cliente. Tiene prioridad sobre `labelKey`.
   */
  options: { value: string; labelKey?: string; label?: string }[]
  /**
   * Ampliación aditiva de 1.9f (`OPEN-CORE-40` = A, `RN-CORE-94`): `false` =
   * **selección única** (grupo de opciones exclusivas con «Todos» delante).
   * `true` o ausente: el filtro de 1.9, sin cambios (casillas, valores por comas).
   */
  multiple?: boolean
  /**
   * Solo con `multiple: false` y sin `urlState`: valor con el que arranca el
   * filtro y que es su **estado de reposo** (`OPEN-CORE-54` = A): cuenta como
   * activo solo si difiere de él y «Limpiar filtros» vuelve a él. Debe ser el
   * `value` de una de `options`; si no, o si se declara con otro `multiple` o
   * con `urlState` (`OPEN-CORE-55` = A), se ignora y se avisa por consola.
   */
  initial?: string
}

export interface DataTableDateRangeFilter {
  type: 'dateRange'
  /** Parámetros `<id>_from` y `<id>_to`, ambos inclusivos (`YYYY-MM-DD`). */
  id: string
  labelKey: string
}

export interface DataTableBooleanFilter {
  type: 'boolean'
  /** `<id>=true`/`false`; «todos» no envía el parámetro. */
  id: string
  labelKey: string
  /**
   * Ampliación aditiva de 1.9b (`OPEN-CORE-40` = A, `RN-CORE-68`): filtro de
   * **dos estados** — una casilla que, marcada, envía `<id>=true` y, desmarcada,
   * no envía el parámetro; sin opción «todos». Ausente o `false`: el filtro de
   * tres estados de 1.9, sin cambios.
   */
  twoState?: boolean
}

/** Opción devuelta por la búsqueda de un filtro `entity`. */
export interface DataTableEntityOption {
  /** Valor que se serializa como `<id>=<value>` (un identificador público, `ADR-029`). */
  value: string
  /** Texto ya traducido o nombre propio de la entidad; se muestra tal cual. */
  label: string
}

/**
 * Ampliación aditiva de 1.9d (`OPEN-CORE-33` = C, `RN-CORE-76`, amplía la
 * lista cerrada de §13.7): **selección única por búsqueda asíncrona** de una
 * entidad (p. ej. el usuario autor de un registro de auditoría). El componente
 * no conoce el *endpoint*: la búsqueda y la resolución de la etiqueta las
 * aporta el consumidor (`RN-CORE-38`). Se serializa como `<id>=<valor>`.
 * Un consumidor que no tiene el permiso del *endpoint* de búsqueda no declara
 * el filtro (`RN-CORE-62`).
 */
export interface DataTableEntityFilter {
  type: 'entity'
  /** Nombre del parámetro: `<id>=<public_id>`. */
  id: string
  labelKey: string
  /** Búsqueda por texto libre. Se llama con el texto ya recortado y tras el *debounce*. */
  search: (text: string, options: { signal: AbortSignal }) => Promise<DataTableEntityOption[]>
  /**
   * Etiqueta de un valor que viene de fuera (la URL): `null` si ya no existe o
   * no es accesible. Se llama una sola vez por valor.
   */
  resolve: (value: string) => Promise<string | null>
}

export type DataTableFilter =
  DataTableEnumFilter | DataTableDateRangeFilter | DataTableBooleanFilter | DataTableEntityFilter

/**
 * Consulta que el componente entrega a la función de petición del
 * consumidor. `filters` ya viene **serializado** en la forma de la *query
 * string* de `ADR-038 §5.2`: enumerados separados por comas, rangos como
 * `<id>_from`/`<id>_to`, booleanos como `true`/`false`. Sin tabla de
 * correspondencias entre `id` de columna y parámetro.
 */
export interface DataTableQuery {
  /** Solo modo `page`. */
  page?: number
  per_page?: number
  /** Solo modo `cursor`: ausente en la primera petición y tras cambiar orden o filtros. */
  cursor?: string
  /** `<id>` ascendente o `-<id>` descendente (`RN-CORE-39`). Ausente = orden por defecto del servidor. */
  sort?: string
  /** Texto libre (`RN-CORE-40`). Nunca va a la URL ni a una exportación (`RN-CORE-54`/`RN-CORE-57`). */
  q?: string
  filters: Record<string, string>
}

/** `ADR-038 §3.1`/`§4.3`. */
export interface DataTablePageMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

/** `ADR-038 §4.4`. */
export interface DataTableCursorMeta {
  next_cursor: string | null
  has_more: boolean
}

export interface DataTablePageResponse<Row> {
  data: Row[]
  meta: DataTablePageMeta
}

export interface DataTableCursorResponse<Row> {
  data: Row[]
  meta: DataTableCursorMeta
}

/**
 * Función de petición del consumidor (`RN-CORE-38`: el componente no
 * construye URLs de *endpoints*). `options.signal` permite cancelar una
 * petición superada; ignorarla es válido, su respuesta se descarta igual
 * (`RN-CORE-41`).
 */
export type DataTableFetcher<Row> = (
  query: DataTableQuery,
  options: { signal: AbortSignal },
) => Promise<DataTablePageResponse<Row> | DataTableCursorResponse<Row>>

/** `GET /data-exports/{public_id}` (`api.md §8`). */
export type DataTableExportState = 'pendiente' | 'generando' | 'completada' | 'fallida'

export interface DataTableExportStatus {
  public_id: string
  status: DataTableExportState
  download_url: string | null
  expires_at: string | null
  /** Clave de traducción, no texto (`datos.md` A.4). */
  error_code?: string | null
}

/**
 * `§13.14.1`: el componente nunca genera el fichero (`RN-CORE-46`). Solo
 * dispara la solicitud que el módulo dueño del recurso implementa y
 * consulta su estado.
 */
export interface DataTableExportConfig {
  /** Calculado por el consumidor con `<recurso>.exportar` de `/me.permissions`, nunca con el rol (`RN-CORE-51`). */
  canExport: boolean
  /**
   * Recibe **solo los filtros estructurados** del listado visible en su forma
   * de *query string* (sin `sort`, `page`, `per_page`, `cursor` ni `q`,
   * `ADR-054 §7.3`). La traducción a *array* JSON del cuerpo la hace el
   * módulo (`ADR-054 §8.2`).
   */
  request: (filters: Record<string, string>) => Promise<{ public_id: string }>
  /** `GET /data-exports/{id}`: debe lanzar el `ApiError` original (`409`, `410`). */
  status: (publicId: string) => Promise<DataTableExportStatus>
}
