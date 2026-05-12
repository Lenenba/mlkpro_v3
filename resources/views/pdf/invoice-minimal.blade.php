<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <title>Facture</title>
    <style>
      :root {
        --malikia-radius: 2px;
        --table-stripe: #fafaf9;
        --table-head: #f5f5f4;
        --table-head-text: #57534e;
        --table-divider: #e7e5e4;
      }
      @page {
        margin: 30px;
      }
      body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 11px;
        color: #172033;
        margin: 0;
        padding: 0;
        background: #ffffff;
      }
      .page {
        width: 100%;
      }
      .accent {
        width: 92px;
        height: 5px;
        background: #00c875;
        margin-bottom: 18px;
        border-radius: var(--malikia-radius);
      }
      .header,
      .meta-table,
      .summary-table,
      .payment-table,
      .items-table {
        width: 100%;
        border-collapse: collapse;
      }
      .header td,
      .meta-table td,
      .summary-table td {
        vertical-align: top;
      }
      .brand-col {
        width: 54%;
      }
      .invoice-col {
        width: 46%;
        text-align: right;
      }
      .logo {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: var(--malikia-radius);
        margin-bottom: 10px;
      }
      .brand-name {
        font-size: 23px;
        font-weight: 700;
        letter-spacing: 0.02em;
        color: #111827;
        margin: 0 0 5px;
      }
      .muted {
        color: #5a6478;
        line-height: 1.6;
      }
      .eyebrow {
        text-transform: uppercase;
        letter-spacing: 0.12em;
        font-size: 9px;
        font-weight: 700;
        color: #00c875;
        margin-bottom: 5px;
      }
      .invoice-title {
        font-size: 34px;
        font-weight: 700;
        color: #111827;
        margin: 0 0 8px;
      }
      .status-pill {
        display: inline-block;
        padding: 5px 12px;
        border: 1px solid #d8dee9;
        border-radius: var(--malikia-radius);
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
        margin-bottom: 10px;
        color: #111827;
        background: #f8fafc;
      }
      .meta-block {
        margin-top: 22px;
        border-top: 1px solid #d9e0ea;
        border-bottom: 1px solid #d9e0ea;
      }
      .meta-cell {
        width: 50%;
        padding: 16px 0;
      }
      .meta-cell.left {
        padding-right: 16px;
        border-right: 1px solid #d9e0ea;
      }
      .meta-cell.right {
        padding-left: 16px;
      }
      .meta-title {
        text-transform: uppercase;
        letter-spacing: 0.12em;
        font-size: 9px;
        font-weight: 700;
        color: #00c875;
        margin-bottom: 8px;
      }
      .meta-name {
        font-size: 14px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 6px;
      }
      .items-wrap {
        margin-top: 22px;
      }
      .items-table thead th {
        text-align: left;
        padding: 11px 10px;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--table-head-text);
        border-bottom: 1px solid var(--table-divider);
        background: var(--table-head);
      }
      .items-table tbody td {
        padding: 13px 10px;
        border-bottom: 1px solid var(--table-divider);
        vertical-align: top;
      }
      .items-table tbody tr:nth-child(odd) td {
        background: #ffffff;
      }
      .items-table tbody tr:nth-child(even) td {
        background: var(--table-stripe);
      }
      .text-right {
        text-align: right;
        white-space: nowrap;
      }
      .text-center {
        text-align: center;
      }
      .item-title {
        font-size: 12px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 4px;
      }
      .item-copy {
        font-size: 10px;
        color: #5a6478;
        line-height: 1.6;
      }
      .summary-section {
        margin-top: 24px;
      }
      .summary-card {
        border: 1px solid #d9e0ea;
        padding: 16px 18px;
        border-radius: var(--malikia-radius);
      }
      .summary-heading {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        font-weight: 700;
        color: #00c875;
        margin-bottom: 10px;
      }
      .summary-table td {
        padding: 6px 0;
      }
      .summary-label {
        color: #5a6478;
      }
      .summary-value {
        text-align: right;
        font-weight: 700;
        color: #111827;
      }
      .divider td {
        border-top: 1px solid #d9e0ea;
        padding-top: 10px;
      }
      .amount-due {
        margin-top: 14px;
        padding-top: 14px;
        border-top: 1px solid #d9e0ea;
      }
      .amount-due-label {
        text-transform: uppercase;
        letter-spacing: 0.12em;
        font-size: 9px;
        font-weight: 700;
        color: #00c875;
      }
      .amount-due-value {
        font-size: 27px;
        font-weight: 700;
        color: #111827;
        margin-top: 4px;
      }
      .note-card {
        border: 1px solid #d9e0ea;
        padding: 16px 18px;
        border-radius: var(--malikia-radius);
      }
      .payment-table th,
      .payment-table td {
        padding: 9px 10px;
        border-bottom: 1px solid var(--table-divider);
      }
      .payment-table th {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        text-align: left;
        color: var(--table-head-text);
        background: var(--table-head);
      }
      .payment-table tbody tr:nth-child(odd) td {
        background: #ffffff;
      }
      .payment-table tbody tr:nth-child(even) td {
        background: var(--table-stripe);
      }
      .payment-table tbody tr:last-child td {
        border-bottom: none;
      }
      .footer-copy {
        margin-top: 12px;
        color: #5a6478;
        line-height: 1.7;
        font-size: 10px;
      }
    </style>
  </head>
  <body>
    @php
      $companyName = $company?->company_name ?: config('app.name');
      $companyLogo = $company?->company_logo_url;
      $companyLogoUrl = null;
      if (! empty($companyLogo)) {
          $companyLogoUrl = str_starts_with($companyLogo, '/') ? url($companyLogo) : $companyLogo;
      }

      $customerLabel = $customer?->company_name
        ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));
      $customerLabel = $customerLabel ?: 'Client';

      $contactName = trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));
      $contactName = $contactName ?: ($customer?->company_name ?: '-');
      $contactEmail = $customer?->email ?: '-';
      $contactPhone = $customer?->phone ?: '-';

      $companyLocationParts = array_values(array_filter([
          $company?->company_city,
          $company?->company_province,
          $company?->company_country,
      ]));
      $companyLocation = ! empty($companyLocationParts) ? implode(', ', $companyLocationParts) : null;

      $locale = strtolower((string) config('app.locale', 'fr'));
      $currencyCode = strtoupper((string) ($invoice->currency_code ?: $company?->businessCurrencyCode() ?: 'CAD'));
      $useComma = str_starts_with($locale, 'fr');
      $currencySymbols = [
          'CAD' => '$',
          'USD' => '$',
          'EUR' => '€',
          'GBP' => '£',
      ];
      $currencySymbol = $currencySymbols[$currencyCode] ?? $currencyCode.' ';

      $formatMoney = function ($value) use ($useComma, $currencySymbol, $currencyCode) {
          $decimal = $useComma ? ',' : '.';
          $thousands = $useComma ? ' ' : ',';
          $formatted = number_format((float) $value, 2, $decimal, $thousands);

          if (in_array($currencyCode, ['EUR'], true)) {
              return $formatted.' '.$currencySymbol;
          }

          return $currencySymbol.$formatted;
      };

      $formatDate = function ($value) {
          if (! $value) {
              return '-';
          }
          try {
              return \Carbon\Carbon::parse($value)->format('d/m/Y');
          } catch (\Exception $e) {
              return '-';
          }
      };

      $formatTimeRange = function ($start, $end) {
          $startLabel = $start ? substr((string) $start, 0, 5) : '';
          $endLabel = $end ? substr((string) $end, 0, 5) : '';
          if (! $startLabel && ! $endLabel) {
              return null;
          }
          if (! $endLabel) {
              return $startLabel;
          }

          return $startLabel.' - '.$endLabel;
      };

      $invoiceNumber = $invoice->number ?? $invoice->id;
      $status = $invoice->status ?? 'draft';
      $statusLabelMap = [
          'draft' => 'Brouillon',
          'sent' => 'Envoyee',
          'partial' => 'Paiement partiel',
          'paid' => 'Payee',
          'overdue' => 'En retard',
          'void' => 'Annulee',
          'awaiting_acceptance' => 'En attente',
          'accepted' => 'Acceptee',
          'rejected' => 'Rejetee',
      ];
      $statusLabel = $statusLabelMap[$status] ?? $status;
      $displayStatusLabel = $status === 'sent' ? null : $statusLabel;

      $jobTitle = $work?->job_title ?: 'Intervention';
      $property = $work?->quote?->property;
      if (! $property && $customer && $customer->relationLoaded('properties')) {
          $property = $customer->properties->firstWhere('is_default', true) ?? $customer->properties->first();
      }

      $propertyLines = [];
      if ($property) {
          foreach ([$property->street1, $property->street2] as $line) {
              if (! empty($line)) {
                  $propertyLines[] = $line;
              }
          }

          $cityLine = implode(', ', array_values(array_filter([
              $property->city ?? null,
              $property->state ?? null,
              $property->zip ?? null,
          ])));
          if ($cityLine !== '') {
              $propertyLines[] = $cityLine;
          }

          if (! empty($property->country)) {
              $propertyLines[] = $property->country;
          }
      }

      $invoiceSubtotal = $subtotal;
      if (! $isTaskBased && $work && $work->subtotal !== null) {
          $invoiceSubtotal = (float) $work->subtotal;
      }

      $invoiceTotal = (float) ($invoice->total ?? 0);
      $totalPaid = (float) $invoice->amount_paid;
      $balanceDue = (float) $invoice->balance_due;
      $taxOrAdjustments = round(max(0, $invoiceTotal - (float) $invoiceSubtotal), 2);
      $paymentRows = $invoice->payments
          ->whereIn('status', \App\Models\Payment::settledStatuses())
          ->sortByDesc('paid_at');
    @endphp

    <div class="page">
      <div class="accent"></div>

      <table class="header">
        <tr>
          <td class="brand-col">
            @if(! empty($companyLogoUrl))
              <img src="{{ $companyLogoUrl }}" alt="Logo" class="logo">
            @endif
            <div class="eyebrow">{{ $companyName }}</div>
            <div class="brand-name">Invoice</div>
            <div class="muted">
              @if(! empty($company?->email)){{ $company->email }}<br>@endif
              @if(! empty($company?->phone_number)){{ $company->phone_number }}<br>@endif
              @if(! empty($companyLocation)){{ $companyLocation }}@endif
            </div>
          </td>
          <td class="invoice-col">
            @if($displayStatusLabel)
              <span class="status-pill">{{ $displayStatusLabel }}</span>
            @endif
            <div class="invoice-title">{{ $invoiceNumber }}</div>
            <div class="muted">Invoice no. {{ $invoiceNumber }}</div>
            <div class="muted">Issue date {{ $formatDate($invoice->created_at) }}</div>
            <div class="muted">Currency {{ $currencyCode }}</div>
          </td>
        </tr>
      </table>

      <table class="meta-table meta-block">
        <tr>
          <td class="meta-cell left">
            <div class="meta-title">Invoice to</div>
            <div class="meta-name">{{ $customerLabel }}</div>
            <div class="muted">{{ $contactName }}</div>
            <div class="muted">{{ $contactEmail }}</div>
            <div class="muted">{{ $contactPhone }}</div>
          </td>
          <td class="meta-cell right">
            <div class="meta-title">Context</div>
            <div class="meta-name">{{ $jobTitle }}</div>
            @if($work?->number)
              <div class="muted">Job #{{ $work->number }}</div>
            @endif
            @forelse($propertyLines as $line)
              <div class="muted">{{ $line }}</div>
            @empty
              <div class="muted">No property details available.</div>
            @endforelse
          </td>
        </tr>
      </table>

      <div class="items-wrap">
        <table class="items-table">
          <thead>
            @if($isTaskBased)
              <tr>
                <th style="width: 42%;">Description</th>
                <th class="text-center" style="width: 16%;">Date</th>
                <th class="text-center" style="width: 16%;">Time</th>
                <th class="text-center" style="width: 12%;">Assigned</th>
                <th class="text-right" style="width: 14%;">Total</th>
              </tr>
            @else
              <tr>
                <th style="width: 52%;">Description</th>
                <th class="text-right" style="width: 12%;">Qty</th>
                <th class="text-right" style="width: 18%;">Price</th>
                <th class="text-right" style="width: 18%;">Total</th>
              </tr>
            @endif
          </thead>
          <tbody>
            @if($isTaskBased)
              @forelse($taskItems as $item)
                <tr>
                  <td>
                    <div class="item-title">{{ $item['title'] }}</div>
                    <div class="item-copy" style="white-space: pre-line;">{{ $item['description'] ?: $jobTitle }}</div>
                  </td>
                  <td class="text-center">{{ $formatDate($item['scheduled_date']) }}</td>
                  <td class="text-center">{{ $formatTimeRange($item['start_time'], $item['end_time']) ?: '-' }}</td>
                  <td class="text-center">{{ $item['assignee_name'] ?: '-' }}</td>
                  <td class="text-right">{{ $formatMoney($item['total']) }}</td>
                </tr>
              @empty
                <tr>
                  <td colspan="5">
                    <div class="item-copy">No billed items.</div>
                  </td>
                </tr>
              @endforelse
            @else
              @forelse($productItems as $item)
                <tr>
                  <td>
                    <div class="item-title">{{ $item['title'] }}</div>
                    @if(! empty($item['description']))
                      <div class="item-copy">{{ $item['description'] }}</div>
                    @endif
                  </td>
                  <td class="text-right">{{ rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.') }}</td>
                  <td class="text-right">{{ $formatMoney($item['unit_price']) }}</td>
                  <td class="text-right">{{ $formatMoney($item['total']) }}</td>
                </tr>
              @empty
                <tr>
                  <td colspan="4">
                    <div class="item-copy">No billed items.</div>
                  </td>
                </tr>
              @endforelse
            @endif
          </tbody>
        </table>
      </div>

      <table class="summary-section" style="width: 100%; border-collapse: collapse;">
        <tr>
          <td style="width: 50%; padding-right: 12px; vertical-align: top;">
            <div class="note-card">
              <div class="summary-heading">Payment note</div>
              <div class="muted">Bank transfer or your preferred approved payment method.</div>
              <div class="footer-copy">
                Thank you for your trust. Please use the invoice number as your payment reference and contact us if you need any clarification before payment.
              </div>
            </div>

            @if($paymentRows->isNotEmpty())
              <div class="note-card" style="margin-top: 14px;">
                <div class="summary-heading">Payment history</div>
                <table class="payment-table">
                  <thead>
                    <tr>
                      <th>Amount</th>
                      <th>Method</th>
                      <th>Date</th>
                      <th class="text-right">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    @foreach($paymentRows as $payment)
                      <tr>
                        <td>{{ $formatMoney($payment->amount) }}</td>
                        <td>{{ $payment->method ?: '-' }}</td>
                        <td>{{ $formatDate($payment->paid_at) }}</td>
                        <td class="text-right">{{ $payment->status ?: '-' }}</td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            @endif
          </td>
          <td style="width: 50%; padding-left: 12px; vertical-align: top;">
            <div class="summary-card">
              <div class="summary-heading">Summary</div>
              <table class="summary-table">
                <tr>
                  <td class="summary-label">Sub total</td>
                  <td class="summary-value">{{ $formatMoney($invoiceSubtotal) }}</td>
                </tr>
                @if($taxOrAdjustments > 0)
                  <tr>
                    <td class="summary-label">Taxes / adjustments</td>
                    <td class="summary-value">{{ $formatMoney($taxOrAdjustments) }}</td>
                  </tr>
                @endif
                <tr class="divider">
                  <td class="summary-label">Paid</td>
                  <td class="summary-value">{{ $formatMoney($totalPaid) }}</td>
                </tr>
                <tr class="divider">
                  <td class="summary-label">Grand total</td>
                  <td class="summary-value">{{ $formatMoney($invoiceTotal) }}</td>
                </tr>
              </table>

              <div class="amount-due">
                <div class="amount-due-label">Amount due</div>
                <div class="amount-due-value">{{ $formatMoney($balanceDue) }}</div>
                @if($displayStatusLabel)
                  <div class="muted">Current status: {{ $displayStatusLabel }}</div>
                @endif
              </div>
            </div>
          </td>
        </tr>
      </table>
    </div>
  </body>
</html>
