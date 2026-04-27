<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SocialCampaignDraftService
{
    public function __construct(
        private readonly SocialPostService $postService,
        private readonly SocialBrandVoiceService $brandVoiceService,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function allowedIntentions(): array
    {
        return [
            'product_launch',
            'promotion',
            'event',
            'service_push',
            'client_reengagement',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function generate(User $owner, User $actor, array $payload): array
    {
        $batchId = (string) Str::uuid();
        $name = $this->limitedString($payload['name'] ?? null, 140) ?: $this->intentionLabel((string) $payload['intention_type']);
        $brief = $this->limitedString($payload['brief'] ?? null, 1200) ?: $name;
        $postCount = max(2, min(8, (int) ($payload['post_count'] ?? 4)));
        $days = max(1, min(30, (int) ($payload['duration_days'] ?? $postCount)));
        $startDate = Carbon::parse((string) ($payload['start_date'] ?? now()->toDateString()))
            ->setTimezone($this->timezone($owner))
            ->startOfDay()
            ->setTime(9, 0);
        $targetConnectionIds = collect((array) ($payload['target_connection_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $brandVoice = $this->brandVoiceService->resolve($owner);
        $generatedPosts = collect();

        foreach ($this->campaignPlan($payload, $name, $brief, $postCount, $days, $brandVoice) as $index => $item) {
            $scheduledFor = $this->scheduledAt($startDate, $index, $postCount, $days);
            $post = $this->postService->createDraft($owner, $actor, [
                'text' => $item['text'],
                'image_url' => $this->limitedString($payload['image_url'] ?? null, 2048),
                'link_url' => $this->limitedString($payload['link_url'] ?? null, 2048),
                'link_cta_label' => $item['cta'],
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'target_connection_ids' => $targetConnectionIds,
                'metadata' => [
                    'draft_saved_from' => 'social_campaign_mode',
                    'campaign_batch' => [
                        'id' => $batchId,
                        'name' => $name,
                        'intention_type' => $payload['intention_type'],
                        'position' => $index + 1,
                        'post_count' => $postCount,
                        'duration_days' => $days,
                        'reason' => $item['reason'],
                        'generated_at' => now()->toIso8601String(),
                    ],
                ],
            ]);

            $generatedPosts->push($post);
        }

        return [
            'batch' => [
                'id' => $batchId,
                'name' => $name,
                'intention_type' => $payload['intention_type'],
                'post_count' => $generatedPosts->count(),
                'duration_days' => $days,
                'generated_at' => now()->toIso8601String(),
            ],
            'posts' => $generatedPosts
                ->map(fn (SocialPost $post): array => $this->postService->payload($post))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentBatches(User $owner, int $limit = 6): array
    {
        return SocialPost::query()
            ->byUser($owner->id)
            ->latest('created_at')
            ->limit(120)
            ->get(['id', 'status', 'scheduled_for', 'metadata', 'created_at'])
            ->filter(fn (SocialPost $post): bool => is_array(data_get($post->metadata, 'campaign_batch')))
            ->groupBy(fn (SocialPost $post): string => (string) data_get($post->metadata, 'campaign_batch.id'))
            ->filter(fn (Collection $posts, string $batchId): bool => $batchId !== '')
            ->map(function (Collection $posts): array {
                $first = $posts->sortBy('created_at')->first();
                $batch = (array) data_get($first?->metadata, 'campaign_batch', []);

                return [
                    'id' => (string) ($batch['id'] ?? ''),
                    'name' => (string) ($batch['name'] ?? 'Pulse campaign'),
                    'intention_type' => (string) ($batch['intention_type'] ?? ''),
                    'post_count' => $posts->count(),
                    'scheduled_count' => $posts->where('status', SocialPost::STATUS_SCHEDULED)->count(),
                    'pending_approval_count' => $posts->where('status', SocialPost::STATUS_PENDING_APPROVAL)->count(),
                    'generated_at' => (string) ($batch['generated_at'] ?? optional($first?->created_at)->toIso8601String()),
                ];
            })
            ->sortByDesc('generated_at')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $brandVoice
     * @return array<int, array{text: string, cta: string|null, reason: string}>
     */
    private function campaignPlan(array $payload, string $name, string $brief, int $postCount, int $days, array $brandVoice): array
    {
        $intention = (string) $payload['intention_type'];
        $cta = collect((array) ($brandVoice['preferred_ctas'] ?? []))
            ->map(fn ($item): string => trim((string) $item))
            ->first(fn (string $item): bool => $item !== '')
            ?: $this->defaultCta($intention);
        $hashtags = collect((array) ($brandVoice['preferred_hashtags'] ?? []))
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->take(3)
            ->implode(' ');
        $steps = $this->steps($intention, $postCount);

        return collect($steps)
            ->map(function (array $step, int $index) use ($name, $brief, $cta, $hashtags, $postCount, $days): array {
                $blocks = [
                    $step['lead'].' '.$name.'.',
                    $brief,
                    $step['angle'],
                    $cta,
                    $hashtags,
                ];

                return [
                    'text' => Str::limit(trim(implode("\n\n", array_filter($blocks))), 900, ''),
                    'cta' => $cta,
                    'reason' => sprintf(
                        'Post %d/%d place sur %d jours: %s',
                        $index + 1,
                        $postCount,
                        $days,
                        $step['reason']
                    ),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{lead: string, angle: string, reason: string}>
     */
    private function steps(string $intention, int $postCount): array
    {
        $catalog = match ($intention) {
            'product_launch' => [
                ['lead' => 'Annonce', 'angle' => 'Mettez en avant le benefice principal et invitez a decouvrir les details.', 'reason' => 'ouverture de lancement'],
                ['lead' => 'Focus', 'angle' => 'Expliquez ce qui rend cette offre utile ou differente.', 'reason' => 'preuve de valeur'],
                ['lead' => 'Rappel', 'angle' => 'Redonnez une raison simple de passer a l action cette semaine.', 'reason' => 'relance douce'],
                ['lead' => 'Dernier rappel', 'angle' => 'Gardez un message direct, clair et facile a valider.', 'reason' => 'cloture de sequence'],
            ],
            'promotion' => [
                ['lead' => 'Promotion', 'angle' => 'Presentez l avantage sans inventer de rabais ou de condition.', 'reason' => 'annonce de promotion'],
                ['lead' => 'A savoir', 'angle' => 'Clarifiez le contexte et dirigez vers le lien ou la prise de contact.', 'reason' => 'precision utile'],
                ['lead' => 'Rappel promo', 'angle' => 'Reformulez l occasion avec un CTA simple.', 'reason' => 'rappel commercial'],
                ['lead' => 'Derniere chance', 'angle' => 'Creez de l urgence sobre sans promesse non verifiee.', 'reason' => 'fin de campagne'],
            ],
            'event' => [
                ['lead' => 'Evenement', 'angle' => 'Expliquez pourquoi l evenement merite une place dans l agenda.', 'reason' => 'annonce evenement'],
                ['lead' => 'Au programme', 'angle' => 'Mettez en avant l experience attendue en gardant le message court.', 'reason' => 'details evenement'],
                ['lead' => 'Rappel evenement', 'angle' => 'Invitez a reserver ou demander les informations pratiques.', 'reason' => 'rappel agenda'],
                ['lead' => 'Dernier rappel', 'angle' => 'Conservez une action claire avant la date.', 'reason' => 'cloture evenement'],
            ],
            'service_push' => [
                ['lead' => 'Service a decouvrir', 'angle' => 'Exposez le probleme client que ce service aide a regler.', 'reason' => 'positionnement service'],
                ['lead' => 'Pourquoi ce service', 'angle' => 'Mettez l accent sur le resultat attendu et la simplicite.', 'reason' => 'benefice service'],
                ['lead' => 'Rappel service', 'angle' => 'Proposez une action courte pour demander les details.', 'reason' => 'relance service'],
                ['lead' => 'Besoin d aide', 'angle' => 'Terminez par une invitation humaine et directe.', 'reason' => 'conversion service'],
            ],
            default => [
                ['lead' => 'On pense a vous', 'angle' => 'Rouvrez la conversation avec une raison utile et non agressive.', 'reason' => 'relance client'],
                ['lead' => 'Petit rappel', 'angle' => 'Mettez en avant une nouveaute ou un service pertinent.', 'reason' => 'rappel relationnel'],
                ['lead' => 'A ne pas manquer', 'angle' => 'Ajoutez un CTA clair sans pression excessive.', 'reason' => 'relance commerciale'],
                ['lead' => 'Restons en contact', 'angle' => 'Gardez une fin chaleureuse et facile a approuver.', 'reason' => 'cloture relationnelle'],
            ],
        };

        return collect($catalog)
            ->pad($postCount, end($catalog))
            ->take($postCount)
            ->values()
            ->all();
    }

    private function scheduledAt(Carbon $startDate, int $index, int $postCount, int $days): Carbon
    {
        $step = $postCount > 1 ? max(1, (int) floor(max(1, $days - 1) / max(1, $postCount - 1))) : 1;
        $hour = [9, 12, 15, 17][$index % 4];

        return $startDate->copy()->addDays($index * $step)->setTime($hour, 0)->utc();
    }

    private function defaultCta(string $intention): string
    {
        return match ($intention) {
            'event' => 'Reservez votre place.',
            'client_reengagement' => 'Ecrivez-nous pour les details.',
            'promotion' => 'Voir l offre.',
            default => 'Decouvrir les details.',
        };
    }

    private function intentionLabel(string $intention): string
    {
        return match ($intention) {
            'product_launch' => 'Lancement produit',
            'promotion' => 'Promotion',
            'event' => 'Evenement',
            'service_push' => 'Service a pousser',
            'client_reengagement' => 'Relance client',
            default => 'Campagne Pulse',
        };
    }

    private function timezone(User $owner): string
    {
        $timezone = trim((string) ($owner->company_timezone ?: config('app.timezone', 'UTC')));

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    private function limitedString(mixed $value, int $limit): ?string
    {
        $candidate = Str::limit(trim((string) ($value ?? '')), $limit, '');

        return $candidate !== '' ? $candidate : null;
    }
}
