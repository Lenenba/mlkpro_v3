<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Invoice</title>
    <style>
      body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 12px;
        color: #1b1a18;
        margin: 0;
        padding: 24px;
      }
      .header-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 18px;
      }
      .header-left {
        vertical-align: top;
      }
      .header-right {
        text-align: right;
        vertical-align: top;
      }
      .company-table {
        width: 100%;
        border-collapse: collapse;
      }
      .logo {
        width: 48px;
        height: 48px;
        object-fit: cover;
        border: 1px solid #e6e1db;
        border-radius: 6px;
      }
      .company-name {
        font-size: 16px;
        font-weight: bold;
        margin-bottom: 2px;
      }
      .title {
        font-size: 13px;
        font-weight: 600;
        margin-top: 4px;
      }
      .meta {
        color: #6f665c;
        font-size: 11px;
      }
      .invoice-number {
        font-size: 14px;
        font-weight: 600;
        margin-top: 6px;
      }
      .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }
      .status-draft {
        background: #f0ebe4;
        color: #6b6156;
      }
      .status-sent {
        background: #e0edff;
        color: #2162b4;
      }
      .status-partial {
        background: #fff1d6;
        color: #8a5b00;
      }
      .status-paid {
        background: #dcf5e8;
        color: #0a7b50;
      }
      .status-overdue,
      .status-void {
        background: #ffe1e1;
        color: #a12b2b;
      }
      .status-default {
        background: #eef2f6;
        color: #4a5568;
      }
      .section-title {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #6f665c;
        margin-bottom: 6px;
      }
      .card {
        border: 1px solid #e6e1db;
        background: #faf8f5;
        padding: 10px;
        border-radius: 6px;
      }
      .info-grid {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 18px;
      }
      .info-main {
        width: 65%;
        vertical-align: top;
        padding-right: 12px;
      }
      .info-side {
        width: 35%;
        vertical-align: top;
      }
      .inner-grid {
        width: 100%;
        border-collapse: collapse;
      }
      .info-cell {
        padding: 8px 6px;
        vertical-align: top;
      }
      .meta-row {
        display: table;
        width: 100%;
        margin-top: 4px;
      }
      .meta-row span {
        display: table-cell;
        font-size: 11px;
        color: #6f665c;
      }
      .meta-row span:last-child {
        text-align: right;
        color: #1b1a18;
        font-weight: 600;
      }
      .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 8px;
      }
      .items-table th,
      .items-table td {
        padding: 8px 6px;
        border-bottom: 1px solid #e7ded4;
        text-align: left;
        vertical-align: top;
      }
      .items-table th {
        background: #f0ebe4;
        font-weight: bold;
      }
      .right {
        text-align: right;
        white-space: nowrap;
      }
      .center {
        text-align: center;
      }
      .item-title {
        font-weight: 600;
      }
      .item-desc {
        color: #6f665c;
        margin-top: 2px;
        font-size: 11px;
      }
      .empty {
        text-align: center;
        color: #6f665c;
        padding: 16px 0;
      }
      .totals-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 16px;
      }
      .totals-table .spacer {
        width: 65%;
      }
      .totals-inner {
        width: 100%;
        border-collapse: collapse;
      }
      .totals-inner td {
        padding: 4px 0;
        font-size: 11px;
      }
      .totals-inner .label {
        color: #6f665c;
      }
      .totals-inner .value {
        text-align: right;
        font-weight: 600;
      }
      .totals-inner .balance {
        color: #0a7b50;
      }
      .payments-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 8px;
      }
      .payments-table th,
      .payments-table td {
        padding: 6px;
        border-bottom: 1px solid #e7ded4;
        font-size: 11px;
      }
    </style>
  </head>
  <body>
    @php
      $companyName = $company?->company_name ?: config('app.name');
      $companyLogo = $company?->company_logo_url;
      $customerLabel = $customer?->company_name
        ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));
      $customerLabel = $customerLabel ?: 'Customer';
      $contactName = trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));
      $contactName = $contactName ?: ($customer?->company_name ?: 'Customer');
      $companyEmail = $company?->company_email ?? $company?->email;
      $formatMoney = function ($value) {
          return '$' . number_format((float) $value, 2);
      };
      $formatShortDate = function ($value) {
          if (!$value) {
              return '-';
          }
          try {
              return \Carbon\Carbon::parse($value)->format('M d, Y');
          } catch (\Exception $e) {
              return '-';
          }
      };
      $formatTimeRange = function ($start, $end) {
          $startLabel = $start ? substr((string) $start, 0, 5) : '';
          $endLabel = $end ? substr((string) $end, 0, 5) : '';
          if (!$startLabel && !$endLabel) {
              return '-';
          }
          if (!$endLabel) {
              return $startLabel;
          }
          return $startLabel . ' - ' . $endLabel;
      };
      $invoiceNumber = $invoice->number ?? $invoice->id;
      $issuedAt = $invoice->created_at ? $invoice->created_at->format('M d, Y') : '';
      $status = $invoice->status ?? 'draft';
      $statusLabel = ucwords(str_replace('_', ' ', $status));
      $statusClass = 'status-default';
      switch ($status) {
          case 'draft':
              $statusClass = 'status-draft';
              break;
          case 'sent':
          case 'awaiting_acceptance':
          case 'accepted':
              $statusClass = 'status-sent';
              break;
          case 'partial':
              $statusClass = 'status-partial';
              break;
          case 'paid':
              $statusClass = 'status-paid';
              break;
          case 'overdue':
              $statusClass = 'status-overdue';
              break;
          case 'void':
          case 'rejected':
              $statusClass = 'status-void';
              break;
      }
      $jobTitle = $work?->job_title;
      $property = $work?->quote?->property;
      if (!$property && $customer && $customer->relationLoaded('properties')) {
          $property = $customer->properties->firstWhere('is_default', true) ?? $customer->properties->first();
      }
      $propertyLines = [];
      if ($property) {
          $propertyLines = array_filter([
              $property->street1 ?? null,
              $property->street2 ?? null,
              trim(($property->city ?? '') . ' ' . ($property->state ?? '') . ' ' . ($property->zip ?? '')),
              $property->country ?? null,
          ]);
      }
    @endphp

    <table class="header-table">
      <tr>
        <td class="header-left">
          <table class="company-table">
            <tr>
              @if(!empty($companyLogo))
                <td style="width:60px; vertical-align: top;">
                  <img src="{{ $companyLogo }}" alt="Logo" class="logo">
                </td>
              @endif
              <td>
                <div class="company-name">{{ $companyName }}</div>
                @if(!empty($companyEmail))
                  <div class="meta">{{ $companyEmail }}</div>
                @endif
                <div class="title">Invoice For {{ $customerLabel }}</div>
                @if(!empty($jobTitle))
                  <div class="meta">{{ $jobTitle }}</div>
                @endif
              </td>
            </tr>
          </table>
        </td>
        <td class="header-right">
          <div class="status-badge {{ $statusClass }}">{{ $statusLabel }}</div>
          <div class="invoice-number">#{{ $invoiceNumber }}</div>
          @if($issuedAt)
            <div class="meta">Issued {{ $issuedAt }}</div>
          @endif
          <div class="meta">Balance {{ $formatMoney($invoice->balance_due) }}</div>
        </td>
      </tr>
    </table>

    <table class="info-grid">
      <tr>
        <td class="info-main">
          <table class="inner-grid">
            <tr>
              <td class="info-cell">
                <div class="section-title">Property address</div>
                @if(!empty($propertyLines))
                  @foreach($propertyLines as $line)
                    <div class="meta">{{ $line }}</div>
                  @endforeach
                @else
                  <div class="meta">No property selected.</div>
                @endif
              </td>
              <td class="info-cell">
                <div class="section-title">Contact details</div>
                <div class="meta">{{ $contactName }}</div>
                @if(!empty($customer?->email))
                  <div class="meta">{{ $customer->email }}</div>
                @endif
                @if(!empty($customer?->phone))
                  <div class="meta">{{ $customer->phone }}</div>
                @endif
              </td>
            </tr>
          </table>
        </td>
        <td class="info-side">
          <div class="card">
            <div class="section-title">Invoice details</div>
            <div class="meta-row">
              <span>Invoice</span>
              <span>#{{ $invoiceNumber }}</span>
            </div>
            <div class="meta-row">
              <span>Status</span>
              <span>{{ $statusLabel }}</span>
            </div>
            <div class="meta-row">
              <span>Issued</span>
              <span>{{ $issuedAt ?: '-' }}</span>
            </div>
            <div class="meta-row">
              <span>Total</span>
              <span>{{ $formatMoney($invoice->total) }}</span>
            </div>
          </div>
        </td>
      </tr>
    </table>

    <div class="section-title">Line items</div>
    <table class="items-table">
      <thead>
        @if($isTaskBased)
          <tr>
            <th>Task</th>
            <th class="center">Date</th>
            <th class="center">Time</th>
            <th class="center">Assignee</th>
            <th class="right">Total</th>
          </tr>
        @else
          <tr>
            <th>Product/Services</th>
            <th class="right">Qty</th>
            <th class="right">Unit cost</th>
            <th class="right">Total</th>
          </tr>
        @endif
      </thead>
      <tbody>
        @if($isTaskBased)
          @forelse($taskItems as $item)
            <tr>
              <td class="item-title">{{ $item['title'] }}</td>
              <td class="center">{{ $formatShortDate($item['scheduled_date']) }}</td>
              <td class="center">{{ $formatTimeRange($item['start_time'], $item['end_time']) }}</td>
              <td class="center">{{ $item['assignee_name'] ?: '-' }}</td>
              <td class="right">{{ $formatMoney($item['total']) }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="5" class="empty">No line items.</td>
            </tr>
          @endforelse
        @else
          @forelse($productItems as $item)
            <tr>
              <td>
                <div class="item-title">{{ $item['title'] }}</div>
                @if(!empty($item['description']))
                  <div class="item-desc">{{ $item['description'] }}</div>
                @endif
              </td>
              <td class="right">{{ $item['quantity'] }}</td>
              <td class="right">{{ $formatMoney($item['unit_price']) }}</td>
              <td class="right">{{ $formatMoney($item['total']) }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="empty">No line items.</td>
            </tr>
          @endforelse
        @endif
      </tbody>
    </table>

    <table class="totals-table">
      <tr>
        <td class="spacer"></td>
        <td>
          <table class="totals-inner">
            <tr>
              <td class="label">Subtotal</td>
              <td class="value">{{ $formatMoney($subtotal) }}</td>
            </tr>
            <tr>
              <td class="label">Paid</td>
              <td class="value">{{ $formatMoney($totalPaid) }}</td>
            </tr>
            <tr>
              <td class="label">Total</td>
              <td class="value">{{ $formatMoney($invoice->total) }}</td>
            </tr>
            <tr>
              <td class="label balance">Balance due</td>
              <td class="value balance">{{ $formatMoney($invoice->balance_due) }}</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>

    <div class="section-title" style="margin-top: 18px;">Payments</div>
    @if($invoice->payments->isNotEmpty())
      <table class="payments-table">
        <thead>
          <tr>
            <th>Amount</th>
            <th>Method</th>
            <th>Date</th>
            <th class="right">Status</th>
          </tr>
        </thead>
        <tbody>
          @foreach($invoice->payments as $payment)
            <tr>
              <td>{{ $formatMoney($payment->amount) }}</td>
              <td>{{ $payment->method ?: '-' }}</td>
              <td>{{ $formatShortDate($payment->paid_at) }}</td>
              <td class="right">{{ $payment->status ?: '-' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <div class="meta">No payments yet.</div>
    @endif
  </body>
</html>
