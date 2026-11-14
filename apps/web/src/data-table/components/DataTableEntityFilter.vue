<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §14.7`, `RN-CORE-76`, `OPEN-CORE-33` = C.
 * Filtro `entity` (ampliación aditiva de 1.9d): selección única de una
 * entidad por búsqueda asíncrona. La búsqueda y la resolución de la etiqueta
 * las aporta el consumidor (`RN-CORE-38`); aquí solo hay interfaz.
 *
 * Patrón de divulgación, no de `combobox` completo: un campo de búsqueda y,
 * debajo, la lista de resultados como botones normales (orden de tabulación
 * natural). `Esc` cierra la lista; el número de resultados se anuncia con una
 * región `aria-live`. Con un valor elegido, el campo se sustituye por «Filtrado
 * por: {nombre}» y un botón para quitarlo.
 */
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { X } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { SEARCH_DEBOUNCE_MS } from '../constants'
import type { DataTableEntityFilter, DataTableEntityOption } from '../types'

const props = defineProps<{
  filter: DataTableEntityFilter
  /** Valor serializado (`<id>=<valor>`) o `undefined` si no hay filtro. */
  value: string | undefined
}>()

const emit = defineEmits<{ 'update:value': [value: string] }>()

const t = useT()
const uid = useId()

const text = ref('')
const results = ref<DataTableEntityOption[]>([])
const searching = ref(false)
const searchFailed = ref(false)
/** Hay una búsqueda terminada para el texto actual (distingue «sin resultados» de «sin buscar»). */
const searched = ref(false)

const labels = ref<Record<string, string>>({})
const unresolved = ref<Record<string, true>>({})
const resolving = ref(false)

const searchInput = ref<{ $el?: HTMLInputElement } | null>(null)
const removeButton = ref<{ $el?: HTMLButtonElement } | null>(null)

let timer: ReturnType<typeof setTimeout> | null = null
let abort: AbortController | null = null
let seq = 0

function stopSearch(): void {
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }

  abort?.abort()
  seq += 1
}

function resetSearch(): void {
  stopSearch()
  text.value = ''
  results.value = []
  searching.value = false
  searchFailed.value = false
  searched.value = false
}

async function runSearch(term: string): Promise<void> {
  abort?.abort()
  abort = new AbortController()

  const mine = ++seq

  searching.value = true
  searchFailed.value = false

  try {
    const found = await props.filter.search(term, { signal: abort.signal })

    if (mine !== seq) {
      return
    }

    results.value = found
    searched.value = true
  } catch {
    if (mine !== seq) {
      return
    }

    results.value = []
    searchFailed.value = true
    searched.value = true
  } finally {
    if (mine === seq) {
      searching.value = false
    }
  }
}

function onInput(raw: string | undefined): void {
  text.value = raw ?? ''

  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }

  const term = text.value.trim()

  if (term === '') {
    stopSearch()
    results.value = []
    searching.value = false
    searchFailed.value = false
    searched.value = false

    return
  }

  timer = setTimeout(() => {
    timer = null
    void runSearch(term)
  }, SEARCH_DEBOUNCE_MS)
}

function choose(option: DataTableEntityOption): void {
  labels.value = { ...labels.value, [option.value]: option.label }
  resetSearch()
  emit('update:value', option.value)
  void nextTick(() => removeButton.value?.$el?.focus())
}

function remove(): void {
  emit('update:value', '')
  void nextTick(() => searchInput.value?.$el?.focus())
}

// Etiqueta de un valor que no se ha elegido aquí (viene de la URL): una sola resolución por valor.
watch(
  () => props.value,
  async (value) => {
    if (!value || labels.value[value] !== undefined || unresolved.value[value]) {
      return
    }

    resolving.value = true

    try {
      const label = await props.filter.resolve(value)

      if (label === null) {
        unresolved.value = { ...unresolved.value, [value]: true }
      } else {
        labels.value = { ...labels.value, [value]: label }
      }
    } catch {
      unresolved.value = { ...unresolved.value, [value]: true }
    } finally {
      resolving.value = false
    }
  },
  { immediate: true },
)

function activeName(value: string): string {
  if (labels.value[value] !== undefined) {
    return labels.value[value]
  }

  return unresolved.value[value]
    ? t('dataTable.filters.entityUnknown')
    : t('dataTable.filters.entityLoading')
}

onBeforeUnmount(stopSearch)
</script>

<template>
  <fieldset data-slot="data-table-entity-filter" class="flex min-w-56 flex-col gap-1">
    <legend class="mb-1 text-xs font-medium">{{ t(props.filter.labelKey) }}</legend>

    <div v-if="props.value" class="flex items-center gap-1">
      <span
        class="border-border bg-muted inline-flex min-h-8 items-center rounded-lg border px-2.5 text-sm [@media(any-pointer:coarse)]:min-h-11"
        :aria-busy="resolving ? 'true' : undefined"
      >
        {{ t('dataTable.filters.entityActive', { name: activeName(props.value) }) }}
      </span>
      <Button
        ref="removeButton"
        type="button"
        variant="ghost"
        size="sm"
        :aria-label="t('dataTable.filters.entityRemove', { label: t(props.filter.labelKey) })"
        @click="remove"
      >
        <X aria-hidden="true" />
      </Button>
    </div>

    <div v-else class="relative flex flex-col gap-1">
      <label :for="`${uid}-search`" class="sr-only">{{
        t('dataTable.filters.entitySearch', { label: t(props.filter.labelKey) })
      }}</label>
      <Input
        :id="`${uid}-search`"
        ref="searchInput"
        type="search"
        autocomplete="off"
        :model-value="text"
        :placeholder="t('dataTable.filters.entityPlaceholder')"
        :aria-describedby="`${uid}-status`"
        @update:model-value="onInput"
        @keydown.esc="resetSearch"
      />

      <p
        :id="`${uid}-status`"
        class="text-muted-foreground text-xs"
        aria-live="polite"
        aria-atomic="true"
      >
        <template v-if="searching">{{ t('dataTable.filters.entityLoading') }}</template>
        <template v-else-if="searchFailed">{{ t('dataTable.filters.entityError') }}</template>
        <template v-else-if="searched">{{
          t('dataTable.filters.entityResults', { count: results.length }, results.length)
        }}</template>
      </p>

      <ul
        v-if="results.length > 0"
        data-slot="data-table-entity-results"
        class="border-border bg-background flex max-h-60 flex-col overflow-y-auto rounded-lg border p-1"
      >
        <li v-for="option in results" :key="option.value">
          <button
            type="button"
            class="hover:bg-muted focus-visible:ring-ring/50 min-h-8 w-full rounded-md px-2 py-1 text-left text-sm outline-none focus-visible:ring-3 [@media(any-pointer:coarse)]:min-h-11"
            @click="choose(option)"
          >
            {{ option.label }}
          </button>
        </li>
      </ul>
    </div>
  </fieldset>
</template>
