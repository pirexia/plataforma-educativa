<script setup lang="ts">
/**
 * `docs/modulos/REQ-ALUM/funcional.md §10` y `RN-ALUM-04`, `OPEN-ALUM-31` = A. Selector **opcional** de una unidad
 * familiar para el alta de un alumno. Busca por prefijo de código en `GET /family-units` (`fam-unit`, requiere
 * `familia.leer`); el llamador solo lo muestra con `familia.actualizar` (el servidor exige ese permiso si llega
 * `family_unit`, `403` y no escribe nada). El servidor valida igualmente (`family_unit_not_found`, `INV-010`).
 */
import { onBeforeUnmount, ref } from 'vue'
import { useT } from '@/i18n'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { listFamilyUnits } from '@/modules/fam-unit/api'
import { problemDetail } from '../composables/problem'

defineProps<{ modelValue: string | null; idBase: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()

const t = useT()

const RESULTS_PER_PAGE = 20
const SEARCH_DELAY_MS = 300

interface UnitOption {
  public_id: string
  code: string
}

const search = ref('')
const results = ref<UnitOption[]>([])
const loading = ref(false)
const searched = ref(false)
const error = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null
let sequence = 0

async function runSearch(): Promise<void> {
  const mine = ++sequence
  const text = search.value.trim()

  loading.value = true
  error.value = null

  try {
    const page = await listFamilyUnits({
      q: text === '' ? undefined : text,
      per_page: RESULTS_PER_PAGE,
    })

    if (mine !== sequence) return

    results.value = page.data.map((unit) => ({ public_id: unit.public_id, code: unit.code }))
    searched.value = true
  } catch (err) {
    if (mine === sequence) {
      error.value = problemDetail(err) ?? t('alum.familyPicker.loadError')
    }
  } finally {
    if (mine === sequence) loading.value = false
  }
}

function onSearchInput(): void {
  if (timer !== null) clearTimeout(timer)

  timer = setTimeout(() => void runSearch(), SEARCH_DELAY_MS)
}

onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})
</script>

<template>
  <fieldset class="flex flex-col gap-2" data-slot="alum-family-picker">
    <legend class="text-sm font-medium">{{ t('alum.familyPicker.legend') }}</legend>
    <p :id="`${idBase}-hint`" class="text-muted-foreground text-xs">
      {{ t('alum.familyPicker.hint') }}
    </p>

    <div class="flex flex-col gap-1.5">
      <Label :for="`${idBase}-search`">{{ t('alum.familyPicker.search') }}</Label>
      <Input
        :id="`${idBase}-search`"
        v-model="search"
        type="search"
        autocomplete="off"
        :aria-describedby="`${idBase}-hint`"
        @input="onSearchInput"
      />
    </div>

    <p v-if="error" role="alert" class="text-destructive text-sm">{{ error }}</p>
    <p v-else-if="loading" role="status" class="text-muted-foreground text-sm">
      {{ t('alum.familyPicker.loading') }}
    </p>
    <p
      v-else-if="searched && results.length === 0"
      role="status"
      class="text-muted-foreground text-sm"
    >
      {{ t('alum.familyPicker.empty') }}
    </p>

    <ul class="flex max-h-48 flex-col overflow-y-auto" :aria-label="t('alum.familyPicker.legend')">
      <li>
        <label class="flex min-h-11 items-center gap-2 text-sm">
          <input
            type="radio"
            :name="`${idBase}-choice`"
            value=""
            :checked="modelValue === null"
            class="size-4"
            @change="emit('update:modelValue', null)"
          />
          <span>{{ t('alum.familyPicker.none') }}</span>
        </label>
      </li>
      <li v-for="unit in results" :key="unit.public_id">
        <label class="flex min-h-11 items-center gap-2 text-sm">
          <input
            type="radio"
            :name="`${idBase}-choice`"
            :value="unit.public_id"
            :checked="modelValue === unit.public_id"
            class="size-4"
            @change="emit('update:modelValue', unit.public_id)"
          />
          <span>{{ unit.code }}</span>
        </label>
      </li>
    </ul>
  </fieldset>
</template>
