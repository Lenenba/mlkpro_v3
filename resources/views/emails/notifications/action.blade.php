@extends('emails.layouts.base')

@section('title', $title ?? 'Notification')

@section('content')
    <div class="mx-auto w-full max-w-3xl space-y-4">
        <div class="p-5 space-y-2 flex flex-col bg-gray-100 border border-gray-100 rounded-sm shadow-sm">
            <h1 class="text-xl font-semibold text-gray-800">
                {{ $title ?? 'Notification' }}
            </h1>
            @if (!empty($intro))
                <p class="text-sm text-gray-600">{{ $intro }}</p>
            @endif
        </div>

        @if (!empty($details))
            <div class="p-5 bg-white border border-gray-100 rounded-sm shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($details as $detail)
                                <tr>
                                    <td class="py-2 text-sm text-gray-500">{{ $detail['label'] ?? 'Detail' }}</td>
                                    <td class="py-2 text-sm text-gray-800 text-right">{{ $detail['value'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if (!empty($note))
            <div class="text-xs text-gray-500">{{ $note }}</div>
        @endif

        @if (!empty($actionUrl))
            <div>
                <a class="inline-flex items-center gap-x-2 text-sm font-medium rounded-sm border border-transparent bg-green-600 text-white px-4 py-2 hover:bg-green-700"
                   href="{{ $actionUrl }}">
                    {{ $actionLabel ?? 'Open' }}
                </a>
            </div>
        @endif
    </div>
@endsection
