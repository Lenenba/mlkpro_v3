@extends('emails.layouts.base')

@section('title', ($frequency ?? 'Daily') . ' admin digest')

@section('content')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td style="padding-bottom:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0f172a; color:#ffffff; border-radius:8px;">
                    <tr>
                        <td style="padding:20px;">
                            <div style="font-size:18px; font-weight:700; line-height:1.3;">
                                {{ $frequency ?? 'Daily' }} admin digest
                            </div>
                            <div style="margin-top:6px; font-size:12px; color:#cbd5f5;">
                                Generated {{ $generatedAt?->toDateTimeString() ?? now()->toDateTimeString() }}
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:6px; background-color:#ffffff;">
                    <tr>
                        <td style="padding:12px 16px; font-size:14px; font-weight:600; color:#0f172a;">
                            Updates ({{ count($items ?? []) }})
                        </td>
                    </tr>
                    @foreach (($items ?? []) as $item)
                        <tr>
                            <td style="padding:10px 16px; border-top:1px solid #e2e8f0;">
                                <div style="font-size:13px; font-weight:600; color:#0f172a;">
                                    {{ $item['title'] ?? 'Update' }}
                                </div>
                                <div style="margin-top:2px; font-size:11px; color:#64748b;">
                                    {{ strtoupper($item['category'] ?? 'general') }}
                                    @if (!empty($item['created_at']))
                                        - {{ \Illuminate\Support\Carbon::parse($item['created_at'])->toDateTimeString() }}
                                    @endif
                                </div>
                                @if (!empty($item['intro']))
                                    <div style="margin-top:6px; font-size:12px; color:#475569; line-height:1.4;">
                                        {{ $item['intro'] }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding-top:12px; font-size:12px; color:#64748b;">
                Need help? Reply to this email or contact {{ $supportEmail ?? 'support' }}.
            </td>
        </tr>
    </table>
@endsection
