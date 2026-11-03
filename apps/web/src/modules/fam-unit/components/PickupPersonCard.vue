<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.4.4`. Una persona de la lista vigente de un alumno: fotografía o «Sin
 * fotografía» + «Verifica con el documento»; nombre; una etiqueta de texto por base; documento **enmascarado tal como
 * llega** (`D-63`) con su tipo traducido; teléfono (enlace `tel:`, `§23.6`).
 *
 * UI-4: `<img>` con la URL firmada tal cual, `referrerpolicy="no-referrer"`, `alt` traducido con el nombre (nunca
 * vacío), sin descarga ni «abrir en pestaña nueva»; si la carga falla (URL caducada o almacenamiento caído) se sustituye
 * por «Fotografía no disponible» con icono y el botón «Volver a cargar la lista» (acción explícita: la URL firmada se
 * recarga con la lista, UI-2). UI-5: no pinta ni deduce nada judicial. WCAG 1.4.1: cada base se distingue por texto.
 */
import { ref } from 'vue'
import { ImageOff, UserRound } from '@lucide/vue'
import { useT } from '@/i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { useDataTableFormatters } from '@/data-table'
import { useLabels } from '../composables/useLabels'
import { useNtLang } from '../composables/useNtLang'
import type { PickupListBasis, PickupListEntry } from '../types'

const props = defineProps<{ entry: PickupListEntry }>()
const emit = defineEmits<{ reload: [] }>()

const t = useT()
const labels = useLabels()
const ntLang = useNtLang()
const { formatDate } = useDataTableFormatters()

/** La imagen emitió `error`: se trata como no disponible, sin ninguna petición automática. */
const imageFailed = ref(false)

function translated(prefix: string, code: string): string {
  const key = `${prefix}.${code}`
  const text = t(key)

  return text === key ? code : text
}

function basisLabel(base: PickupListBasis): string {
  if (base.basis === 'tutor') {
    return t('famUnit.pickup.basis.tutor', {
      relationship: labels.relationshipLabel(base.relationship),
    })
  }

  if (base.basis === 'puntual') {
    return t('famUnit.pickup.basis.puntual')
  }

  return t('famUnit.pickup.basis.tercero')
}

/** Detalle de una base `tercero`: su relación y hasta cuándo (texto, no solo color). */
function basisDetail(base: PickupListBasis): string[] {
  if (base.basis !== 'tercero') {
    return []
  }

  const until =
    base.valid_until === null || base.valid_until === undefined
      ? t('famUnit.pickup.basis.noEnd')
      : t('famUnit.pickup.basis.until', { date: formatDate(base.valid_until) ?? base.valid_until })

  return [labels.pickupLabel('relationship', base.relationship), until]
}

function documentTypeLabel(type: string): string {
  return translated('core.person.documentType', type)
}
</script>

<template>
  <li>
    <Card class="sm:flex-row">
      <div class="shrink-0">
        <img
          v-if="props.entry.has_photo && props.entry.photo_url && !imageFailed"
          :src="props.entry.photo_url"
          :alt="t('famUnit.pickup.photo.alt', { person: props.entry.display_name })"
          referrerpolicy="no-referrer"
          draggable="false"
          class="size-24 rounded-md object-cover"
          @error="imageFailed = true"
        />
        <div
          v-else-if="props.entry.has_photo"
          class="bg-muted text-muted-foreground flex size-24 flex-col items-center justify-center gap-1 rounded-md p-2 text-center text-xs"
        >
          <ImageOff class="size-5" aria-hidden="true" />
          <span>{{ t('famUnit.pickup.photo.unavailable') }}</span>
        </div>
        <div
          v-else
          class="bg-muted text-muted-foreground flex size-24 flex-col items-center justify-center gap-1 rounded-md p-2 text-center text-xs"
        >
          <UserRound class="size-5" aria-hidden="true" />
          <span>{{ t('famUnit.pickup.photo.none') }}</span>
        </div>
      </div>

      <div class="flex min-w-0 flex-col gap-2">
        <h4 class="text-sm font-semibold">{{ props.entry.display_name }}</h4>

        <p v-if="!props.entry.has_photo" :lang="ntLang" class="text-xs font-medium">
          {{ t('famUnit.pickup.photo.verifyDocument') }}
        </p>

        <div
          v-if="props.entry.has_photo && imageFailed"
          class="flex flex-wrap items-center gap-2 text-xs"
        >
          <Button type="button" variant="outline" size="sm" @click="emit('reload')">
            {{ t('famUnit.pickup.list.reload') }}
          </Button>
        </div>

        <ul class="flex flex-wrap gap-2">
          <li
            v-for="(base, index) in props.entry.bases"
            :key="`${base.basis}-${base.authorization ?? base.guardian_link ?? index}`"
            class="flex flex-wrap items-center gap-1"
          >
            <Badge variant="outline">{{ basisLabel(base) }}</Badge>
            <span
              v-for="detail in basisDetail(base)"
              :key="detail"
              class="text-muted-foreground text-xs"
            >
              {{ detail }}
            </span>
          </li>
        </ul>

        <dl class="flex flex-col gap-1 text-xs">
          <div v-if="props.entry.document" class="flex flex-wrap gap-x-2">
            <dt class="text-muted-foreground">{{ t('famUnit.pickup.person.documentNumber') }}</dt>
            <dd>
              <span>{{ documentTypeLabel(props.entry.document.type) }}</span>
              <span class="ml-1 font-mono">{{ props.entry.document.masked }}</span>
            </dd>
          </div>
          <div v-if="props.entry.contact_phone" class="flex flex-wrap gap-x-2">
            <dt class="text-muted-foreground">{{ t('famUnit.pickup.person.phone') }}</dt>
            <dd>
              <a
                :href="`tel:${props.entry.contact_phone}`"
                class="text-primary-on-background underline underline-offset-4"
              >
                {{ props.entry.contact_phone }}
              </a>
            </dd>
          </div>
        </dl>
      </div>
    </Card>
  </li>
</template>
