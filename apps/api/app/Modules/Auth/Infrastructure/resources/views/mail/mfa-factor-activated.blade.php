<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.mfa_factor_activated.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.mfa_factor_activated.body', ['tenant' => $tenantName]) }}</p>
    <p>{{ __('auth.mail.mfa_factor_activated.warning') }}</p>
</body>
</html>
