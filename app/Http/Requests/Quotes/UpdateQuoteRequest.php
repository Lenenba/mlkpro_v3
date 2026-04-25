<?php

namespace App\Http\Requests\Quotes;

use App\Models\Quote;

class UpdateQuoteRequest extends QuoteWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $quote = $this->route('quote');
        $allowsCustomerlessQuote = $quote instanceof Quote
            && ! $quote->customer_id
            && ($quote->prospect_id || $quote->request_id);

        return $this->quoteRules(requireCustomer: ! $allowsCustomerlessQuote);
    }
}
