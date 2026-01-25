<!doctype html>
<html lang="fr">
  <head>
    <meta charset="utf-8">
    <title>Recu de vente</title>
    <style>
      @page {
        size: 80mm 200mm;
        margin: 6mm;
      }
      body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 10px;
        color: #1c1917;
        margin: 0;
        padding: 0;
      }
      .center {
        text-align: center;
      }
      .muted {
        color: #78716c;
      }
      .title {
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 2px;
      }
      .divider {
        border-top: 1px dashed #d6d3d1;
        margin: 8px 0;
      }
      .full {
        width: 100%;
        border-collapse: collapse;
      }
      .items th,
      .items td {
        padding: 4px 0;
        vertical-align: top;
      }
      .items th {
        font-weight: 600;
        color: #44403c;
        border-bottom: 1px solid #e7e5e4;
      }
      .items td {
        border-bottom: 1px dashed #e7e5e4;
      }
      .right {
        text-align: right;
        white-space: nowrap;
      }
      .summary td {
        padding: 3px 0;
      }
      .summary .label {
        color: #78716c;
      }
      .summary .value {
        text-align: right;
        font-weight: 600;
      }
      .badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 999px;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }
      .badge-paid {
        background: #dcfce7;
        color: #166534;
      }
      .badge-pending {
        background: #fef3c7;
        color: #92400e;
      }
      .badge-canceled {
        background: #ffe4e6;
        color: #9f1239;
      }
      .payment-row {
        border-bottom: 1px dashed #e7e5e4;
        padding: 4px 0;
      }
    </style>
  </head>
  <body>
    @php
      $companyName = $company?->company_name ?: config('app.name');
      $customerLabel = $customer?->company_name
        ?: trim(($customer?->first_name ?? '') . ' ' . ($customer?->last_name ?? ''));
      $customerLabel = $customerLabel ?: 'Client';
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
              return \Carbon\Carbon::parse($value)->format('d/m/Y H:i');
          } catch (\Exception $e) {
              return '-';
          }
      };
      $orderNumber = $sale->number ?? $sale->id;
      $status = $sale->payment_status ?? $sale->status ?? 'pending';
      $statusClass = $status === 'paid'
        ? 'badge-paid'
        : ($status === 'canceled' ? 'badge-canceled' : 'badge-pending');
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
      $totalPaid = (float) ($totalPaid ?? 0);
      $depositAmount = (float) ($depositAmount ?? 0);
      $balanceDue = max(0, (float) ($sale->total ?? 0) - $totalPaid);
    @endphp

    <div class="center">
      <div class="title">{{ $companyName }}</div>
      <div class="muted">Recu de vente {{ $orderNumber }}</div>
      <div class="muted">{{ $formatShortDate($sale->created_at) }}</div>
      <div style="margin-top: 4px;">
        <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
      </div>
    </div>

    <div class="divider"></div>

    <div>
      <div class="muted">Client</div>
      <div>{{ $customerLabel }}</div>
    </div>

    <div class="divider"></div>

    <table class="full items">
      <thead>
        <tr>
          <th>Produit</th>
          <th class="right">Qt.</th>
          <th class="right">Total</th>
        </tr>
      </thead>
      <tbody>
        @forelse($items as $item)
          <tr>
            <td>
              {{ $item['title'] }}
              <div class="muted">{{ $formatMoney($item['unit_price']) }} x {{ $item['quantity'] }}</div>
            </td>
            <td class="right">{{ $item['quantity'] }}</td>
            <td class="right">{{ $formatMoney($item['total']) }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="3" class="muted">Aucun element.</td>
          </tr>
        @endforelse
      </tbody>
    </table>

    <div class="divider"></div>

    <table class="full summary">
      <tr>
        <td class="label">Sous-total</td>
        <td class="value">{{ $formatMoney($sale->subtotal) }}</td>
      </tr>
      <tr>
        <td class="label">Taxes</td>
        <td class="value">{{ $formatMoney($sale->tax_total) }}</td>
      </tr>
      @if((float) $sale->discount_total > 0)
        <tr>
          <td class="label">Remise</td>
          <td class="value">- {{ $formatMoney($sale->discount_total) }}</td>
        </tr>
      @endif
      @if((float) $sale->delivery_fee > 0)
        <tr>
          <td class="label">Livraison</td>
          <td class="value">{{ $formatMoney($sale->delivery_fee) }}</td>
        </tr>
      @endif
      <tr>
        <td class="label"><strong>Total</strong></td>
        <td class="value"><strong>{{ $formatMoney($sale->total) }}</strong></td>
      </tr>
      @if($depositAmount > 0)
        <tr>
          <td class="label">Acompte requis</td>
          <td class="value">{{ $formatMoney($depositAmount) }}</td>
        </tr>
      @endif
      <tr>
        <td class="label">Paye</td>
        <td class="value">{{ $formatMoney($totalPaid) }}</td>
      </tr>
      <tr>
        <td class="label">Solde du</td>
        <td class="value">{{ $formatMoney($balanceDue) }}</td>
      </tr>
    </table>

    @if($payments && $payments->count())
      <div class="divider"></div>
      <div class="muted" style="margin-bottom: 4px;">Paiements</div>
      @foreach($payments as $payment)
        <div class="payment-row">
          <div>{{ $formatMoney($payment->amount) }} - {{ $payment->method ?: '-' }}</div>
          <div class="muted">{{ $formatShortDate($payment->paid_at) }}</div>
        </div>
      @endforeach
    @endif
  </body>
</html>
