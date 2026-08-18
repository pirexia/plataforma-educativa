<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { apiFetch, ApiError } from '@/api/client'

interface HealthResponse {
  status: string
  version: string
  timestamp: string
}

const t = useT()

const health = ref<HealthResponse | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)

async function checkHealth() {
  loading.value = true
  error.value = null

  try {
    health.value = await apiFetch<HealthResponse>('/health')
  } catch (err) {
    error.value = err instanceof ApiError ? err.message : t('home.error.unexpected')
  } finally {
    loading.value = false
  }
}

onMounted(checkHealth)
</script>

<template>
  <section class="flex flex-col gap-4">
    <h1 class="text-2xl font-semibold">{{ t('home.title') }}</h1>

    <p v-if="loading" class="text-muted-foreground">{{ t('home.checking') }}</p>
    <p v-else-if="error" class="text-destructive">{{ error }}</p>
    <p v-else-if="health" class="text-muted-foreground">
      {{
        t('home.status', {
          status: health.status,
          version: health.version,
          timestamp: health.timestamp,
        })
      }}
    </p>

    <Button :disabled="loading" @click="checkHealth">{{ t('home.retry') }}</Button>
  </section>
</template>
