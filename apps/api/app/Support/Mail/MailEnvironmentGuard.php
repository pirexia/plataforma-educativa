<?php

namespace App\Support\Mail;

use RuntimeException;

/**
 * Issue #128 (revisión de seguridad A-1). Guarda de arranque lanzada desde
 * `CoreServiceProvider::boot()`: se ejecuta en la API, en el worker de colas
 * y en cualquier comando de consola, porque todos arrancan la aplicación.
 *
 * Con un worker consumiendo `auth-mail`, el mailer por defecto de Laravel
 * (`log`, o `array`, que descarta en silencio) dejaría de ser inocuo: cada
 * correo transaccional —código MFA, código de alta, restablecimiento de
 * contraseña, invitación con enlace— se volcaría en claro en los logs, o
 * se perdería sin error. Fuera de `local`/`testing` es un error de
 * configuración que debe impedir el arranque, no un valor por defecto.
 */
final class MailEnvironmentGuard
{
    public function verify(): void
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array'], true) && ! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                "MAIL_MAILER={$mailer} fuera de local/testing: los correos transaccionales (códigos MFA, ".
                'restablecimiento de contraseña, invitaciones) se volcarían en claro en los logs o se descartarían '.
                'en silencio. Configura un transporte real (MAIL_MAILER=smtp y credenciales) en el fichero de '.
                'entorno (SYSADMIN.md §2c, issue #128).'
            );
        }
    }
}
