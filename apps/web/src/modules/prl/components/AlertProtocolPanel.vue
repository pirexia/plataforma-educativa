<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.5.3`, `§23.5.4`, `§23.6`, `DP-5`: lo que se ve de una alerta **abierta**.
 * El aviso de registro de la consulta, el protocolo (texto del servidor, tal cual, en una región con título) y los
 * teléfonos del tutor principal y del contacto de emergencia como enlaces `tel:` (`null` ⇒ «No disponible», sin explicar
 * por qué: puede ser una revocación). WCAG 3.1.2: el protocolo y los textos [NT] llevan `lang="es-ES"` si la interfaz no
 * está en español; 4.1.3: con `announce`, la alerta recién abierta se anuncia con región `assertive`.
 */
import { computed } from 'vue'
import { useT } from '@/i18n'
import { useNtLang } from '../composables/useNtLang'
import type { PickupAlert } from '../types'

const props = defineProps<{ alert: PickupAlert; announce?: boolean; showCallManagement: boolean }>()

const t = useT()
const ntLang = useNtLang()

const phones = computed(() => [
  {
    id: 'principal',
    label: t('prl.alert.phones.principal'),
    number: props.alert.contacts?.principal_phone ?? null,
  },
  {
    id: 'emergency',
    label: t('prl.alert.phones.emergency'),
    number: props.alert.contacts?.emergency_phone ?? null,
  },
])
</script>

<template>
  <div class="flex flex-col gap-4">
    <p v-if="announce" role="alert" aria-live="assertive" class="sr-only">
      {{ t('prl.alert.raised') }}
    </p>

    <p :lang="ntLang" class="text-muted-foreground text-sm">
      {{ t('prl.door.auditNotice') }}
    </p>

    <section
      v-if="alert.protocol"
      aria-labelledby="prl-alert-protocol"
      class="border-border bg-muted flex flex-col gap-2 rounded-lg border p-4"
    >
      <h2 id="prl-alert-protocol" :lang="ntLang" class="text-base font-semibold">
        {{ t('prl.alert.protocolTitle') }}
      </h2>
      <p :lang="ntLang" class="whitespace-pre-line text-sm">{{ alert.protocol }}</p>
    </section>

    <section aria-labelledby="prl-alert-phones" class="flex flex-col gap-2">
      <h2 id="prl-alert-phones" class="text-base font-semibold">
        {{ t('prl.person.phone') }}
      </h2>
      <dl class="flex flex-col gap-1 text-sm">
        <div v-for="phone in phones" :key="phone.id" class="flex flex-wrap gap-x-2">
          <dt class="text-muted-foreground">{{ phone.label }}</dt>
          <dd>
            <a
              v-if="phone.number"
              :href="`tel:${phone.number}`"
              class="text-primary-on-background inline-flex min-h-11 items-center underline underline-offset-4"
            >
              {{ phone.number }}
            </a>
            <span v-else>{{ t('prl.alert.phones.unavailable') }}</span>
          </dd>
        </div>
      </dl>
      <p v-if="showCallManagement" :lang="ntLang" class="text-sm font-medium">
        {{ t('prl.alert.callManagement') }}
      </p>
    </section>
  </div>
</template>
