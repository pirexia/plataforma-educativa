<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * ADR-034 §3: tabla única polimórfica, append-only. El registro
 * automático desde el ciclo de vida del ORM (con la lista de redacción
 * por modelo) lo escribe el paso 0.9 — este modelo solo fija el esquema y
 * la relación polimórfica.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property int|null $actor_user_id
 * @property string $actor_type
 * @property string $auditable_type
 * @property int $auditable_id
 * @property string|null $auditable_public_id
 * @property string $event
 * @property array<array-key, mixed>|null $changes
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property array<array-key, mixed>|null $context
 * @property-read \App\Models\User|null $actor
 * @property-read \Illuminate\Database\Eloquent\Model $auditable
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereActorType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereActorUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereAuditableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereAuditablePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereAuditableType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereChanges($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereEvent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AuditLog whereUserAgent($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperAuditLog {}
}

namespace App\Models{
/**
 * ADR-038 §8.3, datos.md §A.5. Registro técnico de deduplicación, sin
 * `public_id` (nunca se expone en URL ni cuerpo) y no auditable (no es una
 * entidad de negocio). La purga es física (forceDelete), no borrado
 * lógico — ver PurgeExpiredIdempotencyKeys.
 *
 * Vive en `App\Models`, no en `App\Modules\Core\Domain\Models`: datos.md
 * §A.5 es explícito en que esta tabla "no es de REQ-CORE... infraestructura
 * de plataforma que los 53 módulos comparten, igual que audit_logs o
 * data_exports" — mismo criterio que sitúa `AuditLog`/`User`/`Person` aquí
 * y no bajo un módulo. Corrección de ubicación (issue #50): la migración
 * original la creó bajo el namespace de Core por ser 1.1 su primer y único
 * consumidor; se traslada ahora, antes de que un segundo módulo la
 * importe desde el sitio equivocado (`INV-007`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $endpoint
 * @property string $idempotency_key
 * @property string $request_body_hash
 * @property string $status
 * @property int|null $response_status
 * @property array<array-key, mixed>|null $response_body
 * @property \Illuminate\Support\Carbon $expires_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereEndpoint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereIdempotencyKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereRequestBodyHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereResponseBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereResponseStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdempotencyKey withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperIdempotencyKey {}
}

namespace App\Models{
/**
 * ADR-034 §5, §7: catálogo de plataforma, no dato del tenant. Mismo
 * patrón que Permission: sin tenant_id, sin RLS, escritura reservada al
 * comando `platform:sync-registry` (REVOKE en la migración de 0.8.7).
 *
 * @property string $code
 * @property string $name_key
 * @property string $phase
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereNameKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module wherePhase($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereRetiredAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperModule {}
}

namespace App\Models{
/**
 * ADR-034 §5: dato del tenant, con RLS. Ausencia de fila = módulo
 * desactivado (falla en cerrado) — comprobarlo es responsabilidad del
 * middleware EnsureModuleEnabled (1.1/1.6), no de este modelo.
 *
 * ADR-035 §8: Full — `settings` queda acotado por el tope de tamaño de
 * config('audit.max_value_length'), no por clasificación.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $module_code
 * @property bool $enabled
 * @property \Illuminate\Support\Carbon|null $enabled_at
 * @property \Illuminate\Support\Carbon|null $disabled_at
 * @property string|null $reason
 * @property array<array-key, mixed>|null $settings
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereDisabledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereEnabledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereModuleCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ModuleSubscription withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperModuleSubscription {}
}

namespace App\Models{
/**
 * ADR-034 §2, §7: catálogo de plataforma, no dato del tenant. Modelo
 * plano, no TenantModel — sin tenant_id, sin RLS, sin borrado lógico
 * (retired_at cumple ese papel a su manera: nunca se borra una fila).
 *
 * Escritura reservada al comando de 0.8.11 (REVOKE en la migración, no
 * solo en este modelo).
 *
 * REQ-PERM/datos.md §3 (1.5): `applicable_scopes` — qué ámbitos admite este
 * permiso. `NULL` en la columna se interpreta como `['todos']`
 * (funcional.md §3.2 regla 1): el valor que deja el sistema exactamente
 * como estaba y el que menos permite.
 *
 * @property string $code
 * @property string $resource
 * @property string $action
 * @property string $module_code
 * @property bool $is_special_category
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @property array<array-key, mixed>|null $applicable_scopes
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereApplicableScopes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereIsSpecialCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereModuleCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereResource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereRetiredAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPermission {}
}

namespace App\Models{
/**
 * ADR-034 §2: concesión de un permiso a un rol del tenant.
 *
 * REQ-PERM/funcional.md §12.1, `datos.md §5.2` (1.5, issue #165): pasa a
 * `Auditable` con política `Full` — un código de permiso, un código de rol,
 * un efecto y un ámbito no son datos personales (`ADR-035 §2`). No declara
 * `auditExcludedEvents()`: `created` se registra (`ADR-040 §4.4` fija que
 * `UserSession` es la única exclusión del repositorio).
 *
 * **Toda escritura pasa por el modelo** (`create()`/`save()`/`delete()`),
 * nunca por `attach()`/`detach()`/`sync()` sobre una relación
 * `belongsToMany` — esas no disparan eventos de modelo y dejarían esta
 * auditoría muda en la práctica (`RN-PERM-19`). Es exactamente lo que hoy
 * deja sin rastro a `role_user` (`datos.md §5.3`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $role_id
 * @property string $permission_code
 * @property string $effect
 * @property string $scope
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Permission $permission
 * @property-read \App\Models\Role|null $role
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereEffect($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole wherePermissionCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereScope($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PermissionRole withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPermissionRole {}
}

namespace App\Models{
/**
 * ADR-034 §1: la identidad, no la cuenta. `User` es una faceta de
 * autenticación 0.
 *
 * .1 (puede no existir: una persona sin acceso al
 * portal). Las facetas de alumno/tutor/empleado llegan con sus propios
 * módulos (REQ-ALUM, REQ-FAM-UNIT, REQ-RRHH), no en 0.8.
 *
 * ADR-035 §8: Selective. Todo identificador se redacta salvo la lista de
 * inclusión — dato de mayor volumen personal del sistema (el `created` de
 * cada alumno/tutor/empleado escribe su identidad completa si no se
 * clasifica).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $given_name
 * @property string $family_name_1
 * @property string|null $family_name_2
 * @property \Illuminate\Support\Carbon|null $birth_date
 * @property string|null $document_type
 * @property string|null $document_number
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string $locale
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $user
 * @method static \Database\Factories\PersonFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereBirthDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereContactEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereContactPhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereDocumentNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereDocumentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereFamilyName1($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereFamilyName2($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereGivenName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Person withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPerson {}
}

namespace App\Models{
/**
 * ADR-034 §2: esquema completo desde 0.8, resolutor de permisos en 1.5.
 *
 * mfa_required/special_data_access existen desde ahora aunque nadie los
 * lea todavía (RPERM-014, RPERM-015).
 *
 * ADR-035 §8: Full — `name` es contenido del centro, no dato personal.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $code
 * @property string|null $name_key
 * @property string|null $name
 * @property bool $is_system
 * @property bool $mfa_required
 * @property bool $special_data_access
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PermissionRole> $permissionGrants
 * @property-read int|null $permission_grants_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereIsSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereMfaRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereNameKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereSpecialDataAccess($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRole {}
}

namespace App\Models{
/**
 * ADR-034 §1: la credencial, no la identidad — esa es Person.
 *
 * Implementa Authenticatable (paso 1.1, `REQ-CORE`, api.md §1: "en 1.1 los
 * tests autentican con actingAs()") — el contrato mínimo que exige el
 * guard `web` de config/auth.php y el propio helper de test de Laravel.
 * Esto NO adelanta REQ-AUTH (1.2): no hay ninguna ruta de login, ningún
 * flujo de credenciales, ni política de contraseñas aquí. Es la pieza de
 * infraestructura que hace que `actingAs()` funcione en los tests de este
 * módulo, tal como la especificación ya asumía que sería posible.
 *
 * ADR-035 §8: Selective. `email` se redacta como `identifier` (se asume la
 * pérdida de diff — 1.2 la cubre como evento de seguridad, ver ADR-035
 * §8); `password`/`remember_token` los redacta la regla 1 (patrón global
 * de secretos), sin necesidad de declararlos aquí.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $person_id
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \App\Models\UserStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \App\Models\Person|null $person
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Role> $roles
 * @property-read int|null $roles_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePersonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUser {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §1.7, §4 (1.11d-a, `REQ-ACAD-001`). Período lectivo de una
 * etapa del mismo curso académico (FK compuesta).
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-50). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso será por las interfaces de `Acad\Domain` cuando haya
 * consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $education_level_id
 * @property string $period_type
 * @property array<array-key, mixed> $name_translations
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon $ends_on
 * @property int $position
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Acad\Domain\Models\EducationLevel|null $educationLevel
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereEducationLevelId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereEndsOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereNameTranslations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod wherePeriodType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereStartsOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicPeriod withoutTrashed()
* @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperAcademicPeriod {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §1.2 (1.11-b, `REQ-ACAD-001`). Curso de etapa, colgado de una etapa del mismo curso académico.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $education_level_id
 * @property string $code
 * @property array<array-key, mixed> $name_translations
 * @property int $position
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereEducationLevelId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereNameTranslations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Course withoutTrashed()
* @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperCourse {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §1.1 (1.11-b, `REQ-ACAD-001`). Etapa educativa de un curso académico.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property string $code
 * @property array<array-key, mixed> $name_translations
 * @property string $stage
 * @property string $regime
 * @property int $position
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereNameTranslations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereRegime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereStage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EducationLevel withoutTrashed()
* @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperEducationLevel {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §1.8, §4 (1.11d-b, `REQ-ACAD-001`). Escala de calificación
 * de un curso académico: numérica, cualitativa o mixta.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-50). Escrituras por
 * instancia, nunca masivas (AR-15). Los campos `numeric(5,2)` no tienen cast:
 * llegan como cadena exacta (`"5.00"`), nunca como `float` (RN-ACAD-75). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las
 * interfaces de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property string $code
 * @property array<array-key, mixed> $name_translations
 * @property string $scale_type
 * @property numeric|null $min_value
 * @property numeric|null $max_value
 * @property int|null $decimals
 * @property numeric|null $passing_min_value
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Acad\Domain\Models\GradingScaleValue> $values
 * @property-read int|null $values_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereDecimals($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereMaxValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereMinValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereNameTranslations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale wherePassingMinValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereScaleType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScale withoutTrashed()
* @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperGradingScale {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §1.9, §4 (1.11d-b, `REQ-ACAD-001`). Valor de una escala
 * cualitativa o tramo de una mixta. Solo se escribe dentro de su escala
 * (`RN-ACAD-80`).
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-50). Escrituras por
 * instancia, nunca masivas (AR-15): la sincronización de `RN-ACAD-88` y el
 * borrado en cascada lógica de `RN-ACAD-76` recorren las instancias.
 * `range_min`/`range_max` sin cast (cadena exacta, RN-ACAD-75).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $grading_scale_id
 * @property string $scale_type
 * @property string $code
 * @property array<array-key, mixed> $label_translations
 * @property int $position
 * @property bool|null $is_passing
 * @property numeric|null $range_min
 * @property numeric|null $range_max
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Acad\Domain\Models\GradingScale|null $gradingScale
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereGradingScaleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereIsPassing($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereLabelTranslations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereRangeMax($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereRangeMin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereScaleType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GradingScaleValue withoutTrashed()
* @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperGradingScaleValue {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §1.3 (1.11-b, `REQ-ACAD-001`). Grupo de un curso de etapa. `name` es texto simple, no traducible.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $course_id
 * @property string $code
 * @property string $name
 * @property int $position
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereCourseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group withoutTrashed()
* @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperGroup {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §1.6 (1.11-b, `REQ-ACAD-001`). Asignatura que cursa un grupo; el par es la «clase» de ADR-063 §5.3. `course_id` lo fija el servicio a partir del grupo.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $course_id
 * @property int $group_id
 * @property int $subject_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereCourseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereSubjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupSubject withoutTrashed()
* @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperGroupSubject {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §1.4 (1.11-b, `REQ-ACAD-001`). Subgrupo de un único grupo (RN-ACAD-16).
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $group_id
 * @property string $code
 * @property string $name
 * @property int $position
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subgroup withoutTrashed()
* @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSubgroup {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §1.5 (1.11-b, `REQ-ACAD-001`). Asignatura de un curso de etapa.
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-09). Escrituras por
 * instancia, nunca masivas (AR-15). Los demás módulos no usan este modelo
 * (AR-01): el acceso es por las interfaces de `Acad\Domain`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $course_id
 * @property string $code
 * @property array<array-key, mixed> $name_translations
 * @property int $position
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereCourseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereNameTranslations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject wherePosition($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Subject withoutTrashed()
* @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSubject {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`). Espacio: aula o recurso. Del centro, sin curso académico (`OPEN-ACAD-33`).
 *
 * ADR-035 §8: Full — sin datos personales (RN-ACAD-101). Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $facility_type
 * @property string $code
 * @property string $name
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereFacilityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Facility withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperFacility {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §9.2, §9.6 (1.12-b, `REQ-ACAD-002`), `ADR-065`. Reserva de un espacio en una fecha y un intervalo horario (`RN-ACAD-140`). El reservante es `created_by`.
 *
 * ADR-035 §8: Full — sin datos personales (`created_by` es un identificador interno). Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $facility_id
 * @property \Illuminate\Support\Carbon $booked_on
 * @property string $starts_at
 * @property string $ends_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Acad\Domain\Models\Facility|null $facility
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereBookedOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereFacilityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereStartsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FacilityBooking withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperFacilityBooking {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`). Tutoría de un grupo (`RN-ACAD-97`).
 *
 * ADR-035 §8: Full — `user_id` es un identificador interno, no un identificador personal de `ADR-035` (a confirmar por `security-reviewer`). Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $group_id
 * @property int $user_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Acad\Domain\Models\Group|null $group
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GroupTutor withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperGroupTutor {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`). Asignación docente: un usuario imparte una asignatura de un grupo, opcionalmente a un subgrupo (`RN-ACAD-93`).
 *
 * ADR-035 §8: Full — `user_id` es un identificador interno, no un identificador personal de `ADR-035` (a confirmar por `security-reviewer`). Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $user_id
 * @property int $group_id
 * @property int $group_subject_id
 * @property int|null $subgroup_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Acad\Domain\Models\Group|null $group
 * @property-read \App\Modules\Acad\Domain\Models\GroupSubject|null $groupSubject
 * @property-read \App\Modules\Acad\Domain\Models\Subgroup|null $subgroup
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereGroupSubjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereSubgroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TeachingAssignment withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTeachingAssignment {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`), `ADR-065`. Franja de un marco: día ISO y hora civil del centro (`time` sin zona).
 *
 * ADR-035 §8: Full — sin datos personales. Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $timetable_frame_id
 * @property int $weekday
 * @property string $starts_at
 * @property string $ends_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Acad\Domain\Models\TimetableFrame|null $frame
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereStartsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereTimetableFrameId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot whereWeekday($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimeSlot withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTimeSlot {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`). Ancla de serialización del horario de un curso académico (`RN-ACAD-115`, `RN-ACAD-125`).
 *
 * ADR-035 §8: Full — sin datos personales. Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Timetable withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTimetable {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`). Marco horario de una etapa (`RN-ACAD-110`).
 *
 * ADR-035 §8: Full — sin datos personales. Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $education_level_id
 * @property string $frame_type
 * @property array<array-key, mixed> $name_translations
 * @property \Illuminate\Support\Carbon|null $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Acad\Domain\Models\EducationLevel|null $educationLevel
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Acad\Domain\Models\TimeSlot> $slots
 * @property-read int|null $slots_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereEducationLevelId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereFrameType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereNameTranslations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereValidFrom($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame whereValidTo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableFrame withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTimetableFrame {}
}

namespace App\Modules\Acad\Domain\Models{
/**
 * REQ-ACAD/datos.md §9.1, §9.6 (1.12-a, `REQ-ACAD-002`). Sesión: una asignación docente en una franja, con aula opcional (`RN-ACAD-120`).
 *
 * ADR-035 §8: Full — sin datos personales. Escrituras por instancia, nunca masivas (AR-15). Los
 * demás módulos no usan este modelo (AR-01): el acceso será por las interfaces
 * de `Acad\Domain` cuando haya consumidor (`ADR-048`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $time_slot_id
 * @property int $teaching_assignment_id
 * @property int|null $facility_id
 * @property string|null $facility_type
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Acad\Domain\Models\Facility|null $facility
 * @property-read \App\Modules\Acad\Domain\Models\TeachingAssignment|null $teachingAssignment
 * @property-read \App\Modules\Acad\Domain\Models\TimeSlot|null $timeSlot
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereFacilityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereFacilityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereTeachingAssignmentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereTimeSlotId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TimetableSession withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTimetableSession {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §A.2. Selective: `email` se redacta como `identifier` (dato
 * personal directo, mismo criterio que `users.email`); `unlock_token_hash`
 * lo redacta automáticamente el patrón global `*token*` sin declararlo.
 *
 * La creación de la fila es un evento `created` y el desbloqueo un
 * `updated`, ambos por el *observer* de 0.9 — ningún código de este
 * módulo llama a AuditRecorder para bloqueo/desbloqueo (funcional.md §10.1).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $email
 * @property int|null $user_id
 * @property int $failed_count
 * @property \Illuminate\Support\Carbon $locked_at
 * @property string|null $unlock_token_hash
 * @property \Illuminate\Support\Carbon|null $unlock_token_expires_at
 * @property \Illuminate\Support\Carbon|null $unlocked_at
 * @property int|null $unlocked_by
 * @property \App\Modules\Auth\Domain\UnlockReason|null $unlock_reason
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $unlockedByUser
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereFailedCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereLockedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereUnlockReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereUnlockTokenExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereUnlockTokenHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereUnlockedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereUnlockedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AccountLockout withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperAccountLockout {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §F.2, ampliado en `datos.md §G.2` (REQ-AUTH-004, 1.4c: el
 * discriminador `protocol`). El catálogo de proveedores de identidad de
 * un centro, OIDC y SAML (`ADR-043 §3.5` punto 1: tabla de tenant, sin
 * excepción de *tenancy*).
 *
 * `Full`: sin datos personales (`ADR-035 §8`). URLs, un `client_id`, una
 * lista de dominios y varios conmutadores — el mismo perfil que
 * `AcademicYear`/`Role`/`ModuleSubscription`.
 *
 * `protocol` es **inmutable tras el alta** (`RN-AUTH-114`): no lo impone
 * un `CHECK` (no ve el valor anterior), lo impone el servicio
 * (`IdentityProviderService`/`SamlIdentityProviderAdminService`,
 * `CA-AUTH-316`). Ninguna columna de mapeo de atributos hacia `people`
 * (`funcional.md §F.5.2`, `§G.5.2`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $display_name
 * @property string|null $discovery_url
 * @property string $issuer
 * @property string $authorization_endpoint
 * @property string|null $token_endpoint
 * @property string|null $userinfo_endpoint
 * @property \App\Modules\Auth\Domain\ClaimsSource|null $claims_source
 * @property \App\Modules\Auth\Domain\EmailClaim|null $email_claim
 * @property array<array-key, mixed>|null $scopes
 * @property string|null $client_id
 * @property array<array-key, mixed> $allowed_email_domains
 * @property \App\Modules\Auth\Domain\ProvisioningMode $provisioning_mode
 * @property bool $is_enabled
 * @property \Illuminate\Support\Carbon|null $discovery_fetched_at
 * @property \Illuminate\Support\Carbon|null $discovery_failed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \App\Modules\Auth\Domain\Protocol $protocol
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Auth\Domain\Models\IdentityProviderCertificate> $certificates
 * @property-read int|null $certificates_count
 * @property-read \App\Modules\Auth\Domain\Models\SamlIdentityProviderSettings|null $samlSettings
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Auth\Domain\Models\IdentityProviderSecret> $secrets
 * @property-read int|null $secrets_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereAllowedEmailDomains($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereAuthorizationEndpoint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereClaimsSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereDiscoveryFailedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereDiscoveryFetchedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereDiscoveryUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereEmailClaim($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereIsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereIssuer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereProtocol($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereProvisioningMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereScopes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereTokenEndpoint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider whereUserinfoEndpoint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProvider withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperIdentityProvider {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §G.5 (REQ-AUTH-004, 1.4c). La ventana de rotación de los
 * certificados de firma de un IdP SAML. Con `public_id`: sí se expone en
 * URL (`DELETE .
 *
 * ../certificates/{public_id}`).
 *
 * Selective: `certificate` (el PEM) se declara secreto a mano
 * (`ADR-043 §3.5.5`, `RN-AUTH-127`, `CA-AUTH-333`) — no porque sea
 * secreto (es una clave pública), sino por proporción: un bloque de
 * kilobytes que `audit_logs` no tiene por qué duplicar en cada cambio.
 * `config('audit.secret_attribute_patterns')` no cubre `certificate`, así
 * que esta declaración explícita no es defensa en profundidad aquí: es
 * la única barrera.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $identity_provider_id
 * @property string $certificate
 * @property string $fingerprint_sha256
 * @property \Illuminate\Support\Carbon $not_before
 * @property \Illuminate\Support\Carbon $not_after
 * @property \App\Modules\Auth\Domain\SamlCertificateSource $source
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Auth\Domain\Models\IdentityProvider|null $identityProvider
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereCertificate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereFingerprintSha256($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereIdentityProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereNotAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereNotBefore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereRetiredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderCertificate withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperIdentityProviderCertificate {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §F.3. La credencial de cliente de un proveedor, cifrada con
 * `APP_KEY` (`RN-AUTH-112`). **De solo escritura a través de la API**:
 * nadie la relee en claro, ni siquiera quien la cargó — se descifra
 * únicamente dentro del canje de código, en memoria.
 *
 * Selective: `client_secret` se declara secreto a mano (paso 1 del orden
 * de `ADR-035 §4`, absoluto y anterior al patrón global de
 * `config('audit.secret_attribute_patterns')`, que también lo cubriría
 * por `*secret*` como defensa en profundidad — `funcional.md §F.0.4`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $identity_provider_id
 * @property string $client_secret
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon $activated_at
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Auth\Domain\Models\IdentityProvider|null $identityProvider
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereActivatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereClientSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereIdentityProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereRetiredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IdentityProviderSecret withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperIdentityProviderSecret {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §A.1. Append-only: sin created_at/updated_at (usa
 * attempted_at), sin borrado lógico (INV-004 no aplica: un registro de
 * seguridad append-only no se "revierte"). No implementa Auditable a
 * propósito: registrarla en audit_logs duplicaría cada fila y, en un
 * ataque de fuerza bruta, inundaría la tabla de dos años de retención
 * (funcional.md §10.2, datos.md §A.1).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property int|null $user_id
 * @property \App\Modules\Auth\Domain\LoginOutcome $outcome
 * @property \Illuminate\Support\Carbon $attempted_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property \App\Modules\Auth\Domain\LoginMethod $method
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereAttemptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereOutcome($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereUserId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperLoginAttempt {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §C.4. **No implementa `Auditable`, a propósito**: es un
 * artefacto transitorio de cinco minutos, mismo trato que
 * `password_reset_tokens` (`§A.5`). Registrar su creación y su consumo
 * escribiría dos filas de `audit_logs` por cada login con MFA para decir
 * algo que el evento `login` ya dice mejor y una sola vez (funcional.md
 * §C.10). El intento fallido va a `login_attempts`, no aquí.
 *
 * `session_id` **nunca sale por la API** (RN-AUTH-40) y es la única
 * credencial que autoriza el desafío (RN-AUTH-53): se busca siempre por
 * `(tenant_id, session_id)`, nunca por `public_id`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $user_id
 * @property string $session_id
 * @property \App\Modules\Auth\Domain\MfaMethod $method
 * @property string|null $code_hash
 * @property \Illuminate\Support\Carbon|null $code_expires_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property int $attempts
 * @property int $deliveries
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property string|null $ip_address
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereCodeExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereCodeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereConsumedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereDeliveries($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaChallenge withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMfaChallenge {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §C.2. Un solo tipo de fila para el alta provisional
 * (`confirmed_at NULL`) y para el factor confirmado — la misma entidad en
 * un estado anterior, igual que una invitación no aceptada en `REQ-CORE`.
 *
 * Selective: `secret_encrypted` se declara secreto explícitamente además
 * de encajar en el patrón global `*secret*` (mismo argumento que `§B.2`
 * con `session_id`: depender solo de una coincidencia de nombre es
 * depender de una coincidencia). `last_used_step`/`last_used_at` no se
 * registran: cambian en cada login y no dicen nada que `login` no diga ya.
 *
 * `code_hash`/`code_expires_at` (datos.md §D.2, 1.3b): hash SHA-256 y
 * caducidad del código de un alta de método de entrega (`email`),
 * distinta de `expires_at` (caducidad del alta). Se ponen a `NULL` al
 * confirmar, en la misma transacción (`RN-AUTH-75`). `code_hash` se
 * declara secreto a mano: el patrón global `*secret*` no cubre su nombre.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $user_id
 * @property \App\Modules\Auth\Domain\MfaMethod $method
 * @property string|null $secret_encrypted
 * @property int|null $last_used_step
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int $confirmation_attempts
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property bool $is_preferred
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string|null $code_hash
 * @property \Illuminate\Support\Carbon|null $code_expires_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereCodeExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereCodeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereConfirmationAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereIsPreferred($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereLastUsedStep($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereSecretEncrypted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaFactor withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMfaFactor {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §C.3. Sin `public_id`: no se expone individualmente por
 * ninguna API. Cuelga del usuario y no del factor (funcional.md §C.4.5).
 *
 * Selective: `code_hash` se declara secreto explícitamente, además de
 * encajar en el patrón global `*recovery_code*` — un hash SHA-256 de un
 * valor de ~50 bits es material atacable por fuerza bruta si se filtra, y
 * `audit_logs` es exportable a CSV (`REQ-CORE-005`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $code_hash
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property string|null $used_ip
 * @property string $batch_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereBatchId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereCodeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereUsedIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaRecoveryCode withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMfaRecoveryCode {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §C.6.1. Traza *append-only* del restablecimiento de MFA por el
 * administrador (RN-AUTH-66). `reason` se registra **con valor, a
 * propósito** — es exactamente la información que `REQ-AUTH-003` exige
 * conservar y `ADR-035` redactaría si viviera solo en `audit_logs.changes`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $reason
 * @property int $factors_removed
 * @property int $performed_by
 * @property \Illuminate\Support\Carbon $performed_at
 * @property string|null $request_id
 * @property-read \App\Models\User|null $performedByUser
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset whereFactorsRemoved($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset wherePerformedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset wherePerformedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset whereRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MfaReset whereUserId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperMfaReset {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §G.4.1 (REQ-AUTH-004, 1.4c). La correlación del `AuthnRequest`
 * emitido: sostiene la excepción de CSRF del ACS (`RN-AUTH-120`,
 * `RN-AUTH-124`). Sin `HasPublicId`: no se expone en ninguna URL ni
 * respuesta de API — el identificador que viaja es `request_id`, dentro
 * del propio mensaje SAML.
 *
 * **No implementa `Auditable`**: política de auditoría `None`
 * (`datos.md §G.4.1`). Es estado transitorio de protocolo con vida de
 * cinco minutos, del mismo carácter que el `state` de OIDC en sesión, que
 * tampoco se audita.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $identity_provider_id
 * @property string $request_id
 * @property string $intent
 * @property int|null $linking_user_id
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Auth\Domain\Models\IdentityProvider|null $identityProvider
 * @property-read \App\Models\User|null $linkingUser
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereConsumedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereIdentityProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereIntent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereLinkingUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlAuthRequest withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSamlAuthRequest {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §G.4.2 (REQ-AUTH-004, 1.4c). Cubre un ataque distinto de
 * `SamlAuthRequest`: que una misma aserción legítima se reenvíe contra
 * OTRA petición viva (`RN-AUTH-122`). No guarda el XML de la aserción ni
 * ningún fragmento suyo (`CA-AUTH-363`).
 *
 * **No implementa `Auditable`**: política de auditoría `None`, mismo
 * argumento que `SamlAuthRequest`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $identity_provider_id
 * @property string $assertion_id
 * @property \Illuminate\Support\Carbon $not_on_or_after
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Auth\Domain\Models\IdentityProvider|null $identityProvider
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereAssertionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereIdentityProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereNotOnOrAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlConsumedAssertion withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSamlConsumedAssertion {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §G.3 (REQ-AUTH-004, 1.4c). La hija 1:1 de un proveedor SAML.
 *
 * Sin `HasPublicId`: no se expone nunca en una URL propia (`api.md §G.2`).
 *
 * `Full`: sin datos personales (configuración técnica y metadatos
 * públicos de una institución). **Salvedad declarada a mano**
 * (`ADR-043 §3.5.5`): `metadata_xml` va en `$auditSecretAttributes` — no
 * porque sea secreto, sino porque es un documento de decenas de
 * kilobytes con certificados dentro y `Full` lo copiaría entero en
 * `audit_logs` en cada modificación. Se registra que cambió, no su valor.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $identity_provider_id
 * @property \App\Modules\Auth\Domain\SamlMetadataSource $metadata_source
 * @property string|null $metadata_url
 * @property string|null $metadata_xml
 * @property \App\Modules\Auth\Domain\SamlNameIdFormat $name_id_format
 * @property string|null $email_attribute
 * @property bool $sign_authn_requests
 * @property \Illuminate\Support\Carbon|null $metadata_fetched_at
 * @property \Illuminate\Support\Carbon|null $metadata_failed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Auth\Domain\Models\IdentityProvider|null $identityProvider
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereEmailAttribute($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereIdentityProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereMetadataFailedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereMetadataFetchedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereMetadataSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereMetadataUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereMetadataXml($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereNameIdFormat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereSignAuthnRequests($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SamlIdentityProviderSettings withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSamlIdentityProviderSettings {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §E.2. El vínculo entre un usuario y una cuenta externa
 * concreta. Ninguna columna de token (`RN-AUTH-95`) ni de nombre o
 * fotografía (`RN-AUTH-88`).
 *
 * Selective: `subject` y `email_at_link` se declaran secretos a mano
 * (`ADR-035`) — identificadores personales que `config('audit.
 * secret_attribute_patterns')` no cubre (no encajan en `*password*`,
 * `*secret*`, `*token*` ni `*recovery_code*`). `last_login_at` no se
 * registra: cambia en cada acceso y no dice nada que el evento `login`
 * no diga ya (mismo criterio que `user_mfa_factors.last_used_at`).
 *
 * `identity_provider_id` (1.4b, `datos.md §F.4`): re-tecleado por
 * proveedor concreto, no por protocolo (`ADR-043 §3.6`). Se añade a
 * `$auditRecordedAttributes`: identificador interno de una entidad de
 * configuración, no un dato personal, y es lo que responde "¿por qué
 * proveedor entró esta persona?".
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $user_id
 * @property string $provider
 * @property string $subject
 * @property string|null $email_at_link
 * @property bool $email_verified_at_link
 * @property \App\Modules\Auth\Domain\LinkMethod $link_method
 * @property \Illuminate\Support\Carbon $linked_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property int|null $identity_provider_id
 * @property-read \App\Modules\Auth\Domain\Models\IdentityProvider|null $identityProvider
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereEmailAtLink($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereEmailVerifiedAtLink($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereIdentityProviderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereLastLoginAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereLinkMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereLinkedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserIdentity withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUserIdentity {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §B.1. Selective: `device_token_hash` lo redacta automáticamente
 * el patrón global `*token*` sin declararlo; `last_ip_address` se redacta
 * como `identifier` por no estar en la lista de inclusión (paso 3 de
 * `AuditChangeBuilder`). Sin `public_id` (§B.1): ningún endpoint de 1.2b
 * devuelve un dispositivo como recurso propio, viaja dentro de la sesión.
 *
 * El alta (`created`) y el aviso (`updated` con `alerted_at`) quedan
 * auditados sin ninguna llamada manual (funcional.md §B.10) — a diferencia
 * de `UserSession`, este modelo no declara `auditExcludedEvents()`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $device_token_hash
 * @property \Illuminate\Support\Carbon $first_seen_at
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property int $login_count
 * @property string|null $label
 * @property string|null $last_ip_address
 * @property \Illuminate\Support\Carbon|null $alerted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereAlertedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereDeviceTokenHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereFirstSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereLastIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereLoginCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserKnownDevice withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUserKnownDevice {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §C.6. Excepción temporal nominal (RN-AUTH-68): `expires_at` es
 * `NOT NULL` en el esquema — "no existe la exención permanente" no es una
 * validación de aplicación, la garantiza el motor.
 *
 * Full: `reason` es contenido del centro sobre otra persona, no dato
 * personal en sí (`ADR-035 §8`, mismo criterio que `roles.name`), aunque
 * pueda contener información sensible según lo que se escriba
 * (datos.md §C.11, punto 1) — el manual de administración debe advertirlo.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $user_id
 * @property string $reason
 * @property \Illuminate\Support\Carbon $expires_at
 * @property int $granted_by
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $grantedByUser
 * @property-read \App\Models\User|null $revokedByUser
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereGrantedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereRevokedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaExemption withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUserMfaExemption {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §C.5. Una fila por período de obligación (RN-AUTH-65): cumplir
 * cierra la fila (`resolved_at`), volver a quedar sin factor abre otra
 * con plazo completo. Sin `public_id`: no se expone individualmente.
 *
 * Full: sin ningún dato personal más allá de la relación con el usuario.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $obligated_since
 * @property \Illuminate\Support\Carbon $grace_deadline_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property string $trigger
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereGraceDeadlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereObligatedSince($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereResolvedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereTrigger($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserMfaObligation withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUserMfaObligation {}
}

namespace App\Modules\Auth\Domain\Models{
/**
 * datos.md §B.2. Complementaria de `sessions` del framework
 * (funcional.md §B.2.2): `session_id` es la credencial portadora del
 * navegador y NUNCA sale por la API, por eso se declara explícitamente
 * como secreto — no lo cubre ningún patrón automático de
 * `config('audit.secret_attribute_patterns')` (no contiene "token" ni
 * "password" ni "secret"). `ip_address`/`user_agent`/`location_label` se
 * redactan como `identifier` por no estar en la lista de inclusión.
 *
 * `auditExcludedEvents(): ['created']` — ADR-040 §4.3: el nacimiento de
 * la fila, siempre dentro de la transacción del login (funcional.md
 * §B.4.1), ya lo registra el evento `login` sobre `User` (ADR-039). El
 * resto del ciclo de vida (revocación, los siete cierres de §B.4.6,
 * borrado lógico) se sigue auditando entero por el mecanismo automático.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $user_id
 * @property string $session_id
 * @property \Illuminate\Support\Carbon $started_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $client_browser
 * @property string|null $client_platform
 * @property \App\Modules\Auth\Domain\ClientDeviceType|null $client_device_type
 * @property string|null $location_label
 * @property int|null $known_device_id
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property \App\Modules\Auth\Domain\SessionEndReason|null $end_reason
 * @property int|null $ended_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $endedByUser
 * @property-read \App\Modules\Auth\Domain\Models\UserKnownDevice|null $knownDevice
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereClientBrowser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereClientDeviceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereClientPlatform($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereEndReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereEndedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereEndedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereKnownDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereLocationLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSession withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUserSession {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * REQ-BO-007, ADR-033 §7, ADR-036, ADR-047 §4.1/§4.3/§4.4. Solo-anexión
 * permanente: no hay `update()`/`delete()` que valga desde este modelo
 * (RN-BO-29) — el motor ya lo rechaza para `plataforma_platform`
 * (`REVOKE UPDATE, DELETE`), y esto es defensa en profundidad, no la
 * barrera real.
 *
 * Conexión `pgsql_platform` (BYPASSRLS): es como el backoffice lee la
 * tabla entera, incluidas las columnas que `plataforma_app` no tiene
 * concedidas. La consulta del propio centro (`GET
 * /api/v1/platform-actions`, REQ-CORE) NO usa este modelo: corre sobre
 * la conexión `pgsql` del tenant, con las seis columnas del `GRANT` y
 * filtrada por la política `tenant_visibility`, no por `BYPASSRLS`.
 *
 * @property int $id
 * @property string $public_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property \App\Modules\Backoffice\Domain\AdminActionLogActorType $actor_type
 * @property int|null $actor_platform_admin_id
 * @property int|null $affected_tenant_id
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_public_id
 * @property \App\Modules\Backoffice\Domain\AdminActionLogAction $action
 * @property string|null $reason
 * @property array<array-key, mixed>|null $changes
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property array<array-key, mixed>|null $context
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $actor
 * @property-read \App\Support\Tenancy\Tenant|null $affectedTenant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereActorPlatformAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereActorType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereAffectedTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereChanges($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereSubjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereSubjectPublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereSubjectType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdminActionLog whereUserAgent($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperAdminActionLog {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * REQ-BO-007, datos.md §3. La tabla que sostiene "ninguna acción
 * destructiva en un solo paso". La restricción de aprobador distinto
 * (RN-BO-19) vive en el motor (`dual_authorizations_distinct_approver`),
 * no en este modelo — CA-BO-062 la comprueba escribiendo por SQL
 * directo, precisamente para no confiar en que este código la respete.
 *
 * Mecanismo genérico sin *endpoint* propio en 1.6 (docblock de la
 * migración): el motor y su restricción son de este sub-paso; solicitar,
 * aprobar y ejecutar una operación concreta llega con la primera acción
 * real (`tenant.eliminar` en 1.6b).
 *
 * @property int $id
 * @property string $public_id
 * @property \App\Modules\Backoffice\Domain\DualAuthorizationAction $action
 * @property array<array-key, mixed> $payload
 * @property string $payload_fingerprint
 * @property string $reason
 * @property int $requested_by
 * @property \Illuminate\Support\Carbon $requested_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \App\Modules\Backoffice\Domain\DualAuthorizationStatus $status
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string|null $resolution_reason
 * @property \Illuminate\Support\Carbon|null $executed_at
 * @property string|null $execution_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $approver
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $requester
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereApprovedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereExecutedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereExecutionError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization wherePayloadFingerprint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereRequestedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereRequestedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereResolutionReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DualAuthorization whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperDualAuthorization {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * REQ-BO-007, datos.md §2.1. La identidad de plataforma: RN-BO-01, ningún
 * `platform_admin` tiene tenant — ninguna columna es `tenant_id`, ni
 * nula, y ninguna consulta pasa por el scope global de tenant.
 *
 * Conexión de plataforma directa (`pgsql_platform`), igual que `Tenant`:
 * este módulo no necesita `TenantContext::runAsPlatform()` para leer o
 * escribir sus propias tablas (ninguna es de tenant); esa primitiva
 * sirve para cuando el backoffice toca una tabla de TENANT (p. ej.
 * `module_subscriptions`, 1.6c).
 *
 * No implementa `Auditable`/`RecordsAuditTrail` (ADR-035): esa maquinaria
 * escribe en `audit_logs`, que es tabla de tenant. El rastro de toda
 * escritura sobre este modelo lo deja explícitamente el servicio que
 * escribe, en `admin_action_logs` (RN-BO-29 a RN-BO-32).
 *
 * No tiene `person_id` (Person es de tenant, ADR-034 §1) ni
 * `mfa_required` (obligatorio sin excepción, datos.md §2.1).
 *
 * @property int $id
 * @property string $public_id
 * @property string $email
 * @property string $name
 * @property string $password
 * @property \App\Modules\Backoffice\Domain\PlatformAdminStatus $status
 * @property string $locale
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon $password_changed_at
 * @property \Illuminate\Support\Carbon|null $mfa_enrolled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Backoffice\Domain\Models\PlatformAdminMfaFactor> $mfaFactors
 * @property-read int|null $mfa_factors_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Backoffice\Domain\Models\PlatformAdminRole> $roleAssignments
 * @property-read int|null $role_assignments_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereLastLoginAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereMfaEnrolledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin wherePasswordChangedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdmin withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlatformAdmin {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * Issue #173. Precedente de forma: `App\Modules\Core\Domain\Models\
 * UserInvitation` — pero de plataforma (sin `tenant_id`, sin scope de
 * tenant, sin `TenantModel`), como el resto de `App\Modules\Backoffice\
 * Domain\Models`.
 *
 * No implementa `Auditable`/`RecordsAuditTrail` (ADR-035), por el mismo
 * motivo que `PlatformAdmin`: esa maquinaria escribe en `audit_logs`, que
 * es tabla de tenant. El rastro lo deja explícitamente el servicio que
 * escribe, en `admin_action_logs`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $platform_admin_id
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $admin
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation whereAcceptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation wherePlatformAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation whereTokenHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminInvitation whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlatformAdminInvitation {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * datos.md §2.3. Segundo paso pendiente de un login de plataforma,
 * artefacto transitorio purgado por `bo:purge-mfa-challenges`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $platform_admin_id
 * @property string $challenge_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $consumed_at
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $admin
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge whereAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge whereChallengeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge whereConsumedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge wherePlatformAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaChallenge whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlatformAdminMfaChallenge {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * datos.md §2.3. Solo TOTP (datos.md §2.4): el correo no es segundo
 * factor en el backoffice.
 *
 * @property int $id
 * @property string $public_id
 * @property int $platform_admin_id
 * @property string $type
 * @property string $secret_encrypted
 * @property int|null $last_used_step
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $admin
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor whereConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor whereLastUsedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor whereLastUsedStep($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor wherePlatformAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor whereSecretEncrypted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaFactor withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlatformAdminMfaFactor {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * datos.md §2.3. Solo el hash, nunca el código.
 *
 * @property int $id
 * @property int $platform_admin_id
 * @property string $code_hash
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $admin
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaRecoveryCode newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaRecoveryCode newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaRecoveryCode query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaRecoveryCode whereCodeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaRecoveryCode whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaRecoveryCode whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaRecoveryCode wherePlatformAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaRecoveryCode whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminMfaRecoveryCode whereUsedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlatformAdminMfaRecoveryCode {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * datos.md §2.2. Pivote entre administrador y rol interno.
 *
 * @property int $id
 * @property int $platform_admin_id
 * @property \App\Modules\Backoffice\Domain\PlatformRole $role
 * @property int|null $granted_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $admin
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole whereGrantedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole wherePlatformAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminRole withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlatformAdminRole {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * ADR-047 §5.1, datos.md §2.7. Dato de negocio: qué sesiones de
 * plataforma hay abiertas, desde dónde y por qué terminó cada una.
 *
 * `session_id` sin FK a propósito (docblock de la migración): lo
 * sustituye el barrido de sesiones huérfanas (§2.7.2,
 * `CloseOrphanedPlatformSessions`).
 *
 * @property int $id
 * @property int $platform_admin_id
 * @property string|null $session_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property \Illuminate\Support\Carbon|null $reauthenticated_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property \App\Modules\Backoffice\Domain\PlatformAdminSessionEndReason|null $end_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $admin
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereEndReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereEndedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession wherePlatformAdminId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereReauthenticatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformAdminSession whereUserAgent($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlatformAdminSession {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * `ADR-038 §8` (1.6c). Versión de plataforma de `App\Models\
 * IdempotencyKey` — ver el docblock de la migración para el porqué de
 * que exista una segunda tabla en vez de reutilizar la de tenant: esa es
 * `TenantModel` y exige contexto de tenant activo, que el backoffice
 * nunca tiene.
 *
 * Sin `public_id` (nunca se expone) y no auditable (registro técnico, no
 * una entidad de negocio) — mismo criterio que su homóloga de tenant.
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIdempotencyKey newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIdempotencyKey newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIdempotencyKey query()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlatformIdempotencyKey {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * REQ-BO-007, datos.md §2.5. `cidr` nativo: el operador de contención lo
 * verifica el motor, no PHP analizando una máscara en cada petición.
 *
 * @property int $id
 * @property string $public_id
 * @property string $cidr
 * @property string $description
 * @property bool $enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry whereCidr($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PlatformIpAllowlistEntry withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperPlatformIpAllowlistEntry {}
}

namespace App\Modules\Backoffice\Domain\Models{
/**
 * REQ-BO-001, datos.md §5, ADR-047 §4.1/§4.2/§4.3/§4.4. Historia de la
 * máquina de estados de un centro: dato de negocio que la aplicación lee
 * (cuándo vence la gracia, desde cuándo está suspendido), no auditoría —
 * por eso es tabla distinta de `admin_action_logs` (datos.md §5.1).
 *
 * Append-only, como `AdminActionLog`: no hay `update()`/`delete()` que
 * valga desde este modelo — el motor ya lo rechaza (`REVOKE UPDATE,
 * DELETE`) bajo `FORCE ROW LEVEL SECURITY` sin política permisiva de
 * escritura.
 *
 * @property int $id
 * @property string $public_id
 * @property int $affected_tenant_id
 * @property \App\Support\Tenancy\TenantStatus|null $from_status
 * @property \App\Support\Tenancy\TenantStatus $to_status
 * @property string $reason
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property int|null $performed_by
 * @property int|null $dual_authorization_id
 * @property \Illuminate\Support\Carbon|null $grace_period_ends_at
 * @property-read \App\Support\Tenancy\Tenant|null $affectedTenant
 * @property-read \App\Modules\Backoffice\Domain\Models\DualAuthorization|null $dualAuthorization
 * @property-read \App\Modules\Backoffice\Domain\Models\PlatformAdmin|null $performer
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent whereAffectedTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent whereDualAuthorizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent whereFromStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent whereGracePeriodEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent wherePerformedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantLifecycleEvent whereToStatus($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTenantLifecycleEvent {}
}

namespace App\Modules\Core\Domain\Models{
/**
 * datos.md §A.4. Full: no contiene datos personales, solo qué se pidió,
 * cuándo y por quién. Primitiva compartida (ExportRequestService,
 * INV-007) — otros módulos la usan por interfaz, no importando esta clase.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $kind
 * @property string $format
 * @property array<array-key, mixed>|null $filters
 * @property string $status
 * @property string|null $object_key
 * @property int|null $row_count
 * @property string|null $error_code
 * @property int $requested_by
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $requester
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereErrorCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereFilters($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereFormat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereObjectKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereRequestedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereRowCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DataExport withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperDataExport {}
}

namespace App\Modules\Core\Domain\Models{
/**
 * datos.md §A.1. Selective: se redactan como `identifier` los datos
 * fiscales (razón social, NIF, dirección) porque en un centro que sea
 * persona física o sociedad unipersonal son datos personales; las claves
 * de objeto de branding quedan fuera por no aportar nada legible.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $default_locale
 * @property array<array-key, mixed> $active_locales
 * @property string $timezone
 * @property string $currency
 * @property string|null $autonomous_community
 * @property string|null $legal_name
 * @property string|null $tax_id
 * @property string|null $fiscal_address
 * @property string|null $fiscal_postal_code
 * @property string|null $fiscal_city
 * @property string|null $fiscal_province
 * @property string $fiscal_country_code
 * @property string|null $color_primary
 * @property string|null $color_secondary
 * @property string|null $logo_object_key
 * @property string|null $favicon_object_key
 * @property string|null $login_background_object_key
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property int $session_timeout_minutes
 * @property array<array-key, mixed> $mfa_allowed_methods
 * @property int $mfa_grace_period_days
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereActiveLocales($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereAutonomousCommunity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereColorPrimary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereColorSecondary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereDefaultLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereFaviconObjectKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereFiscalAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereFiscalCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereFiscalCountryCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereFiscalPostalCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereFiscalProvince($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereLegalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereLoginBackgroundObjectKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereLogoObjectKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereMfaAllowedMethods($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereMfaGracePeriodDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereSessionTimeoutMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereTaxId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereTimezone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTenantSetting {}
}

namespace App\Modules\Core\Domain\Models{
/**
 * datos.md §A.3. Selective: `original_filename`, las claves de objeto y
 * `error_summary` se redactan como `identifier` — pueden contener nombres
 * de fichero o fragmentos identificativos de personal del centro.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $original_filename
 * @property string|null $source_object_key
 * @property string|null $report_object_key
 * @property string $status
 * @property int|null $row_count
 * @property int|null $error_count
 * @property int|null $created_count
 * @property array<array-key, mixed>|null $error_summary
 * @property bool $send_invitations
 * @property \Illuminate\Support\Carbon|null $validated_at
 * @property \Illuminate\Support\Carbon|null $executed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereCreatedCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereErrorCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereErrorSummary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereExecutedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereOriginalFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereReportObjectKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereRowCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereSendInvitations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereSourceObjectKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport whereValidatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImport withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUserImport {}
}

namespace App\Modules\Core\Domain\Models{
/**
 * datos.md §A.2. Selective: `token_hash` lo redacta automáticamente el
 * patrón global `*token*` de config('audit.secret_attribute_patterns') —
 * no hace falta declararlo aquí.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $user_id
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereAcceptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereTokenHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserInvitation withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUserInvitation {}
}

namespace App\Modules\Curso\Domain\Models{
/**
 * REQ-CURSO/datos.md §1.2 (1.10, OPEN-CURSO-03): movido desde `App\Models`
 * al módulo `curso`. Los demás módulos NO lo usan (`AR-01`, RN-CURSO-26):
 * acceden al curso por las interfaces de `Curso\Domain`
 * (`AcademicYearContext`, `AcademicYearDirectory`…).
 *
 * ADR-034 §4: del tenant, nunca catálogo compartido — cada centro fija sus
 * propias fechas. academic_year_id es NOT NULL o no existe la columna en
 * cualquier tabla que la referencie; nunca nullable (regla verificada por
 * el test de esquema de 0.8.10, no por este modelo).
 *
 * ADR-035 §8: Full — sin datos personales.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $code
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon $ends_on
 * @property \App\Modules\Curso\Domain\AcademicYearStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereEndsOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereStartsOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYear withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperAcademicYear {}

/**
 * REQ-CURSO/datos.md §1.5 (1.11c, `ADR-059 §5.4`, `RN-CURSO-43`/`-44`). Registro
 * *append-only* de una reapertura: curso, motivo, actor y momento. Sin
 * `updated_at`, `updated_by` ni `deleted_at`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property string $reason
 * @property int $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AcademicYearReopening whereTenantId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperAcademicYearReopening {}

/**
 * REQ-CURSO/datos.md §1.6 (1.12b, `REQ-CURSO-002`): una copia (ejecución del
 * *rollover*) hacia el curso destino. Estado, selección efectiva, resultado y
 * fallo (`RN-CURSO-67`, `-69`, `-73` a `-75`). Sin datos personales: solo
 * códigos y cuentas (`ADR-035 §8`, política `Full`); los `jsonb` grandes los
 * acota el tope de `ADR-035 §5`.
 *
 * `status` lo escriben solo el servicio y los dos trabajos de `curso`, con
 * transiciones condicionales bajo `FOR UPDATE` (`RN-CURSO-84`). Sin
 * *endpoint* de borrado: una copia no se borra, se revierte (borrado lógico
 * heredado de `TenantModel`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $source_academic_year_id
 * @property \App\Modules\Curso\Domain\YearRolloverStatus $status
 * @property array<array-key, mixed> $elements
 * @property bool $copy_teaching_assignments
 * @property int|null $date_offset_days
 * @property array<array-key, mixed>|null $result
 * @property array<array-key, mixed>|null $failure
 * @property array<array-key, mixed>|null $revert_failure
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $reverted_by
 * @property \Illuminate\Support\Carbon|null $reverted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereCopyTeachingAssignments($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereDateOffsetDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereElements($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereFailure($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereResult($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereRevertFailure($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereRevertedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereRevertedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereSourceAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRollover withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperYearRollover {}

/**
 * REQ-CURSO/datos.md §1.7 (1.12b, `RN-CURSO-73`, `-75`, `-79`): qué fila creó
 * cada copia. *Append-only*, polimórfica sin FK (`entity_type` + `target_id`).
 * Extiende `AppendOnlyModel` (precedente `AcademicYearReopening`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $academic_year_id
 * @property int $year_rollover_id
 * @property string $module_code
 * @property string $entity_type
 * @property int $source_id
 * @property int $target_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property int $created_by
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereAcademicYearId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereEntityType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereModuleCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereSourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereTargetId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|YearRolloverItem whereYearRolloverId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperYearRolloverItem {}
}

namespace App\Modules\FamUnit\Domain\Models{
/**
 * REQ-FAM-UNIT/datos.md §1.1, §4 (1.14-a, `REQ-FAM-UNIT-001`). Unidad familiar: agrupa tutores y alumnado.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $code
 * @property int|null $principal_guardian_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit wherePrincipalGuardianId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnit withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperFamilyUnit {}
}

namespace App\Modules\FamUnit\Domain\Models{
/**
 * REQ-FAM-UNIT/datos.md §1.3, §4 (1.14-a, `REQ-FAM-UNIT-001`). Pertenencia de la persona de un alumno a una unidad.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $family_unit_id
 * @property int $student_person_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent whereFamilyUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent whereStudentPersonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FamilyUnitStudent withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperFamilyUnitStudent {}
}

namespace App\Modules\FamUnit\Domain\Models{
/**
 * REQ-FAM-UNIT/datos.md §1.2, §4 (1.14-a, `REQ-FAM-UNIT-001`). Tutor: faceta de `people` que pertenece a una sola unidad.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $person_id
 * @property int $family_unit_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian whereFamilyUnitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian wherePersonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Guardian withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperGuardian {}
}

namespace App\Modules\FamUnit\Domain\Models{
/**
 * REQ-FAM-UNIT/datos.md §1.4, §4 (1.14-a, `REQ-FAM-UNIT-002`). Vínculo tutor ↔ alumno.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $guardian_id
 * @property int $student_person_id
 * @property string $relationship
 * @property bool $is_legal_guardian
 * @property \Illuminate\Support\Carbon|null $legal_guardian_verified_at
 * @property int|null $legal_guardian_verified_by
 * @property string $access_level
 * @property array<array-key, mixed> $restricted_categories
 * @property bool $is_emergency_contact
 * @property string $status
 * @property string|null $revocation_reason
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereAccessLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereGuardianId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereIsEmergencyContact($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereIsLegalGuardian($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereLegalGuardianVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereLegalGuardianVerifiedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereRelationship($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereRestrictedCategories($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereRevocationReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereRevokedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereStudentPersonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GuardianStudentLink withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperGuardianStudentLink {}
/**
 * REQ-FAM-UNIT/datos.md §1.6, §4 (1.14-b, `REQ-FAM-UNIT-004`). Decisión de imagen de un tutor (solo anexar).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $student_person_id
 * @property string $level
 * @property string $decision
 * @property int $guardian_id
 * @property int $recorded_by
 * @property string $channel
 * @property int|null $consent_text_version_id
 * @property string|null $shown_locale
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property-read \App\Modules\FamUnit\Domain\Models\TermsVersion|null $termsVersion
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereConsentTextVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereDecision($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereGuardianId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereRecordedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereShownLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereStudentPersonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImageConsentDecision whereUserAgent($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperImageConsentDecision {}
/**
 * REQ-FAM-UNIT/datos.md §1.8, §4 (1.14-b, `REQ-FAM-UNIT-003`/`-004`). Versión de un texto legal del centro.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property string $kind
 * @property int $version_number
 * @property array<array-key, mixed> $title_translations
 * @property array<array-key, mixed> $body_translations
 * @property string $status
 * @property bool $requires_reacceptance
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereBodyTranslations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereKind($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereRequiresReacceptance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereTitleTranslations($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereUpdatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion whereVersionNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsVersion withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTermsVersion {}
/**
 * REQ-FAM-UNIT/datos.md §1.9, §4 (1.14-c, `REQ-FAM-UNIT-003`). Aceptación de las condiciones de uso (solo anexar).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $user_id
 * @property int $terms_version_id
 * @property string $shown_locale
 * @property \Illuminate\Support\Carbon $accepted_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property-read \App\Modules\FamUnit\Domain\Models\TermsVersion|null $termsVersion
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance whereAcceptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance whereShownLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance whereTermsVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TermsAcceptance whereUserId($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTermsAcceptance {}
}

namespace App\Support\Tenancy{
/**
 * Raíz del aislamiento (ADR-033 §7): no lleva tenant_id, se identifica a sí
 * misma. Conexión de plataforma (BYPASSRLS) a propósito: resolver un tenant
 * por slug (middleware ResolveTenant, 0.7.5) ocurre ANTES de que exista
 * contexto de tenant, y sobre la conexión pgsql (rol plataforma_app) la
 * política `id = app.current_tenant_id()` daría siempre cero filas sin
 * tenant activo. La política RLS de la tabla sigue ahí como red de
 * seguridad para el día en que algo la consulte por la conexión pgsql: en
 * ese caso solo vería su propia fila, nunca las de otro tenant.
 *
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property string $name
 * @property \App\Support\Tenancy\TenantStatus $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string|null $suspension_message
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property \Illuminate\Support\Carbon|null $grace_period_ends_at
 * @property \Illuminate\Support\Carbon|null $grace_period_expired_at
 * @method static \Database\Factories\TenantFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereGracePeriodEndsAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereGracePeriodExpiredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereSuspendedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereSuspensionMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTenant {}
}

