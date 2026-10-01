<script setup lang="ts">
/**
 * `RN-CORE-65`: los mensajes de un campo, ya traducidos por el servidor
 * (`ADR-038 §6.3`), en un único contenedor con `id` estable para el
 * `aria-describedby` del campo. Sin mensajes no pinta nada.
 */
defineProps<{ id: string; messages: readonly string[] | undefined }>()
</script>

<template>
  <div v-if="messages && messages.length > 0" :id="id" class="text-destructive text-sm">
    <p v-for="message in messages" :key="message">{{ message }}</p>
  </div>
</template>
