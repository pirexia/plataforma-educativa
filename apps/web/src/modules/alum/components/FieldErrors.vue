<script setup lang="ts">
/**
 * Errores de un campo del servidor (`ADR-038 §6`), ya traducidos, junto al campo y enlazados por `aria-describedby`
 * (`CA-ALUM-071`). Sin mensajes no pinta nada.
 */
defineProps<{ id: string; messages: string[] }>()
</script>

<template>
  <ul v-if="messages.length > 0" :id="id" role="alert" class="text-destructive text-xs">
    <li v-for="message in messages" :key="message">{{ message }}</li>
  </ul>
</template>
