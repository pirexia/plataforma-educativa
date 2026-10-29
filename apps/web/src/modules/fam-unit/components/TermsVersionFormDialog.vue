<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §4.9`, `§10`, `ADR-062 §5.7` (`REQ-FAM-UNIT-003`/`-004`). Alta y edición del
 * **borrador** de un texto legal: el tipo (`kind`) se elige solo en el alta y es inmutable después; el título y el texto
 * se editan con el campo traducible común, una entrada por idioma activo del centro y el idioma por defecto primero y
 * obligatorio. En la edición solo viajan los idiomas que cambian (fusión por idioma, `ADR-062 §5.2`). El texto es
 * **contenido del centro**: no se traduce con ficheros de idioma ni se interpreta como HTML. Los errores del servidor
 * ya vienen traducidos (`ADR-038 §6.3`) y se muestran junto a su idioma o campo.
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
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import TranslatableField from '@/components/TranslatableField.vue'
import type { Locale } from '@/modules/core/types'
import { cleanTranslations, translationsPatch, type TranslationValues } from '@/lib/translatable'
import { useTenantLocales } from '@/tenant/useTenantLocales'
import { problemDetail, problemFieldErrors } from '../composables/problem'
import { useLabels } from '../composables/useLabels'
import type { TermsVersion } from '../types'

const KINDS = ['condiciones_uso', 'consentimiento_imagen_web', 'consentimiento_imagen_redes']

const props = defineProps<{
  open: boolean
  /** `null` = alta. */
  version: TermsVersion | null
  save: (body: Record<string, unknown>) => Promise<unknown>
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()
const { defaultLocale, locales } = useTenantLocales()
const { termsKindLabel } = useLabels()

const mode = computed(() => (props.version === null ? 'create' : 'edit'))

const kind = ref('')
const title = ref<TranslationValues>({})
const body = ref<TranslationValues>({})
const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

function reset(): void {
  kind.value = props.version?.kind ?? ''
  title.value = { ...(props.version?.title_translations ?? {}) }
  body.value = { ...(props.version?.body_translations ?? {}) }
  fieldErrors.value = {}
  formError.value = null
}

watch(
  () => [props.open, props.version?.public_id],
  () => {
    if (props.open) {
      reset()
    }
  },
  { immediate: true },
)

function localeErrorsOf(field: string): Partial<Record<Locale, string[]>> {
  const result: Partial<Record<Locale, string[]>> = {}
  const pattern = new RegExp(`^${field}\\.(.+)$`)

  for (const [name, messages] of Object.entries(fieldErrors.value)) {
    const match = pattern.exec(name)

    if (match) {
      result[match[1] as Locale] = messages
    }
  }

  return result
}

const titleLocaleErrors = computed(() => localeErrorsOf('title_translations'))
const bodyLocaleErrors = computed(() => localeErrorsOf('body_translations'))

const otherErrors = computed(() =>
  Object.entries(fieldErrors.value)
    .filter(
      ([field]) =>
        !['kind', 'title_translations', 'body_translations'].includes(field) &&
        !field.startsWith('title_translations.') &&
        !field.startsWith('body_translations.'),
    )
    .flatMap(([, messages]) => messages),
)

function buildBody(): Record<string, unknown> {
  const current = props.version

  if (current === null) {
    return {
      kind: kind.value,
      title_translations: cleanTranslations(title.value, locales.value),
      body_translations: cleanTranslations(body.value, locales.value),
    }
  }

  const result: Record<string, unknown> = {}
  const titlePatch = translationsPatch(current.title_translations ?? {}, title.value, locales.value)
  const bodyPatch = translationsPatch(current.body_translations ?? {}, body.value, locales.value)

  if (Object.keys(titlePatch).length > 0) result.title_translations = titlePatch
  if (Object.keys(bodyPatch).length > 0) result.body_translations = bodyPatch

  return result
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null

  const payload = buildBody()

  if (props.version !== null && Object.keys(payload).length === 0) {
    emit('update:open', false)

    return
  }

  busy.value = true

  try {
    await props.save(payload)
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
    <DialogContent
      :close-label="t('famUnit.form.close')"
      class="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
      data-slot="fam-unit-terms-form"
    >
      <DialogHeader>
        <DialogTitle>{{ t(`famUnit.terms.form.title.${mode}`) }}</DialogTitle>
        <DialogDescription>{{ t('famUnit.terms.form.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="fam-unit-terms-kind">{{ t('famUnit.fields.kind') }}</Label>
          <Select v-model="kind" required :disabled="version !== null">
            <SelectTrigger
              id="fam-unit-terms-kind"
              :aria-invalid="fieldErrors.kind ? 'true' : undefined"
              :aria-describedby="
                version !== null
                  ? 'fam-unit-terms-kind-hint'
                  : fieldErrors.kind
                    ? 'fam-unit-terms-kind-errors'
                    : undefined
              "
            >
              <SelectValue :placeholder="t('famUnit.form.choose')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="code in KINDS" :key="code" :value="code">
                {{ termsKindLabel(code) }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p
            v-if="version !== null"
            id="fam-unit-terms-kind-hint"
            class="text-muted-foreground text-xs"
          >
            {{ t('famUnit.terms.form.kindImmutable') }}
          </p>
          <ul
            v-if="fieldErrors.kind"
            id="fam-unit-terms-kind-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.kind" :key="message">{{ message }}</li>
          </ul>
        </div>

        <TranslatableField
          v-model="title"
          :label="t('famUnit.fields.title')"
          :locales="locales"
          :default-locale="defaultLocale"
          id-base="fam-unit-terms-title"
          :locale-errors="titleLocaleErrors"
          :field-errors="fieldErrors.title_translations ?? []"
        />

        <TranslatableField
          v-model="body"
          multiline
          :label="t('famUnit.fields.body')"
          :locales="locales"
          :default-locale="defaultLocale"
          id-base="fam-unit-terms-body"
          :locale-errors="bodyLocaleErrors"
          :field-errors="fieldErrors.body_translations ?? []"
        />

        <ul v-if="otherErrors.length > 0" role="alert" class="text-destructive text-sm">
          <li v-for="message in otherErrors" :key="message">{{ message }}</li>
        </ul>
        <p v-if="formError" role="alert" class="text-destructive text-sm">{{ formError }}</p>

        <DialogFooter>
          <Button type="button" variant="outline" @click="emit('update:open', false)">
            {{ t('famUnit.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">
            {{ t(mode === 'create' ? 'famUnit.form.create' : 'famUnit.form.save') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
