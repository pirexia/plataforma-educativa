<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.new_device_login.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.new_device_login.body', ['tenant' => $tenantName, 'time' => $occurredAt->toIso8601String()]) }}</p>
    <p>{{ __('auth.mail.new_device_login.detail', ['client' => $clientLabel, 'ip' => $ipAddress ?? '—']) }}</p>
    {{-- funcional.md §B.7: location siempre null en 1.2b. Hueco preparado, no pintado hasta OPEN-AUTH-13. --}}
    @if($locationLabel !== null)
        <p>{{ __('auth.mail.new_device_login.location_line', ['location' => $locationLabel]) }}</p>
    @endif
    <p>{{ __('auth.mail.new_device_login.what_to_do') }}</p>
    {{-- RN-AUTH-50: enlace a la SPA, que exige sesión — ninguna acción se ejecuta al pulsarlo. --}}
    <p><a href="{{ $sessionsUrl }}">{{ __('auth.mail.new_device_login.cta') }}</a></p>
</body>
</html>
