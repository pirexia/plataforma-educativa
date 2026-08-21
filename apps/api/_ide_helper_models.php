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
 * @property \App\Models\AcademicYearStatus $status
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
}

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
 * @property string $code
 * @property string $resource
 * @property string $action
 * @property string $module_code
 * @property bool $is_special_category
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Permission whereAction($value)
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
 * ADR-034 §2: concesión de un permiso a un rol del tenant. Sin política de
 * auditoría propia en 1.1 — en este paso solo la siembra
 * `tenant:provision-defaults`, no hay escritura de usuario que auditar
 * todavía (1.5 la traerá junto con el resolutor completo).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $role_id
 * @property string $permission_code
 * @property string $effect
 * @property string|null $scope
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantSetting wherePublicId($value)
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
 * @method static \Database\Factories\TenantFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tenant withoutTrashed()
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperTenant {}
}

