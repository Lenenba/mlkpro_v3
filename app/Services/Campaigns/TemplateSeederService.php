<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignType;
use App\Models\Campaign;
use App\Models\MessageTemplate;
use App\Models\User;

class TemplateSeederService
{
    public function __construct(
        private readonly EmailTemplateComposer $emailTemplateComposer,
    ) {
    }

    /**
     * Seeds editable defaults directly into tenant templates.
     *
     * Approach A is intentionally used here:
     * - Templates are copied per tenant and remain fully editable.
     * - This avoids runtime indirection between system templates and tenant overrides.
     */
    public function seedDefaultsForTenant(User $accountOwner, ?User $actor = null): int
    {
        $seeded = 0;
        $actorId = $actor?->id ?: $accountOwner->id;

        foreach ($this->defaultTemplates() as $entry) {
            $template = MessageTemplate::query()->updateOrCreate(
                [
                    'user_id' => $accountOwner->id,
                    'channel' => $entry['channel'],
                    'campaign_type' => $entry['campaign_type'],
                    'language' => $entry['language'],
                    'name' => $entry['name'],
                ],
                [
                    'created_by_user_id' => $actorId,
                    'updated_by_user_id' => $actorId,
                    'content' => $entry['content'],
                    'is_default' => true,
                    'tags' => array_values(array_unique(array_merge(
                        ['seed', 'starter', strtolower($entry['channel'])],
                        is_array($entry['tags'] ?? null) ? $entry['tags'] : []
                    ))),
                ]
            );

            MessageTemplate::query()
                ->where('user_id', $accountOwner->id)
                ->where('channel', $entry['channel'])
                ->where('campaign_type', $entry['campaign_type'])
                ->where('language', $entry['language'])
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);

            $seeded++;
        }

        return $seeded;
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     channel: string,
     *     campaign_type: string,
     *     language: string,
     *     content: array<string, mixed>
     * }>
     */
    private function defaultTemplates(): array
    {
        $languages = ['FR', 'EN'];
        $rows = [];

        foreach ($this->emailTemplateComposer->presetCatalog() as $preset) {
            $rows[] = [
                'name' => (string) $preset['name'],
                'channel' => Campaign::CHANNEL_EMAIL,
                'campaign_type' => (string) $preset['campaign_type'],
                'language' => (string) $preset['language'],
                'content' => is_array($preset['content'] ?? null) ? $preset['content'] : [],
                'tags' => is_array($preset['tags'] ?? null) ? $preset['tags'] : [],
            ];
        }

        foreach (CampaignType::values() as $campaignType) {
            foreach ([Campaign::CHANNEL_SMS, Campaign::CHANNEL_IN_APP] as $channel) {
                foreach ($languages as $language) {
                    $rows[] = [
                        'name' => $this->templateName($campaignType, $channel, $language),
                        'channel' => $channel,
                        'campaign_type' => $campaignType,
                        'language' => $language,
                        'content' => $this->templateContent($campaignType, $channel, $language),
                        'tags' => [],
                    ];
                }
            }
        }

        return $rows;
    }

    private function templateName(string $campaignType, string $channel, string $language): string
    {
        $typeLabel = str_replace('_', ' ', strtolower($campaignType));
        $typeLabel = ucwords($typeLabel);

        return sprintf('%s %s %s Default', $typeLabel, strtoupper($channel), strtoupper($language));
    }

    /**
     * @return array<string, mixed>
     */
    private function templateContent(string $campaignType, string $channel, string $language): array
    {
        $isFrench = strtoupper($language) === 'FR';
        $campaignLabel = str_replace('_', ' ', strtolower($campaignType));

        if (strtoupper($channel) === Campaign::CHANNEL_EMAIL) {
            return [
                'subject' => $isFrench
                    ? "Mise a jour {$campaignLabel} pour {firstName}"
                    : ucfirst($campaignLabel) . ' update for {firstName}',
                'previewText' => $isFrench
                    ? 'Decouvrez votre offre: {offerName}'
                    : 'Discover your offer: {offerName}',
                'html' => $isFrench
                    ? '<p>Bonjour {firstName},</p><p>Profitez de {offerName} a {offerPrice}.</p><p><a href="{ctaUrl}">Voir l offre</a></p>'
                    : '<p>Hello {firstName},</p><p>Enjoy {offerName} at {offerPrice}.</p><p><a href="{ctaUrl}">View offer</a></p>',
            ];
        }

        if (strtoupper($channel) === Campaign::CHANNEL_SMS) {
            return [
                'text' => $isFrench
                    ? '{firstName}, offre {offerName} disponible: {ctaUrl}'
                    : '{firstName}, {offerName} is available now: {ctaUrl}',
                'shortener' => true,
            ];
        }

        return [
            'title' => $isFrench ? 'Nouveaute pour vous' : 'New update for you',
            'body' => $isFrench
                ? 'Profitez de {offerName} ({offerAvailability}).'
                : 'Enjoy {offerName} ({offerAvailability}).',
            'deepLink' => '/campaigns/{campaignId}',
            'image' => '{offerImageUrl}',
        ];
    }
}
