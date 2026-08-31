{{-- The plain-text alternative. Not decoration: an HTML-only message is a
     long-standing spam signal, and a multipart/alternative message with a
     genuine text part scores better at every major provider. It is also what
     a screen reader and a text-mode client actually get. --}}
Hello {{ $user->name }},

Somebody asked to reset the password for your staff account ({{ $user->email }}).

Open this link to choose a new one:

{{ $resetUrl }}

The link works once and expires in {{ $expiresInMinutes }} minutes. Signing in with
the new password still needs your authenticator code, exactly as before -- resetting
a password does not get past two-factor authentication.

If you did not ask for this, you can ignore this email; your password has not
changed. If it keeps arriving, tell whoever runs this system: it means somebody
knows your address and is trying to get in.

--
{{ config('app.name') }} -- admin console. Sent automatically; please do not reply.
