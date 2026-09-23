#!/usr/bin/env node
// INV-009. CLI: recorre src/**/*.vue y aplica findLiterals (lógica
// probada en i18n-literals.spec.ts) a cada uno. Sale con código 1 si
// encuentra algún literal sin traducir.

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { findLiterals } from './i18n-literals.mjs'

const rootDir = fileURLToPath(new URL('..', import.meta.url))
const srcDir = join(rootDir, 'src')

// `docs/design-system.md` §14: ya no se excluye `components/ui` (ni
// `design-system`, que no tiene ficheros `.vue`). `ADR-052 §5`/`RN-DS-24`:
// "un componente base no tiene literales propios" — con eso garantizado
// por construcción, el script pasa a demostrarlo en vez de suponerlo.

function listVueFiles(dir) {
  const files = []

  for (const entry of readdirSync(dir)) {
    const fullPath = join(dir, entry)

    const stats = statSync(fullPath)

    if (stats.isDirectory()) {
      files.push(...listVueFiles(fullPath))
    } else if (entry.endsWith('.vue')) {
      files.push(fullPath)
    }
  }

  return files
}

const files = listVueFiles(srcDir)
let totalFindings = 0

for (const file of files) {
  const findings = findLiterals(readFileSync(file, 'utf-8'), file)

  if (findings.length > 0) {
    totalFindings += findings.length
    console.error(`\n${relative(rootDir, file)}`)
    for (const finding of findings) {
      console.error(`  ${finding}`)
    }
  }
}

if (totalFindings > 0) {
  console.error(
    `\nINV-009: ${totalFindings} literal(es) sin traducir. Usa t('clave')/$t('clave') en su lugar.`,
  )
  process.exit(1)
}

console.log(`i18n: sin literales sin traducir en ${files.length} ficheros .vue.`)
