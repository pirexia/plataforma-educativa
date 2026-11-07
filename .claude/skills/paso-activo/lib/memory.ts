// Extrae de memory.md lo que el panel muestra (sin `$`: se prueba con Node).
export type Parsed = { phase: string; current: string; next: string }

function section(md: string, title: string): string {
  const start = md.search(new RegExp(`^## ${title}\\s*$`, 'm'))
  if (start < 0) return ''
  const rest = md.slice(start).replace(/^## .*\n/, '')
  const end = rest.search(/^(## |---\s*$)/m)
  return end < 0 ? rest : rest.slice(0, end)
}

function clean(text: string, max: number): string {
  const t = text.replace(/\*\*|`/g, '').replace(/\[([^\]]+)\]\([^)]*\)/g, '$1').replace(/\s+/g, ' ').trim()
  return t.length > max ? `${t.slice(0, max - 1)}…` : t
}

function paragraphs(text: string): string[] {
  return text.split(/\n\s*\n/).map(p => p.trim()).filter(Boolean)
}

// `1. **Siguiente…**` hasta el siguiente ítem numerado.
function firstItem(text: string): string {
  const lines = text.split('\n')
  const first = lines.findIndex(l => /^1\.\s/.test(l))
  if (first < 0) return ''
  const out = [lines[first]]
  for (const l of lines.slice(first + 1)) {
    if (/^\d+[a-z]?\.\s/.test(l)) break
    out.push(l)
  }
  return out.join(' ')
}

export function parseMemory(md: string): Parsed {
  const phase = paragraphs(section(md, 'Estado actual'))[0] ?? ''
  const current = paragraphs(section(md, 'Trabajo en curso')).find(p => p.startsWith('**Sesión')) ?? paragraphs(section(md, 'Trabajo en curso'))[0] ?? ''
  const next = firstItem(section(md, 'Siguiente paso concreto'))
  return { phase: clean(phase, 420), current: clean(current, 900), next: clean(next, 1100) }
}
