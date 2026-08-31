<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.mfa_enrollment_code.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.mfa_enrollment_code.body', ['tenant' => $tenantName]) }}</p>
    <p>{{ __('auth.mail.mfa_enrollment_code.code', ['code' => $code]) }}</p>
    <p>{{ __('auth.mail.mfa_enrollment_code.expires', ['minutes' => $ttlMinutes]) }}</p>
</body>
</html>
