// ADR-075 §2.7: comportamiento de src/js/carousel.js con una maqueta mínima de DOM, sin dependencias.
// El script se ejecuta con `vm` contra los stubs de abajo (document, matchMedia, IntersectionObserver,
// temporizadores con reloj virtual).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import vm from 'node:vm'
import { PROJECT } from './helpers.mjs'

const SOURCE = readFileSync(join(PROJECT, 'src', 'js', 'carousel.js'), 'utf8')

class El {
  constructor(tag) {
    this.tagName = tag
    this.attrs = {}
    this.children = []
    this.parentNode = null
    this.listeners = {}
    this.className = ''
    this.hidden = false
    this.textContent = ''
    this.scrollLeft = 0
    this.scrolls = []
    this.rect = { left: 0, right: 0 }
    this.focusVisible = false
  }
  setAttribute(k, v) {
    this.attrs[k] = String(v)
  }
  getAttribute(k) {
    return k in this.attrs ? this.attrs[k] : null
  }
  removeAttribute(k) {
    delete this.attrs[k]
  }
  detach() {
    if (this.parentNode) this.parentNode.children = this.parentNode.children.filter((c) => c !== this)
    this.parentNode = null
  }
  appendChild(c) {
    c.detach()
    c.parentNode = this
    this.children.push(c)
    return c
  }
  insertBefore(n, ref) {
    n.detach()
    n.parentNode = this
    this.children.splice(this.children.indexOf(ref), 0, n)
    return n
  }
  addEventListener(t, f) {
    ;(this.listeners[t] ??= []).push(f)
  }
  fire(t, ev = {}) {
    for (const f of this.listeners[t] ?? []) f({ target: this, ...ev })
  }
  contains(x) {
    return x === this || this.children.some((c) => c.contains(x))
  }
  matches(sel) {
    return sel === ':focus-visible' && this.focusVisible
  }
  querySelectorAll(sel) {
    return sel === '.slide' ? this.slideEls : []
  }
  querySelector(sel) {
    return sel === '.slides' ? this.listEl : null
  }
  getBoundingClientRect() {
    return this.rect
  }
  scrollTo(o) {
    this.scrolls.push(o)
  }
}

function setup({ slides = 8, reduced = false, rtl = false, io = true } = {}) {
  let clock = 0
  let seq = 0
  let timers = []
  const state = { reduced, hidden: false }
  const docListeners = {}
  const mqlListeners = []
  const box = new El('div')
  box.className = 'carousel'
  Object.assign(box.attrs, {
    'data-prev': 'Prev',
    'data-next': 'Next',
    'data-goto': 'Go {n}/{total}',
    'data-pause': 'Pause',
    'data-resume': 'Resume',
  })
  box.listEl = new El('ul')
  box.rect = { left: 0, right: 1000 }
  box.slideEls = Array.from({ length: slides }, (_, i) => {
    const s = new El('li')
    s.rect = rtl ? { left: 1000 - i * 500 - 450, right: 1000 - i * 500 } : { left: i * 500, right: i * 500 + 450 }
    return s
  })
  const root = new El('section')
  root.appendChild(box)
  let observer = null
  const document = {
    get hidden() {
      return state.hidden
    },
    querySelectorAll: (sel) => (sel === '.carousel' ? [box] : []),
    createElement: (t) => new El(t),
    addEventListener: (t, f) => (docListeners[t] ??= []).push(f),
  }
  const ctx = {
    document,
    matchMedia: () => ({
      get matches() {
        return state.reduced
      },
      addEventListener: (_, f) => mqlListeners.push(f),
    }),
    getComputedStyle: () => ({ direction: rtl ? 'rtl' : 'ltr', scrollPaddingInlineStart: '20px' }),
    IntersectionObserver: !io ? undefined : class {
      constructor(cb) {
        this.cb = cb
        observer = this
        this.seen = []
      }
      observe(el) {
        this.seen.push(el)
      }
    },
    Date: { now: () => clock },
    setTimeout: (fn, ms) => {
      const id = ++seq
      timers.push({ id, at: clock + ms, fn })
      return id
    },
    clearTimeout: (id) => {
      timers = timers.filter((t) => t.id !== id)
    },
  }
  vm.runInNewContext(SOURCE, ctx)
  // El navegador real informa de las razones de visibilidad: al montar y al terminar cada desplazamiento.
  const report = (ratios) =>
    observer.cb(ratios.map((r, i) => ({ target: box.slideEls[i], intersectionRatio: r })))
  const only = (i) => box.slideEls.map((_, k) => (k === i ? 1 : 0))
  if (observer) {
    report(only(0))
    box.scrollTo = (o) => {
      box.scrolls.push(o)
      const i = rtl ? (20 - o.left) / 500 : (o.left + 20) / 500
      ctx.setTimeout(() => report(only(Math.round(i))), 300)
    }
  }
  const frame = box.parentNode
  const [, prev, next, bar] = frame.children
  const [pause, ...dots] = bar?.children ?? []
  const api = {
    box,
    frame,
    prev,
    next,
    bar,
    pause,
    dots,
    list: box.listEl,
    advance(ms) {
      const end = clock + ms
      for (;;) {
        const due = timers.filter((t) => t.at <= end).sort((a, b) => a.at - b.at || a.id - b.id)[0]
        if (!due) break
        timers = timers.filter((t) => t !== due)
        clock = due.at
        due.fn()
      }
      clock = end
    },
    current: () => dots.findIndex((d) => d.attrs['aria-current'] === 'true'),
    reportRatios: (ratios) => report(ratios),
    setHidden(v) {
      state.hidden = v
      docListeners.visibilitychange.forEach((f) => f())
    },
    setReduced(v) {
      state.reduced = v
      mqlListeners.forEach((f) => f())
    },
    observer: () => observer,
  }
  return api
}

