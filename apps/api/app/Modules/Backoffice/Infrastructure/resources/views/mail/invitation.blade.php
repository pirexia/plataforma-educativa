{{-- Issue #173. El token en claro sólo viaja aquí, nunca se persiste. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body>
    <p>{{ __('bo.mail.invitation.greeting', ['name' => $recipientName]) }}</p>
    <p>{{ __('bo.mail.invitation.body') }}</p>
    <p><a href="{{ $activationUrl }}">{{ __('bo.mail.invitation.cta') }}</a></p>
    <p>{{ __('bo.mail.invitation.expires', ['days' => $expiresInDays]) }}</p>
</body>
</html>
