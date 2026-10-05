<script setup lang="ts">
/**
 * `docs/modulos/REQ-PERM/funcional.md §20.8.1`-`§20.8.3`, `RN-PERM-32`-`-35`,
 * issue #170. Panel de edición de **una celda** de la matriz de concesiones
 * (`sheet`, ya vendorizado: `role="dialog"`, foco atrapado, `Esc` cierra y el foco
 * vuelve al botón de la celda): título «{recurso} · {acción}», código técnico,
 * control de tres estados (`radio-group`), selector de ámbito (`select`) con las
 * opciones en uno de sus cuatro estados, la explicación de «Denegar», los avisos
 * completos y el error del servidor.
 *
 * El motivo por el que una opción o «Permitir» están deshabilitados **nunca va
 * solo en un `title` ni en un *tooltip*** (WCAG 1.3.1, 1.4.13): el texto es parte
 * del nombre accesible de la opción y la nota está asociada al grupo con
 * `aria-describedby`. Los cambios se aplican al estado de edición al pulsar
 * «Aplicar»; «Cancelar» o `Esc` cierran sin cambios.
 */
import { computed, ref, watch } from 'vue'
import { Info } from '@lucide/vue'
import { useT } from '@/i18n'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { usePermissionVocabulary } from '../../permissionVocabulary'
import { defaultAllowScope, type CellChoice, type ScopeOption } from '../../roleEditor'

const props = defineProps<{
  open: boolean
  /** «{recurso} · {acción}». */
  title: string
  /** Código técnico del permiso. */
  code: string
  choice: CellChoice
  options: readonly ScopeOption[]
  /** `false` ⇒ «Permitir» deshabilitado (`RN-PERM-33` punto 1). */
  allowEnabled: boolean
  /** Avisos de la celda ya traducidos: «Solo puedes denegar…», inercia (`RN-PERM-33`/`-35`). */
  notes: readonly string[]
  /** Error del servidor para esta celda (`RN-PERM-36`). */
  error: string | null
}>()

const emit = defineEmits<{ 'update:open': [open: boolean]; apply: [choice: CellChoice] }>()

const t = useT()
const vocabulary = usePermissionVocabulary()

type State = CellChoice['state']

const draftState = ref<State>('none')
const draftScope = ref<string>('')

// Cada vez que se abre, el borrador parte del estado vigente de la celda.
watch(
  () => props.open,
  (open) => {
    if (!open) {
      return
    }

    draftState.value = props.choice.state
    draftScope.value =
      props.choice.state === 'allow' ? props.choice.scope : (defaultAllowScope(props.options) ?? '')
  },
  { immediate: true },
)

const idBase = computed(() => `role-cell-${props.code.replace(/[^a-zA-Z0-9_-]/g, '-')}`)
const notesId = computed(() => `${idBase.value}-notes`)
const denyId = computed(() => `${idBase.value}-deny`)
const errorId = computed(() => `${idBase.value}-error`)

const describedBy = computed(() =>
  [
    props.notes.length > 0 ? notesId.value : null,
    draftState.value === 'deny' ? denyId.value : null,
    props.error ? errorId.value : null,
  ]
    .filter(Boolean)
    .join(' '),
)

function optionText(option: ScopeOption): string {
  const label = vocabulary.scope(option.scope)

  switch (option.status) {
    case 'current':
      return t('core.roles.editor.option.current', { scope: label })
    case 'not_held':
      return t('core.roles.editor.option.notHeld', { scope: label })
    case 'no_resolver':
      return t('core.roles.editor.option.noResolver', { scope: label })
    default:
      return label
  }
}

function onState(value: unknown): void {
  const next = String(value) as State

  draftState.value = next

  if (
    next === 'allow' &&
    !props.options.some((o) => o.scope === draftScope.value && o.selectable)
  ) {
    draftScope.value = defaultAllowScope(props.options) ?? ''
  }
}

