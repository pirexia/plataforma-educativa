<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.identity_matched.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.identity_matched.body', ['tenant' => $tenantName, 'provider' => $providerDisplayName, 'email' => $matchedEmailMasked]) }}</p>
    <p>{{ __('auth.mail.identity_matched.warning') }}</p>
</body>
</html>