test('ADR-075 estructura: dos flechas, un punto por diapositiva con su etiqueta, activo el primero', () => {
  const c = setup()
  assert.equal(c.frame.className, 'carousel-frame')
  assert.equal(c.frame.children[0], c.box)
  assert.equal(c.prev.attrs['aria-label'], 'Prev')
  assert.equal(c.next.attrs['aria-label'], 'Next')
  assert.equal(c.prev.attrs.type, 'button')
  assert.equal(c.dots.length, 8)
  assert.equal(c.dots[2].attrs['aria-label'], 'Go 3/8')
  assert.equal(c.current(), 0)
  assert.equal(c.pause.textContent, 'Pause')
  assert.equal(c.pause.attrs['aria-pressed'], 'false')
  assert.equal(c.observer().seen.length, 8)
})

test('ADR-075 avance automático cada 6 s con vuelta a la primera', () => {
  const c = setup()
  c.advance(5999)
  assert.equal(c.box.scrolls.length, 0)
  c.advance(1)
  assert.equal(c.box.scrolls.length, 1)
  assert.equal(c.box.scrolls[0].left, 480) // 500 - relleno de 20
  assert.equal(c.box.scrolls[0].behavior, 'smooth')
  assert.equal(c.current(), 1)
  for (let i = 2; i <= 7; i++) {
    c.advance(6000)
    assert.equal(c.current(), i)
  }
  c.advance(6000)
  assert.equal(c.current(), 0, 'vuelve a la primera')
  assert.equal(c.box.scrolls.at(-1).left, -20)
})

test('ADR-075 flechas y puntos: avanzan, retroceden con vuelta y reinician la cuenta atrás', () => {
  const c = setup()
  c.prev.fire('click')
  assert.equal(c.current(), 7, 'anterior desde la primera da la última')
  c.next.fire('click')
  assert.equal(c.current(), 0, 'siguiente desde la última da la primera')
  c.dots[4].fire('click')
  assert.equal(c.current(), 4)
  assert.equal(c.box.scrolls.at(-1).left, 4 * 500 - 20)
  c.advance(5000)
  c.next.fire('click')
  assert.equal(c.current(), 5)
  const n = c.box.scrolls.length
  c.advance(5999)
  assert.equal(c.box.scrolls.length, n, 'el clic reinició los 6 s')
  c.advance(1)
  assert.equal(c.current(), 6)
})

test('ADR-075 botón de pausa: aria-pressed, etiqueta y reanudación (WCAG 2.2.2)', () => {
  const c = setup()
  assert.equal(c.list.attrs['aria-live'], 'off')
  c.pause.fire('click')
  assert.equal(c.pause.attrs['aria-pressed'], 'true')
  assert.equal(c.pause.textContent, 'Resume')
  assert.equal(c.list.attrs['aria-live'], 'polite')
  c.advance(60000)
  assert.equal(c.box.scrolls.length, 0, 'en pausa no avanza')
  c.pause.fire('click')
  assert.equal(c.pause.attrs['aria-pressed'], 'false')
  assert.equal(c.pause.textContent, 'Pause')
  c.advance(6000)
  assert.equal(c.current(), 1)
})

test('ADR-075 prefers-reduced-motion: sin avance automático, sin botón de pausa, controles manuales sí', () => {
  const c = setup({ reduced: true })
  assert.equal(c.pause.hidden, true)
  c.advance(60000)
  assert.equal(c.box.scrolls.length, 0)
  c.next.fire('click')
  assert.equal(c.current(), 1)
  assert.equal(c.box.scrolls[0].behavior, 'auto', 'sin desplazamiento suave')
  c.advance(60000)
  assert.equal(c.box.scrolls.length, 1)
  // El cambio de preferencia en caliente se respeta en los dos sentidos.
  c.setReduced(false)
  assert.equal(c.pause.hidden, false)
  c.advance(6000)
  assert.equal(c.current(), 2)
  c.setReduced(true)
  c.advance(60000)
  assert.equal(c.current(), 2)
})

