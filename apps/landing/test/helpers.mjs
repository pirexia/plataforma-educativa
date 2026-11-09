import { mkdtempSync, cpSync, readFileSync, writeFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { build } from '../scripts/build.mjs'

export const PROJECT = join(dirname(fileURLToPath(import.meta.url)), '..')
export const FIXTURES = join(PROJECT, 'test', 'fixtures')

const created = []

// Copia src/ y locales/ del proyecto a un directorio temporal. Con `built`, construye dist/.
export function makeSite({ built = true } = {}) {
  const root = mkdtempSync(join(tmpdir(), 'landing-test-'))
  created.push(root)
  cpSync(join(PROJECT, 'src'), join(root, 'src'), { recursive: true })
  cpSync(join(PROJECT, 'locales'), join(root, 'locales'), { recursive: true })
  if (built) build({ root })
  return root
}

export function cleanup() {
  for (const d of created.splice(0)) rmSync(d, { recursive: true, force: true })
}

export function edit(root, rel, fn) {
  const file = join(root, rel)
  writeFileSync(file, fn(readFileSync(file, 'utf8')))
}

export function editJson(root, rel, fn) {
  const file = join(root, rel)
  const data = JSON.parse(readFileSync(file, 'utf8'))
  fn(data)
  writeFileSync(file, JSON.stringify(data, null, 2))
}

export const fixture = (name) => readFileSync(join(FIXTURES, name), 'utf8')
