<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.identity_unlinked.greeting', ['name' => $givenName]) }}</p>
    <p>{{ __('auth.mail.identity_unlinked.body', ['tenant' => $tenantName, 'email' => $unlinkedEmailMasked]) }}</p>
    <p>{{ __('auth.mail.identity_unlinked.warning') }}</p>
</body>
</html>
