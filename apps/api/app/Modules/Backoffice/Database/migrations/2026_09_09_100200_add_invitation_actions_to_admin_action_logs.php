<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #173. Amplía el vocabulario cerrado de `admin_action_logs.action`
 * (datos.md §4.2) con las dos acciones que introduce el mecanismo de
 * invitación de `platform_admins`: `admin.invitado` (se emitió el
 * token) y `admin.invitacion_canjeada` (se fijó la contraseña). Es una
 * migración a propósito (docblock de
 * `2026_09_08_101000_create_admin_action_logs_table.php`): «ampliarlo es
 * una migración, deliberadamente».
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
                    'modulo.contratado', 'modulo.descontratado', 'modulo.masivo_ejecutado',
                    'job.reintentado'
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
                    'ip.permitida_anadida', 'ip.permitida_retirada',
                    'autorizacion.solicitada', 'autorizacion.aprobada', 'autorizacion.rechazada',
                    'autorizacion.caducada', 'autorizacion.ejecutada', 'autorizacion.fallida',
                    'tenant.creado', 'tenant.actualizado', 'tenant.suspendido', 'tenant.reactivado',
                    'tenant.baja_iniciada', 'tenant.rescatado', 'tenant.eliminado', 'tenant.clonado',
                    'modulo.contratado', 'modulo.descontratado', 'modulo.masivo_ejecutado',
                    'job.reintentado'
                ))
            SQL);
    }
};
