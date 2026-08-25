<?php

namespace App\Modules\Auth\Domain;

/**
 * datos.md §B.2, funcional.md §B.6.4. Derivado del `User-Agent` por
 * `ClientDescriber`, solo para mostrar — nunca participa en la decisión de
 * `RN-AUTH-46`. `Desconocido` no es un error: un `User-Agent`
 * irreconocible produce este valor en los tres campos de cliente
 * (CA-AUTH-097).
 */
enum ClientDeviceType: string
{
    case Escritorio = 'escritorio';
    case Movil = 'movil';
    case Tableta = 'tableta';
    case Bot = 'bot';
    case Desconocido = 'desconocido';
}
