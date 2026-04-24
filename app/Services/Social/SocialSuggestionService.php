<?php

namespace App\Services\Social;

use App\Models\User;
use App\Support\LocalePreference;
use Illuminate\Support\Str;

class SocialSuggestionService
{
    public function __construct(
        private readonly SocialPrefillService $prefillService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function suggest(User $owner, array $payload, ?string $locale = null): array
    {
        $context = $this->buildContext($owner, $payload, LocalePreference::normalize($locale ?: $owner->locale));

        return [
            'context' => [
                'locale' => $context['locale'],
                'source_type' => $context['source_type'],
                'source_id' => $context['source_id'],
                'source_label' => $context['source_label'],
            ],
            'captions' => $this->captions($context),
            'hashtags' => $this->hashtags($context),
            'ctas' => $this->ctas($context),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildContext(User $owner, array $payload, string $locale): array
    {
        $sourcePayload = $this->prefillService->resolveComposerPrefill($owner, [
            'source_type' => $payload['source_type'] ?? null,
            'source_id' => $payload['source_id'] ?? null,
        ]);

        $sourceLabel = $this->nullableString($sourcePayload ?? [], 'source_label');
        $text = $this->nullableString($payload, 'text')
            ?? $this->nullableString($sourcePayload ?? [], 'text')
            ?? $this->fallbackSummary($owner, $locale);
        $paragraphs = $this->paragraphs($text);

        if ($sourceLabel === null && isset($paragraphs[0]) && Str::length($paragraphs[0]) <= 90) {
            $sourceLabel = $paragraphs[0];
        }

        $summary = $this->summaryLine($paragraphs, $sourceLabel) ?? $text;
        $summary = Str::limit($summary, 220);

        return [
            'locale' => $locale,
            'company_name' => trim((string) ($owner->company_name ?: $owner->name ?: config('app.name'))),
            'company_type' => trim((string) ($owner->company_type ?? 'services')),
            'company_sector' => trim((string) ($owner->company_sector ?? '')),
            'source_type' => $sourcePayload['source_type'] ?? null,
            'source_id' => $sourcePayload['source_id'] ?? null,
            'source_label' => $sourceLabel,
            'subject' => $sourceLabel ?: trim((string) ($owner->company_name ?: config('app.name'))),
            'summary' => $summary,
            'has_link' => $this->validUrl($payload['link_url'] ?? ($sourcePayload['link_url'] ?? null)) !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, string>>
     */
    private function captions(array $context): array
    {
        $copy = $this->copy((string) $context['locale']);
        $subject = (string) ($context['subject'] ?? $copy['generic_subject']);
        $summary = (string) ($context['summary'] ?? '');
        $companyName = (string) ($context['company_name'] ?? config('app.name'));

        return [
            [
                'key' => 'direct',
                'label' => $copy['caption_labels']['direct'],
                'text' => $this->joinBlocks([
                    $this->directCaptionLead($context, $subject, $companyName, $copy),
                    $summary,
                ]),
            ],
            [
                'key' => 'benefit',
                'label' => $copy['caption_labels']['benefit'],
                'text' => $this->joinBlocks([
                    str_replace(':subject', $subject, $copy['caption_leads']['benefit']),
                    $summary,
                ]),
            ],
            [
                'key' => 'cta',
                'label' => $copy['caption_labels']['cta'],
                'text' => $this->joinBlocks([
                    $this->actionCaptionLead($context, $subject, $copy),
                    $summary,
                    $this->ctaPrompt($context, $copy),
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function hashtags(array $context): array
    {
        $hashtags = match ((string) ($context['source_type'] ?? '')) {
            SocialPrefillService::SOURCE_PROMOTION => ['LimitedTime', 'OfferAlert', 'SpecialDeal'],
            SocialPrefillService::SOURCE_PRODUCT => ['ProductSpotlight', 'NewIn', 'ShopSmall'],
            SocialPrefillService::SOURCE_SERVICE => ['ServiceSpotlight', 'BookNow', 'ClientCare'],
            SocialPrefillService::SOURCE_CAMPAIGN => ['CampaignLaunch', 'BrandUpdate', 'Community'],
            default => ['BrandUpdate', 'LocalBusiness', 'MalikiaPulse'],
        };

        if (($context['company_type'] ?? 'services') === 'products') {
            $hashtags[] = 'Retail';
            $hashtags[] = 'ShopLocal';
        } else {
            $hashtags[] = 'ServiceBusiness';
            $hashtags[] = 'BookDirect';
        }

        match ((string) ($context['company_sector'] ?? '')) {
            'salon', 'wellness' => $hashtags = [...$hashtags, 'Beauty', 'SelfCare'],
            'retail' => $hashtags = [...$hashtags, 'RetailLife', 'StoreUpdate'],
            'field_services' => $hashtags = [...$hashtags, 'FieldService', 'OnSite'],
            'professional_services' => $hashtags = [...$hashtags, 'ProfessionalService', 'BusinessSupport'],
            default => null,
        };

        if (! empty($context['source_label'])) {
            $hashtags[] = (string) $context['source_label'];
        }

        return collect($hashtags)
            ->map(fn ($value) => $this->normalizeHashtag((string) $value))
            ->filter()
            ->unique(fn (string $value) => Str::lower($value))
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, string>>
     */
    private function ctas(array $context): array
    {
        $copy = $this->copy((string) $context['locale']);
        $companyType = (string) ($context['company_type'] ?? 'services');

        $options = $companyType === 'products'
            ? $copy['cta_products']
            : $copy['cta_services'];

        if ((bool) ($context['has_link'] ?? false)) {
            array_unshift($options, $copy['cta_link']);
        }

        return collect($options)
            ->map(function (array $item): array {
                return [
                    'key' => (string) ($item['key'] ?? Str::slug((string) ($item['label'] ?? 'cta'))),
                    'label' => (string) ($item['label'] ?? ''),
                    'text' => (string) ($item['text'] ?? ''),
                ];
            })
            ->filter(fn (array $item): bool => $item['label'] !== '' && $item['text'] !== '')
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $copy
     */
    private function directCaptionLead(array $context, string $subject, string $companyName, array $copy): string
    {
        return match ((string) ($context['source_type'] ?? '')) {
            SocialPrefillService::SOURCE_PROMOTION => str_replace(':subject', $subject, $copy['caption_leads']['promotion']),
            SocialPrefillService::SOURCE_PRODUCT => str_replace(':subject', $subject, $copy['caption_leads']['product']),
            SocialPrefillService::SOURCE_SERVICE => str_replace(':subject', $subject, $copy['caption_leads']['service']),
            SocialPrefillService::SOURCE_CAMPAIGN => str_replace(':subject', $subject, $copy['caption_leads']['campaign']),
            default => str_replace(':company', $companyName, $copy['caption_leads']['generic']),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $copy
     */
    private function actionCaptionLead(array $context, string $subject, array $copy): string
    {
        if ((bool) ($context['has_link'] ?? false)) {
            return str_replace(':subject', $subject, $copy['caption_leads']['action_with_link']);
        }

        return str_replace(':subject', $subject, $copy['caption_leads']['action_without_link']);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $copy
     */
    private function ctaPrompt(array $context, array $copy): string
    {
        return (bool) ($context['has_link'] ?? false)
            ? $copy['cta_prompt_with_link']
            : $copy['cta_prompt_without_link'];
    }

    /**
     * @return array<string, mixed>
     */
    private function copy(string $locale): array
    {
        return match ($locale) {
            'es' => [
                'generic_subject' => 'esta novedad',
                'caption_labels' => [
                    'direct' => 'Directo',
                    'benefit' => 'Valor',
                    'cta' => 'Accion',
                ],
                'caption_leads' => [
                    'promotion' => 'Oferta destacada: :subject.',
                    'product' => 'Producto en foco: :subject.',
                    'service' => 'Servicio en foco: :subject.',
                    'campaign' => 'Campana activa: :subject.',
                    'generic' => 'Novedades de :company.',
                    'benefit' => 'Lo esencial sobre :subject.',
                    'action_with_link' => ':subject ya esta listo para mostrarse.',
                    'action_without_link' => ':subject merece una publicacion rapida y clara.',
                ],
                'cta_prompt_with_link' => 'Abre el enlace para ver todos los detalles.',
                'cta_prompt_without_link' => 'Escribenos para recibir mas detalles.',
                'cta_link' => [
                    'key' => 'link',
                    'label' => 'Enlace',
                    'text' => 'Abre el enlace para ver todos los detalles.',
                ],
                'cta_products' => [
                    ['key' => 'shop', 'label' => 'Comprar', 'text' => 'Haz tu pedido ahora.'],
                    ['key' => 'message', 'label' => 'Mensaje', 'text' => 'Envianos un mensaje para recibir los detalles.'],
                    ['key' => 'details', 'label' => 'Detalles', 'text' => 'Pide los detalles hoy mismo.'],
                ],
                'cta_services' => [
                    ['key' => 'book', 'label' => 'Reservar', 'text' => 'Reserva tu espacio ahora.'],
                    ['key' => 'message', 'label' => 'Mensaje', 'text' => 'Envianos un mensaje para coordinar contigo.'],
                    ['key' => 'details', 'label' => 'Detalles', 'text' => 'Pide los detalles hoy mismo.'],
                ],
            ],
            'en' => [
                'generic_subject' => 'this update',
                'caption_labels' => [
                    'direct' => 'Direct',
                    'benefit' => 'Value',
                    'cta' => 'Action',
                ],
                'caption_leads' => [
                    'promotion' => 'Offer spotlight: :subject.',
                    'product' => 'Product spotlight: :subject.',
                    'service' => 'Service spotlight: :subject.',
                    'campaign' => 'Campaign spotlight: :subject.',
                    'generic' => 'Fresh from :company.',
                    'benefit' => 'What matters most about :subject.',
                    'action_with_link' => ':subject is ready to share.',
                    'action_without_link' => ':subject is worth a quick post today.',
                ],
                'cta_prompt_with_link' => 'Open the link for the full details.',
                'cta_prompt_without_link' => 'Send us a message to get the details.',
                'cta_link' => [
                    'key' => 'link',
                    'label' => 'Link',
                    'text' => 'Open the link for the full details.',
                ],
                'cta_products' => [
                    ['key' => 'shop', 'label' => 'Shop', 'text' => 'Place your order now.'],
                    ['key' => 'message', 'label' => 'Message', 'text' => 'Send us a message for the details.'],
                    ['key' => 'details', 'label' => 'Details', 'text' => 'Ask for the details today.'],
                ],
                'cta_services' => [
                    ['key' => 'book', 'label' => 'Book', 'text' => 'Book your spot now.'],
                    ['key' => 'message', 'label' => 'Message', 'text' => 'Send us a message so we can help.'],
                    ['key' => 'details', 'label' => 'Details', 'text' => 'Ask for the details today.'],
                ],
            ],
            default => [
                'generic_subject' => 'cette nouveaute',
                'caption_labels' => [
                    'direct' => 'Direct',
                    'benefit' => 'Valeur',
                    'cta' => 'Action',
                ],
                'caption_leads' => [
                    'promotion' => 'Offre a mettre en avant : :subject.',
                    'product' => 'Produit a mettre en avant : :subject.',
                    'service' => 'Service a mettre en avant : :subject.',
                    'campaign' => 'Campagne a relayer : :subject.',
                    'generic' => 'Nouveau chez :company.',
                    'benefit' => 'Ce qu il faut retenir sur :subject.',
                    'action_with_link' => ':subject est pret a etre partage.',
                    'action_without_link' => ':subject merite un post simple et rapide aujourd hui.',
                ],
                'cta_prompt_with_link' => 'Ouvrez le lien pour voir tous les details.',
                'cta_prompt_without_link' => 'Ecrivez-nous pour recevoir les details.',
                'cta_link' => [
                    'key' => 'link',
                    'label' => 'Lien',
                    'text' => 'Ouvrez le lien pour voir tous les details.',
                ],
                'cta_products' => [
                    ['key' => 'shop', 'label' => 'Commander', 'text' => 'Commandez maintenant.'],
                    ['key' => 'message', 'label' => 'Message', 'text' => 'Ecrivez-nous pour recevoir les details.'],
                    ['key' => 'details', 'label' => 'Details', 'text' => 'Demandez les details aujourd hui.'],
                ],
                'cta_services' => [
                    ['key' => 'book', 'label' => 'Reserver', 'text' => 'Reservez votre place maintenant.'],
                    ['key' => 'message', 'label' => 'Message', 'text' => 'Ecrivez-nous pour organiser cela avec vous.'],
                    ['key' => 'details', 'label' => 'Details', 'text' => 'Demandez les details aujourd hui.'],
                ],
            ],
        };
    }

    private function fallbackSummary(User $owner, string $locale): string
    {
        $company = trim((string) ($owner->company_name ?: config('app.name')));

        return match ($locale) {
            'es' => 'Comparte una actualizacion breve y clara con tu audiencia.',
            'en' => 'Share a short and clear update with your audience.',
            default => $company !== ''
                ? 'Partagez une mise a jour courte et claire pour '.$company.'.'
                : 'Partagez une mise a jour courte et claire avec votre audience.',
        };
    }

    /**
     * @return array<int, string>
     */
    private function paragraphs(string $text): array
    {
        return collect(preg_split('/\r\n|\r|\n/', $text) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $paragraphs
     */
    private function summaryLine(array $paragraphs, ?string $sourceLabel): ?string
    {
        return collect($paragraphs)
            ->reject(function (string $line) use ($sourceLabel): bool {
                if ($sourceLabel !== null && Str::lower($line) === Str::lower($sourceLabel)) {
                    return true;
                }

                return filter_var($line, FILTER_VALIDATE_URL) !== false;
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nullableString(array $payload, string $key): ?string
    {
        $value = trim((string) ($payload[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function validUrl(mixed $value): ?string
    {
        $candidate = trim((string) $value);

        return filter_var($candidate, FILTER_VALIDATE_URL)
            ? $candidate
            : null;
    }

    private function normalizeHashtag(string $value): ?string
    {
        $ascii = Str::ascii($value);
        $parts = preg_split('/[^A-Za-z0-9]+/', $ascii) ?: [];

        $token = collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->map(fn (string $part) => ucfirst(Str::lower($part)))
            ->implode('');

        return $token !== '' ? '#'.$token : null;
    }

    /**
     * @param  array<int, string|null>  $blocks
     */
    private function joinBlocks(array $blocks): string
    {
        return collect($blocks)
            ->map(fn ($block) => trim((string) $block))
            ->filter()
            ->implode("\n\n");
    }
}
