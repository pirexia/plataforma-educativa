// Cabeceras y redirecciones de ADR-072 §4.3 y §4.4. La CSP lleva `script-src 'self'` desde ADR-075
// (único script propio, sin 'unsafe-inline' ni 'unsafe-eval').
// Las usan build.mjs (las escribe) y check.mjs (comprueba que `_headers` las lleva con el valor exacto).

export const SECURITY_HEADERS = {
  'Content-Security-Policy':
    "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; upgrade-insecure-requests",
  // ADR-072 §4.3: primero 86400; 31536000 tras una semana sin incidencias. Sin includeSubDomains ni preload.
  'Strict-Transport-Security': 'max-age=86400',
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'DENY',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'Permissions-Policy': [
    'accelerometer',
    'autoplay',
    'camera',
    'display-capture',
    'encrypted-media',
    'fullscreen',
    'geolocation',
    'gyroscope',
    'magnetometer',
    'microphone',
    'midi',
    'payment',
    'picture-in-picture',
    'publickey-credentials-get',
    'usb',
    'xr-spatial-tracking',
  ]
    .map((name) => `${name}=()`)
    .join(', '),
  'Cross-Origin-Opener-Policy': 'same-origin',
  'Cross-Origin-Resource-Policy': 'same-origin',
}

// ADR-072 §4.3: www y los otros tres dominios -> 301 a https://adagiox.com/<misma ruta>.
export const REDIRECT_HOSTS = [
  'www.adagiox.com',
  'adagiox.es',
  'www.adagiox.es',
  'adagiox.eu',
  'www.adagiox.eu',
  'adagiox.net',
  'www.adagiox.net',
]
