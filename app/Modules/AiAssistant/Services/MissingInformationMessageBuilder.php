<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MissingInformationMessageBuilder
{
    /**
     * @param  array<string, mixed>  $draft
     * @param  array<int, string>  $missingFields
     * @param  Collection<int, Product>  $services
     */
    public function build(array $draft, array $missingFields, Collection $services, string $language): string
    {
        if ($missingFields === []) {
            return '';
        }

        $isFr = $language === 'fr';

        if (in_array('service_id', $missingFields, true)) {
            return $isFr
                ? 'Quel service souhaitez-vous réserver? '.$this->availableServicesLine($services, $language)
                : 'Which service would you like to book? '.$this->availableServicesLine($services, $language);
        }

        $missingName = in_array('contact_name', $missingFields, true);
        $missingPhone = in_array('contact_phone', $missingFields, true);

        if ($missingName && $missingPhone) {
            $firstName = $this->knownFirstName($draft);

            if ($firstName !== '') {
                return $isFr
                    ? "J’ai déjà votre prénom, {$firstName}. Pouvez-vous me donner votre nom complet et un numéro de téléphone?"
                    : "I already have your first name, {$firstName}. Could you share your full name and a phone number?";
            }

            return $isFr
                ? 'Pour préparer la demande, j’ai besoin de votre nom complet et d’un numéro de téléphone.'
                : 'To prepare the request, I need your full name and a phone number.';
        }

        if ($missingName) {
            $firstName = $this->knownFirstName($draft);

            if ($firstName !== '') {
                return $isFr
                    ? "J’ai déjà votre prénom, {$firstName}. Il me manque seulement votre nom complet pour préparer la demande."
                    : "I already have your first name, {$firstName}. I only need your full name to prepare the request.";
            }

            return $isFr
                ? 'Il me manque seulement votre nom complet pour préparer la demande.'
                : 'I only need your full name to prepare the request.';
        }

        if ($missingPhone) {
            $name = trim((string) ($draft['contact_name'] ?? ''));
            $thanks = $name !== ''
                ? ($isFr ? 'Merci '.$this->displayName($name).'. ' : 'Thanks '.$this->displayName($name).'. ')
                : '';

            return $thanks.($isFr
                ? 'Il me manque seulement un numéro de téléphone pour que l’équipe puisse vous confirmer la demande.'
                : 'I only need a phone number so the team can confirm the request with you.');
        }

        if (in_array('contact_email', $missingFields, true)) {
            return $isFr
                ? 'Merci. Quelle adresse email pouvons-nous utiliser pour vous envoyer la confirmation?'
                : 'Thanks. Which email address can we use to send your confirmation?';
        }

        if (in_array('service_address', $missingFields, true)) {
            $serviceName = trim((string) ($draft['service_name'] ?? ''));

            return $isFr
                ? ($serviceName !== ''
                    ? "Pour le service {$serviceName}, il me manque l’adresse où l’intervention doit être effectuée."
                    : 'Il me manque l’adresse où l’intervention doit être effectuée.')
                : ($serviceName !== ''
                    ? "For {$serviceName}, I need the address where the service should be done."
                    : 'I need the address where the service should be done.');
        }

        if (in_array('preferred_date', $missingFields, true)) {
            return $isFr
                ? 'Parfait. Pour quelle date ou période souhaitez-vous réserver? Vous pouvez dire par exemple demain, cette semaine, la fin du mois, ou une date précise.'
                : 'Perfect. What date or period would you like to book? You can say tomorrow, this week, the end of the month, or a specific date.';
        }

        return $isFr
            ? 'Pouvez-vous me donner la précision manquante pour continuer?'
            : 'Could you share the missing detail so I can continue?';
    }

    /**
     * @param  array<string, mixed>  $previousDraft
     * @param  array<string, mixed>  $draft
     */
    public function selectedServiceAcknowledgement(array $previousDraft, array $draft, string $language): string
    {
        $serviceName = trim((string) ($draft['service_name'] ?? ''));
        if ($serviceName === '') {
            return '';
        }

        $previousServiceId = (int) ($previousDraft['service_id'] ?? 0);
        $serviceId = (int) ($draft['service_id'] ?? 0);

        if ($serviceId <= 0 || $previousServiceId === $serviceId) {
            return '';
        }

        return $language === 'fr'
            ? "Parfait, vous souhaitez réserver le service {$serviceName}."
            : "Perfect, you would like to book {$serviceName}.";
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function naturalUnderstanding(array $draft, string $language): string
    {
        $serviceName = trim((string) ($draft['service_name'] ?? ''));
        if ($serviceName === '') {
            return '';
        }

        $date = $this->datePhrase($draft, $language);
        $time = $this->timePhrase($draft, $language);

        if ($language === 'fr') {
            $suffix = trim($date.' '.$time);

            return trim("Parfait, je note votre demande pour {$serviceName}".($suffix !== '' ? ' '.$suffix : '').'.').' ';
        }

        $suffix = trim($date.' '.$time);

        return trim("Perfect, I have your request for {$serviceName}".($suffix !== '' ? ' '.$suffix : '').'.').' ';
    }

    /**
     * @param  Collection<int, Product>  $services
     */
    private function availableServicesLine(Collection $services, string $language): string
    {
        if ($services->isEmpty()) {
            return '';
        }

        $servicesText = $services
            ->values()
            ->map(fn (Product $service, int $index): string => ($index + 1).'. '.(string) $service->name)
            ->implode('; ');

        return ($language === 'fr' ? 'Options disponibles: ' : 'Available options: ').$servicesText.'.';
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function knownFirstName(array $draft): string
    {
        $name = trim((string) ($draft['contact_name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $firstName = preg_split('/\s+/', $name)[0] ?? $name;
        $firstName = trim($firstName, " \t\n\r\0\x0B,.;:!?");

        return Str::ucfirst(Str::lower($firstName));
    }

    private function displayName(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->map(fn (string $part): string => Str::ucfirst(Str::lower($part)))
            ->implode(' ');
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function datePhrase(array $draft, string $language): string
    {
        if (! empty($draft['preferred_date'])) {
            return $language === 'fr'
                ? 'le '.(string) $draft['preferred_date']
                : 'on '.(string) $draft['preferred_date'];
        }

        if (! empty($draft['preferred_date_label'])) {
            return match ((string) $draft['preferred_date_label']) {
                'next_week' => $language === 'fr' ? 'la semaine prochaine' : 'next week',
                'end_of_month' => $language === 'fr' ? 'à la fin du mois' : 'at the end of the month',
                'next_month' => $language === 'fr' ? 'le mois prochain' : 'next month',
                'weekend' => $language === 'fr' ? 'la fin de semaine' : 'on the weekend',
                default => (string) $draft['preferred_date_label'],
            };
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function timePhrase(array $draft, string $language): string
    {
        if (! empty($draft['preferred_time'])) {
            return $language === 'fr'
                ? 'à '.(string) $draft['preferred_time']
                : 'at '.(string) $draft['preferred_time'];
        }

        if (! empty($draft['preferred_time_label'])) {
            return match ((string) $draft['preferred_time_label']) {
                'after_work' => $language === 'fr' ? 'après le travail' : 'after work',
                'evening' => $language === 'fr' ? 'le soir' : 'in the evening',
                'morning' => $language === 'fr' ? 'le matin' : 'in the morning',
                'afternoon' => $language === 'fr' ? 'l’après-midi' : 'in the afternoon',
                default => (string) $draft['preferred_time_label'],
            };
        }

        return '';
    }
}
