<?php

namespace App\Support\Files;

use RuntimeException;

/**
 * ADR-068 §5.3, `INV-001`: se intentó firmar o borrar una clave de objeto que no cuelga del prefijo del centro en
 * curso. El mensaje no incluye la clave (podría ser de otro centro).
 */
final class ForeignObjectKeyException extends RuntimeException {}
