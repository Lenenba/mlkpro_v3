@extends('emails.layouts.base')

@section('title', __('mail.billing_upcoming.title'))
@section('preheader', __('mail.billing_upcoming.preheader'))

@section('content')
    @php
        $heroIntro = trim(sprintf(
            '%s',
            __('mail.billing_upcoming.hero_intro', [
                'name' => $recipientName ?? __('mail.common.preview_recipient'),
                'plan' => $planName ?? 'Malikia Pro',
                'date' => $billingDateLabel ?? ($billingDate ?? '-'),
                'amount' => $formattedTotal ?? '-',
            ])
        ));
        $billingPeriodText = ($billingPeriod ?? null) === 'yearly'
            ? __('mail.billing_upcoming.cycle_yearly')
            : __('mail.billing_upcoming.cycle_monthly');
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:12px;">
                @include('emails.partials.structured-hero', [
                    'heroEyebrow' => __('mail.billing_upcoming.hero_eyebrow'),
                    'heroTitle' => __('mail.billing_upcoming.hero_title'),
                    'heroIntro' => $heroIntro,
                    'heroActionUrl' => $manageBillingUrl ?? null,
                    'heroActionLabel' => __('mail.billing_upcoming.hero_action'),
                    'heroCaption' => __('mail.billing_upcoming.hero_caption'),
                    'heroSideTitle' => __('mail.common.billing_snapshot'),
                    'heroSideLogo' => $companyLogo ?? null,
                    'heroSideRows' => [
                        ['label' => __('mail.common.company'), 'value' => $companyName ?? config('app.name')],
                        ['label' => __('mail.common.plan'), 'value' => $planName ?? 'Malikia Pro'],
                        ['label' => __('mail.billing_upcoming.next_date'), 'value' => $billingDateLabel ?? ($billingDate ?? null)],
                        ['label' => __('mail.billing_upcoming.estimated_total'), 'value' => $formattedTotal ?? null],
                    ],
                    'heroMetrics' => [
                        ['value' => (string) ($daysUntilBilling ?? '?'), 'label' => __('mail.common.days')],
                        ['value' => (string) ($seatQuantity ?? 1), 'label' => __('mail.common.seats')],
                        ['value' => $billingPeriodText, 'label' => __('mail.billing_upcoming.cycle')],
                    ],
                ])
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td valign="top" width="50%" style="padding-right:6px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                                <tr>
                                    <td style="padding:16px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.billing_upcoming.details_title') }}
                                        </div>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                                            <tr>
                                                <td style="padding:0 0 10px 0; height:48px; vertical-align:top;">
                                                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                        {{ __('mail.billing_upcoming.subscription') }}
                                                    </div>
                                                    <div style="margin-top:2px; font-size:13px; color:#292524; line-height:1.6;">
                                                        {{ $planName ?? 'Malikia Pro' }}
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top;">
                                                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                        {{ __('mail.billing_upcoming.cycle') }}
                                                    </div>
                                                    <div style="margin-top:2px; font-size:13px; color:#292524; line-height:1.6;">
                                                        {{ $billingPeriodText }}
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top;">
                                                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                        {{ __('mail.billing_upcoming.next_date') }}
                                                    </div>
                                                    <div style="margin-top:2px; font-size:13px; color:#292524; line-height:1.6;">
                                                        {{ $billingDateLabel ?? ($billingDate ?? '-') }}
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top;">
                                                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                        {{ __('mail.common.currency') }}
                                                    </div>
                                                    <div style="margin-top:2px; font-size:13px; color:#292524; line-height:1.6;">
                                                        {{ $currencyCode ?? 'CAD' }}
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top;">
                                                    <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                                        {{ __('mail.billing_upcoming.estimated_total') }}
                                                    </div>
                                                    <div style="margin-top:2px; font-size:16px; font-weight:700; color:#0f172a; line-height:1.6;">
                                                        {{ $formattedTotal ?? '-' }}
                                                    </div>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td valign="top" width="50%" style="padding-left:6px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                                <tr>
                                    <td style="padding:16px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.billing_upcoming.summary_title') }}
                                        </div>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px;">
                                            <tr>
                                                <td style="padding:0 0 10px 0; height:48px; vertical-align:top; font-size:12px; color:#78716c;">{{ __('mail.common.subtotal') }}</td>
                                                <td align="right" style="padding:0 0 10px 0; height:48px; vertical-align:top; font-size:12px; font-weight:600; color:#292524;">{{ $formattedSubtotal ?? '-' }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top; font-size:12px; color:#78716c;">{{ __('mail.billing_upcoming.estimated_taxes') }}</td>
                                                <td align="right" style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top; font-size:12px; font-weight:600; color:#292524;">{{ $formattedTax ?? '-' }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top; font-size:12px; color:#78716c;">{{ __('mail.billing_upcoming.billable_quantity') }}</td>
                                                <td align="right" style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top; font-size:12px; font-weight:600; color:#292524;">{{ $seatQuantity ?? 1 }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top; font-size:12px; color:#78716c;">{{ __('mail.common.currency') }}</td>
                                                <td align="right" style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top; font-size:12px; font-weight:600; color:#292524;">{{ $currencyCode ?? 'CAD' }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top; font-size:14px; font-weight:700; color:#292524;">{{ __('mail.billing_upcoming.upcoming_total') }}</td>
                                                <td align="right" style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; height:48px; vertical-align:top; font-size:14px; font-weight:700; color:#292524;">{{ $formattedTotal ?? '-' }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f4; border:1px solid #e7e5e4; border-top:3px solid #16a34a; border-radius:3px;">
                    <tr>
                        <td style="padding:14px 16px; font-size:13px; color:#57534e; line-height:1.7;">
                            {{ __('mail.billing_upcoming.payment_note') }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        @if (!empty($lineItems))
            <tr>
                <td style="padding-bottom:12px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border:1px solid #e7e5e4; border-radius:3px;">
                        <tr>
                            <td style="padding:16px;">
                                <div style="font-size:14px; font-weight:600; color:#1c1917;">
                                    {{ __('mail.billing_upcoming.line_items_title') }}
                                </div>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px; border-collapse:collapse;">
                                    <tr style="background-color:#f5f5f4; border-bottom:1px solid #e7e5e4;">
                                        <th align="left" style="padding:12px 14px; font-size:12px; font-weight:600; color:#292524;">{{ __('mail.billing_upcoming.description') }}</th>
                                        <th align="right" style="padding:12px 14px; font-size:12px; font-weight:600; color:#292524;">{{ __('mail.common.quantity') }}</th>
                                        <th align="right" style="padding:12px 14px; font-size:12px; font-weight:600; color:#292524;">{{ __('mail.common.total') }}</th>
                                    </tr>
                                    @foreach ($lineItems as $item)
                                        <tr>
                                            <td style="padding:14px; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }};">
                                                <div style="font-size:13px; font-weight:600; color:#292524; line-height:1.6;">
                                                    {{ $item['label'] ?? __('mail.common.recurring_charge') }}
                                                </div>
                                            </td>
                                            <td align="right" style="padding:14px; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:13px; color:#44403c; white-space:nowrap;">
                                                {{ $item['quantity'] ?? '-' }}
                                            </td>
                                            <td align="right" style="padding:14px; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:13px; font-weight:600; color:#292524; white-space:nowrap;">
                                                {{ $item['formatted_amount'] ?? '-' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        @endif

        <tr>
            <td style="font-size:12px; color:#57534e; line-height:1.7;">
                {{ __('mail.billing_upcoming.footer_help', ['support' => $supportEmail ?? 'support']) }}
            </td>
        </tr>
    </table>
@endsection
