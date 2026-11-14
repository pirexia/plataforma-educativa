<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.password_changed.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.password_changed.body', ['tenant' => $tenantName]) }}</p>
    <p>{{ __('auth.mail.password_changed.warning') }}</p>
</body>
</html>
