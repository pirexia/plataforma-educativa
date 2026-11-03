<script setup lang="ts">
/**
 * `/conserjeria` (`docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.1`, `REQ-PRL-004`; `1.14b-c2` T1). Búsqueda de alumno
 * por nombre o apellidos en la puerta. `GET /door/students?q=` solo con **2 a 100 caracteres** (por debajo, ninguna
 * petición y el texto de ayuda), con espera de 300 ms tras dejar de teclear y cancelación de la petición anterior: una
 * respuesta obsoleta nunca pisa a la de un término posterior (número de petición + `AbortController`).
 *
 * UI-3: el término de búsqueda **no** se guarda en la URL, en `history.state`, en `document.title` ni en el
 * almacenamiento; el nombre del alumno elegido pasa a la lista de puerta solo por memoria del módulo
 * (`useDoorContext`, `OPEN-FAM-UNIT-58` A). Resultado: hasta 20 alumnos (nombre y código de unidad), cada uno un enlace
 * a la lista de puerta, con anuncio *polite* del número de resultados. Estados: inicial (ayuda), carga, vacío, error,
 * `422` (mensaje del servidor) y `403`.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useT } from '@/i18n'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { resolveErrorState, type ShellErrorState } from '@/layouts/errorState'
import ErrorState from '@/layouts/components/ErrorState.vue'
import LoadingState from '@/layouts/components/LoadingState.vue'
import { searchDoorStudents } from '../api'
import { problemDetail, problemFieldErrors, problemStatus } from '../composables/problem'
import { useDoorContext } from '../composables/useDoorContext'
import { usePermissions } from '../composables/usePermissions'
import type { DoorStudent } from '../types'

const MIN_CHARS = 2
const MAX_CHARS = 100
const DEBOUNCE_MS = 300

const t = useT()
const { can } = usePermissions()
const door = useDoorContext()

const term = ref('')
const results = ref<DoorStudent[] | null>(null)
const loading = ref(false)
const error = ref<ShellErrorState | null>(null)
const validationMessage = ref<string | null>(null)

let timer: ReturnType<typeof setTimeout> | null = null
let controller: AbortController | null = null
let sequence = 0

const canSearch = computed(() => {
  const length = term.value.trim().length

  return length >= MIN_CHARS && length <= MAX_CHARS
})

function cancelPending(): void {
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }

  controller?.abort()
  controller = null
}

async function run(query: string, id: number): Promise<void> {
  controller = new AbortController()

  const mine = controller

  try {
    const response = await searchDoorStudents(query, mine.signal)

    if (id !== sequence) return

    results.value = response.data
  } catch (err) {
    // Cancelada o superada por un término posterior: no pinta nada.
    if (id !== sequence || mine.signal.aborted) return

    results.value = null

    if (problemStatus(err) === 422) {
      validationMessage.value =
        problemFieldErrors(err).q?.[0] ??
        problemDetail(err) ??
        t('shell.states.error.unexpected.text')
    } else {
      error.value = resolveErrorState(err) ?? { kind: 'unexpected' }
    }
  } finally {
    if (id === sequence) {
      loading.value = false
    }
  }
}

function schedule(): void {
  // Cada cambio de término invalida lo que hubiera en vuelo o a la espera.
  cancelPending()
  sequence += 1
  error.value = null
  validationMessage.value = null

  if (!canSearch.value) {
    results.value = null
    loading.value = false

    return
  }

  const id = sequence
  const query = term.value.trim()

  loading.value = true
  timer = setTimeout(() => {
    timer = null
    void run(query, id)
  }, DEBOUNCE_MS)
}

function retry(): void {
  if (!canSearch.value) return

  cancelPending()
  sequence += 1
  error.value = null
  validationMessage.value = null
  loading.value = true
  void run(term.value.trim(), sequence)
}

watch(term, schedule)

onBeforeUnmount(() => {
  cancelPending()
  sequence += 1
})

function choose(student: DoorStudent): void {
  door.remember(student.student_person, {
    displayName: student.display_name,
    familyUnitCode: student.family_unit_code,
  })
}

/** `403` con el texto propio de §23.5.1 y sin «Reintentar» (el servidor ya decidió, UI-1). */
const forbidden = computed(() => error.value?.kind === 'forbidden')

const showHelp = computed(() => !canSearch.value)
</script>

<template>
  <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <h1 class="text-lg font-semibold">{{ t('prl.door.title') }}</h1>
      <RouterLink
        v-if="can('alerta_entrega.leer')"
        :to="{ name: 'prl-alerts' }"
        class="text-primary-on-background inline-flex min-h-11 items-center underline underline-offset-4"
      >
        {{ t('prl.nav.alerts') }}
      </RouterLink>
    </div>

    <div class="flex flex-col gap-2">
      <Label for="prl-door-search">{{ t('prl.search.label') }}</Label>
      <Input
        id="prl-door-search"
        v-model="term"
        type="search"
        autocomplete="off"
        :maxlength="MAX_CHARS"
        class="min-h-11"
        :aria-describedby="showHelp ? 'prl-door-search-help' : undefined"
      />
      <p v-if="showHelp" id="prl-door-search-help" class="text-muted-foreground text-sm">
        {{ t('prl.search.minChars') }}
      </p>
    </div>

    <p v-if="validationMessage" role="alert" class="text-sm">{{ validationMessage }}</p>

    <LoadingState v-if="loading" />

    <p v-else-if="forbidden" role="alert" class="text-sm">{{ t('prl.states.forbidden') }}</p>
    <ErrorState v-else-if="error" :state="error" @retry="retry" />

    <!-- Anuncio polite del resultado (4.1.3): la región existe siempre, su texto cambia. -->
    <div aria-live="polite" role="status">
      <template v-if="!loading && !error && !validationMessage && results !== null">
        <p v-if="results.length === 0" class="text-sm">{{ t('prl.search.empty') }}</p>
        <p v-else class="text-muted-foreground text-sm">
          {{ t('prl.search.results', { count: results.length }, results.length) }}
        </p>
      </template>
    </div>

    <ul
      v-if="!loading && !error && results !== null && results.length > 0"
      class="flex flex-col gap-2"
    >
      <li v-for="student in results" :key="student.student_person">
        <RouterLink
          :to="{
            name: 'prl-door-student',
            params: { studentPersonPublicId: student.student_person },
          }"
          class="border-border hover:bg-muted flex min-h-11 flex-col rounded-lg border px-4 py-3"
          @click="choose(student)"
        >
          <span class="text-base font-medium">{{ student.display_name }}</span>
          <span v-if="student.family_unit_code" class="text-muted-foreground text-sm">
            {{ t('prl.search.unitCode', { code: student.family_unit_code }) }}
          </span>
        </RouterLink>
      </li>
    </ul>
  </div>
</template>
