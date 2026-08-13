<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Button } from '@/components/ui/button'
import { apiFetch, ApiError } from '@/api/client'

interface HealthResponse {
  status: string
  version: string
  timestamp: string
}

const health = ref<HealthResponse | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)

async function checkHealth() {
  loading.value = true
  error.value = null

  try {
    health.value = await apiFetch<HealthResponse>('/health')
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : 'Error inesperado'
  } finally {
    loading.value = false
  }
}

onMounted(checkHealth)
</script>

<template>
  <section class="flex flex-col gap-4">
    <h1 class="text-2xl font-semibold">Estado de la API</h1>

    <p v-if="loading" class="text-muted-foreground">Comprobando…</p>
    <p v-else-if="error" class="text-destructive">{{ error }}</p>
    <p v-else-if="health" class="text-muted-foreground">
      {{ health.status }} · versión {{ health.version }} · {{ health.timestamp }}
    </p>

    <Button :disabled="loading" @click="checkHealth">Volver a comprobar</Button>
  </section>
</template>
