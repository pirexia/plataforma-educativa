<script setup lang="ts">
/**
 * Bloque "los códigos de respaldo se muestran una sola vez"
 * (funcional.md §C.4.3 punto 5, §C.11). Común a la confirmación del
 * primer factor (`MfaTotpEnrollment.vue`) y a la regeneración
 * (`AccountSecurityView.vue`): las dos únicas llamadas del módulo cuyo
 * cuerpo trae `recovery_codes` en claro.
 *
 * Los códigos se muestran como texto seleccionable y descargable, nunca
 * como imagen (WCAG 2.2 AA). El botón "continuar" exige una confirmación
 * explícita —no basta con cerrar— antes de que el padre pueda descartar
 * los códigos de memoria.
 */
import { ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'

defineProps<{ codes: string[] }>()
const emit = defineEmits<{ acknowledged: [] }>()

const t = useT()
const acknowledged = ref(false)

function copy(codes: string[]): void {
  void navigator.clipboard?.writeText(codes.join('\n')).catch(() => {
    // Sin permiso de portapapeles: el bloque sigue siendo seleccionable a mano.
  })
}

function download(codes: string[]): void {
  const blob = new Blob([codes.join('\n') + '\n'], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'codigos-de-respaldo.txt'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <p role="alert" class="text-sm">{{ t('auth.mfa.recoveryCodes.onceWarning') }}</p>

    <ul
      class="border-border bg-muted grid select-all grid-cols-2 gap-x-4 gap-y-1 rounded-lg border px-4 py-3 font-mono text-sm"
    >
      <li v-for="rc in codes" :key="rc">{{ rc }}</li>
    </ul>

    <div class="flex gap-2">
      <Button type="button" variant="outline" @click="copy(codes)">
        {{ t('auth.mfa.recoveryCodes.copy') }}
      </Button>
      <Button type="button" variant="outline" @click="download(codes)">
        {{ t('auth.mfa.recoveryCodes.download') }}
      </Button>
    </div>

    <label class="flex items-start gap-2 text-sm">
      <input
        v-model="acknowledged"
        type="checkbox"
        class="mt-0.5"
        data-testid="recovery-codes-ack"
      />
      <span>{{ t('auth.mfa.recoveryCodes.acknowledge') }}</span>
    </label>

    <Button type="button" :disabled="!acknowledged" @click="emit('acknowledged')">
      {{ t('auth.mfa.recoveryCodes.continue') }}
    </Button>
  </div>
</template>
