<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote Email</title>
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <style>
        {{ file_get_contents(public_path('build/assets/app-DKoz_JkU.css')) }}
    </style>
</head>
<body id="content" class="lg:ps-16 pt-[59px] lg:pt-0">
    @php
        $property = $quote->property ?? ($quote->customer->properties->first() ?? null);
    @endphp
    <div class="grid grid-cols-5 gap-4">
        <div class="col-span-1"></div>
        <div class="col-span-3">
            <div class="p-5 space-y-3 flex flex-col bg-gray-100 border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">
                <div class="flex justify-between items-center mb-4">
                    <h1 class="text-xl inline-block font-semibold text-gray-800 dark:text-green-100">
                        Quote For {{ $quote->customer->company_name }}
                    </h1>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="col-span-2 space-x-2">
                        <div class="bg-white rounded-sm p-4 mb-4">
                            {{ $quote->job_title }}
                        </div>
                        <div class="flex flex-row space-x-6">
                            <div class="lg:col-span-3">
                                <p>Property address</p>
                                <div class="text-xs text-gray-600">
                                    {{ optional($property)->country }}
                                </div>
                                <div class="text-xs text-gray-600">
                                    {{ optional($property)->street1 }}
                                </div>
                                <div class="text-xs text-gray-600">
                                    {{ optional($property)->state }} - {{ optional($property)->zip }}
                                </div>
                            </div>
                            <div class="lg:col-span-3">
                                <p>Contact details</p>
                                <div class="text-xs text-gray-600">
                                    {{ $quote->customer->first_name }} {{ $quote->customer->last_name }}
                                </div>
                                <div class="text-xs text-gray-600">
                                    {{ $quote->customer->email }}
                                </div>
                                <div class="text-xs text-gray-600">
                                    {{ $quote->customer->phone }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-4">
                        <p>Quote details</p>
                        <div class="text-xs text-gray-600 flex justify-between">
                            <span> Quote :</span>
                            <span>{{ $quote?->number }} </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-5 space-y-3 flex flex-col bg-white border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-neutral-700">
                        <thead>
                            <tr>
                                <th class="min-w-[450px]">Product/Services</th>
                                <th>Qty.</th>
                                <th>Unit cost</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-neutral-700">
                            @foreach ($quote->products as $product)
                                <tr>
                                    <td class="px-4 py-3">{{ $product->name }}</td>
                                    <td class="px-4 py-3">{{ $product->pivot->quantity }}</td>
                                    <td class="px-4 py-3">{{ $product->pivot->price }}</td>
                                    <td class="px-4 py-3">{{ $product->pivot->total }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="p-5 grid grid-cols-2 gap-4 justify-between bg-white border border-gray-100 rounded-sm shadow-sm xl:shadow-none dark:bg-green-800 dark:border-green-700">
                <div></div>
                <div class="border-l border-gray-200 dark:border-neutral-700 rounded-sm p-4">
                    <div class="py-4 grid grid-cols-2 gap-x-4">
                        <div class="col-span-1">
                            <p class="text-sm text-gray-500 dark:text-neutral-500">Subtotal:</p>
                        </div>
                        <div class="col-span-1 flex justify-end">
                            <p class="text-sm text-green-600 dark:text-green-400">$ {{ $quote->subtotal }}</p>
                        </div>
                    </div>
                    @if ($quote->taxes && $quote->taxes->count())
                        <div class="space-y-2 py-4 border-t border-gray-200 dark:border-neutral-700">
                            @foreach ($quote->taxes as $tax)
                                <div class="flex justify-between">
                                    <p class="text-sm text-gray-500 dark:text-neutral-500">{{ $tax->tax->name ?? 'Tax' }} ({{ number_format($tax->rate, 2) }}%) :</p>
                                    <p class="text-sm text-gray-800 dark:text-neutral-200">${{ number_format($tax->amount, 2) }}</p>
                                </div>
                            @endforeach
                            <div class="flex justify-between font-bold">
                                <p class="text-sm text-gray-800 dark:text-neutral-200">Total taxes :</p>
                                <p class="text-sm text-gray-800 dark:text-neutral-200">${{ number_format($quote->taxes->sum('amount'), 2) }}</p>
                            </div>
                        </div>
                    @endif
                    <div class="py-4 grid grid-cols-2 gap-x-4 border-t border-gray-200 dark:border-neutral-700">
                        <div class="col-span-1">
                            <p class="text-sm text-gray-800 font-bold dark:text-neutral-500">Total amount:</p>
                        </div>
                        <div class="flex justify-end">
                            <p class="text-sm text-gray-800 font-bold dark:text-neutral-200">$ {{ $quote->total }}</p>
                        </div>
                    </div>
                    @if ($quote->initial_deposit > 0)
                        <div class="py-4 grid grid-cols-2 items-center gap-x-4 border-t border-gray-600 dark:border-neutral-700">
                            <div class="col-span-1">
                                <p class="text-sm text-gray-500 dark:text-neutral-500">Required deposit:</p>
                            </div>
                            <div class="flex justify-end">
                                <span class="text-xs text-gray-500 dark:text-neutral-500">(Min: ${{ $quote->initial_deposit }})</span>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-span-1"></div>
    </div>
</body>
</html>
