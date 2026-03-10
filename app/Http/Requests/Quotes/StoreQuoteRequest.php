<?php

namespace App\Http\Requests\Quotes;

class StoreQuoteRequest extends QuoteWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->quoteRules();
    }
}
