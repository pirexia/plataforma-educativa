<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §4.2`, `§10` (`REQ-FAM-UNIT-001`). Alta de un/a tutor/a en una unidad: se elige
 * la persona **usuaria** (`POST /guardians` recibe el `public_id` del usuario, no el de la persona). Con `usuario.leer`
 * se busca en el directorio; sin él, se escribe el identificador del usuario. Los errores del servidor ya vienen
 * traducidos (`user_not_found`, `user_not_eligible`, `already_exists`, `ADR-038 §6.3`).
 */
import { computed, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { problemDetail, problemFieldErrors } from '../composables/problem'
import { usePermissions } from '../composables/usePermissions'
import UserPicker from './UserPicker.vue'

const props = defineProps<{ open: boolean; save: (user: string) => Promise<unknown> }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()
const { can } = usePermissions()

const canSearch = computed(() => can('usuario.leer'))
const user = ref<string | null>(null)
const typedUser = ref('')
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

watch(
  () => props.open,
  (open) => {
    if (open) {
      user.value = null
      typedUser.value = ''
      fieldErrors.value = {}
      formError.value = null
    }
  },
  { immediate: true },
)

const chosen = computed(() => (canSearch.value ? user.value : typedUser.value.trim()) || null)
const userErrors = computed(() => fieldErrors.value.user ?? [])
const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(([field]) => field !== 'user')
    .flatMap(([, messages]) => messages),
)

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null

  if (chosen.value === null) {
    formError.value = t('famUnit.guardians.form.chooseUser')

    return
  }

  busy.value = true

  try {
    await props.save(chosen.value)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('famUnit.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('famUnit.form.close')" data-slot="fam-unit-guardian-form">
      <DialogHeader>
        <DialogTitle>{{ t('famUnit.guardians.form.title') }}</DialogTitle>
        <DialogDescription>{{ t('famUnit.guardians.form.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <UserPicker
          v-if="canSearch"
          v-model="user"
          id-base="fam-unit-guardian-user"
          :legend="t('famUnit.guardians.form.user')"
        />
        <div v-else class="flex flex-col gap-1.5">
          <Label for="fam-unit-guardian-user-id">{{ t('famUnit.guardians.form.userId') }}</Label>
          <Input
            id="fam-unit-guardian-user-id"
            v-model="typedUser"
            required
            :aria-invalid="userErrors.length > 0 ? 'true' : undefined"
            aria-describedby="fam-unit-guardian-user-hint"
          />
          <p id="fam-unit-guardian-user-hint" class="text-muted-foreground text-xs">
            {{ t('famUnit.guardians.form.userIdHint') }}
          </p>
        </div>

        <ul v-if="userErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in userErrors" :key="message">{{ message }}</li>
        </ul>
        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('famUnit.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">{{ t('famUnit.guardians.form.submit') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
