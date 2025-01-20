<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use Illuminate\Http\Request;
use App\Notifications\SendQuoteNotification;

class QuoteEmaillingController extends Controller
{
    public function __invoke(Quote $quote)
    { // Vérifier que le client a un email valide
        if (!$quote->customer || !$quote->customer->email) {
            return redirect()->back()->with('error', 'Customer email address is not available.');
        }

        // Envoyer la notification par email
        $quote->customer->notify(new SendQuoteNotification($quote));

        return redirect()->back()->with('success', 'Quote sent successfully to ' . $quote->customer->email);
    }
}
