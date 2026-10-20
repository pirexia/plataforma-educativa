<script setup lang="ts">
/**
 * `/academico/reservas` (`docs/modulos/REQ-ACAD/funcional.md §18.8`, `§18.4.6`,
 * `CA-ACAD-138` a `-140`). Reservas de aulas y recursos del curso académico
 * seleccionado con el componente de tablas de 1.9: modo `page`, estado en la URL,
 * orden fijo del servidor (fecha y hora), filtros por espacio y por rango de
 * fechas. El curso académico vive en la URL (`?academic_year=`, `RN-ACAD-40`); sin
 * él, el servidor usa el activo (sin activo, el estado «sin curso activo»).
 *
 * «Nueva reserva» solo con `reservas.crear` **y** si el curso admite escritura
 * (`RN-ACAD-42`); «Cancelar» por fila solo con `reservas.eliminar`. Con el ámbito
 * `propios` (`OPEN-ACAD-42`) el servidor solo cancela las reservas del propio
 * usuario y responde `404` a una ajena: `/me.permissions` no distingue el ámbito,
 * así que el botón se ofrece por permiso y el resultado lo decide el servidor
 * (`INV-002`), que se muestra junto a la acción. Las reservas no se editan
 * (`RN-ACAD-142`): cancelar y volver a reservar.
 */
import { computed, ref, watch } from 'vue'
import { CalendarX } from '@lucide/vue'
import { useT } from '@/i18n'
import { useAcademicYear } from '@/academic-year'
import { ApiError } from '@/api/client'
import { Button } from '@/components/ui/button'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import {
  DataTable,
  type DataTableColumn,
  type DataTableFetcher,
  type DataTableFilter,
} from '@/data-table'
import EmptyState from '@/layouts/components/EmptyState.vue'
import BookingFormDialog from '../components/BookingFormDialog.vue'
import StructureNotices from '../components/StructureNotices.vue'
import {
  createFacilityBooking,
  deleteFacilityBooking,
  listFacilities,
  listFacilityBookings,
} from '../api'
import { formatCivilDate } from '../composables/civilDate'
import { problemErrorCode } from '../composables/problem'
import { useLabels } from '../composables/useLabels'
import { usePermissions } from '../composables/usePermissions'
import { useStructureActions } from '../composables/useStructureActions'
import type { Facility, FacilityBooking } from '../types'

/** Máximo de la API para un selector (`api.md §3`). */
const OPTIONS_PER_PAGE = 100

const t = useT()
const { can } = usePermissions()
const { facilityTypeLabel } = useLabels()
const actions = useStructureActions()
const { urlYear, selectedId, selected, noActive, readOnly, writable } = useAcademicYear()

/** `404 curso.no_active_year` del listado: también es el estado de `RN-ACAD-40`. */
const serverNoActive = ref(false)
const showNoActive = computed(() => noActive.value || serverNoActive.value)

const canCreate = computed(() => can('reservas.crear') && writable.value)
const canDelete = computed(() => can('reservas.eliminar') && writable.value)

const table = ref<{ refresh: () => Promise<void> } | null>(null)
const formOpen = ref(false)
const facilities = ref<Facility[]>([])
/**
 * La tabla no se pinta hasta saber qué espacios hay: un filtro de la URL que la tabla aún no conoce
 * (`?facility=…`) se descartaría al arrancar. Sin `espacios.leer` no hay selector y se pinta ya.
 */
const facilitiesReady = ref(false)

// Los espacios sirven al filtro y al diálogo: se piden una vez (permiso `espacios.leer`).
watch(
  () => can('espacios.leer'),
  async (canRead) => {
    if (!canRead) {
      facilitiesReady.value = true

      return
    }

    try {
      facilities.value = (await listFacilities({ per_page: OPTIONS_PER_PAGE })).data
    } catch {
      facilities.value = []
    } finally {
      facilitiesReady.value = true
    }
  },
  { immediate: true },
)

const filters = computed<DataTableFilter[]>(() => [
  ...(facilities.value.length > 0
    ? [
        {
          type: 'enum',
          id: 'facility',
          labelKey: 'acad.bookings.fields.facility',
          options: facilities.value.map((item) => ({
            value: item.public_id,
            label: `${item.code} · ${item.name}`,
          })),
        } satisfies DataTableFilter,
      ]
    : []),
  { type: 'dateRange', id: 'booked', labelKey: 'acad.bookings.fields.date' },
])

const columns = computed<DataTableColumn<FacilityBooking>[]>(() => [
  {
    id: 'booked_on',
    headerKey: 'acad.bookings.fields.date',
    value: (row) => formatCivilDate(row.booked_on),
    rowHeader: true,
    hideable: false,
    card: 'title',
  },
  {
    id: 'interval',
    headerKey: 'acad.bookings.fields.interval',
    value: (row) => t('acad.timetable.grid.range', { start: row.starts_at, end: row.ends_at }),
    card: 'subtitle',
  },
  {
    id: 'facility',
    headerKey: 'acad.bookings.fields.facility',
    value: (row) =>
      `${row.facility.code} · ${row.facility.name} (${facilityTypeLabel(row.facility.facility_type)})`,
    card: 'field',
  },
  {
    id: 'booked_by',
    headerKey: 'acad.bookings.fields.bookedBy',
    value: (row) => row.booked_by?.name ?? '—',
    card: 'field',
  },
  ...(canDelete.value
    ? [
        {
          id: 'actions',
          headerKey: 'acad.fields.actions',
          hideable: false,
          card: 'actions',
        } satisfies DataTableColumn<FacilityBooking>,
      ]
    : []),
])

