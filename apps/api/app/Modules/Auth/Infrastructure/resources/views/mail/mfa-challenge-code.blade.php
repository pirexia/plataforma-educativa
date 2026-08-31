<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.mfa_challenge_code.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.mfa_challenge_code.body', ['tenant' => $tenantName]) }}</p>
    <p>{{ __('auth.mail.mfa_challenge_code.code', ['code' => $code]) }}</p>
    <p>{{ __('auth.mail.mfa_challenge_code.expires', ['minutes' => $ttlMinutes]) }}</p>
    <p>{{ __('auth.mail.mfa_challenge_code.warning') }}</p>
</body>
</html>
