import type { Register } from 'claude-code'

import { formatStatus, summarizeRollup, type PrInfo } from '../lib/format'

const PR_TTL_MS = 45_000
const TICK_MS = 60_000

let pr: { branch: string; at: number; info: PrInfo } | undefined
let isBusy = false

async function git($: any, cwd: string, argv: string[]): Promise<string | undefined> {
  try {
    const r = await $.process.run(['git', ...argv], { cwd, timeoutMs: 5000 })
    return r.exitCode === 0 ? String(r.stdout).trim() : undefined
  } catch {
    return undefined
  }
}

async function prFor($: any, cwd: string, branch: string | undefined, force: boolean): Promise<PrInfo> {
  if (!branch || branch === 'develop' || branch === 'main') return null
  const now = await $.clock.now()
  if (!force && pr && pr.branch === branch && now - pr.at < PR_TTL_MS) return pr.info
  let info: PrInfo = null
  try {
    const r = await $.process.run(
      ['gh', 'pr', 'view', '--json', 'number,state,statusCheckRollup'],
      { cwd, timeoutMs: 15000 },
    )
    if (r.exitCode === 0) {
      const j = JSON.parse(String(r.stdout))
      info = { number: j.number, state: j.state, ci: summarizeRollup(j.statusCheckRollup) }
    }
  } catch {
    info = null
  }
  pr = { branch, at: now, info }
  return info
}

async function refresh($: any, force = false): Promise<void> {
  if (isBusy) return
  isBusy = true
  try {
    const cwd = await $.session.cwd()
    const branch = await git($, cwd, ['symbolic-ref', '--short', 'HEAD'])
    const porcelain = await git($, cwd, ['status', '--porcelain'])
    const dirty = porcelain ? porcelain.split('\n').filter(Boolean).length : 0
    const usage = await $.session.usage()
    const info = await prFor($, cwd, branch, force)
    $.ui.status(
      formatStatus({
        branch,
        dirty,
        pr: info,
        ctxPercent: usage.context.percent,
        limits: usage.rateLimits,
      }),
    )
  } catch {
    // Nunca romper la sesión por una línea de estado.
  } finally {
    isBusy = false
  }
}

export const register: Register = on => {
  on('session.start', async ($, e, next) => {
    const started = await next(e)
    void refresh($, true)
    // Mientras se espera el CI en segundo plano no hay turnos: refresco periódico.
    $.clock.every(TICK_MS, () => void refresh($))
    return started
  })

  on('turn.complete', async ($, e, next) => {
    const done = await next(e)
    void refresh($)
    return done
  })

  on('tool.call', { tool: 'Bash' }, async ($, e, next) => {
    const ran = await next(e)
    const forces = /\b(gh\s+pr|git\s+(push|checkout|switch|commit))\b/.test(e.command)
    void refresh($, forces)
    return ran
  })
}
