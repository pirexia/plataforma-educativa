const React = require("react");
const ReactDOMServer = require("react-dom/server");
const sharp = require("sharp");
const Fi = require("react-icons/fi");
const Tb = require("react-icons/tb");

const cache = {};

/**
 * Devuelve un data-URI PNG del icono indicado, coloreado.
 * @param {string} nombre  clave del icono (Fi* o Tb*)
 * @param {string} color   hex sin almohadilla
 */
async function icono(nombre, color = "FFFFFF", px = 256) {
  const clave = `${nombre}-${color}-${px}`;
  if (cache[clave]) return cache[clave];

  const Comp = Fi[nombre] || Tb[nombre];
  if (!Comp) throw new Error(`Icono desconocido: ${nombre}`);

  const svg = ReactDOMServer.renderToStaticMarkup(
    React.createElement(Comp, { color: `#${color}`, size: px, strokeWidth: 2 })
  );
  const buf = await sharp(Buffer.from(svg)).resize(px, px).png().toBuffer();
  cache[clave] = "image/png;base64," + buf.toString("base64");
  return cache[clave];
}

module.exports = { icono };
