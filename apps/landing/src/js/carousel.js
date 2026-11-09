/* Carrusel de la portada (ADR-075). Mejora progresiva: sin este script el carrusel se desplaza
 * con scroll-snap y teclado. Sin dependencias ni HTML dinámico. Los textos vienen de los atributos
 * data-* del contenedor (INV-009): este fichero no contiene literales visibles. */
(() => {
  'use strict'
  const DELAY = 6000

  // Lógica pura.
  const stepIndex = (i, n, d) => (i + d + n) % n
  const autoplay = (s) => !(s.reduced || s.user || s.hover || s.focus || s.hidden)
  const mostVisible = (ratios) => {
    let best = 0
    ratios.forEach((r, i) => {
      if (r > ratios[best]) best = i
    })
    return best
  }
  const fill = (text, n, total) => text.replace('{n}', n).replace('{total}', total)

  const make = (tag, cls, attrs) => {
    const e = document.createElement(tag)
    if (cls) e.className = cls
    for (const k in attrs || {}) e.setAttribute(k, attrs[k])
    return e
  }

  const init = (box) => {
    const slides = Array.from(box.querySelectorAll('.slide'))
    const list = box.querySelector('.slides')
    const n = slides.length
    if (n < 2 || !list || !box.parentNode) return
    const label = (k) => box.getAttribute(k) || ''
    const motion = matchMedia('(prefers-reduced-motion: reduce)')
    const st = { reduced: motion.matches, user: false, hover: false, focus: false, hidden: document.hidden }
    const ratios = slides.map(() => 0)
    let cur = 0
    let timer = 0
    let lock = 0
    let settle = 0
    let observer = null

    const frame = make('div', 'carousel-frame')
    box.parentNode.insertBefore(frame, box)
    frame.appendChild(box)
    const arrow = (dir, k) => {
      const b = make('button', 'carousel-arrow', { type: 'button', 'data-dir': dir, 'aria-label': label(k) })
      frame.appendChild(b)
      return b
    }
    const prev = arrow('prev', 'data-prev')
    const next = arrow('next', 'data-next')
    const bar = make('div', 'carousel-bar')
    const pause = make('button', 'carousel-pause', { type: 'button', 'aria-pressed': 'false' })
    bar.appendChild(pause)
    const dots = slides.map((_, i) => {
      const d = make('button', 'carousel-dot', {
        type: 'button',
        'aria-label': fill(label('data-goto'), i + 1, n),
      })
      d.addEventListener('click', () => goTo(i))
      bar.appendChild(d)
      return d
    })
    frame.appendChild(bar)

    const schedule = () => {
      clearTimeout(timer)
      timer = autoplay(st) ? setTimeout(() => goTo(stepIndex(cur, n, 1)), DELAY) : 0
    }
    const refresh = () => {
      pause.hidden = st.reduced
      pause.textContent = label(st.user ? 'data-resume' : 'data-pause')
      pause.setAttribute('aria-pressed', st.user ? 'true' : 'false')
      list.setAttribute('aria-live', autoplay(st) ? 'off' : 'polite')
      schedule()
    }
    const show = (i) => {
      const changed = i !== cur
      cur = i
      dots.forEach((d, k) => {
        if (k === i) d.setAttribute('aria-current', 'true')
        else d.removeAttribute('aria-current')
      })
      if (changed) schedule()
    }
    const sync = () => observer && show(mostVisible(ratios))

    const goTo = (i) => {
      const c = box.getBoundingClientRect()
      const r = slides[i].getBoundingClientRect()
      const cs = getComputedStyle(box)
      const pad = parseFloat(cs.scrollPaddingInlineStart) || 0
      const d = cs.direction === 'rtl' ? r.right - c.right + pad : r.left - c.left - pad
      box.scrollTo({ left: box.scrollLeft + d, behavior: st.reduced ? 'auto' : 'smooth' })
      lock = Date.now() + 800
      clearTimeout(settle)
      settle = setTimeout(sync, 850)
      show(i)
      schedule()
    }

    prev.addEventListener('click', () => goTo(stepIndex(cur, n, -1)))
    next.addEventListener('click', () => goTo(stepIndex(cur, n, 1)))
    pause.addEventListener('click', () => {
      st.user = !st.user
      refresh()
    })
    frame.addEventListener('pointerenter', (e) => {
      if (e.pointerType === 'mouse') {
        st.hover = true
        refresh()
      }
    })
    frame.addEventListener('pointerleave', () => {
      st.hover = false
      refresh()
    })
    frame.addEventListener('focusin', (e) => {
      st.focus = e.target.matches(':focus-visible')
      refresh()
    })
    frame.addEventListener('focusout', (e) => {
      if (!frame.contains(e.relatedTarget)) {
        st.focus = false
        refresh()
      }
    })
    document.addEventListener('visibilitychange', () => {
      st.hidden = document.hidden
      refresh()
    })
    motion.addEventListener('change', () => {
      st.reduced = motion.matches
      refresh()
    })
    if (typeof IntersectionObserver === 'function') {
      observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((e) => {
            ratios[slides.indexOf(e.target)] = e.intersectionRatio
          })
          if (Date.now() >= lock) sync()
        },
        { root: box, threshold: [0, 0.25, 0.5, 0.75, 1] },
      )
      slides.forEach((s) => observer.observe(s))
    }
    show(0)
    refresh()
  }

  document.querySelectorAll('.carousel').forEach(init)
})()
