/**
 * `docs/adr/ADR-053-registro-de-navegacion-y-bloques-del-panel.md` §1-§2.
 * Superficie pública de `core`, junto a `api/index.ts` y `types/index.ts`.
 *
 * `core` no aporta ninguna ruta propia en 1.8: la ruta `home` (`/`) sigue
 * registrada en `src/router/index.ts` (`funcional.md §12.5`,
 * `ADR-053 §1`: "en router/index.ts quedan solo home, catch-all, centro
 * no encontrado"). Este `shell.ts` sí aporta la **entrada de navegación**
 * «Inicio», que apunta a esa ruta por nombre — una entrada no tiene que
 * vivir en el mismo fichero que declara su ruta, solo nombrarla
 * (`CA-CORE-103`: toda entrada apunta a un nombre de ruta registrado).
 *
 * Sin `dashboardBlocks`: 1.8 entrega solo el panel fijo de `funcional.md
 * §12.4` (bienvenida, estado de la cuenta, accesos directos), que son
 * parte del *shell* y no bloques de módulo (`ADR-053 §5.3`). Ningún
 * módulo, incluido `core`, aporta un bloque en este paso (`OPEN-CORE-13`).
 */
import { House } from '@lucide/vue'
import type { ModuleShell } from '@/navigation/types'

export const shell: ModuleShell = {
  routes: [],
  navigation: [
    {
      id: 'core.home',
      route: 'home',
      labelKey: 'core.nav.home',
      icon: House,
      section: 'inicio',
      shortcut: false,
    },
  ],
  dashboardBlocks: [],
}
