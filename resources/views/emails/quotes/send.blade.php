<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote Email</title>
    <style>
        /* Ajout des styles CSS inline-friendly */
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #ffffff;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .header {
            background-color: #f9fafb;
            padding: 20px;
            border-bottom: 1px solid #ddd;
        }
        .header h1 {
            font-size: 20px;
            margin: 0;
            color: #333333;
        }
        .details {
            margin: 20px 0;
            display: flex;
            justify-content: space-between;
        }
        .details div {
            font-size: 14px;
            color: #666666;
        }
        .quote-summary {
            background-color: #f9fafb;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .quote-summary p {
            margin: 5px 0;
            font-size: 14px;
        }
        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .products-table th, .products-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
            font-size: 14px;
        }
        .products-table th {
            background-color: #f9fafb;
            font-weight: bold;
        }
        .totals {
            margin-top: 20px;
        }
        .totals div {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }
        .totals .highlight {
            font-weight: bold;
            color: #333333;
        }
        .totals .total {
            font-size: 16px;
            font-weight: bold;
            color: #000000;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Section -->
        <div class="header">
            <h1>Quote For {{ $quote->customer->company_name }}</h1>
        </div>

        <!-- Quote Details -->
        <div class="details">
            <div>
                <p><strong>Property address:</strong></p>
                <p>{{ $quote->customer->properties[0]->country }}</p>
                <p>{{ $quote->customer->properties[0]->street1 }}</p>
                <p>{{ $quote->customer->properties[0]->state }} - {{ $quote->customer->properties[0]->zip }}</p>
            </div>
            <div>
                <p><strong>Contact details:</strong></p>
                <p>{{ $quote->customer->first_name }} {{ $quote->customer->last_name }}</p>
                <p>{{ $quote->customer->email }}</p>
                <p>{{ $quote->customer->phone }}</p>
            </div>
        </div>

        <!-- Quote Summary -->
        <div class="quote-summary">
            <p><strong>Quote Details:</strong></p>
            <p>Quote Number: {{ $quote->number }}</p>
            <p>Rate Opportunity: ⭐⭐⭐⭐</p>
        </div>

        <!-- Products Table -->
        <table class="products-table">
            <thead>
                <tr>
                    <th>Product/Services</th>
                    <th>Qty.</th>
                    <th>Unit Cost</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($quote->products as $product)
                <tr>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->pivot->quantity }}</td>
                    <td>${{ number_format($product->pivot->price, 2) }}</td>
                    <td>${{ number_format($product->pivot->total, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals Section -->
        <div class="totals">
            <div>
                <span>Subtotal:</span>
                <span>${{ number_format($quote->subtotal, 2) }}</span>
            </div>
            <div>
                <span>Discount (%):</span>
                <span>Add discount</span>
            </div>
            <div>
                <span>TPS (5%):</span>
                <span>${{ number_format($quote->subtotal * 0.05, 2) }}</span>
            </div>
            <div>
                <span>TVQ (9.975%):</span>
                <span>${{ number_format($quote->subtotal * 0.09975, 2) }}</span>
            </div>
            <div class="highlight">
                <span>Total Taxes:</span>
                <span>${{ number_format(($quote->subtotal * 0.05) + ($quote->subtotal * 0.09975), 2) }}</span>
            </div>
            <div class="total">
                <span>Total Amount:</span>
                <span>${{ number_format($quote->total, 2) }}</span>
            </div>
            <div>
                <span>Required Deposit (15%):</span>
                <span>${{ number_format($quote->total * 0.15, 2) }}</span>
            </div>
        </div>
        <!-- Footer -->
        <div class="footer">
            Thank you for choosing {{ config('app.name') }}!<br>
            <a href="{{ route('customer.quote.show', $quote->id) }}">View Quote Online</a>
        </div>
    </div>
</body>
</html>
