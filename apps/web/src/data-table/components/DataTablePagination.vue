<script setup lang="ts">
/**
 * `docs/modulos/REQ-CORE/funcional.md §13.5`, `§13.11`, `CA-CORE-160`/`-161`,
 * `ADR-038 §4.3`. Paginador numerado del modo `page`: primera, anterior,
 * siguiente, última; «página X de Y»; total; `per_page` entre 25, 50 y 100.
 *
 * Foco (`§13.11`): al cambiar de página el foco se queda en el control
 * pulsado; si queda deshabilitado (se llegó a la primera o a la última
 * página), pasa al siguiente control habilitado del paginador y nunca se
 * pierde en `body`.
 */
import { nextTick, ref, watch } from 'vue'
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { PER_PAGE_OPTIONS } from '../constants'
import { useDataTableFormatters } from '../formatters'

const props = defineProps<{
  current: number
  last: number
  total: number
  perPage: number
}>()

const emit = defineEmits<{ page: [page: number]; 'per-page': [perPage: number] }>()

const t = useT()
const { formatNumber } = useDataTableFormatters()

type Control = 'first' | 'previous' | 'next' | 'last'

const buttons = ref<Record<Control, HTMLButtonElement | null>>({
  first: null,
  previous: null,
  next: null,
  last: null,
})
let activated: Control | null = null

function bind(control: Control) {
  return (el: unknown): void => {
    buttons.value[control] = (el as { $el?: HTMLButtonElement } | null)?.$el ?? null
  }
}

function go(control: Control, page: number): void {
  activated = control
  emit('page', page)
}

// Cuando la respuesta llega y el control pulsado queda deshabilitado, el foco pasa al más cercano habilitado.
watch(
  () => [props.current, props.last],
  async () => {
    const control = activated
    activated = null

    if (control === null) {
      return
    }

    await nextTick()

    if (buttons.value[control]?.disabled !== true) {
      return
    }

    const fallbacks: Control[] =
      control === 'next' || control === 'last' ? ['previous', 'first'] : ['next', 'last']

    for (const candidate of fallbacks) {
      const button = buttons.value[candidate]

      if (button && !button.disabled) {
        button.focus()

        return
      }
    }
  },
)

const atStart = () => props.current <= 1
const atEnd = () => props.current >= props.last
</script>

<template>
  <nav
    data-slot="data-table-pagination"
    :aria-label="t('dataTable.pagination.ariaLabel')"
    class="flex flex-col gap-3 text-sm md:flex-row md:items-center md:justify-between"
  >
    <p data-slot="data-table-total" class="text-muted-foreground">
      {{ t('dataTable.pagination.total', { count: formatNumber(props.total) }, props.total) }}
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <Button type="button" variant="outline">
            {{ t('dataTable.pagination.perPage', { count: formatNumber(props.perPage) }) }}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start">
          <DropdownMenuLabel>{{ t('dataTable.pagination.perPageLabel') }}</DropdownMenuLabel>
          <DropdownMenuRadioGroup
            :model-value="String(props.perPage)"
            @update:model-value="(value) => emit('per-page', Number(value))"
          >
            <DropdownMenuRadioItem
              v-for="option in PER_PAGE_OPTIONS"
              :key="option"
              :value="String(option)"
              class="min-h-8 [@media(any-pointer:coarse)]:min-h-11"
            >
              {{ formatNumber(option) }}
            </DropdownMenuRadioItem>
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>

      <Button
        :ref="bind('first')"
        type="button"
        variant="outline"
        size="icon"
        :disabled="atStart()"
        :aria-label="t('dataTable.pagination.first')"
        :title="t('dataTable.pagination.first')"
        @click="go('first', 1)"
      >
        <ChevronsLeft aria-hidden="true" />
      </Button>
      <Button
        :ref="bind('previous')"
        type="button"
        variant="outline"
        size="icon"
        :disabled="atStart()"
        :aria-label="t('dataTable.pagination.previous')"
        :title="t('dataTable.pagination.previous')"
        @click="go('previous', props.current - 1)"
      >
        <ChevronLeft aria-hidden="true" />
      </Button>

      <span data-slot="data-table-page-indicator" class="px-1">
        {{
          t('dataTable.pagination.pageOf', {
            page: formatNumber(props.current),
            lastPage: formatNumber(props.last),
          })
        }}
      </span>

      <Button
        :ref="bind('next')"
        type="button"
        variant="outline"
        size="icon"
        :disabled="atEnd()"
        :aria-label="t('dataTable.pagination.next')"
        :title="t('dataTable.pagination.next')"
        @click="go('next', props.current + 1)"
      >
        <ChevronRight aria-hidden="true" />
      </Button>
      <Button
        :ref="bind('last')"
        type="button"
        variant="outline"
        size="icon"
        :disabled="atEnd()"
        :aria-label="t('dataTable.pagination.last')"
        :title="t('dataTable.pagination.last')"
        @click="go('last', props.last)"
      >
        <ChevronsRight aria-hidden="true" />
      </Button>
    </div>
  </nav>
</template>
