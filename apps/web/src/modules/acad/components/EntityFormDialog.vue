<script setup lang="ts">
/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.1` («altas y ediciones en diálogo»),
 * `§4.2`-`§4.4`, `CA-ACAD-050`, `CA-ACAD-016`. Formulario único de alta y edición
 * de las cinco entidades con `code`:
 *
 * - etapa: código, nombre traducible, tipo de etapa, régimen, posición;
 * - curso de etapa y asignatura: código, nombre traducible, posición;
 * - grupo y subgrupo: código, nombre (texto simple), posición.
 *
 * El nombre traducible se edita con el componente común (`ADR-062 §5.7`) y se
 * envía como `name_translations`; en la edición solo viajan las claves que
 * cambian (fusión por idioma, `ADR-062 §5.2`). **`name` nunca se envía** en las
 * entidades traducibles (`RN-ACAD-13`). El padre y el curso académico no son
 * editables (`RN-ACAD-06`): llegan en `extra` solo en el alta.
 *
 * Los errores del servidor ya vienen traducidos (`ADR-038 §6.3`) y se muestran
 * junto al campo; un `409` (`academic-year-closed`) o cualquier otro, junto a la
 * acción. La pantalla no cambia hasta recargar (`CA-ACAD-050`).
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
import type { Course, EducationLevel, Group, Subgroup, Subject } from '../types'

export type EntityKind = 'level' | 'course' | 'subject' | 'group' | 'subgroup'
type Entity = EducationLevel | Course | Subject | Group | Subgroup

const STAGES = ['infantil', 'primaria', 'eso', 'bachillerato', 'fp', 'universidad']
const REGIMES = ['publico', 'privado', 'concertado']
/** `maxLength` de `code` y de cada valor (`StructureLimits::MAX_TEXT_LENGTH`, `RN-ACAD-02`). */
const MAX_TEXT_LENGTH = 255

const props = defineProps<{
  open: boolean
  kind: EntityKind
  /** `null` = alta. */
  entity: Entity | null
  /** Solo en el alta: campos fijos del cuerpo (`academic_year` de una etapa, `education_level`, `course`). */
  extra?: Record<string, unknown>
  save: (body: Record<string, unknown>) => Promise<unknown>
}>()

const emit = defineEmits<{ 'update:open': [value: boolean]; saved: [] }>()

const t = useT()
const { defaultLocale, locales } = useTenantLocales()

const translatable = computed(() => ['level', 'course', 'subject'].includes(props.kind))
const mode = computed(() => (props.entity === null ? 'create' : 'edit'))

const code = ref('')
/** `type="number"` con `v-model` entrega un número (o `''`): se normaliza al leer. */
const position = ref<string | number>('')
const simpleName = ref('')
const translations = ref<TranslationValues>({})
const stage = ref('')
const regime = ref('')

const busy = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref<string | null>(null)

function reset(): void {
  const entity = props.entity

  code.value = entity?.code ?? ''
  position.value = entity ? String(entity.position) : ''
  simpleName.value = entity !== null && !translatable.value && 'name' in entity ? entity.name : ''
  translations.value =
    entity !== null && 'name_translations' in entity ? { ...entity.name_translations } : {}
  stage.value = entity !== null && 'stage' in entity ? entity.stage : ''
  regime.value = entity !== null && 'regime' in entity ? entity.regime : ''
  fieldErrors.value = {}
  formError.value = null
}

watch(
  () => [props.open, props.entity?.public_id],
  () => {
    if (props.open) {
      reset()
    }
  },
  { immediate: true },
)

/** `ADR-038 §7.3`: un código no anticipado se ofrece con su valor en crudo. */
function options(known: string[], current: string, prefix: string) {
  const values = current !== '' && !known.includes(current) ? [...known, current] : known

  return values.map((value) => {
    const key = `${prefix}.${value}`
    const label = t(key)

    return { value, label: label === key ? value : label }
  })
}

const stageOptions = computed(() => options(STAGES, stage.value, 'acad.stage'))
const regimeOptions = computed(() => options(REGIMES, regime.value, 'acad.regime'))

const localeErrors = computed(() => {
  const result: Partial<Record<Locale, string[]>> = {}

  for (const [field, messages] of Object.entries(fieldErrors.value)) {
    const match = /^name_translations\.(.+)$/.exec(field)

    if (match) {
      result[match[1] as Locale] = messages
    }
  }

  return result
})

const translationsFieldErrors = computed(() => fieldErrors.value['name_translations'] ?? [])

/** Errores de campos que el formulario no pinta (p. ej. `parent_not_found`): se muestran junto a la acción. */
const otherErrors = computed(() => {
  const own = new Set(['code', 'position', 'name', 'stage', 'regime', 'name_translations'])

  return Object.entries(fieldErrors.value)
    .filter(([field]) => !own.has(field) && !field.startsWith('name_translations.'))
    .flatMap(([, messages]) => messages)
})

function parsePosition(): number | undefined {
  const text = String(position.value ?? '').trim()

  return text === '' ? undefined : Number(text)
}

function differs(next: string, before: string): boolean {
  return next !== before
}

/** Cuerpo de la petición: completo en el alta, solo las claves modificadas en la edición (`ADR-038 §9.2`). */
function buildBody(): Record<string, unknown> {
  const entity = props.entity
  const body: Record<string, unknown> = {}
  const nextPosition = parsePosition()

  if (entity === null) {
    Object.assign(body, props.extra ?? {})
    body.code = code.value.trim()

    if (nextPosition !== undefined) body.position = nextPosition

    if (translatable.value) {
      body.name_translations = cleanTranslations(translations.value, locales.value)
    } else {
      body.name = simpleName.value.trim()
    }

    if (props.kind === 'level') {
      body.stage = stage.value
      body.regime = regime.value
    }

    return body
  }

  // `differs` y no `!==` con `.code`: `CA-ACAD-051` (#376) prohíbe comparar por `.code`.
  if (differs(code.value.trim(), entity.code)) body.code = code.value.trim()
  if (nextPosition !== undefined && nextPosition !== entity.position) body.position = nextPosition

  if (translatable.value && 'name_translations' in entity) {
    const patch = translationsPatch(entity.name_translations, translations.value, locales.value)

    if (Object.keys(patch).length > 0) body.name_translations = patch
  } else if (!translatable.value && 'name' in entity && simpleName.value.trim() !== entity.name) {
    body.name = simpleName.value.trim()
  }

  if (props.kind === 'level' && 'stage' in entity) {
    if (stage.value !== entity.stage) body.stage = stage.value
    if (regime.value !== entity.regime) body.regime = regime.value
  }

  return body
}

async function submit(): Promise<void> {
  fieldErrors.value = {}
  formError.value = null

  // `CA-ACAD-016`: no se envía sin el idioma por defecto del centro (el servidor decide igualmente).
  if (translatable.value && (translations.value[defaultLocale.value] ?? '').trim() === '') {
    fieldErrors.value = { name_translations: [t('acad.form.errors.defaultLocaleRequired')] }

    return
  }

  const body = buildBody()

  if (props.entity !== null && Object.keys(body).length === 0) {
    emit('update:open', false)

    return
  }

  busy.value = true

  try {
    await props.save(body)
    emit('saved')
    emit('update:open', false)
  } catch (err) {
    fieldErrors.value = problemFieldErrors(err)
    formError.value =
      problemDetail(err) ??
      (Object.keys(fieldErrors.value).length === 0 ? t('acad.errors.unexpected') : null)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Dialog :open="open" @update:open="emit('update:open', $event)">
    <DialogContent :close-label="t('acad.form.close')" data-slot="acad-entity-form">
      <DialogHeader>
        <DialogTitle>{{ t(`acad.form.title.${mode}.${kind}`) }}</DialogTitle>
        <DialogDescription>{{ t('acad.form.description') }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex flex-col gap-1.5">
          <Label for="acad-code">{{ t('acad.fields.code') }}</Label>
          <Input
            id="acad-code"
            v-model="code"
            required
            :maxlength="MAX_TEXT_LENGTH"
            :aria-invalid="fieldErrors.code ? 'true' : undefined"
            :aria-describedby="fieldErrors.code ? 'acad-code-errors' : undefined"
          />
          <ul
            v-if="fieldErrors.code"
            id="acad-code-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.code" :key="message">{{ message }}</li>
          </ul>
        </div>

        <TranslatableField
          v-if="translatable"
          v-model="translations"
          :label="t('acad.fields.name')"
          :locales="locales"
          :default-locale="defaultLocale"
          id-base="acad-name"
          :locale-errors="localeErrors"
          :field-errors="translationsFieldErrors"
          :max-length="MAX_TEXT_LENGTH"
        />

        <div v-else class="flex flex-col gap-1.5">
          <Label for="acad-name">{{ t('acad.fields.name') }}</Label>
          <Input
            id="acad-name"
            v-model="simpleName"
            required
            :maxlength="MAX_TEXT_LENGTH"
            :aria-invalid="fieldErrors.name ? 'true' : undefined"
            :aria-describedby="fieldErrors.name ? 'acad-name-errors' : undefined"
          />
          <ul
            v-if="fieldErrors.name"
            id="acad-name-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.name" :key="message">{{ message }}</li>
          </ul>
        </div>

        <template v-if="kind === 'level'">
          <div class="flex flex-col gap-1.5">
            <Label for="acad-stage">{{ t('acad.fields.stage') }}</Label>
            <Select v-model="stage" required>
              <SelectTrigger
                id="acad-stage"
                :aria-invalid="fieldErrors.stage ? 'true' : undefined"
                :aria-describedby="fieldErrors.stage ? 'acad-stage-errors' : undefined"
              >
                <SelectValue :placeholder="t('acad.form.choose')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in stageOptions"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <ul
              v-if="fieldErrors.stage"
              id="acad-stage-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in fieldErrors.stage" :key="message">{{ message }}</li>
            </ul>
          </div>

          <div class="flex flex-col gap-1.5">
            <Label for="acad-regime">{{ t('acad.fields.regime') }}</Label>
            <Select v-model="regime" required>
              <SelectTrigger
                id="acad-regime"
                :aria-invalid="fieldErrors.regime ? 'true' : undefined"
                :aria-describedby="fieldErrors.regime ? 'acad-regime-errors' : undefined"
              >
                <SelectValue :placeholder="t('acad.form.choose')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in regimeOptions"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <ul
              v-if="fieldErrors.regime"
              id="acad-regime-errors"
              role="alert"
              class="text-destructive text-xs"
            >
              <li v-for="message in fieldErrors.regime" :key="message">{{ message }}</li>
            </ul>
          </div>
        </template>

        <div class="flex flex-col gap-1.5">
          <Label for="acad-position">{{ t('acad.fields.position') }}</Label>
          <Input
            id="acad-position"
            :model-value="String(position)"
            @update:model-value="position = $event ?? ''"
            type="number"
            min="1"
            step="1"
            inputmode="numeric"
            :aria-invalid="fieldErrors.position ? 'true' : undefined"
            :aria-describedby="fieldErrors.position ? 'acad-position-errors' : 'acad-position-hint'"
          />
          <p id="acad-position-hint" class="text-muted-foreground text-xs">
            {{ t('acad.form.positionHint') }}
          </p>
          <ul
            v-if="fieldErrors.position"
            id="acad-position-errors"
            role="alert"
            class="text-destructive text-xs"
          >
            <li v-for="message in fieldErrors.position" :key="message">{{ message }}</li>
          </ul>
        </div>

        <div
          v-if="formError || otherErrors.length > 0"
          role="alert"
          class="text-destructive text-sm"
        >
          <p v-if="formError">{{ formError }}</p>
          <p v-for="message in otherErrors" :key="message">{{ message }}</p>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            :disabled="busy"
            @click="emit('update:open', false)"
          >
            {{ t('acad.form.cancel') }}
          </Button>
          <Button type="submit" :disabled="busy">
            {{ mode === 'create' ? t('acad.form.create') : t('acad.form.save') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
