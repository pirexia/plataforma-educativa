// Lógica pura de la línea de estado (sin `$`): se prueba con Node directamente.
export type CiState = 'ok' | 'pending' | 'fail' | 'none'
export type PrInfo = { number: number; state: string; ci: CiState } | null
export type Limit = { kind: string; percentUsed: number }

const FAIL_CONCLUSIONS = new Set(['FAILURE', 'CANCELLED', 'TIMED_OUT', 'ACTION_REQUIRED', 'STARTUP_FAILURE'])
const FAIL_STATES = new Set(['FAILURE', 'ERROR'])
const PENDING_STATES = new Set(['PENDING', 'EXPECTED'])

type RollupItem = { status?: string; conclusion?: string; state?: string }

// `gh pr view --json statusCheckRollup`: CheckRun {status, conclusion} o StatusContext {state}.
export function summarizeRollup(items: unknown): CiState {
  if (!Array.isArray(items) || items.length === 0) return 'none'
  let pending = false
  for (const raw of items as RollupItem[]) {
    if (raw.conclusion && FAIL_CONCLUSIONS.has(raw.conclusion)) return 'fail'
    if (raw.state && FAIL_STATES.has(raw.state)) return 'fail'
    if (raw.state && PENDING_STATES.has(raw.state)) pending = true
    if (raw.status && raw.status !== 'COMPLETED') pending = true
  }
  return pending ? 'pending' : 'ok'
}

export const PROTECTED = new Set(['develop', 'main'])

export function branchSegment(branch: string | undefined, dirty: number): string {
  if (!branch) return '⎇ (sin rama)'
  const mark = dirty > 0 ? `*${dirty}` : ''
  if (PROTECTED.has(branch)) return `⛔ ${branch}${mark} (rama protegida)`
  return `⎇ ${branch}${mark}`
}

export function prSegment(pr: PrInfo): string | undefined {
  if (!pr) return undefined
  if (pr.state === 'MERGED') return `PR #${pr.number} mezclado`
  if (pr.state === 'CLOSED') return `PR #${pr.number} cerrado`
  const ci = { ok: 'CI ✓', pending: 'CI …', fail: 'CI ✗', none: 'sin CI' }[pr.ci]
  return `PR #${pr.number} ${ci}`
}

export function ctxSegment(percent: number | undefined): string | undefined {
  if (percent === undefined) return undefined
  const p = Math.round(percent)
  // CLAUDE.md §2.5: reiniciar la sesión por encima de ~50 % de contexto.
  return p >= 50 ? `ctx ${p}% ⚠ reiniciar` : `ctx ${p}%`
}

const LABEL: Record<string, string> = { five_hour: '5h', seven_day: '7d' }

export function limitsSegment(limits: Limit[]): string | undefined {
  const parts = limits
    .filter(l => LABEL[l.kind])
    .map(l => `${LABEL[l.kind]} ${Math.round(l.percentUsed)}%`)
  if (parts.length === 0) return undefined
  const hot = limits.some(l => LABEL[l.kind] && l.percentUsed >= 80)
  // CLAUDE.md §3: al acercarse el límite, cierre de sesión sin esperar a que lo pidan.
  return hot ? `${parts.join(' ')} ⚠ cuota` : parts.join(' ')
}

export function formatStatus(i: {
  branch?: string
  dirty: number
  pr: PrInfo
  ctxPercent?: number
  limits: Limit[]
}): string {
  return [branchSegment(i.branch, i.dirty), prSegment(i.pr), ctxSegment(i.ctxPercent), limitsSegment(i.limits)]
    .filter((s): s is string => Boolean(s))
    .join('  ·  ')
}
