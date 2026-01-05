@extends('emails.layouts.base')

@section('title', 'Bienvenue ' . ($companyName ?? config('app.name')))

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:18px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0f172a; color:#ffffff; border-radius:8px;">
                    <tr>
                        <td style="padding:24px;">
                            <div style="display:inline-block; background-color:#1e293b; color:#e2e8f0; font-size:11px; letter-spacing:0.08em; text-transform:uppercase; padding:4px 10px; border-radius:999px; font-weight:600;">
                                Bienvenue
                            </div>
                            <div style="margin-top:14px; font-size:22px; font-weight:700; line-height:1.35;">
                                Votre espace MLK Pro est pret, {{ $userName ?? 'partner' }}.
                            </div>
                            <div style="margin-top:8px; font-size:14px; line-height:1.5; color:#e2e8f0;">
                                Merci pour votre confiance. Nous avons configure votre compte pour une entreprise de {{ $companyTypeLabel ?? 'services' }}.
                            </div>
                            @if (!empty($actionUrl))
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:16px;">
                                    <tr>
                                        <td bgcolor="#ffffff" style="border-radius:6px;">
                                            <a href="{{ $actionUrl }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:600; color:#0f172a; text-decoration:none;">
                                                Ouvrir le tableau de bord
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:18px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="font-size:16px; font-weight:600; color:#0f172a; padding-bottom:8px;">
                            Etapes principales
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px; border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                            <div style="font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:#047857; font-weight:600;">
                                Etape 1
                            </div>
                            <div style="margin-top:6px; font-size:14px; font-weight:600; color:#0f172a;">
                                Configurez votre catalogue
                            </div>
                            <div style="margin-top:4px; font-size:12px; color:#64748b; line-height:1.4;">
                                Ajoutez vos categories, services et produits cle.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="height:10px; line-height:10px; font-size:0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="padding:12px; border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                            <div style="font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:#047857; font-weight:600;">
                                Etape 2
                            </div>
                            <div style="margin-top:6px; font-size:14px; font-weight:600; color:#0f172a;">
                                Creez un devis
                            </div>
                            <div style="margin-top:4px; font-size:12px; color:#64748b; line-height:1.4;">
                                Transformez un devis en job en un clic.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="height:10px; line-height:10px; font-size:0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td style="padding:12px; border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                            <div style="font-size:11px; letter-spacing:0.08em; text-transform:uppercase; color:#047857; font-weight:600;">
                                Etape 3
                            </div>
                            <div style="margin-top:6px; font-size:14px; font-weight:600; color:#0f172a;">
                                Suivez vos paiements
                            </div>
                            <div style="margin-top:4px; font-size:12px; color:#64748b; line-height:1.4;">
                                Factures et paiements centralises.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        @if (!empty($quickSteps))
            <tr>
                <td style="padding-bottom:18px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                        <tr>
                            <td style="padding:16px 16px 8px; font-size:16px; font-weight:600; color:#0f172a;">
                                Votre checklist rapide
                            </td>
                        </tr>
                        @foreach ($quickSteps as $step)
                            <tr>
                                <td style="padding:6px 16px; font-size:13px; color:#475569; line-height:1.4;">
                                    <span style="color:#16a34a; font-weight:700;">&#8226;</span>
                                    <span style="padding-left:6px;">{{ $step }}</span>
                                </td>
                            </tr>
                        @endforeach
                        <tr>
                            <td style="height:8px; line-height:8px; font-size:0;">&nbsp;</td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        @if (!empty($highlights))
            <tr>
                <td style="padding-bottom:18px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:6px; background-color:#f8fafc;">
                        <tr>
                            <td style="padding:16px 16px 8px; font-size:16px; font-weight:600; color:#0f172a;">
                                Pourquoi MLK Pro
                            </td>
                        </tr>
                        @foreach ($highlights as $highlight)
                            <tr>
                                <td style="padding:8px 16px; font-size:13px; color:#475569; line-height:1.4;">
                                    {{ $highlight }}
                                </td>
                            </tr>
                        @endforeach
                        <tr>
                            <td style="height:8px; line-height:8px; font-size:0;">&nbsp;</td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        <tr>
            <td style="font-size:12px; color:#64748b;">
                Besoin d'aide ? Repondez a cet email ou contactez-nous a {{ $supportEmail ?? 'support' }}.
            </td>
        </tr>
    </table>
@endsection
