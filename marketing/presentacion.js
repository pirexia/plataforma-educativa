const pptxgen = require("pptxgenjs");
const { icono } = require("./iconos");

// ══════════════════════════════════════════ identidad visual
const C = {
  tinta:   "0A2E2D",   // fondo oscuro
  tinta2:  "134240",   // oscuro secundario
  teal:    "0F766E",   // primario
  tealMid: "14A79B",   // primario claro
  ambar:   "E8A33D",   // acento cálido
  ambarOsc:"C4822A",
  claro:   "F4F8F7",   // fondo claro
  gris:    "5B6B6A",   // texto secundario
  blanco:  "FFFFFF",
  linea:   "D5E3E1",
};

const F = { tit: "Cambria", txt: "Calibri" };
const MARCA = "Colegia";

const pres = new pptxgen();
pres.layout = "LAYOUT_WIDE";            // 13.3 x 7.5
pres.author = "Colegia";
pres.title = "Colegia · Plataforma de gestión educativa";

const W = 13.3, H = 7.5;

// ══════════════════════════════════════════ helpers

function slideOscuro() {
  const s = pres.addSlide();
  s.background = { color: C.tinta };
  return s;
}
function slideClaro() {
  const s = pres.addSlide();
  s.background = { color: C.blanco };
  return s;
}

function titulo(s, texto, opts = {}) {
  s.addText(texto, {
    x: 0.7, y: opts.y ?? 0.55, w: W - 1.4, h: 0.9,
    fontFace: F.tit, fontSize: opts.size ?? 34, bold: true,
    color: opts.color ?? C.tinta, align: "left", margin: 0,
  });
}

function subtitulo(s, texto, opts = {}) {
  s.addText(texto, {
    x: 0.7, y: opts.y ?? 1.42, w: opts.w ?? W - 2.4, h: 0.5,
    fontFace: F.txt, fontSize: opts.size ?? 15,
    color: opts.color ?? C.gris, align: "left", margin: 0,
  });
}

// tarjeta con icono en círculo
async function tarjeta(s, { x, y, w, h, ico, cab, cuerpo, tono = "claro", acento = C.teal }) {
  const fondo = tono === "claro" ? C.claro : C.tinta2;
  const cabColor = tono === "claro" ? C.tinta : C.blanco;
  const txtColor = tono === "claro" ? C.gris : "B9CFCD";

  s.addShape(pres.ShapeType.roundRect, {
    x, y, w, h, rectRadius: 0.12,
    fill: { color: fondo }, line: { color: fondo },
    shadow: { type: "outer", color: "0A2E2D", blur: 8, offset: 1, angle: 90, opacity: 0.10 },
  });
  if (ico) {
    s.addShape(pres.ShapeType.ellipse, {
      x: x + 0.28, y: y + 0.26, w: 0.62, h: 0.62,
      fill: { color: acento }, line: { color: acento },
    });
    s.addImage({ data: await icono(ico, C.blanco), x: x + 0.43, y: y + 0.41, w: 0.32, h: 0.32 });
  }
  s.addText(cab, {
    x: x + (ico ? 1.04 : 0.3), y: y + 0.3, w: w - (ico ? 1.34 : 0.6), h: 0.42,
    fontFace: F.txt, fontSize: 14.5, bold: true, color: cabColor, margin: 0, valign: "middle",
  });
  if (cuerpo) {
    s.addText(cuerpo, {
      x: x + 0.3, y: y + (ico ? 0.95 : 0.75), w: w - 0.6, h: h - (ico ? 1.2 : 0.95),
      fontFace: F.txt, fontSize: 12, color: txtColor, margin: 0, lineSpacing: 17,
    });
  }
}

function estadistica(s, { x, y, w, cifra, etiqueta, color = C.ambar }) {
  s.addText(cifra, {
    x, y, w, h: 0.95, fontFace: F.tit, fontSize: 54, bold: true,
    color, align: "center", margin: 0,
  });
  s.addText(etiqueta, {
    x, y: y + 0.95, w, h: 0.6, fontFace: F.txt, fontSize: 12,
    color: "B9CFCD", align: "center", margin: 0,
  });
}

