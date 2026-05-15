<?php

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\DTO\AiDetectedIntent;
use App\Modules\AiAssistant\Models\AiConversation;
use Illuminate\Support\Str;

class AiIntentDetector
{
    public function detect(string $message, ?string $fallbackLanguage = null): AiDetectedIntent
    {
        $normalized = $this->normalize($message);
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

        if ($this->isLikelyReservationRequest($normalized)) {
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
            'reserver',
            'rendez vous',
            'rdv',
            'demain',
            'merci',
            'disponible',
            'creneau',
            'je veux',
            'je veu',
            'j veux',
            'mappel',
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

    private function normalize(string $message): string
    {
        $normalized = Str::ascii(Str::lower($message));
        $normalized = str_replace(["'", '’', '-'], ' ', $normalized);
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private function isLikelyReservationRequest(string $message): bool
    {
        if ($this->containsAny($message, [
            'book',
            'booking',
            'appointment',
            'reserve',
            'reserver',
            'reservation',
            'rendez vous',
            'rendezvous',
            'rdv',
            'creneau',
            'disponible',
            'availability',
            'available',
            'slot',
            'prendre rendez',
            'faire une reservation',
            'faire un rdv',
        ])) {
            return true;
        }

        if (preg_match('/\b(?:veux|veu|veut|souhaite|besoin|faire|prendre)\b.{0,40}\b(?:rdv|rendez|creneau|dispo)\b/u', $message) === 1) {
            return true;
        }

        return $this->containsFuzzyToken($message, [
            'reservation' => 3,
            'reserver' => 2,
            'booking' => 2,
            'appointment' => 3,
        ]);
    }

    /**
     * @param  array<string, int>  $targets
     */
    private function containsFuzzyToken(string $message, array $targets): bool
    {
        $tokens = preg_split('/\s+/', $message) ?: [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if (Str::length($token) < 6) {
                continue;
            }

            foreach ($targets as $target => $maxDistance) {
                if (abs(Str::length($token) - Str::length($target)) > $maxDistance) {
                    continue;
                }

                if (levenshtein($token, $target) <= $maxDistance) {
                    return true;
                }
            }
        }

        return false;
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
