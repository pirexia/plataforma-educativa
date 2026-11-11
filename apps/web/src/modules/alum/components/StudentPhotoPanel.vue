<script setup lang="ts">
/**
 * `docs/modulos/REQ-ALUM/funcional.md §10` (foto de la ficha), `api.md §4`, `RN-ALUM-50` a `RN-ALUM-52`, `OPEN-ALUM-08`.
 * La ficha trae solo `has_photo`; la **URL firmada** (5 minutos) se pide a `GET /students/{id}/photo` **solo por una
 * acción explícita** («Mostrar fotografía»): cada lectura queda auditada (`read`) y gasta cupo (300 por hora), así que
 * no se lanza al montar ni por sondeo (`frontend-spa` regla 7, `UI-2`). La URL no se persiste en el navegador; al
 * caducar se retira y hay que volver a pedirla. `404` (sin foto o fuera de ámbito) y `429` se muestran con el mensaje
 * del servidor. Subir, sustituir o quitar solo con `alumno.actualizar` (`canWrite`); el servidor decide (`INV-002`).
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { getStudentPhotoUrl } from '../api'
import { problemDetail } from '../composables/problem'
import StudentPhotoUpload from './StudentPhotoUpload.vue'

const props = defineProps<{
  studentId: string
  studentName: string
  hasPhoto: boolean
  canWrite: boolean
  upload: (file: File) => Promise<unknown>
}>()
const emit = defineEmits<{ uploaded: []; remove: [] }>()

const t = useT()

const url = ref<string | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const expired = ref(false)
const replacing = ref(false)
/** Tope de visibilidad de la fotografía una vez cargada. */
const MAX_VISIBLE_MS = 5 * 60 * 1000
let timer: ReturnType<typeof setTimeout> | null = null

const canShow = computed(() => props.hasPhoto)

function clearTimer(): void {
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }
}

function hide(): void {
  clearTimer()
  url.value = null
}

async function show(): Promise<void> {
  loading.value = true
  error.value = null
  expired.value = false
  clearTimer()

  try {
    const result = await getStudentPhotoUrl(props.studentId)
    const remaining = new Date(result.expires_at).getTime() - Date.now()

    url.value = result.url

    // Tope fijo (la URL firmada caduca pronto): el menor entre el restante válido y 5 min; con un valor vacío,
    // mal formado o vencido por reloj desfasado, 5 min. La imagen ya cargada nunca queda visible sin límite.
    const delay =
      Number.isFinite(remaining) && remaining > 0
        ? Math.min(remaining, MAX_VISIBLE_MS)
        : MAX_VISIBLE_MS

    timer = setTimeout(() => {
      url.value = null
      expired.value = true
    }, delay)
  } catch (err) {
    url.value = null
    error.value = problemDetail(err) ?? t('alum.errors.unexpected')
  } finally {
    loading.value = false
  }
}

function onUploaded(): void {
  hide()
  replacing.value = false
  expired.value = false
  emit('uploaded')
}

onBeforeUnmount(clearTimer)
</script>

<template>
  <div class="flex flex-col gap-3" data-slot="alum-student-photo">
    <p v-if="!hasPhoto" class="text-muted-foreground text-sm">{{ t('alum.photo.none') }}</p>

    <template v-else>
      <img
        v-if="url"
        :src="url"
        :alt="t('alum.photo.alt', { student: studentName })"
        referrerpolicy="no-referrer"
        draggable="false"
        class="size-32 rounded-md object-cover"
      />
      <p v-if="loading" role="status" class="text-muted-foreground text-sm">
        {{ t('alum.photo.loading') }}
      </p>
      <p v-if="expired" role="status" class="text-muted-foreground text-sm">
        {{ t('alum.photo.expired') }}
      </p>
      <p v-if="error" role="alert" class="text-destructive text-sm">{{ error }}</p>
    </template>

    <div class="flex flex-wrap gap-2">
      <Button
        v-if="canShow && !url"
        type="button"
        variant="outline"
        size="sm"
        :disabled="loading"
        @click="show"
      >
        {{ t('alum.photo.show') }}
      </Button>
      <Button v-if="canShow && url" type="button" variant="outline" size="sm" @click="hide">
        {{ t('alum.photo.hide') }}
      </Button>
      <Button
        v-if="canWrite && hasPhoto"
        type="button"
        variant="outline"
        size="sm"
        @click="replacing = !replacing"
      >
        {{ t('alum.photo.replace') }}
      </Button>
      <Button
        v-if="canWrite && hasPhoto"
        type="button"
        variant="outline"
        size="sm"
        @click="emit('remove')"
      >
        {{ t('alum.photo.remove') }}
      </Button>
    </div>

    <StudentPhotoUpload
      v-if="canWrite && (!hasPhoto || replacing)"
      :id-prefix="`alum-photo-${studentId}`"
      :student-name="studentName"
      :upload="upload"
      :submit-label="hasPhoto ? t('alum.photo.submitReplace') : t('alum.photo.submitUpload')"
      @uploaded="onUploaded"
    />
  </div>
</template>
