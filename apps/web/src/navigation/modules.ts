/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §2.
 * Único fichero que importa el `shell` de cada módulo — lista explícita y
 * ordenada, mismo patrón que `src/i18n/index.ts`, sin descubrimiento
 * automático. **El orden de esta lista es el orden del producto**
 * (`ADR-053 §4.3`, `§5.3`): reordenarla cambia el menú y el panel.
 *
 * Añadir un módulo al *shell* es añadir su `shell.ts` y una línea aquí.
 */
import { shell as authShell } from '@/modules/auth/shell'
import { shell as coreShell } from '@/modules/core/shell'
import type { ModuleShell } from './types'

export const moduleShells: readonly ModuleShell[] = [coreShell, authShell]
