/**
 * `docs/design-system.md` §5. Capa A del *design system* — sin noción de
 * tenant, sin sesión, sin *router*. Aplica (o retira) la paleta de marca
 * del centro al documento, escribiendo **solo** las cuatro variables de
 * entrada de marca (`--brand-*`, §4.2) por CSSOM. Ningún otro fichero de
 * `src/` escribe `--brand-*` (`RN-DS-01`, `CA-DS-041`).
 *
 * No importa nada de `@/modules`, `@/api`, `@/tenant`, el *router* ni
 * `vue-i18n` (`RN-DS-20`, frontera del *design system*, §10.4). La capa B
 * (`@/tenant/useTenantBranding.ts`) es la única llamadora en producción.
 */

import { isValidHex } from '../color/color'
import { deriveOnBackground } from '../color/deriveOnBackground'

export interface BrandPalette {
  /** `#RRGGBB` (mayúsculas o minúsculas). */
  primary: string
  /** `#RRGGBB`: texto sobre `primary` (`color_secondary`, `ADR-052` P1). */
  primaryForeground: string
}

const BRAND_PRIMARY = '--brand-primary'
const BRAND_PRIMARY_FOREGROUND = '--brand-primary-foreground'
const BRAND_PRIMARY_ON_BACKGROUND_LIGHT = '--brand-primary-on-background-light'
const BRAND_PRIMARY_ON_BACKGROUND_DARK = '--brand-primary-on-background-dark'

const BRAND_PROPERTIES = [
  BRAND_PRIMARY,
  BRAND_PRIMARY_FOREGROUND,
  BRAND_PRIMARY_ON_BACKGROUND_LIGHT,
  BRAND_PRIMARY_ON_BACKGROUND_DARK,
] as const

function removeBrandPalette(target: HTMLElement): void {
  for (const property of BRAND_PROPERTIES) {
    target.style.removeProperty(property)
  }
}

/**
 * `RN-DS-04`/`RN-DS-05`: escribe las cuatro variables de §4.2 juntas, o
 * retira las cuatro (todo o nada) si `palette` es `null` o cualquiera de
 * los dos colores no casa con `^#[0-9A-Fa-f]{6}$`. Nunca crea un
 * elemento `<style>` ni escribe un semántico (`--primary`, …): eso
 * chocaría con una CSP sin `'unsafe-inline'` en `style-src` y rompería
 * el nivel de §4.1. Síncrona e idempotente.
 */
export function applyBrandPalette(
  palette: BrandPalette | null,
  target: HTMLElement = document.documentElement,
): void {
  if (palette === null || !isValidHex(palette.primary) || !isValidHex(palette.primaryForeground)) {
    removeBrandPalette(target)
    return
  }

  const primary = palette.primary.toUpperCase()
  const primaryForeground = palette.primaryForeground.toUpperCase()

  target.style.setProperty(BRAND_PRIMARY, primary)
  target.style.setProperty(BRAND_PRIMARY_FOREGROUND, primaryForeground)
  target.style.setProperty(BRAND_PRIMARY_ON_BACKGROUND_LIGHT, deriveOnBackground(primary, 'light'))
  target.style.setProperty(BRAND_PRIMARY_ON_BACKGROUND_DARK, deriveOnBackground(primary, 'dark'))
}
