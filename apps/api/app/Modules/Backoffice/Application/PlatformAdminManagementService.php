<?php

namespace App\Modules\Backoffice\Application;

use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\PlatformAdminRole;
use App\Modules\Backoffice\Domain\Models\PlatformAdminSession;
use App\Modules\Backoffice\Domain\PlatformAdminSessionEndReason;
use App\Modules\Backoffice\Domain\PlatformAdminStatus;
use App\Modules\Backoffice\Domain\PlatformRole;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-BO-007, funcional.md §4, permisos.md §5. Gestión de administradores
 * de plataforma: RN-BO-10 (nadie se modifica a sí mismo) y RN-BO-11
 * (siempre al menos un `superadministrador` vivo y activo), con bloqueo
 * de fila — son restricciones de conjunto, no caben en un CHECK
 * (datos.md §2.2).
 */
final class PlatformAdminManagementService
{
    public function __construct(
        private readonly AdminActionLogRecorder $recorder,
        private readonly IssuePlatformAdminInvitation $invitations,
    ) {}

    /**
     * Issue #173. `create()` la usan `POST /admins` y `bo:create-admin`
     * por igual (api.md §2.2: «crea sin contraseña utilizable e
     * invita»): el alta y la invitación son un único punto, mismo
     * precedente de forma que `CreateUser` (`REQ-CORE`), que emite la
     * invitación dentro de la misma transacción.
     */
    public function create(string $email, string $name, string $locale, PlatformRole $role): PlatformAdmin
    {
        return DB::connection('pgsql_platform')->transaction(function () use ($email, $name, $locale, $role): PlatformAdmin {
            $admin = PlatformAdmin::create([
                'email' => $email,
                'name' => $name,
                'locale' => $locale,
                // Sin contraseña utilizable: hash de un valor aleatorio
                // que nadie conoce. El alta invita, no fija contraseña
                // (funcional.md §5.1, operacion.md §5 paso 4) — la fija
                // el canje de la invitación (issue #173).
                'password' => Str::password(40),
                'status' => PlatformAdminStatus::Activo,
                'password_changed_at' => now(),
            ]);

            PlatformAdminRole::create([
                'platform_admin_id' => $admin->id,
                'role' => $role,
            ]);

            $this->recorder->record(action: AdminActionLogAction::AdminCreado, subjectPublicId: $admin->public_id, reason: 'alta de administrador de plataforma');

            $this->invitations->issue($admin);

            return $admin;
        });
    }

    public function update(PlatformAdmin $admin, string $name, string $locale): PlatformAdmin
    {
        $admin->forceFill(['name' => $name, 'locale' => $locale])->save();

        $this->recorder->record(action: AdminActionLogAction::AdminActualizado, subjectPublicId: $admin->public_id);

        return $admin;
    }

    /**
     * @param  list<PlatformRole>  $roles
     */
    public function replaceRoles(PlatformAdmin $actor, PlatformAdmin $admin, array $roles, string $reason): void
    {
        $this->guardSelfModification($actor, $admin);

        DB::connection('pgsql_platform')->transaction(function () use ($admin, $roles, $reason): void {
            $this->guardLastSuperadministrator($admin, $roles);

            PlatformAdminRole::query()->where('platform_admin_id', $admin->id)->delete();

            foreach ($roles as $role) {
                PlatformAdminRole::create(['platform_admin_id' => $admin->id, 'role' => $role]);
            }

            $this->recorder->record(action: AdminActionLogAction::AdminRolConcedido, subjectPublicId: $admin->public_id, reason: $reason, context: ['roles' => array_map(fn (PlatformRole $r) => $r->value, $roles)]);
        });
    }