function split(value: string | undefined): string[] | undefined {
  return value ? value.split(',').filter((item) => item !== '') : undefined
}

const fetchBookings: DataTableFetcher<FacilityBooking> = async (query) => {
  serverNoActive.value = false

  try {
    return await listFacilityBookings({
      academic_year: urlYear.value ?? undefined,
      facility: split(query.filters.facility),
      booked_from: query.filters.booked_from || undefined,
      booked_to: query.filters.booked_to || undefined,
      page: query.page,
      per_page: query.per_page,
    })
  } catch (err) {
    if (
      err instanceof ApiError &&
      err.status === 404 &&
      problemErrorCode(err, 'academic_year') === 'curso.no_active_year'
    ) {
      serverNoActive.value = true

      return {
        data: [],
        meta: { current_page: 1, per_page: query.per_page ?? 25, total: 0, last_page: 1 },
      }
    }

    throw err
  }
}

function save(body: Record<string, unknown>): Promise<unknown> {
  return createFacilityBooking({
    academic_year: selectedId.value ?? '',
    ...(body as Omit<Parameters<typeof createFacilityBooking>[0], 'academic_year'>),
  })
}

async function onSaved(): Promise<void> {
  actions.clear()
  actions.message.value = t('acad.flash.bookingCreated')
  await table.value?.refresh()
}

async function cancel(booking: FacilityBooking): Promise<void> {
  const when = `${formatCivilDate(booking.booked_on)} ${t('acad.timetable.grid.range', { start: booking.starts_at, end: booking.ends_at })}`
  const done = await actions.confirmAndRun(
    {
      title: t('acad.bookings.confirmCancel.title', { facility: booking.facility.code }),
      description: t('acad.bookings.confirmCancel.description', { when }),
      confirmLabel: t('acad.bookings.confirmCancel.confirm'),
      destructive: true,
    },
    () => deleteFacilityBooking(booking.public_id),
    t('acad.flash.bookingCancelled'),
  )

  if (done) {
    await table.value?.refresh()
  }
}
</script>

<template>
  <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-lg font-semibold">{{ t('acad.bookings.title') }}</h1>
        <p class="text-muted-foreground text-sm">{{ t('acad.bookings.intro') }}</p>
      </div>
      <Button v-if="canCreate && !showNoActive" type="button" @click="formOpen = true">
        {{ t('acad.bookings.new') }}
      </Button>
    </div>

    <StructureNotices
      :read-only="false"
      :message="actions.message.value"
      :action-error="actions.actionError.value"
      :dependents="actions.dependents.value"
    />

    <p
      v-if="readOnly"
      role="note"
      data-slot="acad-bookings-read-only"
      class="border-border bg-muted rounded-lg border px-3 py-2 text-sm"
    >
      {{ t('acad.readOnlyBookings') }}
    </p>

    <!-- RN-ACAD-40: sin curso activo no es un error; el selector sigue a la vista. -->
    <EmptyState
      v-if="showNoActive"
      :icon="CalendarX"
      :title="t('acad.structure.noActive.title')"
      :text="t('acad.structure.noActive.text')"
    />

    <DataTable
      v-else-if="facilitiesReady"
      ref="table"
      :key="urlYear ?? 'active'"
      table-id="acad.facility_bookings"
      :caption="t('acad.bookings.caption')"
      :columns="columns"
      mode="page"
      :fetcher="fetchBookings"
      :filters="filters"
      url-state
      :empty-title="t('acad.bookings.empty')"
      :card-heading-level="2"
    >
      <template #cell-actions="{ row }">
        <div class="flex flex-wrap justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="actions.busy.value"
            :aria-label="
              t('acad.bookings.cancelFor', {
                facility: row.facility.code,
                date: formatCivilDate(row.booked_on),
                start: row.starts_at,
              })
            "
            @click="cancel(row)"
          >
            {{ t('acad.bookings.cancel') }}
          </Button>
        </div>
      </template>
    </DataTable>

    <BookingFormDialog
      v-model:open="formOpen"
      :facilities="facilities"
      :year-start="selected?.starts_on ?? null"
      :year-end="selected?.ends_on ?? null"
      :save="save"
      @saved="onSaved"
    />

    <ConfirmDialog
      :open="actions.confirmation.open.value"
      :request="actions.confirmation.request.value"
      @confirm="actions.confirmation.confirm"
      @cancel="actions.confirmation.cancel"
    />
  </div>
</template>
