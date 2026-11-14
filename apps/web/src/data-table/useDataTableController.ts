/**
 * `docs/modulos/REQ-CORE/funcional.md §13.5`-`§13.7`, `§13.10`,
 * `RN-CORE-39`-`RN-CORE-42`, `RN-CORE-45`, `RN-CORE-52`, `RN-CORE-54`,
 * `RN-CORE-56`, `ADR-054 §2`/`§3`/`§6`. Máquina de estado de la consulta
 * de una tabla: página o cursor, orden, filtros y búsqueda, todo en
 * servidor (`manualPagination`/`manualSorting`/`manualFiltering`). No hace
 * peticiones por su cuenta: llama a la función que aporta el consumidor
 * (`RN-CORE-38`).
 *
 * Las filas viven **solo en memoria** de este *composable* (`RN-CORE-50`):
 * nunca en `localStorage`, `sessionStorage`, IndexedDB ni en la URL.
 */
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import { i18n } from '@/i18n'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import { DEFAULT_PER_PAGE, MAX_CURSOR_ROWS, SEARCH_DEBOUNCE_MS } from './constants'
import { isSingleEnum, sanitizeSingleEnums, type FilterValues } from './filterState'
import { filterLocalRows, pageLocalRows, sortLocalRows } from './localModel'
import {
  defaultUrlState,
  mergeIntoRouteQuery,
  parseUrlState,
  serializeUrlState,
  type UrlState,
  type UrlStateOptions,
} from './urlState'
import type {
  DataTableColumn,
  DataTableCursorMeta,
  DataTableFetcher,
  DataTableFilter,
  DataTableLocalFetcher,
  DataTableMode,
  DataTablePageMeta,
  DataTableQuery,
} from './types'

export interface ControllerOptions<Row> {
  mode: DataTableMode
  /** Modo `local`: `DataTableLocalFetcher` (`RN-PERM-44` E1); los demás, `DataTableFetcher`. */
  fetcher: () => DataTableFetcher<Row> | DataTableLocalFetcher<Row>
  filters: () => readonly DataTableFilter<Row>[]
  sortableIds: () => readonly string[]
  /** `RN-CORE-54`: opcional por tabla. */
  urlState: boolean
  /** Solo modo `local` (`RN-PERM-44` E3-E5): las columnas, la identidad de fila y la búsqueda del consumidor. */
  columns?: () => readonly DataTableColumn<Row>[]
  rowKey?: (row: Row) => string
  searchText?: () => ((row: Row) => string) | undefined
}

export type Announcement =
  | { kind: 'results'; count: number }
  | { kind: 'local'; count: number; total: number }
  | { kind: 'loaded'; count: number }
  | { kind: 'more'; count: number }

/** Rutas con una tabla que ya refleja su estado en la URL (`RN-CORE-54`, como máximo una por ruta). */
const routesWithUrlState = new Set<string>()

/**
 * `ADR-038 §6`: mensajes ya traducidos por el servidor (`errors.<campo>[].message`),
 * con `detail` como último recurso. Nunca un texto propio del cliente.
 */
function problemMessages(body: unknown): string[] {
  if (typeof body !== 'object' || body === null) {
    return []
  }

  const { errors, detail } = body as {
    errors?: Record<string, { message?: unknown }[]>
    detail?: unknown
  }
  const messages: string[] = []

  if (errors && typeof errors === 'object') {
    for (const entries of Object.values(errors)) {
      for (const entry of Array.isArray(entries) ? entries : []) {
        if (typeof entry?.message === 'string' && !messages.includes(entry.message)) {
          messages.push(entry.message)
        }
      }
    }
  }

  if (messages.length === 0 && typeof detail === 'string' && detail !== '') {
    messages.push(detail)
  }

  return messages
}

