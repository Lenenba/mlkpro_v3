<?php

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\DTO\AiDetectedIntent;
use App\Modules\AiAssistant\Models\AiConversation;
use Illuminate\Support\Str;

class AiIntentDetector
{
    public function detect(string $message, ?string $fallbackLanguage = null): AiDetectedIntent
    {
        $normalized = Str::lower($message);
        $language = $this->detectLanguage($normalized, $fallbackLanguage);

        if ($this->containsAny($normalized, [
            'reschedule',
            'move my appointment',
            'change my appointment',
            'changer mon rendez',
            'deplacer mon rendez',
            'replanifier',
            'reporter mon rendez',
        ])) {
            return new AiDetectedIntent(AiConversation::INTENT_RESCHEDULE, 0.82, $language);
        }

        if ($this->containsAny($normalized, [
            'book',
            'booking',
            'appointment',
            'reserve',
            'reservation',
            'rendez-vous',
            'rdv',
            'creneau',
            'disponible',
            'availability',
            'available',
            'slot',
        ])) {
            return new AiDetectedIntent(AiConversation::INTENT_RESERVATION, 0.86, $language);
        }

        if ($this->containsAny($normalized, [
            'human',
            'person',
            'representative',
            'quelqu un',
            'personne',
            'humain',
            'appeler',
        ])) {
            return new AiDetectedIntent(AiConversation::INTENT_HUMAN_REVIEW, 0.7, $language);
        }

        return new AiDetectedIntent(AiConversation::INTENT_GENERAL, 0.45, $language);
    }

    private function detectLanguage(string $message, ?string $fallbackLanguage): string
    {
        if ($this->containsAny($message, [
            'bonjour',
            'salut',
            'reservation',
            'rendez-vous',
            'rdv',
            'demain',
            'merci',
            'disponible',
            'creneau',
        ])) {
            return 'fr';
        }

        if ($this->containsAny($message, [
            'hello',
            'hi',
            'book',
            'appointment',
            'tomorrow',
            'thanks',
            'available',
            'service',
        ])) {
            return 'en';
        }

        return $fallbackLanguage ?: 'fr';
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (Str::contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
