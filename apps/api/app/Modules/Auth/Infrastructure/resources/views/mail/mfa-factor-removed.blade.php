<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.mfa_factor_removed.greeting', ['name' => $givenName]) }}</p>
    @if ($byAdmin)
        <p>{{ __('auth.mail.mfa_factor_removed.body_by_admin', ['tenant' => $tenantName]) }}</p>
    @else
        <p>{{ __('auth.mail.mfa_factor_removed.body_by_self', ['tenant' => $tenantName]) }}</p>
    @endif
    <p>{{ __('auth.mail.mfa_factor_removed.warning') }}</p>
</body>
</html>
