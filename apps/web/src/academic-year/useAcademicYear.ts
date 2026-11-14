/**
 * `docs/modulos/REQ-ACAD/funcional.md §4.7`, `§10.2`, `RN-ACAD-40..42`
 * (`REQ-CURSO-001` punto 3, `OPEN-CURSO-17` = A + X, `OPEN-ACAD-11` = A).
 *
 * Estado del selector de curso académico, compartido por las pantallas con
 * datos por curso. El curso seleccionado **vive solo en la URL**
 * (`?academic_year=<public_id>`, `RN-ACAD-40`): nada se guarda en
 * `localStorage`, `sessionStorage` ni en la sesión; recargar la página conserva
 * la selección porque está en la ruta. Sin parámetro, el curso activo; sin
 * activo, el estado «sin curso activo» (no un error).
 *
 * Las opciones son las de `GET /academic-years/selectable` (#409), que filtra el
 * servidor según `curso_historico.leer` (`RN-ACAD-41`). Singleton de ámbito de
 * módulo (`shallowRef`), sin Pinia, como `useSession`.
 */
import { computed, onBeforeUnmount, shallowRef, watch, type Ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { listSelectableAcademicYears } from '@/modules/curso/api'
import { useSession } from '@/session/useSession'
import type { AcademicYear } from '@/modules/curso/types'

export type SelectableStatus = 'idle' | 'loading' | 'ready' | 'error'

/** Nombre del parámetro de la URL (`RN-ACAD-40`). */
export const ACADEMIC_YEAR_QUERY = 'academic_year'

const options = shallowRef<AcademicYear[]>([])
const status = shallowRef<SelectableStatus>('idle')
/** Curso fijado por una ficha (§10.1): el selector se muestra, pero no cambia. */
const pinned = shallowRef<string | null>(null)

let inFlight: Promise<void> | null = null

/** Pide las opciones una vez; `force` las renueva (p. ej. tras un fallo). Nunca rechaza. */
export function loadSelectableYears(force = false): Promise<void> {
  if (!force && (status.value === 'ready' || inFlight !== null)) {
    return inFlight ?? Promise.resolve()
  }

  status.value = 'loading'

  inFlight = (async () => {
    try {
      options.value = (await listSelectableAcademicYears()).data
      status.value = 'ready'
    } catch {
      status.value = 'error'
    } finally {
      inFlight = null
    }
  })()

  return inFlight
}

/** Olvida las opciones (cambio de sesión). */
export function resetSelectableYears(): void {
  options.value = []
  status.value = 'idle'
  inFlight = null
}

/** `RN-CURSO-20`: un estado no anticipado se trata como solo lectura (`ADR-038 §7.3`). */
export function isWritableStatus(value: string): boolean {
  return value === 'planificacion' || value === 'activo'
}

function firstString(value: unknown): string | null {
  const candidate = Array.isArray(value) ? value[0] : value

  return typeof candidate === 'string' && candidate !== '' ? candidate : null
}

/**
 * Las opciones dependen de la identidad y de `curso_historico.leer` (#409): si
 * cambian (otra sesión, permisos recargados), se piden de nuevo. Lo monta una
 * sola vez el selector de la barra superior, que vive mientras dura el *shell*.
 */
export function useSelectableYearsSync(): void {
  const { user } = useSession()

  watch(
    () =>
      `${user.value?.public_id ?? ''}|${user.value?.permissions.includes('curso_historico.leer') ?? false}`,
    (key, previous) => {
      if (key === previous) {
        return
      }

      resetSelectableYears()

      if (user.value !== null) {
        void loadSelectableYears()
      }
    },
  )
}

export function useAcademicYear() {
  const route = useRoute()
  const router = useRouter()

  void loadSelectableYears()

  /** El valor de la URL, si lo hay. */
  const fromUrl = computed(() => firstString(route.query[ACADEMIC_YEAR_QUERY]))
  const active = computed(() => options.value.find((year) => year.status === 'activo') ?? null)

  /**
   * `public_id` efectivo: el de una ficha (fijado), el de la URL, o el activo.
   * `null` solo si no hay parámetro, ni fijado, ni curso activo entre las opciones.
   */
  const selectedId = computed<string | null>(
    () => pinned.value ?? fromUrl.value ?? active.value?.public_id ?? null,
  )
  const selected = computed(
    () => options.value.find((year) => year.public_id === selectedId.value) ?? null,
  )
  /** `RN-ACAD-40`: nada en la URL y ningún curso activo entre las opciones ya cargadas. */
  const noActive = computed(
    () =>
      status.value === 'ready' &&
      pinned.value === null &&
      fromUrl.value === null &&
      active.value === null,
  )
  const isPinned = computed(() => pinned.value !== null)
  /**
   * `RN-ACAD-42`: solo se escribe en `planificacion`/`activo`. Mientras el curso
   * seleccionado no se conoce, no es escribible (la interfaz oculta; el
   * servidor decide, `INV-002`).
   */
  const readOnly = computed(
    () => selected.value !== null && !isWritableStatus(selected.value.status),
  )
  const writable = computed(
    () => selected.value !== null && isWritableStatus(selected.value.status),
  )

  /** Navega a la misma ruta con otro curso académico; descarta página, orden y filtros. */
  function select(publicId: string): Promise<unknown> {
    return router.push({ path: route.path, query: { [ACADEMIC_YEAR_QUERY]: publicId } })
  }

  return {
    options,
    status,
    selectedId,
    selected,
    /** `?academic_year=` tal cual, para las llamadas a la API (omitido ⇒ activo en servidor). */
    urlYear: fromUrl,
    noActive,
    isPinned,
    readOnly,
    writable,
    select,
    reload: () => loadSelectableYears(true),
  }
}

/**
 * Las fichas (`acad-*-detail`) muestran el selector **fijo** en el curso de la
 * entidad (`funcional.md §10.1`, **[DERIVADA]**). La vista llama a esta función
 * con el `academic_year` de su entidad; al desmontarse, lo suelta.
 */
export function usePinAcademicYear(publicId: Ref<string | null | undefined>): void {
  void loadSelectableYears()

  watch(
    publicId,
    (value) => {
      pinned.value = value ?? null
    },
    { immediate: true },
  )

  onBeforeUnmount(() => {
    pinned.value = null
  })
}
