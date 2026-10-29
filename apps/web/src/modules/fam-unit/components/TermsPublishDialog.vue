<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §4.9`, `§5.4`, `§5.5` (`RN-FAM-UNIT-60`, `-62`, `-70`). Publicación de un
 * borrador: la versión publicada anterior **del mismo tipo** pasa a `retirada` en la misma transacción. Solo en las
 * condiciones de uso se elige si exige que quien ya aceptó una versión anterior vuelva a aceptar; en un texto de imagen
 * no se ofrece (el servidor lo rechaza, `terms.requires_reacceptance_not_allowed`) y publicarlo no altera ningún estado
 * de consentimiento ya registrado. Los errores del servidor (`default_locale_missing`, `not_draft`) ya vienen
 * traducidos y se muestran en el diálogo.
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
import { problemDetail, problemFieldErrors } from '../composables/problem'
import { useLabels } from '../composables/useLabels'
import type { TermsVersion } from '../types'

const props = defineProps<{
  open: boolean
  version: TermsVersion | null
  /** `requiresReacceptance` es `undefined` para un tipo de imagen. */
  save: (version: TermsVersion, requiresReacceptance: boolean | undefined) => Promise<unknown>
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()
const { termsKindLabel } = useLabels()

const requiresReacceptance = ref(false)
const busy = ref(false)
const errors = ref<string[]>([])

const isTerms = computed(() => props.version?.kind === 'condiciones_uso')

watch(
  () => [props.open, props.version?.public_id],
  () => {
    if (props.open) {
      requiresReacceptance.value = false
      errors.value = []
    }
  },
  { immediate: true },
)

async function submit(): Promise<void> {
  if (props.version === null) return

  errors.value = []
  busy.value = true

  try {
    await props.save(props.version, isTerms.value ? requiresReacceptance.value : undefined)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    const fields = Object.values(problemFieldErrors(err)).flat()
    const detail = problemDetail(err)

    errors.value =
      detail !== null
        ? [detail, ...fields]
        : fields.length > 0
          ? fields
          : [t('famUnit.errors.unexpected')]
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('famUnit.form.close')" data-slot="fam-unit-terms-publish">
      <DialogHeader>
        <DialogTitle>
          {{
            t('famUnit.terms.publish.title', {
              kind: version ? termsKindLabel(version.kind) : '',
              number: version?.version_number ?? '',
            })
          }}
        </DialogTitle>
        <DialogDescription>
          {{
            t(
              isTerms
                ? 'famUnit.terms.publish.descriptionTerms'
                : 'famUnit.terms.publish.descriptionImage',
            )
          }}
        </DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div v-if="isTerms" class="flex flex-col gap-1">
          <label class="flex min-h-11 items-center gap-2 text-sm">
            <input v-model="requiresReacceptance" type="checkbox" class="size-4" />
            {{ t('famUnit.terms.publish.requiresReacceptance') }}
          </label>
          <p class="text-muted-foreground text-xs">
            {{ t('famUnit.terms.publish.requiresReacceptanceHint') }}
          </p>
        </div>

        <ul v-if="errors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in errors" :key="message">{{ message }}</li>
        </ul>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('famUnit.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">{{ t('famUnit.terms.publish.confirm') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