test('ADR-075 puntero: el ratón encima pausa y al salir reanuda; un toque no pausa', () => {
  const c = setup()
  c.frame.fire('pointerenter', { pointerType: 'touch' })
  c.advance(6000)
  assert.equal(c.current(), 1, 'el toque no pausa')
  c.frame.fire('pointerenter', { pointerType: 'mouse' })
  c.advance(60000)
  assert.equal(c.current(), 1)
  assert.equal(c.list.attrs['aria-live'], 'polite')
  c.frame.fire('pointerleave')
  c.advance(6000)
  assert.equal(c.current(), 2)
})

test('ADR-075 foco: el foco de teclado dentro pausa y al salir reanuda; el foco de ratón no', () => {
  const c = setup()
  const outside = new El('a')
  c.next.focusVisible = false
  c.frame.fire('focusin', { target: c.next })
  c.advance(6000)
  assert.equal(c.current(), 1, 'foco por clic: no pausa')
  c.next.focusVisible = true
  c.frame.fire('focusin', { target: c.next })
  c.advance(60000)
  assert.equal(c.current(), 1)
  c.frame.fire('focusout', { relatedTarget: c.prev })
  c.advance(60000)
  assert.equal(c.current(), 1, 'el foco sigue dentro del carrusel')
  c.frame.fire('focusout', { relatedTarget: outside })
  c.advance(6000)
  assert.equal(c.current(), 2)
})

test('ADR-075 pestaña oculta: pausa y al volver reanuda', () => {
  const c = setup()
  c.setHidden(true)
  c.advance(60000)
  assert.equal(c.box.scrolls.length, 0)
  c.setHidden(false)
  c.advance(6000)
  assert.equal(c.current(), 1)
})

test('ADR-075 pausa del usuario y de otra causa son independientes: reanudar no vence al ratón', () => {
  const c = setup()
  c.pause.fire('click')
  c.frame.fire('pointerenter', { pointerType: 'mouse' })
  c.pause.fire('click')
  c.advance(60000)
  assert.equal(c.box.scrolls.length, 0, 'sigue el ratón encima')
  c.frame.fire('pointerleave')
  c.advance(6000)
  assert.equal(c.current(), 1)
})

test('ADR-075 RTL: el desplazamiento se calcula desde el borde derecho', () => {
  const c = setup({ rtl: true })
  c.next.fire('click')
  // Diapositiva 1: right = 1000 - 500 = 500; borde derecho del contenedor = 1000; relleno 20.
  assert.equal(c.box.scrolls[0].left, 500 - 1000 + 20)
  c.dots[3].fire('click')
  assert.equal(c.box.scrolls.at(-1).left, 1000 - 3 * 500 - 1000 + 20)
})

test('ADR-075 puntos sincronizados con el desplazamiento manual (IntersectionObserver)', () => {
  const c = setup()
  c.reportRatios([0.2, 1, 0.1, 0, 0, 0, 0, 0])
  assert.equal(c.current(), 1)
  c.reportRatios([0, 0, 0.3, 0.9, 0, 0, 0, 0])
  assert.equal(c.current(), 3)
  c.reportRatios([0, 0, 0, 0, 0, 0, 0.1, 1])
  assert.equal(c.current(), 7)
  // Un gesto manual también reinicia la cuenta atrás de 6 s.
  c.advance(5000)
  c.reportRatios([1, 0.1, 0, 0, 0, 0, 0, 0])
  assert.equal(c.current(), 0)
  const n = c.box.scrolls.length
  c.advance(5999)
  assert.equal(c.box.scrolls.length, n)
  c.advance(1)
  assert.equal(c.current(), 1)
})

test('ADR-075 durante el desplazamiento programático los puntos no parpadean y luego se sincronizan', () => {
  const c = setup()
  c.dots[5].fire('click')
  c.reportRatios([0, 0.5, 0.5, 0, 0, 0, 0, 0]) // pasos intermedios del scroll suave
  assert.equal(c.current(), 5)
  c.advance(900)
  c.reportRatios([0, 0, 0, 0, 0, 1, 0, 0])
  assert.equal(c.current(), 5)
})

test('ADR-075 sin IntersectionObserver el índice sigue a los controles y no se reinicia', () => {
  const c = setup({ io: false })
  c.dots[3].fire('click')
  c.advance(900)
  assert.equal(c.current(), 3)
  c.advance(6000)
  assert.equal(c.current(), 4)
})

test('ADR-075 con una sola diapositiva no crea controles', () => {
  const one = setup({ slides: 1 })
  assert.equal(one.frame.className, '')
  assert.equal(one.box.parentNode.children.length, 1)
})

test('ADR-075 el script no escribe HTML ni texto propio: las etiquetas son las de los data-*', () => {
  const c = setup()
  const texts = [c.pause.textContent, ...[c.prev, c.next, ...c.dots].map((b) => b.attrs['aria-label'])]
  const allowed = ['Prev', 'Next', 'Pause', 'Resume', ...Array.from({ length: 8 }, (_, i) => `Go ${i + 1}/8`)]
  for (const t of texts) assert.ok(allowed.includes(t), t)
})
