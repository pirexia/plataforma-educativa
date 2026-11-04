/**
 * `REQ-PRL-004`, `REQ-FAM-UNIT/funcional.md §23.5.2` (B-1 de la revisión de seguridad): la memoria de nombres de
 * alumnos no sobrevive al cierre de sesión. Datos ficticios (`REQ-SEED-005`).
 */
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { clearSession, useSession } from '@/session/useSession'
import { useDoorContext } from './useDoorContext'

describe('REQ-PRL-004: memoria de nombres de la puerta', () => {
  it('se vacía al cerrar la sesión', async () => {
    const door = useDoorContext()
    const { user } = useSession()

    user.value = { public_id: 'ME', permissions: [] } as never
    await nextTick()
    door.remember('P1', { displayName: 'Lucas Ejemplo Ruiz', familyUnitCode: null })
    expect(door.find('P1')).not.toBeNull()

    clearSession()
    await nextTick()

    expect(door.find('P1')).toBeNull()
  })
})