// ══════════════════════════════════════════ contenido

const PAQUETES = {
  esencial: {
    nombre: "Esencial",
    lema: "El colegio funcionando",
    color: C.tealMid,
    precio: "3,20 €",
    modulos: 17,
    para: "Centros que quieren dejar el papel y la hoja de cálculo",
    incluye: [
      "Alumnado, matrícula y expediente",
      "Unidad familiar, tutores y autorizados",
      "Estructura académica, grupos y horarios",
      "Paso de lista y control de asistencia",
      "Calificaciones y boletines en PDF",
      "Mensajería, circulares y calendario",
      "Portal de familias, docente y alumno",
      "Primer ciclo de Infantil 0-3",
      "Copias de seguridad y migración de datos",
    ],
  },
  avanzado: {
    nombre: "Avanzado",
    lema: "La gestión completa",
    color: C.teal,
    precio: "5,40 €",
    modulos: 34,
    para: "Centros concertados y privados con facturación propia",
    incluye: [
      "Todo lo del paquete Esencial",
      "Facturación, recibos y remesas SEPA",
      "Becas, ayudas y bonificaciones",
      "Transporte escolar y rutas",
      "Comedor y menús",
      "Actividades extraescolares",
      "Recursos humanos y control de jornada",
      "Convivencia y partes de incidencia",
      "Protección de datos y registro de actividades",
      "Cuadros de mando e informes",
    ],
  },
  completo: {
    nombre: "Completo",
    lema: "Todo el centro, en un sitio",
    color: C.ambar,
    precio: "7,90 €",
    modulos: 53,
    para: "Grupos educativos y centros con servicios propios",
    incluye: [
      "Todo lo del paquete Avanzado",
      "Banco de libros y material curricular",
      "Tienda y uniformes",
      "Web pública del centro",
      "Nóminas y gestión de espacios",
      "Aula virtual y biblioteca",
      "Encuestas y gobierno del centro",
      "Admisión, CRM y captación",
      "API abierta e integraciones",
      "Aplicaciones móviles con marca del centro",
    ],
  },
};

const AREAS = [
  { ico: "FiUsers",      t: "Personas",      d: "Alumnado, familias, tutores, personal", n: 9 },
  { ico: "FiBookOpen",   t: "Académico",     d: "Grupos, horarios, asistencia, notas", n: 8 },
  { ico: "FiCreditCard", t: "Económico",     d: "Facturación, becas, recibos, cobros", n: 6 },
  { ico: "FiTruck",      t: "Servicios",     d: "Transporte, comedor, extraescolares", n: 9 },
  { ico: "FiShield",     t: "Cumplimiento",  d: "RGPD, convivencia, salud, riesgos", n: 8 },
  { ico: "FiSettings",   t: "Plataforma",    d: "Portales, API, copias, soporte", n: 13 },
];

