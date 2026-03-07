<?php

namespace App\Http\Requests\Quotes;

class UpdateQuoteRequest extends QuoteWriteRequest
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
