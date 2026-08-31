{{--
    Standalone rather than built on emails/notification.blade.php: that shell
    is shaped around an attendee's ticket (hero, ticket card, QR counterfoil)
    and carries none of it here. Kept plain and table-based so Outlook's Word
    engine renders it; the palette matches the public site's brand tokens.
--}}
<div style="margin:0;padding:24px 12px;background:#f5f3ff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color-scheme:light;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="max-width:520px;background:#ffffff;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td bgcolor="#6d28d9" style="background:#6d28d9;padding:22px 28px;">
                            <div style="font-family:Georgia,'Times New Roman',serif;font-size:19px;font-weight:700;color:#ffffff;">
                                {{ config('app.name') }}
                            </div>
                            <div style="font-size:12.5px;color:#ddd6fe;padding-top:2px;">Admin console</div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 14px;font-size:15px;color:#3d1d7a;font-weight:600;">
                                Hello {{ $user->name }},
                            </p>

                            <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#374151;">
                                Somebody asked to reset the password for your staff account
                                (<strong>{{ $user->email }}</strong>). Use the button below to choose a new one.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
                                <tr>
                                    <td bgcolor="#7c3aed" style="background:#7c3aed;border-radius:8px;">
                                        <a href="{{ $resetUrl }}"
                                           style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">
                                            Choose a new password
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 18px;font-size:13px;line-height:1.6;color:#6b7280;">
                                This link works once and expires in {{ $expiresInMinutes }} minutes. Signing in with
                                the new password still needs your authenticator code, exactly as before — resetting a
                                password does not get past two-factor authentication.
                            </p>

                            {{-- Said plainly rather than left implied: a reset email nobody asked for is the
                                 first sign of somebody trying the account, and the reader is the one who can tell. --}}
                            <p style="margin:0 0 6px;font-size:13px;line-height:1.6;color:#6b7280;">
                                <strong style="color:#374151;">If you did not ask for this</strong>, you can ignore this
                                email — your password has not changed. If it keeps arriving, tell whoever runs this system:
                                it means somebody knows your address and is trying to get in.
                            </p>

                            <p style="margin:18px 0 0;font-size:11.5px;line-height:1.6;color:#9ca3af;word-break:break-all;">
                                If the button does not work, paste this into your browser:<br>{{ $resetUrl }}
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td bgcolor="#faf5ff" style="background:#faf5ff;padding:14px 28px;font-size:11.5px;color:#9ca3af;">
                            Sent automatically by {{ config('app.name') }}. Please do not reply.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
