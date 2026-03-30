@extends('emails.layouts.base')

@section('title', __('mail.quote.title', ['customer' => $quote->customer->company_name ?? __('mail.common.customer')]))

@section('content')
    @php
        $customer = $quote->customer;
        $property = $quote->property ?? ($customer->properties->first() ?? null);
        $contactName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        $contactLabel = $contactName !== '' ? $contactName : ($customer->company_name ?? __('mail.common.customer'));
        $actionUrl = $actionUrl ?? route('dashboard');
        $actionLabel = $actionLabel ?? __('mail.quote.action_open_dashboard');
        $actionMessage = $actionMessage ?? __('mail.quote.action_message_dashboard');
        $itemCount = $quote->products->count();
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:16px;">
                @include('emails.partials.structured-hero', [
                    'heroEyebrow' => __('mail.quote.hero_eyebrow'),
                    'heroTitle' => __('mail.quote.hero_title', ['customer' => $customer->company_name ?? $contactLabel]),
                    'heroIntro' => $quote->job_title ?: __('mail.quote.hero_intro'),
                    'heroActionUrl' => $actionUrl,
                    'heroActionLabel' => $actionLabel,
                    'heroCaption' => __('mail.quote.hero_caption'),
                    'heroSideTitle' => __('mail.quote.title', ['customer' => $customer->company_name ?? $contactLabel]),
                    'heroSideLogo' => $companyLogo ?? null,
                    'heroSideRows' => [
                        ['label' => __('mail.common.customer'), 'value' => $customer->company_name ?? $contactLabel],
                        ['label' => __('mail.common.quote'), 'value' => $quote->number ?? $quote->id],
                        ['label' => __('mail.common.status'), 'value' => $quote->status ?? 'sent'],
                    ],
                    'heroMetrics' => [
                        ['value' => (string) $itemCount, 'label' => __('mail.common.items')],
                        ['value' => '$'.number_format((float) $quote->total, 0), 'label' => __('mail.common.total')],
                        ['value' => $quote->initial_deposit > 0 ? '$'.number_format((float) $quote->initial_deposit, 0) : '0', 'label' => __('mail.common.label_deposit')],
                    ],
                ])
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td valign="top" width="50%" style="padding-right:8px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e7e5e4; border-radius:3px; background-color:#ffffff;">
                                <tr>
                                    <td style="padding:12px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.quote.property_address') }}
                                        </div>
                                        <div style="margin-top:6px; font-size:12px; color:#57534e;">
                                            @if ($property)
                                                <div>{{ $property->street1 }}</div>
                                                @if (!empty($property->street2))
                                                    <div>{{ $property->street2 }}</div>
                                                @endif
                                                <div>{{ $property->city ?? '' }} {{ $property->state ?? '' }} {{ $property->zip ?? '' }}</div>
                                                <div>{{ $property->country ?? '' }}</div>
                                            @else
                                                <div>{{ __('mail.quote.no_property') }}</div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td valign="top" width="50%" style="padding-left:8px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e7e5e4; border-radius:3px; background-color:#ffffff;">
                                <tr>
                                    <td style="padding:12px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                            {{ __('mail.quote.contact_details') }}
                                        </div>
                                        <div style="margin-top:6px; font-size:12px; color:#57534e;">
                                            <div>{{ $contactName !== '' ? $contactName : '-' }}</div>
                                            <div>{{ $customer->email ?? '-' }}</div>
                                            <div>{{ $customer->phone ?? '-' }}</div>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e7e5e4; border-radius:3px; background-color:#ffffff;">
                    <tr>
                        <td style="padding:12px;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">{{ __('mail.quote.quote_details') }}</div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;">
                                <tr>
                                    <td style="font-size:12px; color:#57534e;">{{ __('mail.common.quote') }}:</td>
                                    <td align="right" style="font-size:12px; color:#292524; font-weight:600;">
                                        {{ $quote->number ?? $quote->id }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:12px; color:#57534e;">{{ __('mail.common.status') }}:</td>
                                    <td align="right" style="font-size:12px; color:#292524; font-weight:600;">
                                        {{ $quote->status ?? 'sent' }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e7e5e4; border-radius:3px; background-color:#ffffff;">
                    <tr>
                        <td style="padding:16px 20px 18px;">
                            <div style="font-size:16px; font-weight:600; color:#1c1917;">
                                {{ __('mail.quote.products_services') }}
                            </div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:12px; border-collapse:collapse;">
                                <tr style="background-color:#f5f5f4; border-bottom:1px solid #e7e5e4;">
                                    <th align="left" style="padding:12px 14px; font-size:12px; font-weight:600; color:#292524;">{{ __('mail.quote.products_services') }}</th>
                                    <th align="right" style="padding:12px 14px; font-size:12px; font-weight:600; color:#292524;">{{ __('mail.common.quantity') }}</th>
                                    <th align="right" style="padding:12px 14px; font-size:12px; font-weight:600; color:#292524;">{{ __('mail.quote.unit_cost') }}</th>
                                    <th align="right" style="padding:12px 14px; font-size:12px; font-weight:600; color:#292524;">{{ __('mail.common.total') }}</th>
                                </tr>
                                @foreach ($quote->products as $product)
                                    <tr>
                                        <td style="padding:14px; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:13px; color:#292524; line-height:1.6;">
                                            {{ $product->name }}
                                        </td>
                                        <td align="right" style="padding:14px; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:13px; color:#44403c; white-space:nowrap;">
                                            {{ $product->pivot->quantity }}
                                        </td>
                                        <td align="right" style="padding:14px; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:13px; color:#44403c; white-space:nowrap;">
                                            ${{ number_format((float) $product->pivot->price, 2) }}
                                        </td>
                                        <td align="right" style="padding:14px; border-top:{{ $loop->first ? '0' : '1px solid #e7e5e4' }}; font-size:13px; font-weight:600; color:#292524; white-space:nowrap;">
                                            ${{ number_format((float) $product->pivot->total, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e7e5e4; border-radius:3px; background-color:#ffffff;">
                    <tr>
                        <td valign="top" width="52%" style="padding:18px 20px;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#78716c;">
                                {{ __('mail.quote.next_step') }}
                            </div>
                            <div style="margin-top:10px; font-size:13px; color:#57534e; line-height:1.8;">
                                {{ $actionMessage }}
                            </div>
                        </td>
                        <td valign="top" width="48%" style="padding:18px 20px; border-left:1px solid #e7e5e4;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding:0 0 10px 0; font-size:12px; color:#78716c;">{{ __('mail.common.subtotal') }}:</td>
                                    <td align="right" style="padding:0 0 10px 0; font-size:12px; color:#292524; font-weight:600;">
                                        $ {{ number_format((float) $quote->subtotal, 2) }}
                                    </td>
                                </tr>
                                @if ($quote->taxes && $quote->taxes->count())
                                    @foreach ($quote->taxes as $tax)
                                        <tr>
                                            <td style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; font-size:12px; color:#78716c;">
                                                {{ $tax->tax->name ?? 'Tax' }} ({{ number_format($tax->rate, 2) }}%)
                                            </td>
                                            <td align="right" style="padding:10px 0 0 0; border-top:1px solid #e7e5e4; font-size:12px; color:#292524;">
                                                ${{ number_format((float) $tax->amount, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                    <tr>
                                        <td style="padding:10px 0 0 0; font-size:12px; color:#78716c; font-weight:600;">
                                            {{ __('mail.quote.total_taxes') }}:
                                        </td>
                                        <td align="right" style="padding:10px 0 0 0; font-size:12px; color:#292524; font-weight:600;">
                                            ${{ number_format((float) $quote->taxes->sum('amount'), 2) }}
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding:12px 0 0 0; border-top:1px solid #d6d3d1; font-size:14px; color:#292524; font-weight:700;">
                                        {{ __('mail.quote.total_amount') }}:
                                    </td>
                                    <td align="right" style="padding:12px 0 0 0; border-top:1px solid #d6d3d1; font-size:14px; color:#292524; font-weight:700;">
                                        $ {{ number_format((float) $quote->total, 2) }}
                                    </td>
                                </tr>
                                @if ($quote->initial_deposit > 0)
                                    <tr>
                                        <td style="padding:10px 0 0 0; font-size:12px; color:#78716c;">
                                            {{ __('mail.quote.required_deposit') }}:
                                        </td>
                                        <td align="right" style="padding:10px 0 0 0; font-size:12px; color:#78716c;">
                                            Min: ${{ number_format((float) $quote->initial_deposit, 2) }}
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td>
                <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        <td bgcolor="#16a34a" style="border-radius:3px;">
                            <a href="{{ $actionUrl }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                                {{ $actionLabel }}
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endsection
