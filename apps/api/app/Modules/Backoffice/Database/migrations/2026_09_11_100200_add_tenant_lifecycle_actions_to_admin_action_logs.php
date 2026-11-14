<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `docs/modulos/REQ-BO/datos.md §4.2.1`, §12 fila 4b. Amplía el
 * vocabulario cerrado de `admin_action_logs.action` con los tres valores
 * que introduce `1.6b`: `tenant.slug_cambiado` (cambio de `slug`),
 * `tenant.aprovisionamiento_fallido` (la fase 2 del alta agota sus
 * reintentos) y `tenant.gracia_vencida` (la tarea diaria marca un
 * `en_baja` cuyo plazo venció). `DROP CONSTRAINT` + `ADD CONSTRAINT` del
 * mismo `CHECK`, nunca renombrando ni retirando valores existentes —
 * precedente literal: la migración del issue #173.
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
};
