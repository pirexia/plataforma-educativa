/**
 * `docs/modulos/REQ-CORE/funcional.md §14.4.2`, `RN-CORE-61`/`RN-CORE-23`.
 * Una acción de pantalla se muestra si y solo si `/me.permissions` contiene
 * el permiso de su *endpoint* — nunca por código de rol. La interfaz oculta;
 * el servidor decide (`INV-002`).
 */
import { computed } from 'vue'
import { useSession } from '@/session/useSession'

export function usePermissions() {
  const { user } = useSession()
  const granted = computed<readonly string[]>(() => user.value?.permissions ?? [])

  function can(code: string): boolean {
    return granted.value.includes(code)
  }

  return { can, granted, me: user }
}