export function useDataTableController<Row>(options: ControllerOptions<Row>) {
  const route = options.urlState ? useRoute() : null
  const router = options.urlState ? useRouter() : null
  const ownPath = route?.path ?? null
  let urlClaimed = false

  if (route && ownPath !== null) {
    if (routesWithUrlState.has(ownPath)) {
      console.warn(
        'data-table: ya hay otra tabla con `urlState` en esta ruta (RN-CORE-54); esta guarda su consulta solo en memoria.',
      )
    } else {
      routesWithUrlState.add(ownPath)
      urlClaimed = true
    }
  }

  const urlEnabled = urlClaimed && route !== null && router !== null

  const isLocal = options.mode === 'local'

  function urlOptions(): UrlStateOptions {
    return {
      // `RN-PERM-44` E7: el modo `local` serializa la URL como el modo `page`.
      mode: options.mode === 'cursor' ? 'cursor' : 'page',
      filters: options.filters(),
      sortableIds: options.sortableIds(),
    }
  }

  // --- Estado de la consulta ---------------------------------------------

  /**
   * `RN-CORE-94`/`OPEN-CORE-54`/`-55`: valor de reposo de los `enum` de
   * selección única con `initial` válido. Con `urlState` (declarado, aunque
   * otra tabla ya tenga la URL de la ruta) o con `multiple` distinto de
   * `false`, o con un valor no declarado, `initial` se ignora.
   */
  function restFilters(): FilterValues {
    const rest: FilterValues = {}

    if (options.urlState) {
      return rest
    }

    for (const filter of options.filters()) {
      if (
        isSingleEnum(filter) &&
        filter.initial !== undefined &&
        filter.options.some((option) => option.value === filter.initial)
      ) {
        rest[filter.id] = filter.initial
      }
    }

    return rest
  }

  for (const filter of options.filters()) {
    if (filter.type !== 'enum' || filter.initial === undefined) {
      continue
    }

    if (options.urlState) {
      console.warn(
        `data-table: \`initial\` del filtro «${filter.id}» ignorado: no se admite con \`urlState\` (OPEN-CORE-55).`,
      )
    } else if (filter.multiple !== false) {
      console.warn(
        `data-table: \`initial\` del filtro «${filter.id}» ignorado: solo se admite con \`multiple: false\` (RN-CORE-94).`,
      )
    } else if (!filter.options.some((option) => option.value === filter.initial)) {
      console.warn(
        `data-table: \`initial\` del filtro «${filter.id}» ignorado: no es el valor de ninguna opción (RN-CORE-94).`,
      )
    }
  }

  const initial =
    urlEnabled && route
      ? parseUrlState(route.query, urlOptions())
      : { ...defaultUrlState(), filters: restFilters() }

  const page = ref(initial.page)
  const perPage = ref(initial.perPage || DEFAULT_PER_PAGE)
  const sort = ref<string | null>(initial.sort)
  const filters = ref<FilterValues>({ ...initial.filters })
  const searchText = ref('')
  const appliedQ = ref('')

  // --- Estado de los datos -----------------------------------------------

  const rows = shallowRef<Row[]>([])
  /** Modo `local`: la colección entera tal como llegó (`RN-PERM-44` E1). */
  const localAll = shallowRef<Row[]>([])
  const pageMeta = ref<DataTablePageMeta | null>(null)
  const nextCursor = ref<string | null>(null)
  const hasMore = ref(false)
  const loading = ref(false)
  const loadingMore = ref(false)
  const hasLoaded = ref(false)
  const error = ref<ShellErrorState | null>(null)
  const filterError = ref<string[] | null>(null)
  const loadMoreError = ref<ShellErrorState | null>(null)
  const announcement = ref<Announcement | null>(null)

  let seq = 0
  let abort: AbortController | null = null
  let searchTimer: ReturnType<typeof setTimeout> | null = null
  let overflowRetried = false

  /** `OPEN-CORE-54` = A: un filtro con valor de reposo cuenta como activo solo si difiere de él. */
  const hasActiveFilters = computed(() => {
    const rest = restFilters()
    const ids = new Set([...Object.keys(filters.value), ...Object.keys(rest)])
    const filtered = [...ids].some((id) => (filters.value[id] ?? '') !== (rest[id] ?? ''))

    return filtered || searchText.value.trim() !== '' || appliedQ.value !== ''
  })
  /** `RN-CORE-57`: hay una búsqueda escrita o aplicada. */
  const searchActive = computed(() => searchText.value.trim() !== '' || appliedQ.value !== '')
  const capReached = computed(
    () => options.mode === 'cursor' && hasMore.value && rows.value.length >= MAX_CURSOR_ROWS,
  )

  function currentQuery(cursor?: string): DataTableQuery {
    const query: DataTableQuery = {
      filters: { ...sanitizeSingleEnums(filters.value, options.filters()) },
    }

    if (options.mode === 'page') {
      query.page = page.value
      query.per_page = perPage.value
    }

    if (sort.value) {
      query.sort = sort.value
    }

    if (appliedQ.value !== '') {
      query.q = appliedQ.value
    }

    if (cursor) {
      query.cursor = cursor
    }

    return query
  }

  // --- Estado en la URL (`RN-CORE-54`) -----------------------------------

  function currentUrlState(): UrlState {
    return { page: page.value, perPage: perPage.value, sort: sort.value, filters: filters.value }
  }

  function pushToUrl(): void {
    if (!urlEnabled || !route || !router) {
      return
    }

    const opts = urlOptions()

    if (
      serializeUrlState(currentUrlState(), opts) ===
      serializeUrlState(parseUrlState(route.query, opts), opts)
    ) {
      return
    }

    void router.push({ query: mergeIntoRouteQuery(route.query, currentUrlState(), opts) })
  }

  if (urlEnabled && route) {
    watch(
      () => route.query,
      (query) => {
        if (route.path !== ownPath) {
          return
        }

        const opts = urlOptions()
        const fromUrl = parseUrlState(query, opts)

        if (serializeUrlState(fromUrl, opts) === serializeUrlState(currentUrlState(), opts)) {
          return
        }

        page.value = fromUrl.page
        perPage.value = fromUrl.perPage
        sort.value = fromUrl.sort
        filters.value = { ...fromUrl.filters }
        overflowRetried = false

        if (isLocal) {
          applyLocal()
        } else {
          void load()
        }
      },
    )
  }

  // --- Carga -----------------------------------------------------------

  function classify(err: unknown): ShellErrorState | null {
    return resolveErrorState(err)
  }

  /** `RN-PERM-44` E2-E6: filtra, ordena y pagina en cliente sin tocar el servidor. */
  function applyLocal(): void {
    const locale = i18n.global.locale.value
    const columns = options.columns?.() ?? []
    const rowKey =
      options.rowKey ?? ((row: Row) => String((row as { public_id?: string }).public_id))
    const filtered = filterLocalRows(localAll.value, {
      filters: options.filters(),
      filterValues: sanitizeSingleEnums(filters.value, options.filters()),
      searchText: options.searchText?.(),
      query: appliedQ.value,
      locale,
    })
    const sorted = sortLocalRows(filtered, sort.value, columns, rowKey, locale)
    const paged = pageLocalRows(sorted, page.value, perPage.value)

    rows.value = paged.rows
    pageMeta.value = paged.meta
    page.value = paged.meta.current_page
    announcement.value = { kind: 'local', count: filtered.length, total: localAll.value.length }
  }

  async function loadLocal(): Promise<void> {
    abort?.abort()
    abort = new AbortController()

    const mySeq = ++seq

    loading.value = true

    try {
      const fetcher = options.fetcher() as DataTableLocalFetcher<Row>
      const response = await fetcher({ signal: abort.signal })

      if (mySeq !== seq) {
        return
      }

      error.value = null
      filterError.value = null
      localAll.value = response.data
      applyLocal()
      hasLoaded.value = true
    } catch (err) {
      if (mySeq !== seq) {
        return
      }

      error.value = classify(err)
      hasLoaded.value = true

      if (error.value === null) {
        // `401`: el guard ya redirige a /entrar; las filas se descartan (`RN-CORE-50`).
        localAll.value = []
        rows.value = []
      }
    } finally {
      if (mySeq === seq) {
        loading.value = false
      }
    }
  }

  async function load(): Promise<void> {
    if (isLocal) {
      return loadLocal()
    }

    abort?.abort()
    abort = new AbortController()

    const mySeq = ++seq

    loading.value = true
    loadingMore.value = false
    loadMoreError.value = null

    try {
      const response = await (options.fetcher() as DataTableFetcher<Row>)(currentQuery(), {
        signal: abort.signal,
      })

      if (mySeq !== seq) {
        return
      }

      error.value = null
      filterError.value = null
      rows.value = response.data

      if (options.mode === 'page') {
        const meta = response.meta as DataTablePageMeta

        pageMeta.value = meta
        page.value = meta.current_page

        // `RN-CORE-45`: se borraron filas mientras se paginaba. Se pide la
        // última página **una sola vez**, nunca en bucle.
        if (
          response.data.length === 0 &&
          meta.current_page > 1 &&
          meta.total > 0 &&
          !overflowRetried &&
          Math.max(meta.last_page, 1) !== meta.current_page
        ) {
          overflowRetried = true
          page.value = Math.max(meta.last_page, 1)
          pushToUrl()
          await load()

          return
        }

        announcement.value = { kind: 'results', count: meta.total }
      } else {
        const meta = response.meta as DataTableCursorMeta

        nextCursor.value = meta.next_cursor
        hasMore.value = meta.has_more
        announcement.value = { kind: 'loaded', count: response.data.length }
      }

      hasLoaded.value = true
    } catch (err) {
      if (mySeq !== seq) {
        return
      }

      if (err instanceof ApiError && err.status === 422) {
        // `RN-CORE-42`: se conservan las filas anteriores y el estado de la
        // tabla no pasa a error de pantalla completa.
        const messages = problemMessages(err.body)

        filterError.value = messages.length > 0 ? messages : ['']
        error.value = null
        hasLoaded.value = true

        return
      }

      filterError.value = null
      error.value = classify(err)
      hasLoaded.value = true

      if (error.value === null) {
        // `401`: el guard ya redirige a /entrar; las filas se descartan (`RN-CORE-50`).
        rows.value = []
      }
    } finally {
      if (mySeq === seq) {
        loading.value = false
      }
    }
  }

  /** `RN-CORE-56`: «Cargar más» añade filas con el `cursor`; un fallo conserva las filas y repite con el mismo. */
  async function loadMore(): Promise<void> {
    if (
      options.mode !== 'cursor' ||
      !hasMore.value ||
      nextCursor.value === null ||
      loading.value ||
      loadingMore.value ||
      capReached.value
    ) {
      return
    }

    const cursor = nextCursor.value
    const mySeq = ++seq

    loadingMore.value = true
    loadMoreError.value = null

    try {
      const response = await (options.fetcher() as DataTableFetcher<Row>)(currentQuery(cursor), {
        signal: (abort ??= new AbortController()).signal,
      })

      if (mySeq !== seq) {
        return
      }

      const meta = response.meta as DataTableCursorMeta

      rows.value = [...rows.value, ...response.data]
      nextCursor.value = meta.next_cursor
      hasMore.value = meta.has_more
      announcement.value = { kind: 'more', count: response.data.length }
    } catch (err) {
      if (mySeq !== seq) {
        return
      }

      loadMoreError.value = classify(err) ?? { kind: 'unexpected' }
    } finally {
      if (mySeq === seq) {
        loadingMore.value = false
      }
    }
  }

  // --- Acciones del usuario --------------------------------------------

  function restart(resetPage = true): void {
    overflowRetried = false

    if (resetPage) {
      page.value = 1
    }

    // Cambiar orden o filtros reinicia la lista **sin cursor** (`ADR-038 §4.4` regla 2).
    nextCursor.value = null

    if (isLocal) {
      // `RN-PERM-44` E2: sin petición; solo se recalcula la vista.
      applyLocal()

      return
    }

    void load()
  }

  function setPage(next: number): void {
    const last = Math.max(pageMeta.value?.last_page ?? 1, 1)

    if (options.mode === 'cursor' || next < 1 || next > last || next === page.value) {
      return
    }

    page.value = next
    restart(false)
    pushToUrl()
  }

  function setPerPage(next: number): void {
    perPage.value = next
    restart()
    pushToUrl()
  }

  function setSort(next: string | null): void {
    sort.value = next
    restart()
    pushToUrl()
  }

  /** `RN-CORE-39`: ascendente → descendente → sin orden explícito (no se envía `sort`). */
  function cycleSort(columnId: string): void {
    if (sort.value === columnId) {
      setSort(`-${columnId}`)
    } else if (sort.value === `-${columnId}`) {
      setSort(null)
    } else {
      setSort(columnId)
    }
  }

  function setFilters(next: FilterValues): void {
    filters.value = next
    restart()
    pushToUrl()
  }

  function clearSearchTimer(): void {
    if (searchTimer !== null) {
      clearTimeout(searchTimer)
      searchTimer = null
    }
  }

  /** `RN-CORE-40`: una sola petición cuando el usuario deja de escribir durante `SEARCH_DEBOUNCE_MS`. */
  function setSearchText(text: string): void {
    searchText.value = text
    clearSearchTimer()

    searchTimer = setTimeout(() => {
      searchTimer = null

      const applied = text.trim()

      if (applied !== appliedQ.value) {
        appliedQ.value = applied
        restart()
      }
    }, SEARCH_DEBOUNCE_MS)
  }

  function clearFilters(): void {
    clearSearchTimer()
    searchText.value = ''
    appliedQ.value = ''
    filters.value = restFilters()
    restart()
    pushToUrl()
  }

  /** «Reintentar» del estado de error: repite **la misma** consulta (`CA-CORE-182`). */
  function retry(): void {
    overflowRetried = false
    void load()
  }

  onBeforeUnmount(() => {
    // Invalida cualquier respuesta pendiente y descarta las filas (`RN-CORE-50`).
    seq += 1
    abort?.abort()
    clearSearchTimer()
    rows.value = []

    if (urlClaimed && ownPath !== null) {
      routesWithUrlState.delete(ownPath)
    }
  })

  void load()

  return {
    // consulta
    page,
    perPage,
    sort,
    filters,
    searchText,
    hasActiveFilters,
    searchActive,
    // datos
    rows,
    pageMeta,
    hasMore,
    loading,
    loadingMore,
    hasLoaded,
    error,
    filterError,
    loadMoreError,
    capReached,
    announcement,
    // acciones
    setPage,
    setPerPage,
    setSort,
    cycleSort,
    setFilters,
    setSearchText,
    clearFilters,
    retry,
    loadMore,
    refresh: load,
  }
}
