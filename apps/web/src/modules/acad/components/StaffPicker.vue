<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §18.4.1` (`RN-ACAD-92`), `§18.8`. Selector de
 * usuarios del centro a quienes se asigna docencia o tutoría. Busca por nombre o
 * correo en el listado de usuarios activos de `Core` (`GET /users`, `status=activo`;
 * requiere `usuario.leer`) y no comprueba ningún rol: cualquier usuario activo es
 * asignable. Los elegidos se conservan aunque dejen de salir en la búsqueda. Una
 * sola elección (`multiple = false`, radio) o varias (casillas). El servidor valida
 * igualmente (`INV-010`).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useT } from '@/i18n'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { listUsers } from '@/modules/core/api'
import type { User } from '@/modules/core/types'
import { problemDetail } from '../composables/problem'
import type { StaffSummary } from '../types'

const props = withDefaults(
  defineProps<{
    modelValue: string[]
    /** Usuarios ya elegidos, para mostrar su nombre sin buscarlos. */
    initial?: StaffSummary[]
    multiple?: boolean
    idBase: string
    legend: string
    invalid?: boolean
    describedby?: string
  }>(),
  { initial: () => [], multiple: false, invalid: false, describedby: undefined },
)

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()

const t = useT()

/** Un centro tiene decenas o cientos de usuarios: la búsqueda acota; se muestran los primeros. */
const RESULTS_PER_PAGE = 20
const SEARCH_DELAY_MS = 300

const search = ref('')
const results = ref<StaffSummary[]>([])
const known = ref<Record<string, string>>(
  Object.fromEntries(props.initial.map((person) => [person.public_id, person.name])),
)
const loading = ref(false)
const error = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null
let sequence = 0

function displayName(user: User): string {
  const person = user.person
  const name = [person.given_name, person.family_name_1, person.family_name_2]
    .filter((part): part is string => typeof part === 'string' && part !== '')
    .join(' ')

  return name !== '' ? name : user.email
}

async function runSearch(): Promise<void> {
  const mine = ++sequence

  loading.value = true
  error.value = null

  try {
    const page = await listUsers({
      q: search.value.trim() === '' ? undefined : search.value.trim(),
      status: ['activo'],
      per_page: RESULTS_PER_PAGE,
    })

    if (mine !== sequence) return

    results.value = page.data.map((user) => ({
      public_id: user.public_id,
      name: displayName(user),
    }))

    for (const person of results.value) {
      known.value[person.public_id] = person.name
    }
  } catch (err) {
    if (mine === sequence) {
      error.value = problemDetail(err) ?? t('acad.staff.loadError')
    }
  } finally {
    if (mine === sequence) loading.value = false
  }
}

function onSearchInput(): void {
  if (timer !== null) clearTimeout(timer)

  timer = setTimeout(() => void runSearch(), SEARCH_DELAY_MS)
}

onMounted(() => void runSearch())
onBeforeUnmount(() => {
  if (timer !== null) clearTimeout(timer)
})

/** Elegidos que no salen en la búsqueda actual, para poder quitarlos. */
const hidden = computed(() =>
  props.modelValue
    .filter((id) => !results.value.some((person) => person.public_id === id))
    .map((id) => ({ public_id: id, name: known.value[id] ?? id })),
)

const options = computed(() => [...hidden.value, ...results.value])

function toggle(id: string, checked: boolean): void {
  if (!props.multiple) {
    emit('update:modelValue', checked ? [id] : [])

    return
  }

  emit(
    'update:modelValue',
    checked ? [...props.modelValue, id] : props.modelValue.filter((current) => current !== id),
  )
}
</script>

<template>
  <fieldset
    class="flex flex-col gap-2"
    :aria-invalid="invalid ? 'true' : undefined"
    :aria-describedby="describedby"
    data-slot="acad-staff-picker"
  >
    <legend class="text-sm font-medium">{{ legend }}</legend>

    <div class="flex flex-col gap-1.5">
      <Label :for="`${idBase}-search`">{{ t('acad.staff.search') }}</Label>
      <Input
        :id="`${idBase}-search`"
        v-model="search"
        type="search"
        autocomplete="off"
        @input="onSearchInput"
      />
    </div>

    <p v-if="error" role="alert" class="text-destructive text-sm">{{ error }}</p>
    <p v-else-if="loading" role="status" class="text-muted-foreground text-sm">
      {{ t('acad.staff.loading') }}
    </p>
    <p v-else-if="options.length === 0" role="status" class="text-muted-foreground text-sm">
      {{ t('acad.staff.empty') }}
    </p>

    <ul class="flex max-h-56 flex-col overflow-y-auto" :aria-label="legend">
      <li v-for="person in options" :key="person.public_id">
        <label class="flex min-h-11 items-center gap-2 text-sm">
          <input
            :type="multiple ? 'checkbox' : 'radio'"
            :name="`${idBase}-choice`"
            :value="person.public_id"
            :checked="modelValue.includes(person.public_id)"
            class="size-4"
            @change="toggle(person.public_id, ($event.target as HTMLInputElement).checked)"
          />
          <span>{{ person.name }}</span>
        </label>
      </li>
    </ul>
  </fieldset>
</template>
