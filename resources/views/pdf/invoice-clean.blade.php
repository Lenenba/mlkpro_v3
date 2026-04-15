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
        margin: 28px;
      }
      body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 11px;
        color: #2d2d2d;
        margin: 0;
        padding: 0;
        background: #ffffff;
      }
      .page {
        width: 100%;
      }
      .top-rule {
        height: 8px;
        background: #00c875;
        margin-bottom: 22px;
        border-radius: var(--malikia-radius);
      }
      .hero,
      .two-col,
      .summary-wrap {
        width: 100%;
        border-collapse: collapse;
      }
      .hero td,
      .two-col td,
      .summary-wrap td {
        vertical-align: top;
      }
      .hero-left {
        width: 58%;
      }
      .hero-right {
        width: 42%;
        text-align: right;
      }
      .logo {
        width: 44px;
        height: 44px;
        object-fit: cover;
        border-radius: var(--malikia-radius);
        border: 1px solid #dcdcdc;
      }
      .eyebrow {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: #00c875;
        margin-bottom: 6px;
      }
      .brand-name {
        font-size: 24px;
        font-weight: 700;
        color: #2d2d2d;
        margin: 0 0 4px;
      }
      .brand-copy,
      .muted {
        color: #626262;
        line-height: 1.6;
      }
      .invoice-title {
        font-size: 34px;
        font-weight: 700;
        color: #2d2d2d;
        margin: 0 0 8px;
      }
      .invoice-badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: var(--malikia-radius);
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin-bottom: 10px;
      }
      .status-draft { background: #ececec; color: #555555; }
      .status-sent { background: #dff7ea; color: #0f7a4a; }
      .status-partial { background: #fff2d8; color: #8a5a00; }
      .status-paid { background: #dff7ea; color: #0f7a4a; }
      .status-overdue, .status-void { background: #ffe3e3; color: #b42318; }
      .status-default { background: #ececec; color: #555555; }
      .box {
        border: 1px solid #dfdfdf;
        border-radius: var(--malikia-radius);
        padding: 16px;
        background: #ffffff;
      }
      .box-title {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #00c875;
        margin-bottom: 8px;
      }
      .box-strong {
        font-size: 15px;
        font-weight: 700;
        color: #2d2d2d;
        margin-bottom: 6px;
      }
      .section {
        margin-top: 18px;
      }
      .table-card {
        margin-top: 22px;
        border: 1px solid #dfdfdf;
        border-radius: var(--malikia-radius);
        overflow: hidden;
      }
      .table-card-header {
        padding: 14px 16px;
        border-bottom: 1px solid #dfdfdf;
        background: #fafafa;
      }
      .table-card-title {
        font-size: 15px;
        font-weight: 700;
        color: #2d2d2d;
        margin: 0 0 4px;
      }
      .table-card-copy {
        font-size: 10px;
        color: #626262;
        margin: 0;
      }
      .items-table,
      .mini-table,
      .payments-table {
        width: 100%;
        border-collapse: collapse;
      }
      .items-table th,
      .items-table td,
      .payments-table th,
      .payments-table td {
        padding: 12px 14px;
        border-bottom: 1px solid var(--table-divider);
      }
      .items-table th,
      .payments-table th {
        text-align: left;
        background: var(--table-head);
        color: var(--table-head-text);
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }
      .items-table tbody tr:nth-child(odd) td,
      .payments-table tbody tr:nth-child(odd) td {
        background: #ffffff;
      }
      .items-table tbody tr:nth-child(even) td,
      .payments-table tbody tr:nth-child(even) td {
        background: var(--table-stripe);
      }
      .items-table tbody tr:last-child td,
      .payments-table tbody tr:last-child td {
        border-bottom: none;
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
        color: #2d2d2d;
        margin-bottom: 4px;
      }
      .item-copy {
        font-size: 10px;
        color: #6e6e6e;
        line-height: 1.6;
      }
      .totals-card {
        border: 1px solid #dfdfdf;
        border-top: 4px solid #00c875;
        border-radius: var(--malikia-radius);
        padding: 16px;
        background: #ffffff;
      }
      .totals-card td {
        padding: 7px 0;
      }
      .totals-label {
        color: #626262;
      }
      .totals-value {
        text-align: right;
        color: #2d2d2d;
        font-weight: 700;
      }
      .divider td {
        border-top: 1px solid #e7e7e7;
      }
      .due {
        margin-top: 14px;
        padding-top: 14px;
        border-top: 1px solid #e7e7e7;
      }
      .due-label {
        color: #00c875;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
      }
      .due-amount {
        font-size: 28px;
        font-weight: 700;
        color: #2d2d2d;
        margin-top: 6px;
      }
      .footer-copy {
        margin-top: 12px;
        font-size: 10px;
        color: #6e6e6e;
        line-height: 1.7;
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
      <div class="top-rule"></div>

      <table class="hero">
        <tr>
          <td class="hero-left">
            @if(! empty($companyLogoUrl))
              <div style="margin-bottom: 10px;">
                <img src="{{ $companyLogoUrl }}" alt="Logo" class="logo">
              </div>
            @endif
            <div class="eyebrow">{{ $companyName }}</div>
            <div class="brand-name">{{ $companyName }}</div>
            <div class="brand-copy">
              @if(! empty($company?->email)){{ $company->email }}<br>@endif
              @if(! empty($company?->phone_number)){{ $company->phone_number }}<br>@endif
              @if(! empty($companyLocation)){{ $companyLocation }}@endif
            </div>
          </td>
          <td class="hero-right">
            @if($displayStatusLabel)
              <span class="invoice-badge {{ $statusClass }}">{{ $displayStatusLabel }}</span>
            @endif
            <div class="invoice-title">Invoice</div>
            <div class="muted">Invoice no: {{ $invoiceNumber }}</div>
            <div class="muted">Issue date: {{ $formatDate($invoice->created_at) }}</div>
            <div class="muted">Currency: {{ $currencyCode }}</div>
          </td>
        </tr>
      </table>

      <table class="two-col section">
        <tr>
          <td style="width: 50%; padding-right: 10px;">
            <div class="box">
              <div class="box-title">Invoice to</div>
              <div class="box-strong">{{ $customerLabel }}</div>
              <div class="muted">{{ $contactName }}</div>
              <div class="muted">{{ $contactEmail }}</div>
              <div class="muted">{{ $contactPhone }}</div>
            </div>
          </td>
          <td style="width: 50%; padding-left: 10px;">
            <div class="box">
              <div class="box-title">Service context</div>
              <div class="box-strong">{{ $jobTitle }}</div>
              @if($work?->number)
                <div class="muted">Job #{{ $work->number }}</div>
              @endif
              @forelse($propertyLines as $line)
                <div class="muted">{{ $line }}</div>
              @empty
                <div class="muted">No property details available.</div>
              @endforelse
            </div>
          </td>
        </tr>
      </table>

      <div class="table-card">
        <div class="table-card-header">
          <div class="table-card-title">Invoice details</div>
          <p class="table-card-copy">Selected line items and billing amounts included in this client invoice.</p>
        </div>

        <table class="items-table">
          <thead>
            @if($isTaskBased)
              <tr>
                <th style="width: 46%;">Description</th>
                <th class="text-center" style="width: 16%;">Date</th>
                <th class="text-center" style="width: 16%;">Time</th>
                <th class="text-center" style="width: 10%;">Assigned</th>
                <th class="text-right" style="width: 12%;">Total</th>
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
                    <div class="item-copy">{{ $jobTitle }}</div>
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

      <table class="summary-wrap section">
        <tr>
          <td style="width: 52%; padding-right: 10px;">
            <div class="box">
              <div class="box-title">Payment method</div>
              <div class="muted">
                Bank transfer or your preferred approved payment method.
              </div>
              <div class="footer-copy">
                Thank you for your business. Unless otherwise agreed in writing, payment is expected on receipt of this invoice.
              </div>
            </div>

            @if($paymentRows->isNotEmpty())
              <div class="box section">
                <div class="box-title">Payment history</div>
                <table class="payments-table">
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
          <td style="width: 48%; padding-left: 10px;">
            <div class="totals-card">
              <div class="box-title">Summary</div>
              <table class="mini-table">
                <tr>
                  <td class="totals-label">Sub total</td>
                  <td class="totals-value">{{ $formatMoney($invoiceSubtotal) }}</td>
                </tr>
                @if($taxOrAdjustments > 0)
                  <tr>
                    <td class="totals-label">Taxes / adjustments</td>
                    <td class="totals-value">{{ $formatMoney($taxOrAdjustments) }}</td>
                  </tr>
                @endif
                <tr class="divider">
                  <td class="totals-label">Paid</td>
                  <td class="totals-value">{{ $formatMoney($totalPaid) }}</td>
                </tr>
                <tr class="divider">
                  <td class="totals-label">Grand total</td>
                  <td class="totals-value">{{ $formatMoney($invoiceTotal) }}</td>
                </tr>
              </table>

              <div class="due">
                <div class="due-label">Amount due</div>
                <div class="due-amount">{{ $formatMoney($balanceDue) }}</div>
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
