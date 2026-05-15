<?php

namespace App\Modules\AiAssistant\Services;

use Illuminate\Support\Carbon;

class BookingSummaryBuilder
{
    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $selectedSlot
     * @param  array<int, string>  $missingFields
     */
    public function build(
        array $draft,
        array $selectedSlot,
        array $missingFields,
        string $language,
        string $timezone,
        bool $autoConfirmed = false
    ): string {
        $isFr = $language === 'fr';
        $lines = $this->summaryLines($draft, $selectedSlot, $language, $timezone);

        $message = $isFr ? "Voici le résumé de votre demande :\n\n" : "Here is the summary of your request:\n\n";
        $message .= implode("\n", $lines);

        if ($missingFields !== []) {
            $message .= "\n\n".($isFr ? 'Il manque encore : ' : 'Still missing: ')
                .implode(', ', $this->missingLabels($missingFields, $language)).'.';

            return $message;
        }

        $message .= "\n\n".($autoConfirmed
            ? ($isFr ? 'Voulez-vous que je confirme ce rendez-vous?' : 'Would you like me to confirm this appointment?')
            : ($isFr ? 'Voulez-vous que j’envoie cette demande à l’équipe?' : 'Would you like me to send this request to the team?'));

        return $message;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $selectedSlot
     */
    public function finalMessage(
        array $draft,
        array $selectedSlot,
        string $language,
        string $timezone,
        bool $autoConfirmed = false
    ): string {
        $service = trim((string) ($draft['service_name'] ?? ''));
        $phone = $this->formatPhone((string) ($draft['contact_phone'] ?? ''));
        $date = $this->slotDate($selectedSlot, $timezone, $language);
        $time = $this->slotTime($selectedSlot, $timezone);
        $member = trim((string) ($selectedSlot['team_member_name'] ?? ''));

        if ($language !== 'fr') {
            if ($autoConfirmed) {
                return trim('Confirmed. Your appointment'
                    .($service !== '' ? " for {$service}" : '')
                    .($date !== '' ? " is scheduled for {$date}" : '')
                    .($time !== '' ? " at {$time}" : '')
                    .($member !== '' ? " with {$member}" : '')
                    .'.');
            }

            return trim('Noted. Your booking request'
                .($service !== '' ? " for {$service}" : '')
                .' has been sent to the team.'
                .($phone !== '' ? " They will contact you at {$phone} if validation is needed." : ''));
        }

        if ($autoConfirmed) {
            return trim('C’est confirmé. Votre rendez-vous'
                .($service !== '' ? " pour {$service}" : '')
                .($date !== '' ? " est prévu le {$date}" : '')
                .($time !== '' ? " à {$time}" : '')
                .($member !== '' ? " avec {$member}" : '')
                .'.');
        }

        return trim('C’est noté. Votre demande de réservation'
            .($service !== '' ? " pour {$service}" : '')
            .' a été envoyée à l’équipe.'
            .($phone !== '' ? " Ils vous contacteront au {$phone} si une validation est nécessaire." : ''));
    }

    public function formatPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6);
        }

        return $phone;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $selectedSlot
     * @return array<int, string>
     */
    private function summaryLines(array $draft, array $selectedSlot, string $language, string $timezone): array
    {
        $isFr = $language === 'fr';
        $labels = [
            'service' => $isFr ? 'Service' : 'Service',
            'name' => $isFr ? 'Nom' : 'Name',
            'phone' => $isFr ? 'Téléphone' : 'Phone',
            'email' => $isFr ? 'Email' : 'Email',
            'address' => $isFr ? 'Adresse' : 'Address',
            'date' => $isFr ? 'Date souhaitée' : 'Preferred date',
            'time' => $isFr ? 'Heure' : 'Time',
            'member' => $isFr ? 'Avec' : 'With',
            'notes' => $isFr ? 'Notes' : 'Notes',
        ];

        $items = [];
        $this->pushKnown($items, $labels['service'], $draft['service_name'] ?? null);
        $this->pushKnown($items, $labels['name'], $draft['contact_name'] ?? null);
        $this->pushKnown($items, $labels['phone'], $this->formatPhone((string) ($draft['contact_phone'] ?? '')));
        $this->pushKnown($items, $labels['email'], $draft['contact_email'] ?? null);
        $this->pushKnown($items, $labels['address'], $draft['service_address'] ?? null);
        $this->pushKnown($items, $labels['date'], $this->summaryDate($draft, $selectedSlot, $timezone, $language));
        $this->pushKnown($items, $labels['time'], $this->slotTime($selectedSlot, $timezone) ?: ($draft['preferred_time'] ?? null));
        $this->pushKnown($items, $labels['member'], $selectedSlot['team_member_name'] ?? null);
        $this->pushKnown($items, $labels['notes'], $draft['notes'] ?? null);

        return $items;
    }

    /**
     * @param  array<int, string>  $items
     */
    private function pushKnown(array &$items, string $label, mixed $value): void
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return;
        }

        $items[] = "- {$label} : {$value}";
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $selectedSlot
     */
    private function summaryDate(array $draft, array $selectedSlot, string $timezone, string $language): string
    {
        $slotDate = $this->slotDate($selectedSlot, $timezone, $language);
        if ($slotDate !== '') {
            return $slotDate;
        }

        if (! empty($draft['preferred_date'])) {
            return $this->formatNaturalDate((string) $draft['preferred_date'], $timezone, $language);
        }

        if (! empty($draft['preferred_date_start']) && ! empty($draft['preferred_date_end'])) {
            $start = $this->formatNaturalDate((string) $draft['preferred_date_start'], $timezone, $language);
            $end = $this->formatNaturalDate((string) $draft['preferred_date_end'], $timezone, $language);

            return $language === 'fr' ? "du {$start} au {$end}" : "from {$start} to {$end}";
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $selectedSlot
     */
    private function slotDate(array $selectedSlot, string $timezone, string $language): string
    {
        $startsAt = trim((string) ($selectedSlot['starts_at'] ?? ''));
        if ($startsAt === '') {
            return '';
        }

        return $this->formatNaturalDate($startsAt, $timezone, $language);
    }

    /**
     * @param  array<string, mixed>  $selectedSlot
     */
    private function slotTime(array $selectedSlot, string $timezone): string
    {
        $startsAt = trim((string) ($selectedSlot['starts_at'] ?? ''));
        if ($startsAt === '') {
            return trim((string) ($selectedSlot['time'] ?? ''));
        }

        try {
            return Carbon::parse($startsAt)->setTimezone($timezone)->format('H:i');
        } catch (\Throwable) {
            return trim((string) ($selectedSlot['time'] ?? ''));
        }
    }

    private function formatNaturalDate(string $value, string $timezone, string $language): string
    {
        try {
            $date = Carbon::parse($value, $timezone)->setTimezone($timezone);
        } catch (\Throwable) {
            return $value;
        }

        if ($language !== 'fr') {
            return $date->format('l, F j, Y');
        }

        $weekdays = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        $months = [
            1 => 'janvier',
            2 => 'février',
            3 => 'mars',
            4 => 'avril',
            5 => 'mai',
            6 => 'juin',
            7 => 'juillet',
            8 => 'août',
            9 => 'septembre',
            10 => 'octobre',
            11 => 'novembre',
            12 => 'décembre',
        ];

        return $weekdays[(int) $date->dayOfWeek].' '.(int) $date->day.' '.$months[(int) $date->month].' '.(int) $date->year;
    }

    /**
     * @param  array<int, string>  $missingFields
     * @return array<int, string>
     */
    private function missingLabels(array $missingFields, string $language): array
    {
        $fr = [
            'service_id' => 'service',
            'contact_name' => 'nom complet',
            'contact_phone' => 'téléphone',
            'contact_email' => 'email',
            'service_address' => 'adresse',
            'preferred_date' => 'date souhaitée',
        ];
        $en = [
            'service_id' => 'service',
            'contact_name' => 'full name',
            'contact_phone' => 'phone',
            'contact_email' => 'email',
            'service_address' => 'address',
            'preferred_date' => 'preferred date',
        ];

        $labels = $language === 'fr' ? $fr : $en;

        return collect($missingFields)
            ->map(fn (string $field): string => $labels[$field] ?? $field)
            ->values()
            ->all();
    }
}
