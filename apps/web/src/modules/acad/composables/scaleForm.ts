/**
 * `docs/modulos/REQ-ACAD/funcional.md §4.9`, `§10.5.2`, `api.md §11.4`. Lógica
 * pura del formulario de escala: estado, cuerpos de `POST`/`PATCH` y reparto de
 * los errores `errors.<campo>` del servidor.
 *
 * - **Decimales como cadena** (`RN-ACAD-75`): ningún `float`. Las comparaciones
 *   de la columna «Aprobado» de la mixta se hacen en centésimas enteras, como el
 *   servidor (`ScaleDecimal`), solo como ayuda: la decisión es del servidor
 *   (`INV-010`).
 * - **Valores = conjunto completo** (`RN-ACAD-88`): en un `PATCH` con cambios en
 *   los valores se envía la lista entera, en el orden de la pantalla (que es el
 *   nuevo `position`); un valor existente lleva `public_id` y solo las claves
 *   modificadas; uno nuevo, todas las suyas.
 */
import type { Locale } from '@/modules/core/types'
import { cleanTranslations, translationsPatch, type TranslationValues } from '@/lib/translatable'
import type {
  GradingScaleCreate,
  GradingScaleUpdate,
  GradingScaleValueCreate,
  GradingScaleValuePatch,
} from '../api'
import type { GradingScale, GradingScaleValue, ScaleType } from '../types'

export const SCALE_TYPES: readonly ScaleType[] = ['numerica', 'cualitativa', 'mixta']
export const DECIMALS_OPTIONS = ['0', '1', '2'] as const

export interface ValueRow {
  /** Identidad local de la fila (clave de `v-for` y del foco); no viaja. */
  key: string
  /** `public_id` del valor guardado, o `null` si es nuevo. */
  public_id: string | null
  code: string
  labels: TranslationValues
  is_passing: boolean
  range_min: string
  range_max: string
}

export interface ScaleFormState {
  scale_type: ScaleType | ''
  code: string
  names: TranslationValues
  min_value: string
  max_value: string
  decimals: string
  passing_min_value: string
  values: ValueRow[]
}

let counter = 0

export function newRowKey(): string {
  counter += 1

  return `row-${counter}`
}

export function emptyRow(): ValueRow {
  return {
    key: newRowKey(),
    public_id: null,
    code: '',
    labels: {},
    is_passing: false,
    range_min: '',
    range_max: '',
  }
}

export function emptyState(type: ScaleType | '' = ''): ScaleFormState {
  return {
    scale_type: type,
    code: '',
    names: {},
    min_value: '',
    max_value: '',
    decimals: '',
    passing_min_value: '',
    values: [],
  }
}

function rowOf(value: GradingScaleValue): ValueRow {
  return {
    key: newRowKey(),
    public_id: value.public_id,
    code: value.code,
    labels: { ...value.label_translations },
    is_passing: value.is_passing === true,
    range_min: value.range_min ?? '',
    range_max: value.range_max ?? '',
  }
}

export function stateFromScale(scale: GradingScale): ScaleFormState {
  return {
    scale_type: scale.scale_type as ScaleType,
    code: scale.code,
    names: { ...scale.name_translations },
    min_value: scale.min_value ?? '',
    max_value: scale.max_value ?? '',
    decimals: scale.decimals === null ? '' : String(scale.decimals),
    passing_min_value: scale.passing_min_value ?? '',
    values: scale.values.map(rowOf),
  }
}

export function hasNumericFields(type: string): boolean {
  return type === 'numerica' || type === 'mixta'
}

export function hasValues(type: string): boolean {
  return type === 'cualitativa' || type === 'mixta'
}

const DECIMAL = /^(-?)(\d{1,3})(?:\.(\d{1,2}))?$/

/** Centésimas enteras de un decimal con punto (`"5.5"` → `550`); `null` si no es un decimal válido. */
export function toHundredths(text: string): number | null {
  const match = DECIMAL.exec(text.trim())

  if (!match) {
    return null
  }

  const whole = Number(match[2]) * 100
  const fraction = Number((match[3] ?? '').padEnd(2, '0'))
  const total = whole + fraction

  return match[1] === '-' ? -total : total
}

/** `RN-ACAD-87`: un tramo es aprobado si `range_min ≥ passing_min_value`. `null` si no se puede decidir. */
export function bandIsPassing(rangeMin: string, threshold: string): boolean | null {
  const from = toHundredths(rangeMin)
  const limit = toHundredths(threshold)

  return from === null || limit === null ? null : from >= limit
}

function createValue(
  type: string,
  row: ValueRow,
  locales: readonly Locale[],
): GradingScaleValueCreate {
  const value: GradingScaleValueCreate = {
    code: row.code.trim(),
    label_translations: cleanTranslations(row.labels, locales),
  }

  if (type === 'cualitativa') {
    value.is_passing = row.is_passing
  } else {
    value.range_min = row.range_min.trim()
    value.range_max = row.range_max.trim()
  }

  return value
}

