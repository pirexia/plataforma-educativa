/**
 * REQ-AUTH-003 (1.3b, pieza 3): "buscar usuario" de las áreas de
 * restablecimiento y excepciones (funcional.md §D.9.1) **sin aportar
 * ningún endpoint nuevo** (api.md §D.5.1) — `GET /mfa-compliance/users`
 * no tiene parámetro de texto libre, así que la búsqueda es un filtro en
 * cliente sobre el listado individualizado ya cargado (nombre o correo),
 * con el mismo permiso (`mfa.leer`) que ya usa el área de cumplimiento.
 *
 * Población cubierta: cualquiera que aparezca en `GET
 * /mfa-compliance/users` sin filtro de `state` — es decir, todo el que
 * esté obligado, inscrito o exento (api.md §C.5: "quien no está obligado
 * por ningún rol, no está inscrito y no tiene excepción viva, no aparece
 * en el listado"). Es exactamente la población sobre la que tienen
 * sentido un restablecimiento o una excepción.
 */
import { ref } from 'vue'
import { getMfaComplianceUsers } from '../api'
import type { MfaComplianceUserSummary } from '../types'

export function useMfaUserSearch() {
  const users = ref<MfaComplianceUserSummary[]>([])
  const loading = ref(false)
  const loaded = ref(false)
  const errored = ref(false)

  async function ensureLoaded(): Promise<void> {
    if (loaded.value || loading.value) {
      return
    }

    loading.value = true
    errored.value = false

    try {
      // per_page=100 (el tope que admite el servidor, `IndexMfaComplianceUsersRequest`):
      // suficiente para el tamaño de centro de este producto (RNF de escala,
      // no un listado abierto de toda la plataforma).
      const page = await getMfaComplianceUsers({ per_page: 100 })

      users.value = page.data.map((entry) => entry.user)
      loaded.value = true
    } catch {
      errored.value = true
    } finally {
      loading.value = false
    }
  }

  function search(query: string): MfaComplianceUserSummary[] {
    const term = query.trim().toLowerCase()

    if (term === '') {
      return []
    }

    return users.value.filter((user) => {
      const fullName = `${user.given_name} ${user.family_name_1} ${user.family_name_2 ?? ''}`
      return fullName.toLowerCase().includes(term) || user.email.toLowerCase().includes(term)
    })
  }

  return { ensureLoaded, search, loading, loaded, errored }
}