async function construir() {

  // ─────────────────────────────────── 1 · portada
  {
    const s = slideOscuro();
    s.addShape(pres.ShapeType.ellipse, {
      x: 9.6, y: -1.6, w: 6.2, h: 6.2,
      fill: { color: C.teal }, line: { color: C.teal }, transparency: 62,
    });
    s.addShape(pres.ShapeType.ellipse, {
      x: 11.2, y: 3.6, w: 4.4, h: 4.4,
      fill: { color: C.ambar }, line: { color: C.ambar }, transparency: 76,
    });
    s.addText(MARCA, {
      x: 0.9, y: 2.15, w: 8.6, h: 1.3,
      fontFace: F.tit, fontSize: 72, bold: true, color: C.blanco, margin: 0,
    });
    s.addText("La gestión del colegio, por fin en un solo sitio", {
      x: 0.95, y: 3.45, w: 8.4, h: 0.7,
      fontFace: F.txt, fontSize: 21, color: C.tealMid, margin: 0,
    });
    s.addText(
      "Plataforma integral para centros concertados, públicos y privados.\nDiseñada en España, para el modelo educativo español.",
      { x: 0.95, y: 4.25, w: 8.0, h: 1.0, fontFace: F.txt, fontSize: 13.5, color: "9FBCB9", margin: 0, lineSpacing: 20 }
    );
    s.addShape(pres.ShapeType.roundRect, {
      x: 0.95, y: 5.6, w: 3.2, h: 0.52, rectRadius: 0.26,
      fill: { color: C.ambar }, line: { color: C.ambar },
    });
    s.addText("Curso 2026 / 2027", {
      x: 0.95, y: 5.6, w: 3.2, h: 0.52, fontFace: F.txt, fontSize: 12.5,
      bold: true, color: C.tinta, align: "center", valign: "middle", margin: 0,
    });
    s.addNotes("Presentación comercial. Nombre de marca provisional.");
  }

  // ─────────────────────────────────── 2 · el problema
  {
    const s = slideClaro();
    titulo(s, "El colegio trabaja dos veces");
    subtitulo(s, "Lo que hoy ocurre en la mayoría de centros concertados de Madrid");

    const items = [
      { ico: "FiCopy",       t: "Doble grabación",   d: "El mismo dato se introduce en Raíces y en la herramienta interna. Dos veces, cada vez." },
      { ico: "FiGrid",       t: "Islas de datos",    d: "Comedor en una hoja, transporte en otra, recibos en el banco. Nada se cruza." },
      { ico: "FiMail",       t: "Comunicación rota", d: "Circulares en papel, avisos por WhatsApp y familias que no se enteran." },
      { ico: "FiAlertTriangle", t: "Riesgo legal",   d: "Datos de menores en hojas compartidas, sin consentimientos ni trazabilidad." },
    ];
    let x = 0.7;
    for (const it of items) {
      await tarjeta(s, { x, y: 2.4, w: 2.87, h: 3.0, ico: it.ico, cab: it.t, cuerpo: it.d, acento: C.ambarOsc });
      x += 3.05;
    }
    s.addText("El coste no es el software: es el tiempo del equipo y el error humano.", {
      x: 0.7, y: 5.85, w: W - 1.4, h: 0.5, fontFace: F.txt, fontSize: 15,
      italic: true, color: C.teal, margin: 0,
    });
  }

  // ─────────────────────────────────── 3 · posicionamiento
  {
    const s = slideClaro();
    titulo(s, "No sustituimos a Raíces. Lo alimentamos.");
    subtitulo(s, "Raíces y Roble siguen siendo el sistema oficial. Nosotros eliminamos la doble grabación.");

    // columna izquierda: Raíces
    s.addShape(pres.ShapeType.roundRect, {
      x: 0.7, y: 2.25, w: 3.6, h: 3.5, rectRadius: 0.12,
      fill: { color: C.claro }, line: { color: C.linea },
    });
    s.addText("Raíces / Roble", { x: 0.95, y: 2.5, w: 3.1, h: 0.45, fontFace: F.txt, fontSize: 16, bold: true, color: C.tinta, margin: 0 });
    s.addText("Sistema oficial de la Consejería", { x: 0.95, y: 2.9, w: 3.1, h: 0.35, fontFace: F.txt, fontSize: 11, color: C.gris, margin: 0 });
    s.addText(
      [
        { text: "Matrícula oficial", options: { bullet: true, breakLine: true } },
        { text: "Evaluación final y promoción", options: { bullet: true, breakLine: true } },
        { text: "Documentos oficiales", options: { bullet: true, breakLine: true } },
        { text: "Comunicación con la Administración", options: { bullet: true } },
      ],
      { x: 0.95, y: 3.4, w: 3.1, h: 2.1, fontFace: F.txt, fontSize: 11.5, color: C.gris, margin: 0, paraSpaceAfter: 8 }
    );

    // flecha central
    s.addShape(pres.ShapeType.rightArrow, {
      x: 4.55, y: 3.75, w: 1.0, h: 0.5,
      fill: { color: C.ambar }, line: { color: C.ambar },
    });
    s.addText("volcado\nautomático", { x: 4.35, y: 4.3, w: 1.4, h: 0.6, fontFace: F.txt, fontSize: 9.5, color: C.gris, align: "center", margin: 0 });

    // columna derecha: Colegia
    s.addShape(pres.ShapeType.roundRect, {
      x: 5.85, y: 2.25, w: 6.75, h: 3.5, rectRadius: 0.12,
      fill: { color: C.tinta }, line: { color: C.tinta },
    });
    s.addText(MARCA, { x: 6.15, y: 2.5, w: 6.1, h: 0.45, fontFace: F.tit, fontSize: 18, bold: true, color: C.blanco, margin: 0 });
    s.addText("Toda la gestión diaria del centro", { x: 6.15, y: 2.92, w: 6.1, h: 0.35, fontFace: F.txt, fontSize: 11, color: C.tealMid, margin: 0 });
    s.addText(
      [
        { text: "Asistencia diaria, horarios y calificaciones internas", options: { bullet: true, breakLine: true } },
        { text: "Comunicación con familias y portales por rol", options: { bullet: true, breakLine: true } },
        { text: "Facturación, becas, comedor y transporte", options: { bullet: true, breakLine: true } },
        { text: "Recursos humanos, jornada y convivencia", options: { bullet: true, breakLine: true } },
        { text: "Primer ciclo de Infantil 0-3 como sistema oficial", options: { bullet: true } },
      ],
      { x: 6.15, y: 3.42, w: 6.1, h: 2.1, fontFace: F.txt, fontSize: 12, color: "C7DBD9", margin: 0, paraSpaceAfter: 9 }
    );
    s.addText("El dato se introduce una sola vez, donde se genera.", {
      x: 0.7, y: 6.05, w: W - 1.4, h: 0.5, fontFace: F.txt, fontSize: 15, italic: true, color: C.teal, margin: 0,
    });
  }

  // ─────────────────────────────────── 4 · cifras
  {
    const s = slideOscuro();
    titulo(s, "Una plataforma, todo el centro", { color: C.blanco });
    subtitulo(s, "Construida sobre el modelo educativo español, no adaptada de otro país", { color: C.tealMid });

    const stats = [
      { c: "53", e: "módulos funcionales" },
      { c: "4", e: "portales por rol" },
      { c: "4", e: "idiomas de interfaz\ny documentos" },
      { c: "0-18", e: "años, de la cuna\nal Bachillerato" },
    ];
    let x = 0.7;
    for (const st of stats) {
      estadistica(s, { x, y: 2.65, w: 2.87, cifra: st.c, etiqueta: st.e });
      x += 3.05;
    }
    s.addShape(pres.ShapeType.roundRect, {
      x: 0.7, y: 5.35, w: W - 1.4, h: 1.15, rectRadius: 0.12,
      fill: { color: C.tinta2 }, line: { color: C.tinta2 },
    });
    s.addText(
      "Cada centro tiene su propio espacio aislado, su marca, su calendario y los módulos que decida activar. Se paga por lo que se usa.",
      { x: 1.05, y: 5.35, w: W - 2.1, h: 1.15, fontFace: F.txt, fontSize: 13.5, color: "C7DBD9", valign: "middle", margin: 0 }
    );
  }

  // ─────────────────────────────────── 5 · portales
  {
    const s = slideClaro();
    titulo(s, "Cada persona ve lo que necesita");
    subtitulo(s, "Cuatro portales sobre la misma plataforma, con permisos hasta el último detalle");

    const portales = [
      { ico: "FiBriefcase", t: "Dirección y secretaría", d: "Matrícula, cuadros de mando, facturación, personal, cumplimiento normativo y configuración del centro.", col: C.teal },
      { ico: "FiEdit3",     t: "Profesorado",            d: "Paso de lista en dos toques, calificaciones, incidencias, comunicación con familias y su horario.", col: C.tealMid },
      { ico: "FiHome",      t: "Familias",               d: "Notas, faltas, circulares, autorizaciones, recibos, comedor y transporte. En el móvil.", col: C.ambar },
      { ico: "FiUser",      t: "Alumnado",               d: "Horario, tareas, calificaciones y comunicación con el centro, adaptado a su edad.", col: C.ambarOsc },
    ];
    let x = 0.7;
    for (const p of portales) {
      await tarjeta(s, { x, y: 2.35, w: 2.87, h: 3.35, ico: p.ico, cab: p.t, cuerpo: p.d, acento: p.col });
      x += 3.05;
    }
    s.addText("Todo en castellano, inglés, alemán y francés. Cada usuario elige su idioma.", {
      x: 0.7, y: 6.0, w: W - 1.4, h: 0.5, fontFace: F.txt, fontSize: 14, italic: true, color: C.gris, margin: 0,
    });
  }

  // ─────────────────────────────────── 6 · mapa de módulos
  {
    const s = slideClaro();
    titulo(s, "53 módulos, seis áreas");
    subtitulo(s, "Se activan por centro. Lo que no se contrata, no aparece en la interfaz.");

    let x = 0.7, y = 2.35;
    for (let i = 0; i < AREAS.length; i++) {
      const a = AREAS[i];
      await tarjeta(s, {
        x, y, w: 3.87, h: 2.15, ico: a.ico, cab: `${a.t}`, cuerpo: a.d,
        acento: i % 2 ? C.tealMid : C.teal,
      });
      s.addText(`${a.n}`, {
        x: x + 3.0, y: y + 0.26, w: 0.7, h: 0.6, fontFace: F.tit, fontSize: 26,
        bold: true, color: C.linea, align: "right", margin: 0,
      });
      x += 4.05;
      if ((i + 1) % 3 === 0) { x = 0.7; y += 2.35; }
    }
  }

  // ─────────────────────────────────── 7,8,9 · paquetes
  for (const clave of ["esencial", "avanzado", "completo"]) {
    const p = PAQUETES[clave];
    const s = slideClaro();

    s.addShape(pres.ShapeType.roundRect, {
      x: 0.7, y: 0.6, w: 4.55, h: 6.2, rectRadius: 0.14,
      fill: { color: C.tinta }, line: { color: C.tinta },
    });
    s.addShape(pres.ShapeType.roundRect, {
      x: 1.05, y: 1.0, w: 2.0, h: 0.42, rectRadius: 0.21,
      fill: { color: p.color }, line: { color: p.color },
    });
    s.addText("PAQUETE", {
      x: 1.05, y: 1.0, w: 2.0, h: 0.42, fontFace: F.txt, fontSize: 10.5, bold: true,
      color: C.tinta, align: "center", valign: "middle", charSpacing: 2, margin: 0,
    });
    s.addText(p.nombre, {
      x: 1.05, y: 1.62, w: 3.9, h: 0.85, fontFace: F.tit, fontSize: 44, bold: true, color: C.blanco, margin: 0,
    });
    s.addText(p.lema, {
      x: 1.05, y: 2.5, w: 3.9, h: 0.45, fontFace: F.txt, fontSize: 15, color: p.color, margin: 0,
    });
    s.addText(p.para, {
      x: 1.05, y: 3.05, w: 3.85, h: 0.9, fontFace: F.txt, fontSize: 12, color: "9FBCB9", margin: 0, lineSpacing: 17,
    });
    s.addText(p.precio, {
      x: 1.05, y: 4.15, w: 3.9, h: 0.9, fontFace: F.tit, fontSize: 48, bold: true, color: C.blanco, margin: 0,
    });
    s.addText("por alumno y mes · orientativo", {
      x: 1.05, y: 5.05, w: 3.9, h: 0.35, fontFace: F.txt, fontSize: 10.5, color: "7FA5A2", margin: 0,
    });
    s.addText(`${p.modulos} módulos incluidos`, {
      x: 1.05, y: 5.6, w: 3.9, h: 0.4, fontFace: F.txt, fontSize: 13, bold: true, color: p.color, margin: 0,
    });

    s.addText("Qué incluye", {
      x: 5.75, y: 0.95, w: 6.8, h: 0.5, fontFace: F.tit, fontSize: 24, bold: true, color: C.tinta, margin: 0,
    });
    const lineas = p.incluye.map((t, i) => ({
      text: t, options: { bullet: true, breakLine: i < p.incluye.length - 1 },
    }));
    s.addText(lineas, {
      x: 5.75, y: 1.6, w: 6.85, h: 5.0, fontFace: F.txt, fontSize: 13.5,
      color: C.tinta, margin: 0, paraSpaceAfter: 11,
    });
  }

  // ─────────────────────────────────── 10 · comparativa
  {
    const s = slideClaro();
    titulo(s, "Los tres paquetes, de un vistazo");
    subtitulo(s, "Se puede subir de paquete en cualquier momento del curso, sin migrar nada");

    const filas = [
      ["Área", "Esencial", "Avanzado", "Completo"],
      ["Alumnado, familias y expediente", "Sí", "Sí", "Sí"],
      ["Horarios, asistencia y calificaciones", "Sí", "Sí", "Sí"],
      ["Portales de familia, docente y alumno", "Sí", "Sí", "Sí"],
      ["Primer ciclo de Infantil 0-3", "Sí", "Sí", "Sí"],
      ["Facturación, recibos y becas", "—", "Sí", "Sí"],
      ["Transporte, comedor y extraescolares", "—", "Sí", "Sí"],
      ["Recursos humanos y control de jornada", "—", "Sí", "Sí"],
      ["Cuadros de mando e informes", "—", "Sí", "Sí"],
      ["Banco de libros, tienda y web pública", "—", "—", "Sí"],
      ["Aula virtual, biblioteca y admisión", "—", "—", "Sí"],
      ["API abierta y apps con marca propia", "—", "—", "Sí"],
    ];

    const filaAlto = 0.375;
    const y0 = 2.15;
    const anchos = [5.6, 2.3, 2.3, 2.3];
    const x0 = 0.7;

    filas.forEach((fila, i) => {
      const esCab = i === 0;
      let x = x0;
      fila.forEach((celda, j) => {
        const bg = esCab ? C.tinta : (i % 2 === 0 ? C.claro : C.blanco);
        s.addShape(pres.ShapeType.rect, {
          x, y: y0 + i * filaAlto, w: anchos[j], h: filaAlto,
          fill: { color: bg }, line: { color: esCab ? C.tinta : C.linea, width: 0.5 },
        });
        let color = esCab ? C.blanco : C.tinta;
        if (!esCab && j > 0) color = celda === "Sí" ? C.teal : "BAC9C7";
        s.addText(celda, {
          x: x + (j === 0 ? 0.18 : 0), y: y0 + i * filaAlto,
          w: anchos[j] - (j === 0 ? 0.18 : 0), h: filaAlto,
          fontFace: F.txt, fontSize: esCab ? 12.5 : 11.5,
          bold: esCab || (j > 0 && celda === "Sí"),
          color, align: j === 0 ? "left" : "center", valign: "middle", margin: 0,
        });
        x += anchos[j];
      });
    });
  }

  // ─────────────────────────────────── 11 · gráfico de cobertura
  {
    const s = slideClaro();
    titulo(s, "Cuánto cubre cada paquete");
    subtitulo(s, "Módulos incluidos sobre el total de la plataforma");

    s.addChart(
      pres.ChartType.bar,
      [{
        name: "Módulos incluidos",
        labels: ["Esencial", "Avanzado", "Completo"],
        values: [17, 34, 53],
      }],
      {
        x: 0.7, y: 2.15, w: 7.1, h: 4.3,
        barDir: "col",
        chartColors: [C.tealMid, C.teal, C.ambar],
        varyColors: true,
        showTitle: false,
        showLegend: false,
        showValue: true,
        dataLabelPosition: "outEnd",
        dataLabelColor: C.tinta,
        dataLabelFontSize: 16,
        dataLabelFontBold: true,
        catAxisLabelColor: C.tinta,
        catAxisLabelFontSize: 14,
        catAxisLabelFontBold: true,
        valAxisLabelColor: C.gris,
        valAxisLabelFontSize: 10,
        valAxisMaxVal: 60,
        valGridLine: { color: C.linea, size: 0.5 },
        catGridLine: { style: "none" },
        barGapWidthPct: 60,
      }
    );

    await tarjeta(s, {
      x: 8.2, y: 2.15, w: 4.4, h: 2.0, ico: "FiTrendingUp",
      cab: "Se empieza por donde duele",
      cuerpo: "La mayoría de centros arrancan con Esencial y suben a Avanzado en el segundo trimestre, cuando ven el ahorro en secretaría.",
      acento: C.teal,
    });
    await tarjeta(s, {
      x: 8.2, y: 4.45, w: 4.4, h: 2.0, ico: "FiRefreshCw",
      cab: "Sin migración al subir",
      cuerpo: "Cambiar de paquete activa módulos sobre los mismos datos. No hay traspaso, ni parada del servicio, ni coste de implantación.",
      acento: C.ambar,
    });
  }

  // ─────────────────────────────────── 12 · diferenciadores
  {
    const s = slideOscuro();
    titulo(s, "Lo que no encontrará en otras plataformas", { color: C.blanco });
    subtitulo(s, "Cuatro decisiones de producto que marcan la diferencia en el día a día", { color: C.tealMid });

    const dif = [
      { ico: "FiHeart",  t: "Infantil 0-3 de verdad",   d: "Agenda diaria del aula, evaluación cualitativa, ratios, becas de la Comunidad y facturación privada. No es Primaria recortada." },
      { ico: "FiGlobe",  t: "Cuatro idiomas, tres capas", d: "Interfaz, contenido del centro y documentos generados. El boletín sale en el idioma de la familia, no en el del colegio." },
      { ico: "FiMapPin", t: "Transporte con seguridad", d: "Autorizados a recoger, registro de subida y bajada, y alerta si un alumno sube y no consta que baje." },
      { ico: "FiLock",   t: "Protección de datos de serie", d: "Consentimientos de imagen granulares, datos de salud separados y cifrados, y trazabilidad de cada acceso." },
    ];
    let x = 0.7, y = 2.25;
    for (let i = 0; i < dif.length; i++) {
      await tarjeta(s, {
        x, y, w: 5.9, h: 2.25, ico: dif[i].ico, cab: dif[i].t, cuerpo: dif[i].d,
        tono: "oscuro", acento: i % 2 ? C.ambar : C.tealMid,
      });
      x += 6.2;
      if (i === 1) { x = 0.7; y += 2.45; }
    }
  }

  // ─────────────────────────────────── 13 · seguridad
  {
    const s = slideClaro();
    titulo(s, "Datos de menores. Sin atajos.");
    subtitulo(s, "El colegio es responsable del tratamiento. Nosotros, encargados con contrato.");

    const seg = [
      { ico: "FiServer",   t: "Alojamiento europeo",   d: "Servidores en la Unión Europea, cifrado en tránsito y en reposo." },
      { ico: "FiEye",      t: "Trazabilidad completa", d: "Quién vio qué y cuándo, incluida la lectura de datos sensibles." },
      { ico: "FiKey",      t: "Doble factor",          d: "Obligatorio para los roles que el centro decida, no solo para el administrador." },
      { ico: "FiSave",     t: "Copias verificadas",    d: "Restauración probada de forma automática, no solo copias que nadie comprueba." },
      { ico: "FiUserX",    t: "Custodia y restricciones", d: "Un tutor con acceso revocado por sentencia queda excluido de todos los servicios a la vez." },
      { ico: "FiFileText", t: "Derechos de las familias", d: "Acceso, rectificación y supresión, respetando los plazos legales de conservación." },
    ];
    let x = 0.7, y = 2.35;
    for (let i = 0; i < seg.length; i++) {
      await tarjeta(s, { x, y, w: 3.87, h: 2.15, ico: seg[i].ico, cab: seg[i].t, cuerpo: seg[i].d, acento: C.teal });
      x += 4.05;
      if ((i + 1) % 3 === 0) { x = 0.7; y += 2.35; }
    }
  }

  // ─────────────────────────────────── 14 · implantación
  {
    const s = slideClaro();
    titulo(s, "Del papel a la plataforma en un trimestre");
    subtitulo(s, "Acompañamos la migración. El centro no se queda solo con un manual.");

    const pasos = [
      { n: "1", t: "Diagnóstico", d: "Revisamos qué usa hoy el centro y qué datos hay que traer.", sem: "Semana 1" },
      { n: "2", t: "Migración",   d: "Importamos alumnado, familias y personal desde su sistema actual.", sem: "Semanas 2-3" },
      { n: "3", t: "Formación",   d: "Sesiones por rol: secretaría, claustro y familias.", sem: "Semana 4" },
      { n: "4", t: "Arranque",    d: "Puesta en marcha acompañada, con soporte reforzado el primer mes.", sem: "Semana 5" },
    ];
    let x = 0.7;
    for (const p of pasos) {
      s.addShape(pres.ShapeType.roundRect, {
        x, y: 2.3, w: 2.87, h: 3.0, rectRadius: 0.12,
        fill: { color: C.claro }, line: { color: C.claro },
      });
      s.addShape(pres.ShapeType.ellipse, {
        x: x + 0.3, y: 2.6, w: 0.72, h: 0.72,
        fill: { color: C.teal }, line: { color: C.teal },
      });
      s.addText(p.n, {
        x: x + 0.3, y: 2.6, w: 0.72, h: 0.72, fontFace: F.tit, fontSize: 24,
        bold: true, color: C.blanco, align: "center", valign: "middle", margin: 0,
      });
      s.addText(p.sem, {
        x: x + 1.15, y: 2.72, w: 1.5, h: 0.4, fontFace: F.txt, fontSize: 10.5,
        color: C.ambarOsc, bold: true, margin: 0, valign: "middle",
      });
      s.addText(p.t, {
        x: x + 0.3, y: 3.5, w: 2.3, h: 0.45, fontFace: F.txt, fontSize: 16,
        bold: true, color: C.tinta, margin: 0,
      });
      s.addText(p.d, {
        x: x + 0.3, y: 4.0, w: 2.3, h: 1.1, fontFace: F.txt, fontSize: 11.5,
        color: C.gris, margin: 0, lineSpacing: 16,
      });
      x += 3.05;
    }
    s.addText("Sin coste de implantación en el paquete Completo. Sin permanencia en ninguno.", {
      x: 0.7, y: 5.7, w: W - 1.4, h: 0.5, fontFace: F.txt, fontSize: 15, italic: true, color: C.teal, margin: 0,
    });
  }

  // ─────────────────────────────────── 15 · cierre
  {
    const s = slideOscuro();
    s.addShape(pres.ShapeType.ellipse, {
      x: -1.8, y: 3.4, w: 6.0, h: 6.0,
      fill: { color: C.teal }, line: { color: C.teal }, transparency: 68,
    });
    s.addText("¿Empezamos por su centro?", {
      x: 1.0, y: 2.25, w: 10.5, h: 1.1, fontFace: F.tit, fontSize: 46, bold: true, color: C.blanco, margin: 0,
    });
    s.addText(
      "Buscamos un centro piloto para el curso 2026/2027. Condiciones especiales,\nacceso directo al equipo de producto y voz en la hoja de ruta.",
      { x: 1.05, y: 3.5, w: 9.5, h: 1.0, fontFace: F.txt, fontSize: 16, color: "9FBCB9", margin: 0, lineSpacing: 26 }
    );
    s.addShape(pres.ShapeType.roundRect, {
      x: 1.05, y: 4.9, w: 3.5, h: 0.62, rectRadius: 0.31,
      fill: { color: C.ambar }, line: { color: C.ambar },
    });
    s.addText("Solicitar una demostración", {
      x: 1.05, y: 4.9, w: 3.5, h: 0.62, fontFace: F.txt, fontSize: 13,
      bold: true, color: C.tinta, align: "center", valign: "middle", margin: 0,
    });
    s.addText(MARCA, {
      x: 1.05, y: 6.1, w: 4.0, h: 0.5, fontFace: F.tit, fontSize: 22, bold: true, color: C.tealMid, margin: 0,
    });
    s.addNotes("Cierre. Sustituir por datos de contacto reales antes de presentar.");
  }

  await pres.writeFile({ fileName: "/home/claude/deck/colegia-presentacion.pptx" });
  console.log("generado");
}

construir().catch((e) => { console.error(e); process.exit(1); });
