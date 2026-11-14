<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `docs/modulos/REQ-BO/datos.md §4.2.2`, §12 fila 6b. Amplía el
 * vocabulario cerrado de `admin_action_logs.action` con los cuatro
 * valores que introduce `1.6e`: `flag.estado_cambiado` (el interruptor de
 * emergencia, `RNF-MANT-005`), `flag.reglas_cambiadas` (el reemplazo
 * completo de `PUT .../rules`), `tenant.early_adopter_designado` y
 * `tenant.early_adopter_retirado`. `DROP CONSTRAINT` + `ADD CONSTRAINT`
 * del mismo `CHECK`, nunca renombrando ni retirando valores existentes —
 * precedente literal: `2026_09_11_100200_add_tenant_lifecycle_actions_to_
 * admin_action_logs.php` y la migración del issue #173.
 *
 * Sin esta migración `RN-BO-43` no se puede cumplir: la auditoría va en
 * la misma transacción que la escritura del flag, y el CHECK haría
 * fallar la operación entera — el síntoma sería «no puedo apagar el flag
 * que está rompiendo colegios» (CA-BO-174).
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE admin_action_logs DROP CONSTRAINT admin_action_logs_action_check');

        $owner->statement(<<<'SQL'
            ALTER TABLE admin_action_logs ADD CONSTRAINT admin_action_logs_action_check
                CHECK (action IN (
                    'acceso.concedido', 'acceso.rechazado', 'acceso.rechazado_por_ip',
                    'sesion.cerrada', 'reautenticacion.superada',
                    'admin.creado', 'admin.actualizado', 'admin.suspendido', 'admin.reactivado',
                    'admin.eliminado', 'admin.rol_concedido', 'admin.rol_retirado', 'admin.mfa_restablecido',
                    'admin.invitado', 'admin.invitacion_canjeada',
                    'ip.permitida_anadida', 'ip.permitida_retirada',
                    'autorizacion.solicitada', 'autorizacion.aprobada', 'autorizacion.rechazada',
                    'autorizacion.caducada', 'autorizacion.ejecutada', 'autorizacion.fallida',
                    'tenant.creado', 'tenant.actualizado', 'tenant.suspendido', 'tenant.reactivado',
                    'tenant.baja_iniciada', 'tenant.rescatado', 'tenant.eliminado', 'tenant.clonado',
                    'tenant.slug_cambiado', 'tenant.aprovisionamiento_fallido', 'tenant.gracia_vencida',
                    'modulo.contratado', 'modulo.descontratado', 'modulo.masivo_ejecutado',
                    'job.reintentado',
                    'flag.estado_cambiado', 'flag.reglas_cambiadas',
                    'tenant.early_adopter_designado', 'tenant.early_adopter_retirado'
                ))
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE admin_action_logs DROP CONSTRAINT admin_action_logs_action_check');

        $owner->statement(<<<'SQL'
            ALTER TABLE admin_action_logs ADD CONSTRAINT admin_action_logs_action_check
                CHECK (action IN (
                    'acceso.concedido', 'acceso.rechazado', 'acceso.rechazado_por_ip',
                    'sesion.cerrada', 'reautenticacion.superada',
                    'admin.creado', 'admin.actualizado', 'admin.suspendido', 'admin.reactivado',
                    'admin.eliminado', 'admin.rol_concedido', 'admin.rol_retirado', 'admin.mfa_restablecido',
                    'admin.invitado', 'admin.invitacion_canjeada',
                    'ip.permitida_anadida', 'ip.permitida_retirada',
                    'autorizacion.solicitada', 'autorizacion.aprobada', 'autorizacion.rechazada',
                    'autorizacion.caducada', 'autorizacion.ejecutada', 'autorizacion.fallida',
                    'tenant.creado', 'tenant.actualizado', 'tenant.suspendido', 'tenant.reactivado',
                    'tenant.baja_iniciada', 'tenant.rescatado', 'tenant.eliminado', 'tenant.clonado',
                    'tenant.slug_cambiado', 'tenant.aprovisionamiento_fallido', 'tenant.gracia_vencida',
                    'modulo.contratado', 'modulo.descontratado', 'modulo.masivo_ejecutado',
                    'job.reintentado'
                ))
            SQL);
    }
};