function apply(): void {
  if (draftState.value === 'allow') {
    emit('apply', { state: 'allow', scope: draftScope.value })
  } else {
    emit('apply', { state: draftState.value } as CellChoice)
  }
}

const canApply = computed(() => draftState.value !== 'allow' || draftScope.value !== '')
</script>

<template>
  <Sheet :open="open" @update:open="(value: boolean) => emit('update:open', value)">
    <SheetContent
      side="right"
      :close-label="t('core.roles.editor.panel.close')"
      class="overflow-y-auto data-[side=right]:w-full data-[side=right]:sm:max-w-md"
    >
      <SheetHeader>
        <SheetTitle>{{ title }}</SheetTitle>
        <SheetDescription>
          {{ t('core.roles.editor.panel.code') }}
          <code class="font-mono text-xs">{{ code }}</code>
        </SheetDescription>
      </SheetHeader>

      <div class="flex flex-col gap-4 px-4">
        <RadioGroup
          :model-value="draftState"
          :aria-label="t('core.roles.editor.panel.groupLabel', { name: title })"
          :aria-describedby="describedBy || undefined"
          :aria-invalid="error ? true : undefined"
          @update:model-value="onState"
        >
          <div class="flex items-center gap-2">
            <RadioGroupItem :id="`${idBase}-none`" value="none" />
            <Label :for="`${idBase}-none`">{{ t('core.roles.editor.state.none') }}</Label>
          </div>
          <div class="flex items-center gap-2">
            <RadioGroupItem :id="`${idBase}-allow`" value="allow" :disabled="!allowEnabled" />
            <Label :for="`${idBase}-allow`">{{ vocabulary.effect('allow') }}</Label>
          </div>
          <div class="flex items-center gap-2">
            <RadioGroupItem :id="`${idBase}-deny`" value="deny" />
            <Label :for="`${idBase}-deny`">{{ vocabulary.effect('deny') }}</Label>
          </div>
        </RadioGroup>

        <div v-if="draftState === 'allow'" class="flex flex-col gap-1.5">
          <Label :for="`${idBase}-scope`">{{ t('core.roles.editor.panel.scope') }}</Label>
          <Select v-model="draftScope">
            <SelectTrigger :id="`${idBase}-scope`" class="w-full">
              <SelectValue :placeholder="t('core.roles.editor.panel.scopePlaceholder')" />
            </SelectTrigger>
            <SelectContent position="popper" class="max-w-[min(90vw,28rem)]">
              <SelectItem
                v-for="option in options"
                :key="option.scope"
                :value="option.scope"
                :disabled="!option.selectable"
                class="h-auto min-h-8 whitespace-normal [@media(any-pointer:coarse)]:min-h-11"
              >
                {{ optionText(option) }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-else-if="draftState === 'deny'" class="flex flex-col gap-1.5">
          <p class="text-sm font-medium">{{ t('core.roles.editor.panel.anyScope') }}</p>
          <p :id="denyId" class="text-muted-foreground text-sm">
            {{ t('core.roles.editor.panel.denyExplanation') }}
          </p>
        </div>

        <ul v-if="notes.length > 0" :id="notesId" class="flex flex-col gap-2 text-sm">
          <li v-for="note in notes" :key="note" class="flex items-start gap-2">
            <Info class="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ note }}</span>
          </li>
        </ul>

        <p v-if="error" :id="errorId" role="alert" class="text-destructive text-sm">
          {{ error }}
        </p>
      </div>

      <SheetFooter class="sm:flex-row sm:justify-end">
        <Button type="button" variant="outline" @click="emit('update:open', false)">
          {{ t('core.roles.editor.panel.cancel') }}
        </Button>
        <Button type="button" :disabled="!canApply" @click="apply">
          {{ t('core.roles.editor.panel.apply') }}
        </Button>
      </SheetFooter>
    </SheetContent>
  </Sheet>
</template>
