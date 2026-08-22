{{-- RN-AUTH-09: el token en claro solo viaja aquí, nunca se persiste. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.password_reset.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.password_reset.body', ['tenant' => $tenantName]) }}</p>
    <p><a href="{{ $resetUrl }}">{{ __('auth.mail.password_reset.cta') }}</a></p>
    <p>{{ __('auth.mail.password_reset.expires', ['minutes' => $expiresInMinutes]) }}</p>
    <p>{{ __('auth.mail.password_reset.ignore') }}</p>
</body>
</html>
