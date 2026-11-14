<script setup lang="ts">
/**
 * `funcional.md §23.4.3` (`OPEN-FAM-UNIT-48`, `D-52`): «Nueva persona autorizada» en tres pasos, persona →
 * autorización → fotografía. Cada paso envía su petición al pulsar «Siguiente»; volver atrás tras un éxito no deshace
 * nada (la persona y la autorización ya existen) ni borra lo escrito (WCAG 3.3.7: los pasos siguen montados). El foco
 * va al encabezado de cada paso (WCAG 2.4.3). Con `texto_legal.leer` se cargan las versiones del texto
 * `autorizacion_recogida` al abrir (no escribe `read`); sin él el asistente ni se abre (`OPEN-FAM-UNIT-55` A).
 * Al cerrar, la sección recarga la tabla de autorizaciones (no la lista vigente, UI-2).
 */
import { computed, nextTick, ref, watch } from 'vue'
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
import { listTermsVersions } from '../api'
import { problemDetail } from '../composables/problem'
import type { FamilyUnitStudent, PickupPerson, TermsVersion } from '../types'
import PickupAuthorizationStep from './PickupAuthorizationStep.vue'
import PickupPersonStep from './PickupPersonStep.vue'
import PickupPhotoStep from './PickupPhotoStep.vue'

const props = defineProps<{
  open: boolean
  students: FamilyUnitStudent[]
  initialStudent: string
  principalGuardian: string | null
  principalName: string
  canUpdate: boolean
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; done: [created: boolean] }>()

const t = useT()

const step = ref<1 | 2 | 3>(1)
const person = ref<PickupPerson | null>(null)
const created = ref(false)
const personStep = ref<InstanceType<typeof PickupPersonStep> | null>(null)
const authorizationStep = ref<InstanceType<typeof PickupAuthorizationStep> | null>(null)
const heading = ref<HTMLElement | null>(null)
const terms = ref<TermsVersion[]>([])
const termsLoading = ref(false)
const termsError = ref<string | null>(null)
const busy = ref(false)

const stepKeys = ['person', 'authorization', 'photo'] as const
const stepTitle = computed(() => t(`famUnit.pickup.wizard.steps.${stepKeys[step.value - 1]}`))

async function loadTerms(): Promise<void> {
  termsLoading.value = true
  termsError.value = null

  try {
    terms.value = (
      await listTermsVersions({
        kind: 'autorizacion_recogida',
        status: 'publicada,retirada',
        per_page: 100,
      })
    ).data
  } catch (err) {
    terms.value = []
    termsError.value = problemDetail(err) ?? t('famUnit.errors.unexpected')
  } finally {
    termsLoading.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      step.value = 1
      person.value = null
      created.value = false
      void loadTerms()
    }
  },
  { immediate: true },
)

async function goTo(next: 1 | 2 | 3): Promise<void> {
  step.value = next
  await nextTick()
  heading.value?.focus()
}

async function use(found: PickupPerson): Promise<void> {
  person.value = found
  await goTo(2)
}

async function next(): Promise<void> {
  busy.value = true

  try {
    if (step.value === 1) {
      const resolved = await personStep.value?.submit()

      if (resolved) {
        person.value = resolved
        await goTo(2)
      }
    } else if (step.value === 2 && person.value) {
      if (created.value) {
        await goTo(3)

        return
      }

      const authorization = await authorizationStep.value?.submit(person.value)

      if (authorization) {
        created.value = true
        await goTo(3)
      }
    }
  } finally {
    busy.value = false
  }
}

function finish(uploaded: boolean): void {
  emit('done', created.value || uploaded)
  emit('update:open', false)
}

/** Cerrar en cualquier punto: si ya se creó algo, la tabla se recarga. */
function onOpenChange(value: boolean): void {
  if (!value && created.value) {
    emit('done', true)
  }

  emit('update:open', value)
}
</script>

<template>
  <Dialog :open="open" @update:open="onOpenChange">
    <DialogContent
      :close-label="t('famUnit.form.close')"
      class="max-h-[90vh] overflow-y-auto sm:max-w-2xl"
      data-slot="fam-unit-pickup-wizard"
    >
      <DialogHeader>
        <DialogTitle>{{ t('famUnit.pickup.wizard.title') }}</DialogTitle>
        <DialogDescription>
          <span ref="heading" tabindex="-1" class="font-medium outline-none">{{ stepTitle }}</span>
        </DialogDescription>
      </DialogHeader>

      <p v-if="termsError" role="alert" class="text-destructive text-sm">{{ termsError }}</p>

      <div v-show="step === 1">
        <PickupPersonStep ref="personStep" :can-update="canUpdate" @use="use" />
      </div>
      <div v-show="step === 2">
        <PickupAuthorizationStep
          ref="authorizationStep"
          :students="students"
          :initial-student="initialStudent"
          :principal-guardian="principalGuardian"
          :principal-name="principalName"
          :can-update="canUpdate"
          :terms="terms"
          :terms-loading="termsLoading"
          @reload-terms="loadTerms"
        />
      </div>
      <div v-if="step === 3 && person">
        <PickupPhotoStep
          :can-update="canUpdate"
          :person-public-id="person.person"
          :person-name="person.display_name ?? ''"
          :has-photo="person.has_photo === true"
          @finish="finish"
        />
      </div>

      <DialogFooter v-if="step !== 3">
        <Button v-if="step === 2 && !created" type="button" variant="outline" @click="goTo(1)">
          {{ t('famUnit.pickup.wizard.back') }}
        </Button>
        <Button type="button" variant="outline" @click="onOpenChange(false)">
          {{ t('famUnit.form.cancel') }}
        </Button>
        <Button type="button" :disabled="busy" @click="next">
          {{ t('famUnit.pickup.wizard.next') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
