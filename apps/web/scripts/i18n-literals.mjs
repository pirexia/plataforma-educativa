// INV-009: ningún literal visible escrito en el código, todo por el
// sistema de traducción. Lógica pura (sin tocar disco) para que
// i18n-literals.spec.ts la pruebe sobre fuentes en memoria; el recorrido
// de ficheros vive en check-i18n-literals.mjs.
//
// Limitación conocida y asumida (no tiene que ser perfecto, sí tiene que
// ejecutarse en CI, ver ADR-035/paso 0.9): no analiza .ts/.js sueltos
// (mensajes construidos fuera de una plantilla), ni detecta concatenación
// de texto con una interpolación (`{{ 'Hola ' + nombre }}`, un patrón que
// tampoco se usa en este proyecto). Cubre plantillas Vue: nodos de texto
// y un conjunto acotado de atributos de contenido visible.

import { parse as parseSfc } from '@vue/compiler-sfc'
import { parse as parseTemplate, NodeTypes } from '@vue/compiler-dom'

// Atributos de contenido visible en los que un literal estático es un
// texto de interfaz igual que uno en el cuerpo de la plantilla.
export const CONTENT_ATTRIBUTES = new Set(['placeholder', 'title', 'alt', 'aria-label'])

function hasLetters(text) {
  return /\p{L}/u.test(text)
}

function walk(node, onTextLike, onElement) {
  if (node.type === NodeTypes.TEXT) {
    onTextLike(node)
  }

  if (node.type === NodeTypes.ELEMENT) {
    onElement(node)
  }

  const children = node.children ?? []

  for (const child of children) {
    walk(child, onTextLike, onElement)
  }
}

/**
 * @param {string} source contenido completo de un fichero .vue
 * @param {string} filename usado solo para mensajes de error del parser
 * @returns {string[]} hallazgos legibles, vacío si no hay literales
 */
export function findLiterals(source, filename = 'anonymous.vue') {
  const { descriptor } = parseSfc(source, { filename })

  if (!descriptor.template) {
    return []
  }

  const findings = []
  const { content, loc } = descriptor.template
  const ast = parseTemplate(content, { onError: () => {} })

  walk(
    ast,
    (textNode) => {
      const text = textNode.content.trim()

      if (text !== '' && hasLetters(text)) {
        const line = loc.start.line + textNode.loc.start.line - 1
        findings.push(`literal sin traducir: "${text}" (línea ${line})`)
      }
    },
    (elementNode) => {
      for (const prop of elementNode.props) {
        if (
          prop.type === NodeTypes.ATTRIBUTE &&
          CONTENT_ATTRIBUTES.has(prop.name) &&
          prop.value?.content?.trim()
        ) {
          const line = loc.start.line + prop.loc.start.line - 1
          findings.push(
            `literal sin traducir en atributo "${prop.name}": "${prop.value.content}" (línea ${line})`,
          )
        }
      }
    },
  )

  return findings
}
