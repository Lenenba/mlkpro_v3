@extends('emails.layouts.base')

@section('title', 'Quote for ' . ($quote->customer->company_name ?? 'Client'))

@section('content')
    @php
        $customer = $quote->customer;
        $property = $quote->property ?? ($customer->properties->first() ?? null);
        $contactName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
        $contactLabel = $contactName !== '' ? $contactName : ($customer->company_name ?? 'Client');
    @endphp

    <div class="mx-auto w-full max-w-6xl space-y-5">
        <div class="p-5 space-y-3 flex flex-col bg-gray-100 border border-gray-100 rounded-sm shadow-sm">
            <div class="flex justify-between items-center mb-4">
                <h1 class="text-xl inline-block font-semibold text-gray-800">
                    Quote For {{ $customer->company_name ?? $contactLabel }}
                </h1>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="col-span-2 space-x-2">
                    <div class="bg-white rounded-sm border border-gray-100 p-4 mb-4">
                        {{ $quote->job_title }}
                    </div>
                    <div class="flex flex-row space-x-6">
                        <div class="lg:col-span-3">
                            <p>Property address</p>
                            @if ($property)
                                <div class="text-xs text-gray-600">{{ $property->country }}</div>
                                <div class="text-xs text-gray-600">{{ $property->street1 }}</div>
                                <div class="text-xs text-gray-600">{{ $property->state }} - {{ $property->zip }}</div>
                            @else
                                <div class="text-xs text-gray-600">No property selected.</div>
                            @endif
                        </div>
                        <div class="lg:col-span-3">
                            <p>Contact details</p>
                            <div class="text-xs text-gray-600">{{ $contactName !== '' ? $contactName : '-' }}</div>
                            <div class="text-xs text-gray-600">{{ $customer->email ?? '-' }}</div>
                            <div class="text-xs text-gray-600">{{ $customer->phone ?? '-' }}</div>
                        </div>
                    </div>
                </div>
                <div class="bg-white p-4 rounded-sm border border-gray-100">
                    <p>Quote details</p>
                    <div class="text-xs text-gray-600 flex justify-between">
                        <span>Quote:</span>
                        <span>{{ $quote->number ?? $quote->id }}</span>
                    </div>
                    <div class="text-xs text-gray-600 flex justify-between">
                        <span>Status:</span>
                        <span>{{ $quote->status ?? 'sent' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="p-5 space-y-3 flex flex-col bg-white border border-gray-100 rounded-sm shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="min-w-[300px] text-left text-sm font-medium text-gray-800">Product/Services</th>
                            <th class="text-left text-sm font-medium text-gray-800">Qty.</th>
                            <th class="text-left text-sm font-medium text-gray-800">Unit cost</th>
                            <th class="text-left text-sm font-medium text-gray-800">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($quote->products as $product)
                            <tr>
                                <td class="px-4 py-3">{{ $product->name }}</td>
                                <td class="px-4 py-3">{{ $product->pivot->quantity }}</td>
                                <td class="px-4 py-3">${{ number_format((float) $product->pivot->price, 2) }}</td>
                                <td class="px-4 py-3">${{ number_format((float) $product->pivot->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-gray-100 rounded-sm shadow-sm">
            <div></div>
            <div class="border-l border-gray-200 rounded-sm p-4">
                <div class="py-4 grid grid-cols-2 gap-x-4">
                    <div class="col-span-1">
                        <p class="text-sm text-gray-500">Subtotal:</p>
                    </div>
                    <div class="col-span-1 flex justify-end">
                        <p class="text-sm text-green-600">$ {{ number_format((float) $quote->subtotal, 2) }}</p>
                    </div>
                </div>

                @if ($quote->taxes && $quote->taxes->count())
                    <div class="space-y-2 py-4 border-t border-gray-200">
                        @foreach ($quote->taxes as $tax)
                            <div class="flex justify-between">
                                <p class="text-sm text-gray-500">{{ $tax->tax->name ?? 'Tax' }} ({{ number_format($tax->rate, 2) }}%) :</p>
                                <p class="text-sm text-gray-800">${{ number_format((float) $tax->amount, 2) }}</p>
                            </div>
                        @endforeach
                        <div class="flex justify-between font-bold">
                            <p class="text-sm text-gray-800">Total taxes :</p>
                            <p class="text-sm text-gray-800">${{ number_format((float) $quote->taxes->sum('amount'), 2) }}</p>
                        </div>
                    </div>
                @endif

                <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-gray-200">
                    <div class="col-span-1">
                        <p class="text-sm text-gray-800 font-bold">Total amount:</p>
                    </div>
                    <div class="flex justify-end">
                        <p class="text-sm text-gray-800 font-bold">$ {{ number_format((float) $quote->total, 2) }}</p>
                    </div>
                </div>

                @if ($quote->initial_deposit > 0)
                    <div class="py-4 grid grid-cols-2 items-center gap-x-4 border-t border-gray-200">
                        <div class="col-span-1">
                            <p class="text-sm text-gray-500">Required deposit:</p>
                        </div>
                        <div class="flex justify-end">
                            <span class="text-xs text-gray-500">(Min: ${{ number_format((float) $quote->initial_deposit, 2) }})</span>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="text-sm text-gray-600">
            Log in to your portal to review and validate the quote.
        </div>
        <div>
            <a class="inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white px-4 py-2 hover:bg-green-700"
               href="{{ route('dashboard') }}">
                Open dashboard
            </a>
        </div>
    </div>
@endsection
