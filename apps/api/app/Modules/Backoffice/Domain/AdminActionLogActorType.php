<?php

namespace App\Modules\Backoffice\Domain;

/**
 * datos.md §4.1. Vocabulario propio y cerrado, distinto del de
 * `audit_logs` (ADR-039).
 */
enum AdminActionLogActorType: string
{
    case PlatformAdmin = 'platform_admin';
    case Console = 'console';
    case System = 'system';
}
