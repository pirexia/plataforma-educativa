<script setup lang="ts">
/**
 * RN-AUTH-37: ayuda visual únicamente. La validación que decide es la del
 * servidor (`INV-010`) — este texto describe la política de
 * `RN-AUTH-01`/`RN-AUTH-02` sin implementar ninguna comprobación propia
 * que pudiera aceptar o rechazar algo distinto de lo que decide la API.
 */
import { useT } from '@/i18n'

const t = useT()
</script>

<template>
  <p id="auth-password-policy-hint" class="text-muted-foreground text-xs">
    {{ t('auth.passwordPolicy.hint') }}
  </p>
</template>
