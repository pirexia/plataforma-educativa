/**
 * `RN-ACAD-12`, `CA-ACAD-059`: `stage` y `regime` viajan como código técnico y
 * la SPA traduce su etiqueta; un código sin clave en el catálogo se muestra en
 * crudo (`ADR-038 §7.3`).
 */
import { useT } from '@/i18n'

export function useLabels() {
  const t = useT()

  function label(prefix: string, code: string): string {
    const key = `${prefix}.${code}`
    const text = t(key)

    return text === key ? code : text
  }

  return {
    stageLabel: (code: string) => label('acad.stage', code),
    regimeLabel: (code: string) => label('acad.regime', code),
    /** `CA-ACAD-100`: `period_type` y `scale_type` viajan sin traducir; sin clave, el código en crudo. */
    periodTypeLabel: (code: string) => label('acad.periodType', code),
    scaleTypeLabel: (code: string) => label('acad.scaleType', code),
  }
}
