<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §4.2` (`OPEN-FAM-UNIT-03`). Selector de **una** persona usuaria del centro para
 * darla de alta como tutor/a. Busca por nombre o correo en el listado de usuarios `pendiente` o `activo` de `Core`
 * (`GET /users`, requiere `usuario.leer`) y no comprueba ningún rol. Sin `usuario.leer` no se puede buscar: el
 * formulario ofrece entonces el identificador del usuario (lo decide el llamador). El servidor valida igualmente
 * (`user_not_found`, `user_not_eligible`, `INV-010`).
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useT } from '@/i18n'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { listUsers } from '@/modules/core/api'
import type { User } from '@/modules/core/types'
import { problemDetail } from '../composables/problem'

defineProps<{ modelValue: string | null; idBase: string; legend: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()

const t = useT()

const RESULTS_PER_PAGE = 20
const SEARCH_DELAY_MS = 300

interface UserOption {
  public_id: string
  name: string
}

const search = ref('')
const results = ref<UserOption[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
let timer: ReturnType<typeof setTimeout> | null = null
let sequence = 0

function displayName(user: User): string {
  const person = user.person
  const name = [person.given_name, person.family_name_1, person.family_name_2]
    .filter((part): part is string => typeof part === 'string' && part !== '')
    .join(' ')

  return name !== '' ? `${name} (${user.email})` : user.email
}

async function runSearch(): Promise<void> {
  const mine = ++sequence

  loading.value = true
  error.value = null

  try {
    const page = await listUsers({
      q: search.value.trim() === '' ? undefined : search.value.trim(),
      status: ['activo', 'pendiente'],
      per_page: RESULTS_PER_PAGE,
    })

    if (mine !== sequence) return

    results.value = page.data.map((user) => ({
      public_id: user.public_id,
      name: displayName(user),
    }))
  } catch (err) {
    if (mine === sequence) {
      error.value = problemDetail(err) ?? t('famUnit.userPicker.loadError')
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
</script>

<template>
  <fieldset class="flex flex-col gap-2" data-slot="fam-unit-user-picker">
    <legend class="text-sm font-medium">{{ legend }}</legend>

    <div class="flex flex-col gap-1.5">
      <Label :for="`${idBase}-search`">{{ t('famUnit.userPicker.search') }}</Label>
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
      {{ t('famUnit.userPicker.loading') }}
    </p>
    <p v-else-if="results.length === 0" role="status" class="text-muted-foreground text-sm">
      {{ t('famUnit.userPicker.empty') }}
    </p>

    <ul class="flex max-h-56 flex-col overflow-y-auto" :aria-label="legend">
      <li v-for="user in results" :key="user.public_id">
        <label class="flex min-h-11 items-center gap-2 text-sm">
          <input
            type="radio"
            :name="`${idBase}-choice`"
            :value="user.public_id"
            :checked="modelValue === user.public_id"
            class="size-4"
            @change="emit('update:modelValue', user.public_id)"
          />
          <span>{{ user.name }}</span>
        </label>
      </li>
    </ul>
  </fieldset>
</template>
