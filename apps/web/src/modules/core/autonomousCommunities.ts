/**
 * `docs/modulos/REQ-CORE/funcional.md §14.9`, `RN-CORE-82`, `CA-CORE-252`.
 * Catálogo cerrado de comunidades y ciudades autónomas: constante del cliente,
 * **comprobada contra el servidor** (`autonomousCommunities.spec.ts`,
 * `App\Modules\Core\Domain\AutonomousCommunity::CODES`, fuente única). Mismo
 * orden que la constante PHP. El nombre visible de cada código es
 * `core.settings.autonomousCommunity.<código>` en los cuatro idiomas; un código
 * que el cliente no conoce se pinta crudo (`ADR-038 §7.3`).
 */
export const AUTONOMOUS_COMMUNITIES = [
  'AN',
  'AR',
  'AS',
  'IB',
  'CN',
  'CB',
  'CL',
  'CM',
  'CT',
  'EX',
  'GA',
  'MD',
  'MC',
  'NC',
  'PV',
  'RI',
  'VC',
  'CE',
  'ML',
] as const

export type AutonomousCommunityCode = (typeof AUTONOMOUS_COMMUNITIES)[number]
