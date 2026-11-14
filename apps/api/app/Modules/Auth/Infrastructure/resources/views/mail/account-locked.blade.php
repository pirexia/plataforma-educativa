{{-- RN-AUTH-09: el token en claro solo viaja aquí, nunca se persiste. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.account_locked.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.account_locked.body', ['tenant' => $tenantName, 'count' => $failedCount]) }}</p>
    <p>{{ __('auth.mail.account_locked.auto_unlock', ['time' => $autoUnlocksAt->toIso8601String()]) }}</p>
    <p><a href="{{ $unlockUrl }}">{{ __('auth.mail.account_locked.cta') }}</a></p>
</body>
</html>
