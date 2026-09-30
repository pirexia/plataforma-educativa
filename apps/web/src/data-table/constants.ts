/**
 * `docs/modulos/REQ-CORE/funcional.md §13`, `docs/adr/ADR-054`. Constantes
 * del componente de tabla de datos. Son constantes exportadas (y no
 * números sueltos en los componentes) para que los tests de
 * `CA-CORE-165`/`-170`/`-189`/`-191` no dependan de las cifras (`RN-CORE-49`,
 * `RN-CORE-52`, `RN-CORE-40`).
 */

/**
 * `RN-CORE-52`, `ADR-054 §3`: tope de filas acumuladas en modo `cursor`
 * (20 cargas del `limit` por defecto de `ADR-038 §4.4`). **No es
 * configurable por tabla.** Cifra de diseño sin medición detrás: se revisa
 * con volumen real (`REQ-SEED`, 1.15b).
 */
export const MAX_CURSOR_ROWS = 1000

/** `RN-CORE-40`: espera de la búsqueda `q` tras la última pulsación. */
export const SEARCH_DEBOUNCE_MS = 300

/** `ADR-038 §4.3`: nada por encima de 100 (el servidor responde `422`). */
export const PER_PAGE_OPTIONS = [25, 50, 100] as const
export const DEFAULT_PER_PAGE = 25

/** `RN-CORE-49`, `ADR-054 §7.5`: espera inicial entre consultas de estado de una exportación. */
export const EXPORT_POLL_INITIAL_MS = 2_000
/** La espera se duplica en cada consulta hasta este máximo. */
export const EXPORT_POLL_MAX_MS = 30_000
/** Duración máxima de la consulta, contada desde la solicitud. */
export const EXPORT_POLL_TIMEOUT_MS = 10 * 60 * 1000

/** `RN-CORE-43`: prefijo de la clave de `localStorage` (`plataforma.table.<tableId>`). */
export const COLUMN_PREFERENCES_KEY_PREFIX = 'plataforma.table.'

/** `RN-CORE-29`: `--breakpoint-md`. Por debajo, vista de tarjetas (`RN-CORE-55`). */
export const CARDS_BREAKPOINT_PX = 768
