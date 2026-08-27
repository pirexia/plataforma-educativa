<script setup lang="ts">
/**
 * "Buscar usuario" compartido por las áreas de restablecimiento y de
 * excepciones de `/administracion/mfa` (funcional.md §D.9.1) — filtro en
 * cliente sobre `GET /mfa-compliance/users`, sin endpoint propio
 * (`useMfaUserSearch`, api.md §D.5.1). Emite el usuario elegido; quien lo
 * embebe decide qué hacer con él.
 */
import { computed, ref, watch } from 'vue'
import { useT } from '@/i18n'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useMfaUserSearch } from '../../composables/useMfaUserSearch'
import type { MfaComplianceUserSummary } from '../../types'

const props = defineProps<{ id: string; selected: MfaComplianceUserSummary | null }>()
const emit = defineEmits<{ select: [MfaComplianceUserSummary]; clear: [] }>()

const t = useT()
const { ensureLoaded, search, loading, errored } = useMfaUserSearch()

const query = ref('')

watch(
  () => props.selected,
  (value) => {
    if (value === null) {
      query.value = ''
    }
  },
)

function fullName(user: MfaComplianceUserSummary): string {
  return [user.given_name, user.family_name_1, user.family_name_2].filter(Boolean).join(' ')
}

const matches = computed(() => (props.selected ? [] : search(query.value)))

async function onFocus(): Promise<void> {
  await ensureLoaded()
}

function choose(user: MfaComplianceUserSummary): void {
  emit('select', user)
  query.value = ''
}

function clearSelection(): void {
  emit('clear')
  query.value = ''
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <Label :for="id">{{ t('auth.mfaAdmin.userPicker.label') }}</Label>

    <div
      v-if="selected"
      class="border-border flex items-center justify-between rounded-lg border px-3 py-2 text-sm"
    >
      <span>{{ fullName(selected) }} · {{ selected.email }}</span>
      <button type="button" class="text-primary hover:underline" @click="clearSelection">
        {{ t('auth.mfaAdmin.userPicker.change') }}
      </button>
    </div>

    <template v-else>
      <Input
        :id="id"
        v-model="query"
        type="text"
        autocomplete="off"
        :placeholder="t('auth.mfaAdmin.userPicker.placeholder')"
        @focus="onFocus"
      />
      <p v-if="loading" class="text-muted-foreground text-xs">
        {{ t('auth.mfaAdmin.userPicker.loading') }}
      </p>
      <p v-else-if="errored" role="alert" class="text-destructive text-xs">
        {{ t('auth.common.unexpectedError') }}
      </p>
      <ul
        v-else-if="query.trim() !== ''"
        class="border-border max-h-48 overflow-y-auto rounded-lg border text-sm"
      >
        <li v-if="matches.length === 0" class="text-muted-foreground px-3 py-2">
          {{ t('auth.mfaAdmin.userPicker.noMatches') }}
        </li>
        <li v-for="user in matches" :key="user.public_id">
          <button
            type="button"
            class="hover:bg-muted w-full px-3 py-2 text-left"
            @click="choose(user)"
          >
            {{ fullName(user) }} · {{ user.email }}
          </button>
        </li>
      </ul>
    </template>
  </div>
</template>
