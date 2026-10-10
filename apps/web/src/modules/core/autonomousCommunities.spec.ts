/**
 * `docs/modulos/REQ-CORE/funcional.md §14.9`, `RN-CORE-82`, `CA-CORE-252`:
 * la constante `AUTONOMOUS_COMMUNITIES` del cliente contiene exactamente los
 * códigos de `App\Modules\Core\Domain\AutonomousCommunity::CODES` y cada código
 * tiene nombre en `es`, `en`, `de` y `fr`.
 *
 * **Lectura cruzada** (precedente: `documentTypes.spec.ts`): lee un fichero de
 * `apps/api`, que está en el monorepo pero **no** dentro del contenedor `web`
 * (`compose.yaml` solo monta `apps/web`). Si no es alcanzable, la comprobación
 * cruzada se **omite** (queda como `skipped`, a la vista) en vez de pasar en
 * falso; en CI, con el repositorio completo, se ejecuta.
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import de from './locales/de.json'
import en from './locales/en.json'
import es from './locales/es.json'
import fr from './locales/fr.json'
import { AUTONOMOUS_COMMUNITIES } from './autonomousCommunities'

// `process.cwd()` es `apps/web` (como en `architecture.spec.ts`).
const phpClass = resolve(
  process.cwd(),
  '..',
  'api',
  'app',
  'Modules',
  'Core',
  'Domain',
  'AutonomousCommunity.php',
)

const LOCALES = { es, en, de, fr } as const

describe('CA-CORE-252 (RN-CORE-82): comunidades autónomas del cliente contra el servidor', () => {
  it.skipIf(!existsSync(phpClass))(
    'AUTONOMOUS_COMMUNITIES contiene exactamente los códigos de AutonomousCommunity::CODES',
    () => {
      const source = readFileSync(phpClass, 'utf-8')
      const block = /CODES\s*=\s*\[([\s\S]*?)\];/.exec(source)?.[1] ?? ''
      const codes = [...block.matchAll(/'([A-Z]{2})'/g)].map((match) => match[1])

      expect(codes.length).toBeGreaterThan(0)
      expect([...AUTONOMOUS_COMMUNITIES]).toEqual(codes)
    },
  )

  it.each(Object.entries(LOCALES))(
    '«%s»: cada código tiene nombre y no hay nombres de más',
    (_locale, catalog) => {
      const names = catalog.core.settings.autonomousCommunity as Record<string, string>

      for (const code of AUTONOMOUS_COMMUNITIES) {
        expect(names[code]?.trim().length, code).toBeGreaterThan(0)
      }

      expect(Object.keys(names).sort()).toEqual([...AUTONOMOUS_COMMUNITIES].sort())
    },
  )
})
