@extends('emails.layouts.base')

@section('title', 'Quote for ' . ($quote->customer->company_name ?? 'Client'))

@section('content')
    @php
        $customer = $quote->customer;
        $property = $quote->property ?? ($customer->properties->first() ?? null);
        $contactName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        $contactLabel = $contactName !== '' ? $contactName : ($customer->company_name ?? 'Client');
    @endphp
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                    <tr>
                        <td style="padding:16px;">
                            <div style="font-size:18px; font-weight:700; color:#0f172a;">
                                Quote for {{ $customer->company_name ?? $contactLabel }}
                            </div>
                            <div style="margin-top:6px; font-size:13px; color:#475569;">
                                {{ $quote->job_title }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding-bottom:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td valign="top" width="50%" style="padding-right:8px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                                <tr>
                                    <td style="padding:12px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#047857;">
                                            Property address
                                        </div>
                                        <div style="margin-top:6px; font-size:12px; color:#475569;">
                                            @if ($property)
                                                <div>{{ $property->street1 }}</div>
                                                @if (!empty($property->street2))
                                                    <div>{{ $property->street2 }}</div>
                                                @endif
                                                <div>{{ $property->city ?? '' }} {{ $property->state ?? '' }} {{ $property->zip ?? '' }}</div>
                                                <div>{{ $property->country ?? '' }}</div>
                                            @else
                                                <div>No property selected.</div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td valign="top" width="50%" style="padding-left:8px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                                <tr>
                                    <td style="padding:12px;">
                                        <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#047857;">
                                            Contact details
                                        </div>
                                        <div style="margin-top:6px; font-size:12px; color:#475569;">
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
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                    <tr>
                        <td style="padding:12px;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.08em; color:#047857;">
                                Quote details
                            </div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:8px;">
                                <tr>
                                    <td style="font-size:12px; color:#475569;">Quote:</td>
                                    <td align="right" style="font-size:12px; color:#0f172a; font-weight:600;">
                                        {{ $quote->number ?? $quote->id }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="font-size:12px; color:#475569;">Status:</td>
                                    <td align="right" style="font-size:12px; color:#0f172a; font-weight:600;">
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
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                    <tr>
                        <td style="padding:12px;">
                            <div style="font-size:14px; font-weight:600; color:#0f172a; padding-bottom:8px;">
                                Items
                            </div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <tr style="background-color:#f1f5f9;">
                                    <th align="left" style="padding:8px; font-size:12px; color:#0f172a;">Products / Services</th>
                                    <th align="left" style="padding:8px; font-size:12px; color:#0f172a;">Qty.</th>
                                    <th align="left" style="padding:8px; font-size:12px; color:#0f172a;">Unit cost</th>
                                    <th align="left" style="padding:8px; font-size:12px; color:#0f172a;">Total</th>
                                </tr>
                                @foreach ($quote->products as $product)
                                    <tr style="border-top:1px solid #e2e8f0;">
                                        <td style="padding:8px; font-size:12px; color:#334155;">{{ $product->name }}</td>
                                        <td style="padding:8px; font-size:12px; color:#334155;">{{ $product->pivot->quantity }}</td>
                                        <td style="padding:8px; font-size:12px; color:#334155;">${{ number_format((float) $product->pivot->price, 2) }}</td>
                                        <td style="padding:8px; font-size:12px; color:#334155;">${{ number_format((float) $product->pivot->total, 2) }}</td>
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
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                    <tr>
                        <td style="padding:12px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="font-size:12px; color:#64748b;">Subtotal:</td>
                                    <td align="right" style="font-size:12px; color:#0f172a; font-weight:600;">
                                        $ {{ number_format((float) $quote->subtotal, 2) }}
                                    </td>
                                </tr>
                                @if ($quote->taxes && $quote->taxes->count())
                                    @foreach ($quote->taxes as $tax)
                                        <tr>
                                            <td style="font-size:12px; color:#64748b;">
                                                {{ $tax->tax->name ?? 'Tax' }} ({{ number_format($tax->rate, 2) }}%)
                                            </td>
                                            <td align="right" style="font-size:12px; color:#0f172a;">
                                                ${{ number_format((float) $tax->amount, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                    <tr>
                                        <td style="font-size:12px; color:#64748b; font-weight:600;">
                                            Total taxes:
                                        </td>
                                        <td align="right" style="font-size:12px; color:#0f172a; font-weight:600;">
                                            ${{ number_format((float) $quote->taxes->sum('amount'), 2) }}
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td style="padding-top:8px; font-size:13px; color:#0f172a; font-weight:700;">
                                        Total amount:
                                    </td>
                                    <td align="right" style="padding-top:8px; font-size:13px; color:#0f172a; font-weight:700;">
                                        $ {{ number_format((float) $quote->total, 2) }}
                                    </td>
                                </tr>
                                @if ($quote->initial_deposit > 0)
                                    <tr>
                                        <td style="padding-top:6px; font-size:12px; color:#64748b;">
                                            Required deposit:
                                        </td>
                                        <td align="right" style="padding-top:6px; font-size:12px; color:#64748b;">
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
            <td style="font-size:13px; color:#475569; padding-bottom:12px;">
                Log in to your portal to review and validate the quote.
            </td>
        </tr>
        <tr>
            <td>
                <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        <td bgcolor="#16a34a" style="border-radius:6px;">
                            <a href="{{ route('dashboard') }}" style="display:inline-block; padding:10px 16px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                                Open dashboard
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
@endsection
