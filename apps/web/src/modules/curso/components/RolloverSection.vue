<script setup lang="ts">
/**
 * Sección «Copia de estructura» de la ficha de un curso (`docs/modulos/REQ-CURSO/funcional.md §10.6` entradas (b) y
 * (c), 1.12b): con `apertura_curso_academico.leer`, la copia viva o la última hacia este curso (estado traducido, fecha
 * y resultado resumido) con enlace a su detalle; y, en un curso en `planificacion` sin copia viva y con
 * `apertura_curso_academico.crear`, «Copiar estructura de otro curso», que abre el asistente.
 *
 * Permiso, nunca rol (`RN-CORE-61`). En un curso que no está en planificación y no tiene copias no se pinta nada.
 */
import { computed, onMounted, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { useDataTableFormatters } from '@/data-table'
import { listRollovers } from '../api'
import { usePermissions } from '../composables/usePermissions'
import type { AcademicYear, Rollover } from '../types'

const props = defineProps<{ year: AcademicYear }>()

const t = useT()
const { can } = usePermissions()
const { formatDateTime } = useDataTableFormatters()

const LIVE = ['pendiente', 'en_curso', 'completado', 'revirtiendo']

const items = ref<Rollover[]>([])
const loaded = ref(false)
const failed = ref(false)

const canRead = computed(() => can('apertura_curso_academico.leer'))
const current = computed(
  () => items.value.find((item) => LIVE.includes(item.status)) ?? items.value[0] ?? null,
)
const hasLive = computed(() => items.value.some((item) => LIVE.includes(item.status)))
const canStart = computed(
  () =>
    props.year.status === 'planificacion' &&
    can('apertura_curso_academico.crear') &&
    !hasLive.value,
)
const visible = computed(
  () => canStart.value || (canRead.value && (current.value !== null || failed.value)),
)

function statusLabel(status: string): string {
  const key = `curso.rollover.status.${status}`
  const label = t(key)

  return label === key ? status : label
}

const copiedTotal = computed(() =>
  (current.value?.result?.elements ?? []).reduce((total, element) => total + element.copied, 0),
)

onMounted(async () => {
  if (!canRead.value) {
    loaded.value = true

    return
  }

  try {
    items.value = (
      await listRollovers({ academic_year: props.year.public_id, sort: '-created_at', per_page: 5 })
    ).data
  } catch {
    failed.value = true
  } finally {
    loaded.value = true
  }
})
</script>

<template>
  <section
    v-if="loaded && visible"
    class="flex flex-col gap-2"
    aria-labelledby="curso-rollover-title"
  >
    <h2 id="curso-rollover-title" class="text-sm font-semibold">
      {{ t('curso.rollover.section.title') }}
    </h2>

    <p v-if="failed" class="text-muted-foreground text-sm">
      {{ t('curso.rollover.section.loadFailed') }}
    </p>

    <template v-else-if="current">
      <p class="text-sm" data-testid="rollover-section-status">
        {{ statusLabel(current.status) }} · {{ formatDateTime(current.updated_at) }}
        <template v-if="current.result">
          · {{ t('curso.rollover.section.copied', { count: copiedTotal }) }}</template
        >
      </p>
      <p>
        <RouterLink
          :to="{
            name: 'curso-rollover-detail',
            params: { publicId: year.public_id, rolloverId: current.public_id },
          }"
          class="text-sm underline underline-offset-4"
        >
          {{ t('curso.rollover.section.open') }}
        </RouterLink>
      </p>
    </template>

    <p v-else-if="canStart" class="text-muted-foreground text-sm">
      {{ t('curso.rollover.section.none') }}
    </p>

    <div v-if="canStart">
      <Button variant="outline" as-child>
        <RouterLink :to="{ name: 'curso-rollover-wizard', params: { publicId: year.public_id } }">
          {{ t('curso.rollover.section.copy') }}
        </RouterLink>
      </Button>
    </div>
  </section>
</template>
