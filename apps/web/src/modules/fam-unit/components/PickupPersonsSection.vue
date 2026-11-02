<script setup lang="ts">
/**
 * `docs/modulos/REQ-FAM-UNIT/funcional.md §23.4` (`1.14b-c1` T1, solo lectura). Sección «Personas autorizadas a
 * recoger» de la ficha de familia: la monta `FamilyUnitDetailView` solo con `autorizado_recogida.leer` (`RN-CORE-61`,
 * UI-1); sin el permiso no existe en el DOM y no se hace ninguna petición a `/pickup-*`.
 *
 * Recibe la unidad y sus alumnos **ya cargados** por la vista (no los vuelve a pedir). Al montar no hay petición alguna
 * (UI-2): elegir un alumno carga su tabla de autorizaciones (no escribe `read`), y la lista vigente solo se pide al
 * pulsar «Ver lista de recogida». El nombre del alumno vive únicamente en memoria de este componente: ni URL, ni
 * `history.state`, ni título, ni almacenamiento (UI-3, `OPEN-FAM-UNIT-58`). Al cambiar de alumno se remontan los dos
 * paneles (`:key`) y con ellos se descarta lo cargado, incluidas las URL firmadas.
 */
import { ref } from 'vue'
import { useT } from '@/i18n'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { FamilyUnit, FamilyUnitStudent } from '../types'
import PickupAuthorizationsTable from './PickupAuthorizationsTable.vue'
import PickupListPanel from './PickupListPanel.vue'

defineProps<{ unit: FamilyUnit; students: FamilyUnitStudent[] }>()

const t = useT()

const studentPerson = ref<string>('')
</script>

<template>
  <section aria-labelledby="fam-unit-pickup" class="flex flex-col gap-4">
    <h2 id="fam-unit-pickup" class="text-sm font-semibold">
      {{ t('famUnit.pickup.section.title') }}
    </h2>

    <div class="flex max-w-sm flex-col gap-1.5">
      <Label for="fam-unit-pickup-student">{{ t('famUnit.pickup.section.chooseStudent') }}</Label>
      <Select v-model="studentPerson">
        <SelectTrigger id="fam-unit-pickup-student">
          <SelectValue :placeholder="t('famUnit.form.choose')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="item in students" :key="item.public_id" :value="item.student_person">
            {{ item.display_name }}
          </SelectItem>
        </SelectContent>
      </Select>
    </div>

    <template v-if="studentPerson !== ''">
      <PickupListPanel :key="`list-${studentPerson}`" :student-person="studentPerson" />
      <PickupAuthorizationsTable :key="`table-${studentPerson}`" :student-person="studentPerson" />
    </template>
    <p v-else class="text-muted-foreground text-sm">{{ t('famUnit.pickup.list.initial') }}</p>
  </section>
</template>
