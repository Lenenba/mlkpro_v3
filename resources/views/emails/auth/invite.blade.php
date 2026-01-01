@extends('emails.layouts.base')

@section('title', 'Votre acces ' . ($companyName ?? config('app.name')))

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:16px;">
                <div style="font-size:18px; font-weight:700; color:#0f172a;">
                    Votre acces est pret
                </div>
                <div style="margin-top:6px; font-size:14px; color:#475569; line-height:1.6;">
                    @if (!empty($recipientName))
                        Bonjour {{ $recipientName }},
                    @else
                        Bonjour,
                    @endif
                </div>
                <div style="margin-top:6px; font-size:14px; color:#475569; line-height:1.6;">
                    Un acces {{ $roleLabel ?? 'utilisateur' }} a ete cree sur {{ $companyName ?? config('app.name') }}.
                    Cliquez sur le bouton ci-dessous pour definir votre mot de passe et vous connecter.
                </div>
            </td>
        </tr>
        <tr>
            <td style="padding-bottom:18px;">
                <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        <td bgcolor="#16a34a" style="border-radius:6px;">
                            <a href="{{ $actionUrl }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                                Creer mon mot de passe
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="font-size:12px; color:#64748b; line-height:1.6;">
                Ce lien expire dans {{ $expires ?? 60 }} minutes. Si vous n'etes pas a l'origine de cette demande, vous pouvez ignorer cet email.
            </td>
        </tr>
    </table>
@endsection
