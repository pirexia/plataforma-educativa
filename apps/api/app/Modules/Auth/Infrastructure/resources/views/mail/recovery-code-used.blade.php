<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.recovery_code_used.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.recovery_code_used.body', ['tenant' => $tenantName]) }}</p>
    <p>{{ __('auth.mail.recovery_code_used.warning') }}</p>
</body>
</html>