    public function setStatus(PlatformAdmin $actor, PlatformAdmin $admin, PlatformAdminStatus $status, string $reason): PlatformAdmin
    {
        $this->guardSelfModification($actor, $admin);

        if ($status === PlatformAdminStatus::Suspendido) {
            $this->guardLastSuperadministrator($admin, []);
        }

        $admin->forceFill(['status' => $status])->save();

        if ($status === PlatformAdminStatus::Suspendido) {
            $this->revokeLiveSessions($admin, PlatformAdminSessionEndReason::AdminSuspendido);
        }

        $this->recorder->record(
            action: $status === PlatformAdminStatus::Suspendido ? AdminActionLogAction::AdminSuspendido : AdminActionLogAction::AdminReactivado,
            subjectPublicId: $admin->public_id,
            reason: $reason,
        );

        return $admin;
    }

    public function delete(PlatformAdmin $actor, PlatformAdmin $admin, string $reason): void
    {
        $this->guardSelfModification($actor, $admin);
        $this->guardLastSuperadministrator($admin, []);

        $admin->delete();
        $this->revokeLiveSessions($admin, PlatformAdminSessionEndReason::RevocadaAdmin);

        $this->recorder->record(action: AdminActionLogAction::AdminEliminado, subjectPublicId: $admin->public_id, reason: $reason);
    }

    /**
     * `DELETE /admins/{id}/mfa`. Nunca autoservicio, nunca por correo
     * (CA-BO-012): solo otro `superadministrador` — lo comprueba la
     * capacidad `admin.mfa.restablecer` en el middleware, no este
     * servicio, pero RN-BO-10 sí lo comprueba aquí.
     */
    public function resetMfa(PlatformAdmin $actor, PlatformAdmin $admin): void
    {
        $this->guardSelfModification($actor, $admin);

        $admin->mfaFactors()->delete();
        $admin->forceFill(['mfa_enrolled_at' => null])->save();

        $this->revokeLiveSessions($admin, PlatformAdminSessionEndReason::MfaRestablecido);

        $this->recorder->record(action: AdminActionLogAction::AdminMfaRestablecido, subjectPublicId: $admin->public_id);
    }

    private function guardSelfModification(PlatformAdmin $actor, PlatformAdmin $admin): void
    {
        if ($actor->id === $admin->id) {
            throw ApiException::conflict('bo.admin.self_modification');
        }
    }

    /**
     * `SELECT … FOR UPDATE` sobre el conjunto de `superadministrador`
     * vivos y activos: es una restricción de conjunto, no de fila
     * (datos.md §2.2), y se bloquea para que dos peticiones concurrentes
     * no dejen la plataforma sin ninguno.
     *
     * @param  list<PlatformRole>  $incomingRoles  el conjunto de roles que
     *                                             tendrá el admin tras la
     *                                             operación (vacío si se
     *                                             suspende/elimina)
     */
    private function guardLastSuperadministrator(PlatformAdmin $admin, array $incomingRoles): void
    {
        $willRemainSuperadmin = in_array(PlatformRole::Superadministrador, $incomingRoles, true);

        if ($willRemainSuperadmin) {
            return;
        }

        $wasSuperadmin = PlatformAdminRole::query()
            ->where('platform_admin_id', $admin->id)
            ->where('role', PlatformRole::Superadministrador->value)
            ->exists();

        if (! $wasSuperadmin) {
            return;
        }

        $otherLiveSuperadmins = DB::connection('pgsql_platform')->table('platform_admin_roles')
            ->join('platform_admins', 'platform_admins.id', '=', 'platform_admin_roles.platform_admin_id')
            ->where('platform_admin_roles.role', PlatformRole::Superadministrador->value)
            ->whereNull('platform_admin_roles.deleted_at')
            ->whereNull('platform_admins.deleted_at')
            ->where('platform_admins.status', PlatformAdminStatus::Activo->value)
            ->where('platform_admins.id', '<>', $admin->id)
            ->lockForUpdate()
            ->count();

        if ($otherLiveSuperadmins === 0) {
            throw ApiException::conflict('bo.admin.last_superadministrator');
        }
    }

    private function revokeLiveSessions(PlatformAdmin $admin, PlatformAdminSessionEndReason $reason): void
    {
        PlatformAdminSession::query()
            ->where('platform_admin_id', $admin->id)
            ->whereNull('ended_at')
            ->get()
            ->each(fn (PlatformAdminSession $session) => $session->close($reason));
    }
}
