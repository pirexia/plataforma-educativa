<?php

namespace App\Support\Tenancy;

/**
 * REQ-BO-001: en_alta → activo → suspendido → activo; activo → en_baja →
 * eliminado. La transición entre estados la gobierna REQ-BO (fase 1, no
 * implementado todavía); aquí solo se fija el vocabulario para 0.7.
 */
enum TenantStatus: string
{
    case EnAlta = 'en_alta';
    case Activo = 'activo';
    case Suspendido = 'suspendido';
    case EnBaja = 'en_baja';
    case Eliminado = 'eliminado';
}
