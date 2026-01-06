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
      .header {
        display: table;
        width: 100%;
        margin-bottom: 24px;
      }
      .header-left,
      .header-right {
        display: table-cell;
        vertical-align: top;
      }
      .header-right {
        text-align: right;
      }
      .company-name {
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 4px;
      }
      .meta {
        color: #6f665c;
      }
      .section {
        margin-bottom: 16px;
      }
      .section-title {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #6f665c;
        margin-bottom: 6px;
      }
      table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
      }
      th,
      td {
        padding: 8px 6px;
        border-bottom: 1px solid #e7ded4;
        text-align: left;
        vertical-align: top;
      }
      th {
        background: #f0ebe4;
        font-weight: bold;
      }
      .qty,
      .price,
      .total {
        text-align: right;
        white-space: nowrap;
      }
      .item-title {
        font-weight: 600;
      }
      .item-desc {
        color: #6f665c;
        margin-top: 2px;
      }
      .empty {
        text-align: center;
        color: #6f665c;
        padding: 16px 0;
      }
      .totals {
        margin-top: 18px;
        width: 100%;
        display: table;
      }
      .totals-row {
        display: table-row;
      }
      .totals-row span {
        display: table-cell;
        padding: 4px 0;
      }
      .totals-row span:last-child {
        text-align: right;
      }
      .totals-row.total span {
        font-weight: bold;
      }
      .totals-row.balance span {
        font-weight: bold;
        color: #0a7b50;
      }
    </style>
  </head>
  <body>
    @php
      $companyName = $company?->company_name ?: config('app.name');
      $customerLabel = $customer?->company_name
        ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));
      $customerLabel = $customerLabel ?: 'Customer';
      $companyEmail = $company?->company_email ?? $company?->email;
      $formatMoney = function ($value) {
          return '$' . number_format((float) $value, 2);
      };
      $invoiceNumber = $invoice->number ?? $invoice->id;
      $issuedAt = $invoice->created_at ? $invoice->created_at->format('M d, Y') : '';
    @endphp

    <div class="header">
      <div class="header-left">
        <div class="company-name">{{ $companyName }}</div>
        @if(!empty($companyEmail))
          <div class="meta">{{ $companyEmail }}</div>
        @endif
      </div>
      <div class="header-right">
        <div class="company-name">Invoice</div>
        <div class="meta">#{{ $invoiceNumber }}</div>
        @if($issuedAt)
          <div class="meta">Date: {{ $issuedAt }}</div>
        @endif
      </div>
    </div>

    <div class="section">
      <div class="section-title">Bill To</div>
      <div>{{ $customerLabel }}</div>
      @if(!empty($customer?->email))
        <div class="meta">{{ $customer->email }}</div>
      @endif
    </div>

    @if(!empty($work?->job_title))
      <div class="section">
        <div class="section-title">Job</div>
        <div>{{ $work->job_title }}</div>
      </div>
    @endif

    <table>
      <thead>
        <tr>
          <th>Description</th>
          <th class="qty">Qty</th>
          <th class="price">Unit</th>
          <th class="total">Total</th>
        </tr>
      </thead>
      <tbody>
        @forelse($lineItems as $item)
          <tr>
            <td>
              <div class="item-title">{{ $item['title'] }}</div>
              @if(!empty($item['description']))
                <div class="item-desc">{{ $item['description'] }}</div>
              @endif
            </td>
            <td class="qty">{{ $item['quantity'] }}</td>
            <td class="price">{{ $formatMoney($item['unit_price']) }}</td>
            <td class="total">{{ $formatMoney($item['total']) }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="4" class="empty">No line items.</td>
          </tr>
        @endforelse
      </tbody>
    </table>

    <div class="totals">
      <div class="totals-row">
        <span>Subtotal</span>
        <span>{{ $formatMoney($subtotal) }}</span>
      </div>
      <div class="totals-row">
        <span>Paid</span>
        <span>{{ $formatMoney($totalPaid) }}</span>
      </div>
      <div class="totals-row total">
        <span>Total</span>
        <span>{{ $formatMoney($invoice->total) }}</span>
      </div>
      <div class="totals-row balance">
        <span>Balance Due</span>
        <span>{{ $formatMoney($invoice->balance_due) }}</span>
      </div>
    </div>
  </body>
</html>
