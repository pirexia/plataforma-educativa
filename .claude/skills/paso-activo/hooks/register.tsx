import { atom, read, update } from 'claude-code'
import type { Register } from 'claude-code'

import { parseMemory } from '../lib/memory'
import type { Snapshot } from '../types'

const PANE = 'paso-activo'
const TITLE = 'Paso activo'
const REFRESH_MS = 30_000

const EMPTY: Snapshot = { phase: '', current: '', next: '', error: '' }
// Cambia la etiqueta si cambian los campos: declina lo que guardó una versión anterior.
const snap = atom({ plugin: 'paso-activo', key: 'snap' } as const, EMPTY, { shape: 'snap-1' })

async function refresh($: any): Promise<void> {
  try {
    const md = String(await $.fs.read('memory.md'))
    const next = { ...parseMemory(md), error: '' }
    const old = await read($, snap)
    if (JSON.stringify(old) !== JSON.stringify(next)) await update($, snap, () => next)
  } catch {
    await update($, snap, () => ({ ...EMPTY, error: 'No se puede leer memory.md en este directorio.' }))
  }
}

export const register: Register = on => {
  on('session.start', async ($, e, next) => {
    await $.command.register({ name: 'paso-activo', description: 'Abre el panel con el paso activo (memory.md)' })
    void $.ui.open({ id: PANE, title: TITLE })
    const started = await next(e)
    void refresh($)
    $.clock.every(REFRESH_MS, () => void refresh($))
    return started
  })

  on('command.run', { command: 'paso-activo' }, async $ => {
    await $.ui.open({ id: PANE, title: TITLE })
    void refresh($)
    return { text: 'Panel «Paso activo» abierto.' }
  })

  on('turn.complete', async ($, e, next) => {
    const done = await next(e)
    void refresh($)
    return done
  })

  on('ui.render', { component: 'Pane', requestId: PANE }, async ($, e) => {
    const { Box, Text } = $.ui.resolve(e)
    const s = await read($, snap)
    if (s.error) return <Text color="red">{s.error}</Text>
    if (!s.current && !s.next && !s.phase) return <Text dimColor>Sin datos: memory.md no tiene las secciones esperadas.</Text>
    return (
      <Box flexDirection="column" gap={1}>
        {s.phase && (
          <Box flexDirection="column">
            <Text bold>Estado</Text>
            <Text dimColor>{s.phase}</Text>
          </Box>
        )}
        {s.current && (
          <Box flexDirection="column">
            <Text bold>Trabajo en curso</Text>
            <Text>{s.current}</Text>
          </Box>
        )}
        {s.next && (
          <Box flexDirection="column">
            <Text bold color="cyan">Siguiente paso</Text>
            <Text>{s.next}</Text>
          </Box>
        )}
        <Text dimColor>memory.md · se actualiza al acabar cada turno</Text>
      </Box>
    )
  })
}
