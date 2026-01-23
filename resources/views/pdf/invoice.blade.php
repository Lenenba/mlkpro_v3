<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <title>Facture</title>
    <style>
      body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 12px;
        color: #1c1917;
        margin: 0;
        padding: 24px;
      }
      .full {
        width: 100%;
        border-collapse: collapse;
      }
      .panel {
        border: 1px solid #f5f5f4;
        background: #f5f5f4;
        padding: 16px;
        border-radius: 3px;
      }
      .card {
        border: 1px solid #f5f5f4;
        background: #ffffff;
        padding: 12px;
        border-radius: 3px;
      }
      .card-strong {
        border-color: #e7e5e4;
      }
      .section {
        margin-top: 16px;
      }
      .logo {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border: 1px solid #e7e5e4;
        border-radius: 3px;
      }
      .company-label {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #78716c;
      }
      .title {
        font-size: 16px;
        font-weight: 600;
        color: #292524;
        margin-top: 2px;
      }
      .subtitle {
        font-size: 12px;
        color: #57534e;
      }
      .btn {
        display: inline-block;
        padding: 4px 10px;
        border: 1px solid #e7e5e4;
        background: #ffffff;
        color: #44403c;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 600;
        text-align: center;
        white-space: nowrap;
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
        background: #f5f5f4;
        color: #57534e;
      }
      .status-sent {
        background: #e0f2fe;
        color: #0369a1;
      }
      .status-partial {
        background: #fef3c7;
        color: #92400e;
      }
      .status-paid {
        background: #dcfce7;
        color: #166534;
      }
      .status-overdue,
      .status-void {
        background: #ffe4e6;
        color: #9f1239;
      }
      .status-default {
        background: #f1f5f9;
        color: #475569;
      }
      .muted {
        color: #57534e;
        font-size: 10px;
      }
      .label {
        font-size: 12px;
        color: #1c1917;
        margin-bottom: 4px;
      }
      .meta-row {
        width: 100%;
        margin-top: 4px;
      }
      .meta-row td {
        font-size: 10px;
        color: #57534e;
      }
      .meta-row td:last-child {
        text-align: right;
      }
      .info-cell {
        vertical-align: top;
        padding-right: 12px;
      }
      .items-table th,
      .items-table td {
        padding: 8px 6px;
        border-bottom: 1px solid #e7e5e4;
        text-align: left;
        vertical-align: top;
      }
      .items-table th {
        font-weight: 600;
        color: #292524;
        font-size: 12px;
      }
      .items-table td {
        font-size: 11px;
        color: #44403c;
      }
      .right {
        text-align: right;
        white-space: nowrap;
      }
      .center {
        text-align: center;
      }
      .summary-table td {
        padding: 8px 0;
        font-size: 11px;
      }
      .summary-table .label {
        color: #78716c;
      }
      .summary-table .value {
        text-align: right;
        font-weight: 600;
      }
      .summary-table .highlight {
        color: #16a34a;
      }
      .summary-divider td {
        border-top: 1px solid #e7e5e4;
      }
      .payment-row {
        border: 1px solid #f5f5f4;
        background: #f5f5f4;
        padding: 8px;
        border-radius: 3px;
        margin-bottom: 8px;
      }
      .input {
        border: 1px solid #e7e5e4;
        background: #f5f5f4;
        color: #78716c;
        padding: 8px 10px;
        border-radius: 3px;
        font-size: 10px;
        margin-bottom: 8px;
      }
      .input.textarea {
        height: 36px;
      }
      .btn-primary {
        display: inline-block;
        padding: 6px 10px;
        background: #16a34a;
        color: #ffffff;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 600;
      }
      .stars {
        color: #f59e0b;
        font-size: 10px;
      }
    </style>
  </head>
  <body>
    @php
      $companyName = $company?->company_name ?: config('app.name');
      $companyLogo = $company?->company_logo_url;
      $companyLogoUrl = null;
      if (!empty($companyLogo)) {
          $companyLogoUrl = str_starts_with($companyLogo, '/') ? url($companyLogo) : $companyLogo;
      }
      $customerLabel = $customer?->company_name
        ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));
      $customerLabel = $customerLabel ?: 'Client';
      $contactName = trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));
      $contactName = $contactName ?: ($customer?->company_name ?: '-');
      $contactEmail = $customer?->email ?: '-';
      $contactPhone = $customer?->phone ?: '-';
      $locale = strtolower((string) config('app.locale', 'fr'));
      $useComma = str_starts_with($locale, 'fr');
      $formatMoney = function ($value) use ($useComma) {
          $decimal = $useComma ? ',' : '.';
          $thousands = $useComma ? ' ' : ',';
          return '$' . number_format((float) $value, 2, $decimal, $thousands);
      };
      $formatShortDate = function ($value) {
          if (!$value) {
              return '-';
          }
          try {
              return \Carbon\Carbon::parse($value)->format('d/m/Y');
          } catch (\Exception $e) {
              return '-';
          }
      };
      $formatRelativeDate = function ($value) {
          if (!$value) {
              return '-';
          }
          try {
              return \Carbon\Carbon::parse($value)
                  ->locale(config('app.locale', 'fr'))
                  ->diffForHumans();
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
      $status = $invoice->status ?? 'draft';
      $statusLabelMap = [
          'draft' => 'Brouillon',
          'sent' => 'Envoye',
          'partial' => 'Partiel',
          'paid' => 'Payee',
          'overdue' => 'En retard',
          'void' => 'Annulee',
          'awaiting_acceptance' => 'En attente',
          'accepted' => 'Acceptee',
          'rejected' => 'Rejetee',
      ];
      $statusLabel = $statusLabelMap[$status] ?? $status;
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
      $jobTitle = $work?->job_title ?: 'Job';
      $property = $work?->quote?->property;
      if (!$property && $customer && $customer->relationLoaded('properties')) {
          $property = $customer->properties->firstWhere('is_default', true) ?? $customer->properties->first();
      }
      $propertyLines = [];
      if ($property) {
          if (!empty($property->country)) {
              $propertyLines[] = $property->country;
          }
          if (!empty($property->street1)) {
              $propertyLines[] = $property->street1;
          }
          $stateLine = trim(($property->state ?? '') . ' - ' . ($property->zip ?? ''));
          if ($stateLine !== '') {
              $propertyLines[] = $stateLine;
          }
      }
      $ratingValue = null;
      $ratingCount = 0;
      if ($work && $work->relationLoaded('ratings')) {
          $ratingCount = $work->ratings->count();
          if ($ratingCount > 0) {
              $ratingValue = round($work->ratings->avg('rating'), 1);
          }
      }
      $starHtml = '';
      if ($ratingValue !== null) {
          $filled = (int) round($ratingValue);
          for ($i = 1; $i <= 5; $i++) {
              $starHtml .= $i <= $filled ? '&#9733;' : '&#9734;';
          }
      }
      $invoiceSubtotal = $subtotal;
      if (!$isTaskBased && $work && $work->subtotal !== null) {
          $invoiceSubtotal = (float) $work->subtotal;
      }
      $totalPaid = (float) $invoice->amount_paid;
    @endphp

    <div class="panel">
      <table class="full">
        <tr>
          <td style="width: 70%; vertical-align: top;">
            <table class="full">
              <tr>
                @if(!empty($companyLogoUrl))
                  <td style="width: 50px; vertical-align: top; padding-right: 10px;">
                    <img src="{{ $companyLogoUrl }}" alt="Logo" class="logo">
                  </td>
                @endif
                <td>
                  <div class="company-label">{{ $companyName }}</div>
                  <div class="title">Facture pour {{ $customerLabel }}</div>
                  <div class="subtitle">{{ $jobTitle }}</div>
                </td>
              </tr>
            </table>
          </td>
          <td style="width: 30%; text-align: right; vertical-align: top;">
            <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
          </td>
        </tr>
      </table>

      <table class="full" style="margin-top: 12px;">
        <tr>
          <td style="width: 65%; vertical-align: top; padding-right: 12px;">
            <div class="card card-strong" style="margin-bottom: 12px;">{{ $jobTitle }}</div>
            <table class="full">
              <tr>
                <td class="info-cell">
                  <div class="label">Adresse du bien</div>
                  @if(!empty($propertyLines))
                    @foreach($propertyLines as $line)
                      <div class="muted">{{ $line }}</div>
                    @endforeach
                  @else
                    <div class="muted">Aucune propriete selectionnee.</div>
                  @endif
                </td>
                <td class="info-cell">
                  <div class="label">Coordonnees</div>
                  <div class="muted">{{ $contactName }}</div>
                  <div class="muted">{{ $contactEmail }}</div>
                  <div class="muted">{{ $contactPhone }}</div>
                </td>
              </tr>
            </table>
          </td>
          <td style="width: 35%; vertical-align: top;">
            <div class="card card-strong">
              <div class="label">Details de la facture</div>
              <table class="full">
                <tr class="meta-row">
                  <td>Facture:</td>
                  <td>{{ $invoiceNumber }}</td>
                </tr>
                <tr class="meta-row">
                  <td>Emise:</td>
                  <td>{{ $formatRelativeDate($invoice->created_at) }}</td>
                </tr>
                <tr class="meta-row">
                  <td>Solde du:</td>
                  <td>{{ $formatMoney($invoice->balance_due) }}</td>
                </tr>
                <tr class="meta-row">
                  <td>Note du job:</td>
                  <td>
                    @if($ratingValue !== null)
                      <span class="stars">{!! $starHtml !!}</span>
                      <span class="muted">{{ $ratingValue }} / 5 @if($ratingCount) ({{ $ratingCount }}) @endif</span>
                    @else
                      <span class="muted">Aucune note</span>
                    @endif
                  </td>
                </tr>
              </table>
            </div>
          </td>
        </tr>
      </table>
    </div>

    <div class="card section">
      <table class="full items-table">
        <thead>
          @if($isTaskBased)
            <tr>
              <th style="min-width: 160px;">Taches</th>
              <th class="center">Date</th>
              <th class="center">Heure</th>
              <th class="center">Assigne</th>
              <th class="right">Total</th>
            </tr>
          @else
            <tr>
              <th style="min-width: 160px;">Produits/Services</th>
              <th class="right">Qt.</th>
              <th class="right">Cout unitaire</th>
              <th class="right">Total</th>
            </tr>
          @endif
        </thead>
        <tbody>
          @if($isTaskBased)
            @forelse($taskItems as $item)
              <tr>
                <td>{{ $item['title'] }}</td>
                <td class="center">{{ $formatShortDate($item['scheduled_date']) }}</td>
                <td class="center">{{ $formatTimeRange($item['start_time'], $item['end_time']) }}</td>
                <td class="center">{{ $item['assignee_name'] ?: '-' }}</td>
                <td class="right">{{ $formatMoney($item['total']) }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="5" class="muted">Aucun element.</td>
              </tr>
            @endforelse
          @else
            @forelse($productItems as $item)
              <tr>
                <td>{{ $item['title'] }}</td>
                <td class="right">{{ $item['quantity'] }}</td>
                <td class="right">{{ $formatMoney($item['unit_price']) }}</td>
                <td class="right">{{ $formatMoney($item['total']) }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="4" class="muted">Aucun element.</td>
              </tr>
            @endforelse
          @endif
        </tbody>
      </table>
    </div>

    <div class="card section">
      <table class="full">
        <tr>
          <td style="width: 60%;"></td>
          <td style="width: 40%; border-left: 1px solid #e7e5e4; padding-left: 12px;">
            <table class="full summary-table">
              <tr>
                <td class="label">Sous-total:</td>
                <td class="value highlight">{{ $formatMoney($invoiceSubtotal) }}</td>
              </tr>
              <tr class="summary-divider">
                <td class="label">Payee:</td>
                <td class="value">{{ $formatMoney($totalPaid) }}</td>
              </tr>
              <tr class="summary-divider">
                <td class="label"><strong>Montant total:</strong></td>
                <td class="value"><strong>{{ $formatMoney($invoice->total) }}</strong></td>
              </tr>
              <tr class="summary-divider">
                <td class="label">Solde du:</td>
                <td class="value">{{ $formatMoney($invoice->balance_due) }}</td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </div>

    @if($invoice->payments->isNotEmpty())
      <div class="card section">
        <div class="label" style="font-weight: 600;">Paiements</div>
        @foreach($invoice->payments as $payment)
          <div class="payment-row">
            <table class="full">
              <tr>
                <td>
                  <div style="font-size: 11px; color: #44403c;">
                    {{ $formatMoney($payment->amount) }} - {{ $payment->method ?: '-' }}
                  </div>
                  <div class="muted">{{ $formatRelativeDate($payment->paid_at) }}</div>
                </td>
                <td class="right muted">{{ $payment->status ?: '-' }}</td>
              </tr>
            </table>
          </div>
        @endforeach
      </div>
    @endif
  </body>
</html>
