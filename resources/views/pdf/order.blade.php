<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <title>Commande</title>
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
      .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }
      .status-default {
        background: #f1f5f9;
        color: #475569;
      }
      .status-pending {
        background: #fef3c7;
        color: #92400e;
      }
      .status-paid {
        background: #dcfce7;
        color: #166534;
      }
      .status-canceled {
        background: #ffe4e6;
        color: #9f1239;
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
      .meta-row td {
        font-size: 10px;
        color: #57534e;
        padding: 2px 0;
      }
      .meta-row td:last-child {
        text-align: right;
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
      .summary-table td {
        padding: 6px 0;
        font-size: 11px;
      }
      .summary-table .label {
        color: #78716c;
      }
      .summary-table .value {
        text-align: right;
        font-weight: 600;
      }
      .summary-divider td {
        border-top: 1px solid #e7e5e4;
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
      $orderNumber = $sale->number ?? $sale->id;
      $status = $sale->payment_status ?? $sale->status ?? 'pending';
      $statusLabelMap = [
          'draft' => 'Brouillon',
          'pending' => 'En attente',
          'unpaid' => 'Non payee',
          'deposit_required' => 'Acompte requis',
          'partial' => 'Partiel',
          'paid' => 'Payee',
          'canceled' => 'Annulee',
      ];
      $statusLabel = $statusLabelMap[$status] ?? $status;
      $statusClass = 'status-default';
      switch ($status) {
          case 'paid':
              $statusClass = 'status-paid';
              break;
          case 'canceled':
              $statusClass = 'status-canceled';
              break;
          case 'pending':
          case 'unpaid':
          case 'deposit_required':
          case 'partial':
              $statusClass = 'status-pending';
              break;
      }
      $fulfillmentMap = [
          'delivery' => 'Livraison',
          'pickup' => 'Retrait',
      ];
      $fulfillmentLabel = $fulfillmentMap[$sale->fulfillment_method] ?? 'Commande';
      $totalPaid = (float) ($totalPaid ?? 0);
      $depositAmount = (float) ($depositAmount ?? 0);
      $balanceDue = max(0, (float) ($sale->total ?? 0) - $totalPaid);
      $depositDue = max(0, $depositAmount - $totalPaid);
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
                  <div class="title">Commande {{ $orderNumber }}</div>
                  <div class="subtitle">{{ $customerLabel }}</div>
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
          <td style="width: 60%; vertical-align: top; padding-right: 12px;">
            <div class="card" style="margin-bottom: 12px;">
              <div class="label">Coordonnees</div>
              <div class="muted">{{ $customerLabel }}</div>
              <div class="muted">{{ $contactEmail }}</div>
              <div class="muted">{{ $contactPhone }}</div>
            </div>
            @if($sale->fulfillment_method === 'delivery' && $sale->delivery_address)
              <div class="card">
                <div class="label">Adresse</div>
                <div class="muted">{{ $sale->delivery_address }}</div>
                @if($sale->delivery_notes)
                  <div class="muted">Notes: {{ $sale->delivery_notes }}</div>
                @endif
              </div>
            @endif
            @if($sale->fulfillment_method === 'pickup' && $sale->pickup_notes)
              <div class="card" style="margin-top: 8px;">
                <div class="label">Notes retrait</div>
                <div class="muted">{{ $sale->pickup_notes }}</div>
              </div>
            @endif
          </td>
          <td style="width: 40%; vertical-align: top;">
            <div class="card">
              <div class="label">Details commande</div>
              <table class="full">
                <tr class="meta-row">
                  <td>Commande:</td>
                  <td>{{ $orderNumber }}</td>
                </tr>
                <tr class="meta-row">
                  <td>Cree:</td>
                  <td>{{ $formatShortDate($sale->created_at) }}</td>
                </tr>
                <tr class="meta-row">
                  <td>Livraison:</td>
                  <td>{{ $fulfillmentLabel }}</td>
                </tr>
                @if($sale->scheduled_for)
                  <tr class="meta-row">
                    <td>Horaire:</td>
                    <td>{{ $formatShortDate($sale->scheduled_for) }}</td>
                  </tr>
                @endif
              </table>
            </div>
          </td>
        </tr>
      </table>
    </div>

    <div class="card section">
      <table class="full items-table">
        <thead>
          <tr>
            <th style="min-width: 160px;">Produit</th>
            <th class="right">Qt.</th>
            <th class="right">Prix</th>
            <th class="right">Total</th>
          </tr>
        </thead>
        <tbody>
          @forelse($items as $item)
            <tr>
              <td>
                {{ $item['title'] }}
                @if(!empty($item['sku']))
                  <div class="muted">SKU: {{ $item['sku'] }}</div>
                @endif
              </td>
              <td class="right">{{ $item['quantity'] }}</td>
              <td class="right">{{ $formatMoney($item['unit_price']) }}</td>
              <td class="right">{{ $formatMoney($item['total']) }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="muted">Aucun element.</td>
            </tr>
          @endforelse
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
                <td class="value">{{ $formatMoney($sale->subtotal) }}</td>
              </tr>
              <tr>
                <td class="label">Taxes:</td>
                <td class="value">{{ $formatMoney($sale->tax_total) }}</td>
              </tr>
              @if((float) $sale->discount_total > 0)
                <tr>
                  <td class="label">Remise:</td>
                  <td class="value">- {{ $formatMoney($sale->discount_total) }}</td>
                </tr>
              @endif
              @if((float) $sale->delivery_fee > 0)
                <tr>
                  <td class="label">Livraison:</td>
                  <td class="value">{{ $formatMoney($sale->delivery_fee) }}</td>
                </tr>
              @endif
              <tr class="summary-divider">
                <td class="label"><strong>Total:</strong></td>
                <td class="value"><strong>{{ $formatMoney($sale->total) }}</strong></td>
              </tr>
              @if($depositAmount > 0)
                <tr class="summary-divider">
                  <td class="label">Acompte requis:</td>
                  <td class="value">{{ $formatMoney($depositAmount) }}</td>
                </tr>
              @endif
              <tr class="summary-divider">
                <td class="label">Paye:</td>
                <td class="value">{{ $formatMoney($totalPaid) }}</td>
              </tr>
              <tr class="summary-divider">
                <td class="label">Solde du:</td>
                <td class="value">{{ $formatMoney($balanceDue) }}</td>
              </tr>
              @if($depositDue > 0)
                <tr class="summary-divider">
                  <td class="label">Acompte restant:</td>
                  <td class="value">{{ $formatMoney($depositDue) }}</td>
                </tr>
              @endif
            </table>
          </td>
        </tr>
      </table>
    </div>
  </body>
</html>
