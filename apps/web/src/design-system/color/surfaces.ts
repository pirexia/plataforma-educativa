/**
 * `docs/design-system.md` §6.2. Superficies neutras opacas de cada modo,
 * contra las que `deriveOnBackground` (§6) garantiza `--primary-on-background`
 * — no solo contra `--background` (`RN-DS-06`, `OPEN-DS-01` resuelta:
 * opción A).
 *
 * Son grises puros (`C = 0`, `h = 0`); el único dato que varía es `L`.
 * **Excepción nominal** de la prohibición de colores literales (`RN-DS-19`,
 * `docs/design-system.md` §10.3): son literales a propósito, porque la
 * función de derivación es pura y no puede leer CSS (jsdom no calcula
 * estilos). `CA-DS-017` comprueba que estos valores coinciden, uno a uno,
 * con los semánticos equivalentes de `tokens.css`. Si se cambia un valor
 * aquí sin cambiarlo también allí (o al revés), ese test falla.
 */

import type { Oklch } from './color'

export interface NeutralSurface {
  /** Nombre del semántico de `tokens.css` del que procede este valor. */
  readonly name: string
  readonly oklch: Oklch
}

function gray(name: string, L: number): NeutralSurface {
  return { name, oklch: { L, C: 0, h: 0 } }
}

export const LIGHT_SURFACES: readonly NeutralSurface[] = [
  gray('background', 1),
  gray('card', 1),
  gray('popover', 1),
  gray('sidebar', 0.985),
  gray('muted', 0.97),
  gray('secondary', 0.97),
  gray('accent', 0.97),
  gray('sidebar-accent', 0.97),
]

export const DARK_SURFACES: readonly NeutralSurface[] = [
  gray('background', 0.145),
  gray('card', 0.205),
  gray('popover', 0.205),
  gray('sidebar', 0.205),
  gray('muted', 0.269),
  gray('secondary', 0.269),
  gray('accent', 0.269),
  gray('sidebar-accent', 0.269),
]

export type SurfaceColorMode = 'light' | 'dark'

export function surfacesFor(mode: SurfaceColorMode): readonly NeutralSurface[] {
  return mode === 'light' ? LIGHT_SURFACES : DARK_SURFACES
}
