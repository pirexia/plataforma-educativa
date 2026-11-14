{{-- funcional.md §4.3, RN-CORE-19: el token en claro solo viaja aquí, nunca se persiste. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('core.mail.invitation.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('core.mail.invitation.body', ['tenant' => $tenantName]) }}</p>
    <p><a href="{{ $activationUrl }}">{{ __('core.mail.invitation.cta') }}</a></p>
    <p>{{ __('core.mail.invitation.expires', ['days' => $expiresInDays]) }}</p>
</body>
</html>
