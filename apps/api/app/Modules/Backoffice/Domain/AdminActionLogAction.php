<?php

namespace App\Modules\Backoffice\Domain;

/**
 * datos.md §4.2. Vocabulario cerrado de `admin_action_logs.action`,
 * siguiendo el precedente de `audit_logs.event` (ADR-034 §3, ampliado
 * por ADR-039). Ampliarlo es una migración, deliberadamente: un
 * vocabulario abierto se convierte en texto libre y deja de ser
 * consultable.
 *
 * Los 31 valores replican íntegro el `CHECK` de la migración, aunque
 * 1.6 solo emita los de acceso, administradores, lista blanca y doble
 * autorización — los de `tenant.*` y `modulo.*` los emiten `1.6b`/`1.6c`
 * cuando construyan esas operaciones, sobre la misma tabla y el mismo
 * vocabulario ya cerrado.
 */
enum AdminActionLogAction: string
{
    case AccesoConcedido = 'acceso.concedido';
    case AccesoRechazado = 'acceso.rechazado';
    case AccesoRechazadoPorIp = 'acceso.rechazado_por_ip';
    case SesionCerrada = 'sesion.cerrada';
    case ReautenticacionSuperada = 'reautenticacion.superada';

    case AdminCreado = 'admin.creado';
    case AdminActualizado = 'admin.actualizado';
    case AdminSuspendido = 'admin.suspendido';
    case AdminReactivado = 'admin.reactivado';
    case AdminEliminado = 'admin.eliminado';
    case AdminRolConcedido = 'admin.rol_concedido';
    case AdminRolRetirado = 'admin.rol_retirado';
    case AdminMfaRestablecido = 'admin.mfa_restablecido';

    case IpPermitidaAnadida = 'ip.permitida_anadida';
    case IpPermitidaRetirada = 'ip.permitida_retirada';

    case AutorizacionSolicitada = 'autorizacion.solicitada';
    case AutorizacionAprobada = 'autorizacion.aprobada';
    case AutorizacionRechazada = 'autorizacion.rechazada';
    case AutorizacionCaducada = 'autorizacion.caducada';
    case AutorizacionEjecutada = 'autorizacion.ejecutada';
    case AutorizacionFallida = 'autorizacion.fallida';

    case TenantCreado = 'tenant.creado';
    case TenantActualizado = 'tenant.actualizado';
    case TenantSuspendido = 'tenant.suspendido';
    case TenantReactivado = 'tenant.reactivado';
    case TenantBajaIniciada = 'tenant.baja_iniciada';
    case TenantRescatado = 'tenant.rescatado';
    case TenantEliminado = 'tenant.eliminado';
    case TenantClonado = 'tenant.clonado';

    case ModuloContratado = 'modulo.contratado';
    case ModuloDescontratado = 'modulo.descontratado';
    case ModuloMasivoEjecutado = 'modulo.masivo_ejecutado';

    case JobReintentado = 'job.reintentado';
}
