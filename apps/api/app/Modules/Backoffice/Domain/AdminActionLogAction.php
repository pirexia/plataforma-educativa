<?php

namespace App\Modules\Backoffice\Domain;

/**
 * datos.md §4.2. Vocabulario cerrado de `admin_action_logs.action`,
 * siguiendo el precedente de `audit_logs.event` (ADR-034 §3, ampliado
 * por ADR-039). Ampliarlo es una migración, deliberadamente: un
 * vocabulario abierto se convierte en texto libre y deja de ser
 * consultable.
 *
 * Los 34 valores replican íntegro el `CHECK` de la migración (31 del
 * chasis más los tres que añade `1.6b`, datos.md §4.2.1:
 * `tenant.slug_cambiado`, `tenant.aprovisionamiento_fallido`,
 * `tenant.gracia_vencida`), aunque 1.6 solo emitiera los de acceso,
 * administradores, lista blanca y doble autorización — los de
 * `modulo.*` los emite `1.6c` cuando construya esas operaciones, sobre
 * la misma tabla y el mismo vocabulario ya cerrado.
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
    case AdminInvitado = 'admin.invitado';
    case AdminInvitacionCanjeada = 'admin.invitacion_canjeada';

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

    // 1.6b: datos.md §4.2.1.
    case TenantSlugCambiado = 'tenant.slug_cambiado';
    case TenantAprovisionamientoFallido = 'tenant.aprovisionamiento_fallido';
    case TenantGraciaVencida = 'tenant.gracia_vencida';

    case ModuloContratado = 'modulo.contratado';
    case ModuloDescontratado = 'modulo.descontratado';
    case ModuloMasivoEjecutado = 'modulo.masivo_ejecutado';

    case JobReintentado = 'job.reintentado';
}
