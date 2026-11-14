<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('auth.mail.identity_linked.greeting', ['name' => $givenName]) }}</p>
    @if ($isFusion)
        <p>{{ __('auth.mail.identity_linked.body_fusion', ['tenant' => $tenantName, 'email' => $linkedEmailMasked]) }}</p>
    @else
        <p>{{ __('auth.mail.identity_linked.body_profile', ['tenant' => $tenantName, 'email' => $linkedEmailMasked]) }}</p>
    @endif
    <p>{{ __('auth.mail.identity_linked.warning') }}</p>
</body>
</html>
