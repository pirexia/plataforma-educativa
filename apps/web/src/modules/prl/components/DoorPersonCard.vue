<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.2` (tarjeta de la lista de puerta), `§23.6`, `§23.7`. Una persona de la
 * lista de puerta de un alumno, con el botón «Entregar a {persona}» si `canDeliver` (`1.14b-c2` T2; el diálogo es del padre): fotografía grande (lado de 160 px CSS, la verificación
 * visual es el fin del requisito) o «Sin fotografía» + «Verifica con el documento»; nombre; una etiqueta de texto por
 * base; documento **enmascarado tal como llega** con su tipo traducido; teléfono (enlace `tel:`).
 *
 * UI-4: `<img>` con la URL firmada tal cual, `referrerpolicy="no-referrer"`, `alt` traducido con el nombre (nunca
 * vacío), sin descarga ni «abrir en pestaña nueva»; si la carga falla (URL caducada o almacenamiento caído) se sustituye
 * por «Fotografía no disponible» con icono y el botón «Volver a cargar la lista» (acción explícita: la URL firmada se
 * recarga con la lista, UI-2). UI-5: no pinta ni deduce nada judicial. WCAG 1.4.1: cada base y el estado de la
 * fotografía se distinguen por texto e icono. `prl` no importa nada de `fam-unit`: la tarjeta se duplica (`AR-11`).
 */
import { ref, watch } from 'vue'
import { ImageOff, UserRound } from '@lucide/vue'
import { useT } from '@/i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { useNtLang } from '../composables/useNtLang'
import type { DoorPickupBasis, PickupListEntry } from '../types'

const props = defineProps<{ entry: PickupListEntry; canDeliver?: boolean }>()
const emit = defineEmits<{
  reload: []
  deliver: [entry: PickupListEntry]
  photoError: [person: string]
}>()

const t = useT()
const ntLang = useNtLang()

/** La imagen emitió `error`: se trata como no disponible, sin ninguna petición automática. */
const imageFailed = ref(false)

// Una recarga trae una URL firmada nueva: la imagen se vuelve a intentar.
watch(
  () => props.entry.photo_url,
  () => {
    imageFailed.value = false
  },
)

function onImageError(): void {
  imageFailed.value = true
  emit('photoError', props.entry.person)
}

function translated(prefix: string, code: string): string {
  const key = `${prefix}.${code}`
  const text = t(key)

  return text === key ? code : text
}

function basisLabel(base: DoorPickupBasis): string {
  return translated('prl.basis', base.basis)
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
          :alt="t('prl.photo.alt', { person: props.entry.display_name })"
          referrerpolicy="no-referrer"
          draggable="false"
          class="size-40 rounded-md object-cover"
          @error="onImageError"
        />
        <div
          v-else-if="props.entry.has_photo"
          class="bg-muted text-muted-foreground flex size-40 flex-col items-center justify-center gap-1 rounded-md p-2 text-center text-sm"
        >
          <ImageOff class="size-6" aria-hidden="true" />
          <span>{{ t('prl.photo.unavailable') }}</span>
        </div>
        <div
          v-else
          class="bg-muted text-muted-foreground flex size-40 flex-col items-center justify-center gap-1 rounded-md p-2 text-center text-sm"
        >
          <UserRound class="size-6" aria-hidden="true" />
          <span>{{ t('prl.photo.none') }}</span>
        </div>
      </div>

      <div class="flex min-w-0 flex-col gap-2">
        <h2 class="text-base font-semibold">{{ props.entry.display_name }}</h2>

        <p v-if="!props.entry.has_photo" :lang="ntLang" class="text-sm font-medium">
          {{ t('prl.photo.verifyDocument') }}
        </p>

        <div v-if="props.entry.has_photo && imageFailed" class="flex flex-wrap items-center gap-2">
          <Button type="button" variant="outline" class="min-h-11" @click="emit('reload')">
            {{ t('prl.door.reload') }}
          </Button>
        </div>

        <ul class="flex flex-wrap gap-2">
          <li
            v-for="(base, index) in props.entry.bases"
            :key="`${base.basis}-${base.authorization ?? base.guardian_link ?? index}`"
          >
            <Badge variant="outline">{{ basisLabel(base) }}</Badge>
          </li>
        </ul>

        <dl class="flex flex-col gap-1 text-sm">
          <div v-if="props.entry.document" class="flex flex-wrap gap-x-2">
            <dt class="text-muted-foreground">{{ t('prl.person.documentNumber') }}</dt>
            <dd>
              <span>{{ documentTypeLabel(props.entry.document.type) }}</span>
              <span class="ml-1 font-mono">{{ props.entry.document.masked }}</span>
            </dd>
          </div>
          <div v-if="props.entry.contact_phone" class="flex flex-wrap gap-x-2">
            <dt class="text-muted-foreground">{{ t('prl.person.phone') }}</dt>
            <dd>
              <a
                :href="`tel:${props.entry.contact_phone}`"
                class="text-primary-on-background inline-flex min-h-11 items-center underline underline-offset-4"
              >
                {{ props.entry.contact_phone }}
              </a>
            </dd>
          </div>
        </dl>

        <div v-if="props.canDeliver" class="pt-1">
          <Button type="button" class="min-h-11" @click="emit('deliver', props.entry)">
            {{ t('prl.door.deliver', { person: props.entry.display_name }) }}
          </Button>
        </div>
      </div>
    </Card>
  </li>
</template>