/** Cuerpo de `POST /grading-scales` (`RN-ACAD-72`, `-81`: lo que no aplica al tipo no se envía). */
export function buildCreateBody(
  state: ScaleFormState,
  academicYear: string,
  locales: readonly Locale[],
): GradingScaleCreate {
  const type = state.scale_type as ScaleType
  const body: GradingScaleCreate = {
    academic_year: academicYear,
    code: state.code.trim(),
    name_translations: cleanTranslations(state.names, locales),
    scale_type: type,
  }

  if (hasNumericFields(type)) {
    const min = state.min_value.trim()
    const max = state.max_value.trim()
    const passing = state.passing_min_value.trim()

    if (min !== '') body.min_value = min
    if (max !== '') body.max_value = max
    if (state.decimals !== '') body.decimals = Number(state.decimals)
    if (passing !== '') body.passing_min_value = passing
  }

  if (hasValues(type)) {
    body.values = state.values.map((row) => createValue(type, row, locales))
  }

  return body
}

function rowChanges(
  type: string,
  row: ValueRow,
  stored: GradingScaleValue,
  locales: readonly Locale[],
): GradingScaleValuePatch {
  const item: GradingScaleValuePatch = { public_id: stored.public_id }
  const patch = translationsPatch(stored.label_translations, row.labels, locales)

  if (row.code.trim() !== stored.code) item.code = row.code.trim()
  if (Object.keys(patch).length > 0) item.label_translations = patch

  if (type === 'cualitativa') {
    if (row.is_passing !== (stored.is_passing === true)) item.is_passing = row.is_passing
  } else {
    if (row.range_min.trim() !== (stored.range_min ?? '')) item.range_min = row.range_min.trim()
    if (row.range_max.trim() !== (stored.range_max ?? '')) item.range_max = row.range_max.trim()
  }

  return item
}

/** `¿Cambia el conjunto de valores?` (alta, baja, orden o contenido). */
function valuesChanged(
  type: string,
  state: ScaleFormState,
  original: GradingScale,
  locales: readonly Locale[],
): boolean {
  if (state.values.length !== original.values.length) {
    return true
  }

  return state.values.some((row, index) => {
    const stored = original.values[index]

    if (!stored || row.public_id !== stored.public_id) {
      return true
    }

    const changes = rowChanges(type, row, stored, locales)

    return Object.keys(changes).length > 1
  })
}

/**
 * Cuerpo de `PATCH /grading-scales/{id}` con solo lo modificado (`ADR-038 §9.2`);
 * `null` si no hay nada que enviar. `scale_type` no se envía nunca (`RN-ACAD-70`).
 */
export function buildUpdateBody(
  state: ScaleFormState,
  original: GradingScale,
  locales: readonly Locale[],
): GradingScaleUpdate | null {
  const type = original.scale_type
  const body: GradingScaleUpdate = {}

  if (state.code.trim() !== original.code) body.code = state.code.trim()

  const names = translationsPatch(original.name_translations, state.names, locales)

  if (Object.keys(names).length > 0) body.name_translations = names

  if (hasNumericFields(type)) {
    if (state.min_value.trim() !== (original.min_value ?? ''))
      body.min_value = state.min_value.trim()
    if (state.max_value.trim() !== (original.max_value ?? ''))
      body.max_value = state.max_value.trim()
    if (state.decimals !== (original.decimals === null ? '' : String(original.decimals))) {
      body.decimals = Number(state.decimals)
    }
    if (state.passing_min_value.trim() !== (original.passing_min_value ?? '')) {
      body.passing_min_value = state.passing_min_value.trim()
    }
  }

  if (hasValues(type) && valuesChanged(type, state, original, locales)) {
    const stored = new Map(original.values.map((value) => [value.public_id, value]))

    body.values = state.values.map((row) => {
      const current = row.public_id === null ? undefined : stored.get(row.public_id)

      return current === undefined
        ? createValue(type, row, locales)
        : rowChanges(type, row, current, locales)
    })
  }

  return Object.keys(body).length > 0 ? body : null
}

/** Subcampos de una fila que el editor pinta junto a su control. */
export const ROW_FIELDS = ['code', 'label_translations', 'is_passing', 'range_min', 'range_max']

export interface ScaleErrors {
  /** Por campo de la escala (`code`, `min_value`, `name_translations.<idioma>`, ...). */
  fields: Record<string, string[]>
  /** Errores de la lista entera (`errors.values`). */
  list: string[]
  /** Por fila (índice de la lista enviada) y subcampo (`code`, `public_id`, ...). */
  rows: Record<number, Record<string, string[]>>
  /** Lo que ningún control pinta (`academic_year`, `scale_type`, ...): junto a la acción. */
  general: string[]
}

const SCALE_FIELDS = new Set([
  'code',
  'min_value',
  'max_value',
  'decimals',
  'passing_min_value',
  'name_translations',
])

/**
 * Reparte `errors.<campo>` (docblock de `GradingScaleValidator`): campos de la
 * escala, lista (`values`), filas (`values.<n>.<campo>`) y el resto.
 */
export function distributeErrors(errors: Record<string, string[]>): ScaleErrors {
  const result: ScaleErrors = { fields: {}, list: [], rows: {}, general: [] }

  for (const [field, messages] of Object.entries(errors)) {
    const row = /^values\.(\d+)(?:\.(.+))?$/.exec(field)

    if (field === 'values') {
      result.list.push(...messages)
    } else if (row) {
      const index = Number(row[1])
      const sub = row[2] ?? 'public_id'

      result.rows[index] ??= {}
      result.rows[index][sub] = [...(result.rows[index][sub] ?? []), ...messages]
    } else if (SCALE_FIELDS.has(field) || field.startsWith('name_translations.')) {
      result.fields[field] = messages
    } else {
      result.general.push(...messages)
    }
  }

  return result
}
