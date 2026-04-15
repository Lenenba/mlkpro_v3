<?php

namespace App\Services\Customers;

use App\Mail\RenderedTemplatePreviewMail;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Campaigns\BrandProfileService;
use App\Services\Campaigns\ConsentService;
use App\Services\Campaigns\EmailTemplateComposer;
use App\Services\Campaigns\FatigueLimiter;
use App\Services\Campaigns\MarketingSettingsService;
use App\Services\Campaigns\TemplateRenderer;
use App\Services\SmsNotificationService;
use App\Support\LocalePreference;
use App\Utils\RichTextSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CustomerBulkContactService
{
    public const OBJECTIVE_PAYMENT_FOLLOWUP = 'payment_followup';

    public const OBJECTIVE_PROMOTION = 'promotion';

    public const OBJECTIVE_ANNOUNCEMENT = 'announcement';

    public const OBJECTIVE_MANUAL_MESSAGE = 'manual_message';

    public function __construct(
        private readonly ConsentService $consentService,
        private readonly MarketingSettingsService $marketingSettingsService,
        private readonly BrandProfileService $brandProfileService,
        private readonly FatigueLimiter $fatigueLimiter,
        private readonly SmsNotificationService $smsNotificationService,
        private readonly EmailTemplateComposer $emailTemplateComposer,
        private readonly TemplateRenderer $templateRenderer,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function allowedChannels(): array
    {
        return [
            Campaign::CHANNEL_EMAIL,
            Campaign::CHANNEL_SMS,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedObjectives(): array
    {
        return [
            self::OBJECTIVE_PAYMENT_FOLLOWUP,
            self::OBJECTIVE_PROMOTION,
            self::OBJECTIVE_ANNOUNCEMENT,
            self::OBJECTIVE_MANUAL_MESSAGE,
        ];
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return array<string, mixed>
     */
    public function preview(
        User $accountOwner,
        Collection $customers,
        string $channel,
        string $objective,
        ?Product $offer = null,
        ?string $locale = null
    ): array {
        $evaluation = $this->evaluateRecipients($accountOwner, $customers, $channel, $objective);

        return $this->buildPreviewPayload(
            $accountOwner,
            $customers,
            $evaluation,
            $channel,
            $objective,
            $offer,
            $this->resolveLocale($locale, null, $accountOwner)
        );
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    public function send(
        User $accountOwner,
        ?User $actor,
        Collection $customers,
        array $payload,
        ?Product $offer = null,
        ?string $locale = null
    ): array {
        $channel = strtoupper(trim((string) ($payload['channel'] ?? '')));
        $objective = strtolower(trim((string) ($payload['objective'] ?? '')));
        $subjectTemplate = trim((string) ($payload['subject'] ?? ''));
        $bodyTemplate = trim((string) ($payload['body'] ?? ''));
        $resolvedLocale = $this->resolveLocale($locale, $actor, $accountOwner);
        $evaluation = $this->evaluateRecipients($accountOwner, $customers, $channel, $objective);

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($evaluation['eligible'] as $recipient) {
            $customer = $recipient['customer'];
            $invoiceSummary = $recipient['invoice_summary'];
            $renderContext = $this->renderContext($accountOwner, $customer, $invoiceSummary, $offer);
            $subject = $this->renderSubject($subjectTemplate, $objective, $channel, $renderContext, $offer, $resolvedLocale);
            $body = $this->renderBody($bodyTemplate, $objective, $channel, $renderContext, $offer, $resolvedLocale);

            $result = $channel === Campaign::CHANNEL_EMAIL
                ? $this->sendEmail(
                    $accountOwner,
                    (string) $recipient['destination'],
                    $subject,
                    $body,
                    $objective,
                    $renderContext,
                    $offer,
                    $resolvedLocale
                )
                : $this->sendSms($customer, (string) $recipient['destination'], $body);

            if ($result['ok'] ?? false) {
                $successCount += 1;

                ActivityLog::record($actor, $customer, 'bulk_contact_sent', [
                    'channel' => $channel,
                    'objective' => $objective,
                    'destination' => $recipient['destination'],
                    'open_invoice_count' => $invoiceSummary['open_invoice_count'] ?? 0,
                    'balance_due' => $invoiceSummary['balance_due'] ?? 0,
                ], 'Bulk contact sent');

                continue;
            }

            $failedCount += 1;
            $reason = (string) ($result['reason'] ?? 'send_failed');

            ActivityLog::record($actor, $customer, 'bulk_contact_failed', [
                'channel' => $channel,
                'objective' => $objective,
                'destination' => $recipient['destination'],
                'reason' => $reason,
            ], 'Bulk contact failed');

            if (count($errors) < 10) {
                $errors[] = [
                    'customer_id' => $customer->id,
                    'name' => $this->customerLabel($customer),
                    'reason' => $reason,
                ];
            }
        }

        $skippedCount = count($evaluation['excluded']);
        $processedCount = $customers->count();

        return [
            'message' => $this->resultMessage($successCount, $failedCount, $skippedCount),
            'channel' => $channel,
            'objective' => $objective,
            'processed_count' => $processedCount,
            'eligible_count' => count($evaluation['eligible']),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'reasons' => $evaluation['reason_counts'],
            'errors' => $errors,
        ];
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return array<string, mixed>
     */
    private function evaluateRecipients(User $accountOwner, Collection $customers, string $channel, string $objective): array
    {
        $normalizedChannel = strtoupper(trim($channel));
        $normalizedObjective = strtolower(trim($objective));
        $invoiceSummaries = $this->invoiceSummaries($accountOwner, $customers);
        $channelEnabled = $this->marketingSettingsService->isChannelEnabled($accountOwner, $normalizedChannel);
        $eligible = [];
        $excluded = [];
        $reasonCounts = [];

        foreach ($customers as $customer) {
            $invoiceSummary = $invoiceSummaries[$customer->id] ?? [
                'open_invoice_count' => 0,
                'balance_due' => 0.0,
                'invoice_numbers' => [],
            ];

            $destination = $this->rawDestination($customer, $normalizedChannel);
            $normalizedDestination = $this->normalizeDestination($normalizedChannel, $destination);
            $reason = null;

            if (! $channelEnabled) {
                $reason = 'channel_disabled';
            } elseif (! $normalizedDestination) {
                $reason = 'missing_destination';
            } elseif (
                $normalizedObjective === self::OBJECTIVE_PAYMENT_FOLLOWUP
                && (int) ($invoiceSummary['open_invoice_count'] ?? 0) < 1
            ) {
                $reason = 'no_open_invoices';
            } elseif ($this->requiresConsent($normalizedObjective)) {
                $consentDecision = $this->consentService->canReceive(
                    $accountOwner,
                    $customer,
                    $normalizedChannel,
                    $destination
                );

                if (! ($consentDecision['allowed'] ?? false)) {
                    $reason = (string) ($consentDecision['reason'] ?? 'consent_missing');
                } else {
                    $normalizedDestination = (string) ($consentDecision['destination'] ?? $normalizedDestination);
                }
            }

            if (! $reason && $this->requiresFatigueCheck($normalizedObjective)) {
                $fatigueDecision = $this->fatigueLimiter->canSend(
                    $accountOwner,
                    $customer,
                    $normalizedChannel
                );

                if (! ($fatigueDecision['allowed'] ?? false)) {
                    $reason = (string) ($fatigueDecision['reason'] ?? 'fatigue_limit');
                }
            }

            if ($reason) {
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                $excluded[] = [
                    'id' => $customer->id,
                    'name' => $this->customerLabel($customer),
                    'reason' => $reason,
                ];

                continue;
            }

            $eligible[] = [
                'customer' => $customer,
                'destination' => $normalizedDestination,
                'invoice_summary' => $invoiceSummary,
            ];
        }

        return [
            'eligible' => $eligible,
            'excluded' => $excluded,
            'reason_counts' => collect($reasonCounts)
                ->map(fn ($count, $reason) => [
                    'reason' => $reason,
                    'count' => $count,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function buildPreviewPayload(
        User $accountOwner,
        Collection $customers,
        array $evaluation,
        string $channel,
        string $objective,
        ?Product $offer,
        string $locale
    ): array {
        $currencyCode = $accountOwner->businessCurrencyCode();

        return [
            'channel' => strtoupper(trim($channel)),
            'objective' => strtolower(trim($objective)),
            'offer' => $this->offerPreview($offer),
            'selected_count' => $customers->count(),
            'eligible_count' => count($evaluation['eligible']),
            'excluded_count' => count($evaluation['excluded']),
            'reasons' => $evaluation['reason_counts'],
            'currency_code' => $currencyCode,
            'available_tokens' => $this->availableTokens(),
            'suggested_subject' => $this->defaultSubject($objective, $channel, $offer, $locale),
            'suggested_body' => $this->defaultBody($objective, $channel, $offer, $locale),
            'eligible_preview' => collect($evaluation['eligible'])
                ->take(5)
                ->map(fn (array $entry) => [
                    'id' => $entry['customer']->id,
                    'name' => $this->customerLabel($entry['customer']),
                    'destination' => $entry['destination'],
                    'open_invoice_count' => $entry['invoice_summary']['open_invoice_count'] ?? 0,
                    'balance_due' => $entry['invoice_summary']['balance_due'] ?? 0,
                ])
                ->values()
                ->all(),
            'excluded_preview' => collect($evaluation['excluded'])
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return array<int, array<string, mixed>>
     */
    private function invoiceSummaries(User $accountOwner, Collection $customers): array
    {
        $customerIds = $customers->modelKeys();
        if ($customerIds === []) {
            return [];
        }

        return Invoice::query()
            ->byUser($accountOwner->id)
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', ['draft', 'sent', 'awaiting_acceptance', 'accepted', 'partial', 'overdue'])
            ->withSum([
                'payments as payments_sum_amount' => fn ($query) => $query->whereIn('status', Payment::settledStatuses()),
            ], 'amount')
            ->get(['id', 'customer_id', 'number', 'status', 'total', 'currency_code'])
            ->groupBy('customer_id')
            ->map(function (Collection $invoices): array {
                return [
                    'open_invoice_count' => $invoices->count(),
                    'balance_due' => round((float) $invoices->sum(fn (Invoice $invoice) => (float) $invoice->balance_due), 2),
                    'invoice_numbers' => $invoices
                        ->take(3)
                        ->map(fn (Invoice $invoice) => (string) $invoice->number)
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function availableTokens(): array
    {
        return [
            '{customerName}',
            '{firstName}',
            '{companyName}',
            '{customerEmail}',
            '{customerPhone}',
            '{unpaidInvoiceCount}',
            '{balanceDue}',
            '{offerName}',
            '{offerPrice}',
            '{offerUrl}',
            '{offerImageUrl}',
            '{promoPercent}',
            '{promoCode}',
            '{promoEndDate}',
            '{productName}',
            '{serviceName}',
            '{brandName}',
            '{brandReplyToEmail}',
            '{brandPhone}',
        ];
    }

    /**
     * @param  array<string, mixed>  $invoiceSummary
     * @return array<string, string>
     */
    private function renderContext(User $accountOwner, Customer $customer, array $invoiceSummary, ?Product $offer = null): array
    {
        $customerName = $this->customerLabel($customer);
        $firstName = trim((string) ($customer->first_name ?? '')) ?: $customerName;
        $brandTokens = $this->brandProfileService->tokenMap($accountOwner);
        $offerUrl = $this->offerUrl($accountOwner, $offer);
        $promoPercent = $offer && $offer->promo_discount_percent !== null
            ? (string) $offer->promo_discount_percent
            : '';
        $promoEndDate = $offer?->promo_end_at?->format('Y-m-d') ?? '';
        $offerAvailability = '';
        if ($offer) {
            $offerAvailability = $offer->item_type === Product::ITEM_TYPE_SERVICE
                ? ($offer->is_active ? 'bookable' : 'unavailable')
                : ((int) $offer->stock > 0 ? 'in_stock' : 'out_of_stock');
        }

        return array_merge($brandTokens, [
            'customerName' => $customerName,
            'firstName' => $firstName,
            'companyName' => trim((string) ($customer->company_name ?? '')) ?: $customerName,
            'customerEmail' => trim((string) ($customer->email ?? '')),
            'customerPhone' => trim((string) ($customer->phone ?? '')),
            'unpaidInvoiceCount' => (string) ((int) ($invoiceSummary['open_invoice_count'] ?? 0)),
            'balanceDue' => $this->formatMoney(
                (float) ($invoiceSummary['balance_due'] ?? 0),
                $accountOwner->businessCurrencyCode()
            ),
            'offerName' => (string) ($offer?->name ?? ''),
            'offerPrice' => $offer ? $this->formatMoney((float) $offer->price, $offer->currency_code ?: $accountOwner->businessCurrencyCode()) : '',
            'offerUrl' => $offerUrl,
            'offerImageUrl' => (string) ($offer?->image_url ?? ''),
            'trackedCtaUrl' => $offerUrl ?: (string) ($brandTokens['brandWebsiteUrl'] ?? ''),
            'promoPercent' => $promoPercent,
            'promoCode' => $promoPercent !== '' ? 'PROMO'.$offer?->id : '',
            'promoEndDate' => $promoEndDate,
            'offerAvailability' => $offerAvailability,
            'offerType' => (string) ($offer?->item_type ?? ''),
            'productName' => (string) ($offer?->name ?? ''),
            'productPrice' => $offer ? $this->formatMoney((float) $offer->price, $offer->currency_code ?: $accountOwner->businessCurrencyCode()) : '',
            'serviceName' => (string) ($offer?->item_type === Product::ITEM_TYPE_SERVICE ? $offer?->name : ''),
            'serviceCategory' => (string) ($offer?->item_type === Product::ITEM_TYPE_SERVICE ? ($offer?->category?->name ?? '') : ''),
        ]);
    }

    /**
     * @param  array<string, string>  $context
     */
    private function renderSubject(
        string $template,
        string $objective,
        string $channel,
        array $context,
        ?Product $offer,
        string $locale
    ): string {
        $base = $template !== ''
            ? $template
            : $this->defaultSubject($objective, $channel, $offer, $locale);

        return $this->templateRenderer->render($base, $context, false);
    }

    /**
     * @param  array<string, string>  $context
     */
    private function renderBody(
        string $template,
        string $objective,
        string $channel,
        array $context,
        ?Product $offer,
        string $locale
    ): string {
        $base = $template !== ''
            ? $template
            : $this->defaultBody($objective, $channel, $offer, $locale);

        return $this->templateRenderer->render($base, $context, false);
    }

    private function defaultSubject(string $objective, string $channel, ?Product $offer, string $locale): string
    {
        $locale = LocalePreference::normalize($locale);

        if ($channel !== Campaign::CHANNEL_EMAIL) {
            return '';
        }

        return match ($objective) {
            self::OBJECTIVE_PAYMENT_FOLLOWUP => match ($locale) {
                'fr' => 'Rappel de paiement de {brandName}',
                'es' => 'Recordatorio de pago de {brandName}',
                default => 'Payment reminder from {brandName}',
            },
            self::OBJECTIVE_PROMOTION => match ($locale) {
                'fr' => $offer?->item_type === Product::ITEM_TYPE_SERVICE
                    ? '{firstName}, decouvrez {offerName}'
                    : '{firstName}, profitez de {offerName}',
                'es' => $offer?->item_type === Product::ITEM_TYPE_SERVICE
                    ? '{firstName}, descubre {offerName}'
                    : '{firstName}, aprovecha {offerName}',
                default => $offer?->item_type === Product::ITEM_TYPE_SERVICE
                    ? '{firstName}, discover {offerName}'
                    : '{firstName}, enjoy {offerName}',
            },
            self::OBJECTIVE_ANNOUNCEMENT => match ($locale) {
                'fr' => 'Nouvelle importante de {brandName}',
                'es' => 'Nueva actualización de {brandName}',
                default => 'An important update from {brandName}',
            },
            self::OBJECTIVE_MANUAL_MESSAGE => match ($locale) {
                'fr' => 'Message de {brandName}',
                'es' => 'Mensaje de {brandName}',
                default => 'A message from {brandName}',
            },
            default => '',
        };
    }

    private function defaultBody(string $objective, string $channel, ?Product $offer, string $locale): string
    {
        $locale = LocalePreference::normalize($locale);

        if ($objective === self::OBJECTIVE_PAYMENT_FOLLOWUP && $channel === Campaign::CHANNEL_SMS) {
            return match ($locale) {
                'fr' => 'Bonjour {firstName}, rappel de {brandName}: vous avez {unpaidInvoiceCount} facture(s) ouverte(s) pour un solde de {balanceDue}. Repondez si vous avez besoin d aide.',
                'es' => 'Hola {firstName}, recordatorio de {brandName}: tienes {unpaidInvoiceCount} factura(s) abierta(s) por {balanceDue}. Responde si necesitas ayuda.',
                default => 'Hello {firstName}, friendly reminder from {brandName}: you currently have {unpaidInvoiceCount} open invoice(s) with {balanceDue} outstanding. Reply if you need help.',
            };
        }

        if ($objective === self::OBJECTIVE_PAYMENT_FOLLOWUP) {
            return match ($locale) {
                'fr' => "Bonjour {customerName},\n\nPetit rappel de la part de {brandName}. Votre compte affiche actuellement {unpaidInvoiceCount} facture(s) ouverte(s) pour un solde de {balanceDue}.\n\nSi vous avez besoin d une copie de facture ou d aide pour le paiement, il suffit de repondre a ce message.\n\nMerci,\n{brandName}",
                'es' => "Hola {customerName},\n\nEste es un recordatorio amable de {brandName}. Tu cuenta tiene actualmente {unpaidInvoiceCount} factura(s) abierta(s) por un saldo de {balanceDue}.\n\nSi necesitas una copia de la factura o ayuda para completar el pago, solo responde a este mensaje.\n\nGracias,\n{brandName}",
                default => "Hello {customerName},\n\nThis is a friendly reminder from {brandName}. Our records show {unpaidInvoiceCount} open invoice(s) on your account, with an outstanding balance of {balanceDue}.\n\nIf you need a copy of your invoice or help completing payment, simply reply to this message.\n\nThank you,\n{brandName}",
            };
        }

        if ($objective === self::OBJECTIVE_PROMOTION && $channel === Campaign::CHANNEL_SMS) {
            return match ($locale) {
                'fr' => 'Bonjour {firstName}, {brandName} souhaite vous presenter {offerName}. Repondez a ce message si vous voulez plus de details.',
                'es' => 'Hola {firstName}, {brandName} quiere presentarte {offerName}. Responde a este mensaje si deseas mas detalles.',
                default => 'Hello {firstName}, {brandName} would like to share {offerName} with you. Reply to this message if you would like more details.',
            };
        }

        if ($objective === self::OBJECTIVE_PROMOTION) {
            $productOrService = $offer?->item_type === Product::ITEM_TYPE_SERVICE ? '{serviceName}' : '{productName}';

            return match ($locale) {
                'fr' => "Bonjour {customerName},\n\nNous voulions vous presenter {$productOrService} de la part de {brandName}.\n\nRetrouvez ci-dessous le point fort de l offre, puis repondez a cet email si vous souhaitez plus de details.\n\nMerci,\n{brandName}",
                'es' => "Hola {customerName},\n\nQueremos presentarte {$productOrService} de parte de {brandName}.\n\nConsulta abajo los puntos fuertes de la oferta y responde a este correo si deseas mas detalles.\n\nGracias,\n{brandName}",
                default => "Hello {customerName},\n\nWe wanted to share {$productOrService} from {brandName} with you.\n\nSee the key offer details below, then reply to this email if you would like more information.\n\nThank you,\n{brandName}",
            };
        }

        if ($objective === self::OBJECTIVE_ANNOUNCEMENT && $channel === Campaign::CHANNEL_SMS) {
            return match ($locale) {
                'fr' => 'Bonjour {firstName}, {brandName} souhaite vous partager une mise a jour importante. Repondez a ce message si vous souhaitez plus de details.',
                'es' => 'Hola {firstName}, {brandName} quiere compartir una actualización importante contigo. Responde a este mensaje si deseas mas detalles.',
                default => 'Hello {firstName}, {brandName} would like to share an important update with you. Reply to this message if you would like more details.',
            };
        }

        if ($objective === self::OBJECTIVE_ANNOUNCEMENT) {
            return match ($locale) {
                'fr' => "Bonjour {customerName},\n\nNous voulions vous partager une mise a jour importante de la part de {brandName}.\n\nRetrouvez les informations essentielles ci-dessous, puis repondez a cet email si vous avez des questions.\n\nMerci,\n{brandName}",
                'es' => "Hola {customerName},\n\nQueríamos compartir contigo una actualización importante de parte de {brandName}.\n\nConsulta la información clave a continuación y responde a este correo si tienes alguna pregunta.\n\nGracias,\n{brandName}",
                default => "Hello {customerName},\n\nWe wanted to share an important update from {brandName}.\n\nPlease review the key information below and reply to this email if you have any questions.\n\nThank you,\n{brandName}",
            };
        }

        if ($channel === Campaign::CHANNEL_SMS) {
            return match ($locale) {
                'fr' => 'Bonjour {firstName}, voici un message de {brandName}.',
                'es' => 'Hola {firstName}, este es un mensaje de {brandName}.',
                default => 'Hello {firstName}, this is a message from {brandName}.',
            };
        }

        return match ($locale) {
            'fr' => "Bonjour {customerName},\n\nNous souhaitions vous contacter.\n\nMerci,\n{brandName}",
            'es' => "Hola {customerName},\n\nQueremos ponernos en contacto contigo.\n\nGracias,\n{brandName}",
            default => "Hello {customerName},\n\nWe wanted to reach out to you.\n\nThank you,\n{brandName}",
        };
    }

    private function sendEmail(
        User $accountOwner,
        string $destination,
        string $subject,
        string $body,
        string $objective,
        array $context,
        ?Product $offer,
        string $locale
    ): array {
        $brandProfile = $this->brandProfileService->resolve($accountOwner);
        $replyTo = trim((string) ($brandProfile['reply_to_email'] ?? ''));

        try {
            $htmlBody = $this->renderEmailHtml(
                $objective,
                $subject,
                $body,
                $context,
                $offer,
                $locale
            );

            $mailable = new RenderedTemplatePreviewMail(
                $subject !== '' ? $subject : 'Message from '.($brandProfile['name'] ?? config('app.name')),
                $htmlBody
            );

            if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mailable->replyTo($replyTo);
            }

            Mail::to($destination)->send($mailable);
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'reason' => 'mail_exception',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'ok' => true,
        ];
    }

    /**
     * @param  array<string, string>  $context
     */
    private function renderEmailHtml(
        string $objective,
        string $subject,
        string $body,
        array $context,
        ?Product $offer,
        string $locale
    ): string {
        $emailTitle = trim($subject) !== '' ? trim($subject) : ($context['brandName'] ?? config('app.name'));
        $bodyHtml = $this->normalizeEmailBodyHtml($body);
        $summaryRows = $this->bulkEmailSummaryRows($objective, $context, $offer, $locale);
        $action = $this->bulkEmailAction($objective, $context, $offer, $locale);
        $channelLabel = $this->bulkEmailText($locale, 'email_channel');
        $messageMetricLabel = $this->bulkEmailText($locale, 'message_metric');
        $ctaMetricLabel = $this->bulkEmailText($locale, 'cta_metric');
        $metrics = match ($objective) {
            self::OBJECTIVE_PAYMENT_FOLLOWUP => [
                ['value' => $context['unpaidInvoiceCount'] ?? '0', 'label' => $this->bulkEmailText($locale, 'open_invoices')],
                ['value' => $context['balanceDue'] ?? '-', 'label' => $this->bulkEmailText($locale, 'balance_due')],
                ['value' => $channelLabel, 'label' => $this->bulkEmailText($locale, 'channel')],
            ],
            self::OBJECTIVE_PROMOTION => array_values(array_filter([
                ['value' => $context['offerPrice'] ?? '', 'label' => $this->bulkEmailText($locale, 'price')],
                ! empty($context['promoPercent'])
                    ? ['value' => ($context['promoPercent'] ?? '').'%', 'label' => $this->bulkEmailText($locale, 'promotion_metric')]
                    : ['value' => $this->offerTypeLabel($offer, $locale), 'label' => $this->bulkEmailText($locale, 'type')],
                ['value' => $channelLabel, 'label' => $this->bulkEmailText($locale, 'channel')],
            ], fn (array $metric) => filled($metric['value'] ?? null))),
            default => [
                ['value' => '1', 'label' => $messageMetricLabel],
                ['value' => $action['label'] ? '1' : '0', 'label' => $ctaMetricLabel],
                ['value' => $channelLabel, 'label' => $this->bulkEmailText($locale, 'channel')],
            ],
        };

        $bodyPreview = $this->plainTextFromHtml($bodyHtml);

        return (string) view('emails.customer-bulk-outreach-branded', [
            'companyName' => $context['brandName'] ?? config('app.name'),
            'companyLogo' => $context['brandLogoUrl'] ?? null,
            'emailTitle' => $emailTitle,
            'emailPreheader' => Str::limit($this->bulkEmailHeroIntro($objective, $context, $offer, $locale, $bodyPreview), 140),
            'heroEyebrow' => $this->bulkEmailObjectiveLabel($objective, $locale),
            'heroTitle' => $emailTitle,
            'heroIntro' => $this->bulkEmailHeroIntro($objective, $context, $offer, $locale, $bodyPreview),
            'heroActionUrl' => $action['url'],
            'heroActionLabel' => $action['label'],
            'heroCaption' => $this->bulkEmailHeroCaption($objective, $locale),
            'heroSideTitle' => $this->bulkEmailText($locale, 'summary'),
            'heroSideLogo' => $objective === self::OBJECTIVE_PROMOTION && filled($context['offerImageUrl'] ?? null)
                ? $context['offerImageUrl']
                : ($context['brandLogoUrl'] ?? null),
            'heroSideRows' => array_slice($summaryRows, 0, 3),
            'heroMetrics' => $metrics,
            'messageHeading' => $this->bulkEmailText($locale, 'message_heading'),
            'summaryHeading' => $this->bulkEmailText($locale, 'summary'),
            'bodyHtml' => $bodyHtml,
            'summaryRows' => $summaryRows,
        ])->render();
    }

    /**
     * @param  array<string, string>  $context
     * @return array<int, array{label: string, value: string}>
     */
    private function bulkEmailSummaryRows(string $objective, array $context, ?Product $offer, string $locale): array
    {
        $rows = match ($objective) {
            self::OBJECTIVE_PAYMENT_FOLLOWUP => [
                ['label' => $this->bulkEmailText($locale, 'customer'), 'value' => (string) ($context['customerName'] ?? '-')],
                ['label' => $this->bulkEmailText($locale, 'balance_due'), 'value' => (string) ($context['balanceDue'] ?? '-')],
                ['label' => $this->bulkEmailText($locale, 'open_invoices'), 'value' => (string) ($context['unpaidInvoiceCount'] ?? '0')],
                ['label' => $this->bulkEmailText($locale, 'reply_to'), 'value' => (string) (($context['brandReplyToEmail'] ?? $context['brandContactEmail'] ?? '') ?: '-')],
                ['label' => $this->bulkEmailText($locale, 'phone'), 'value' => (string) (($context['brandPhone'] ?? '') ?: '-')],
            ],
            self::OBJECTIVE_PROMOTION => [
                ['label' => $this->bulkEmailText($locale, 'offer'), 'value' => (string) (($context['offerName'] ?? '') ?: '-')],
                ['label' => $this->bulkEmailText($locale, 'type'), 'value' => $this->offerTypeLabel($offer, $locale)],
                ['label' => $this->bulkEmailText($locale, 'price'), 'value' => (string) (($context['offerPrice'] ?? '') ?: '-')],
                ['label' => $this->bulkEmailText($locale, 'category'), 'value' => (string) (($context['serviceCategory'] ?? '') ?: '-')],
                ['label' => $this->bulkEmailText($locale, 'promo_code'), 'value' => (string) (($context['promoCode'] ?? '') ?: '-')],
            ],
            default => [
                ['label' => $this->bulkEmailText($locale, 'company'), 'value' => (string) (($context['brandName'] ?? '') ?: '-')],
                ['label' => $this->bulkEmailText($locale, 'reply_to'), 'value' => (string) (($context['brandReplyToEmail'] ?? $context['brandContactEmail'] ?? '') ?: '-')],
                ['label' => $this->bulkEmailText($locale, 'phone'), 'value' => (string) (($context['brandPhone'] ?? '') ?: '-')],
                ['label' => $this->bulkEmailText($locale, 'website'), 'value' => (string) (($context['brandWebsiteUrl'] ?? '') ?: '-')],
            ],
        };

        return array_values(array_filter(
            $rows,
            fn (array $row) => filled($row['value'] ?? null) && ($row['value'] ?? '-') !== '-'
        ));
    }

    /**
     * @param  array<string, string>  $context
     * @return array{label: string, url: string}
     */
    private function bulkEmailAction(string $objective, array $context, ?Product $offer, string $locale): array
    {
        return match ($objective) {
            self::OBJECTIVE_PAYMENT_FOLLOWUP => [
                'label' => $this->bulkEmailText($locale, 'contact_team'),
                'url' => (string) (($context['brandSupportUrl'] ?? $context['brandContactUrl'] ?? $context['brandWebsiteUrl'] ?? '') ?: ''),
            ],
            self::OBJECTIVE_PROMOTION => [
                'label' => $offer?->item_type === Product::ITEM_TYPE_SERVICE
                    ? $this->bulkEmailText($locale, 'book_now')
                    : $this->bulkEmailText($locale, 'view_offer'),
                'url' => (string) (($context['trackedCtaUrl'] ?? $context['offerUrl'] ?? $context['brandWebsiteUrl'] ?? '') ?: ''),
            ],
            self::OBJECTIVE_ANNOUNCEMENT => [
                'label' => $this->bulkEmailText($locale, 'view_update'),
                'url' => (string) (($context['brandWebsiteUrl'] ?? $context['brandContactUrl'] ?? '') ?: ''),
            ],
            default => [
                'label' => $this->bulkEmailText($locale, 'reply_to_us'),
                'url' => (string) (($context['brandContactUrl'] ?? $context['brandWebsiteUrl'] ?? '') ?: ''),
            ],
        };
    }

    /**
     * @param  array<string, string>  $context
     */
    private function bulkEmailHeroIntro(string $objective, array $context, ?Product $offer, string $locale, string $bodyPreview = ''): string
    {
        return match ($objective) {
            self::OBJECTIVE_PAYMENT_FOLLOWUP => match ($locale) {
                'fr' => sprintf(
                    'Votre compte affiche actuellement %s facture(s) ouverte(s) pour un solde de %s.',
                    $context['unpaidInvoiceCount'] ?? '0',
                    $context['balanceDue'] ?? '-'
                ),
                'es' => sprintf(
                    'Tu cuenta tiene actualmente %s factura(s) abierta(s) por un saldo de %s.',
                    $context['unpaidInvoiceCount'] ?? '0',
                    $context['balanceDue'] ?? '-'
                ),
                default => sprintf(
                    'Your account currently has %s open invoice(s) with an outstanding balance of %s.',
                    $context['unpaidInvoiceCount'] ?? '0',
                    $context['balanceDue'] ?? '-'
                ),
            },
            self::OBJECTIVE_PROMOTION => match ($locale) {
                'fr' => sprintf(
                    'Decouvrez %s avec une presentation claire, brandee et un acces direct a l action.',
                    $context['offerName'] ?? $this->bulkEmailText($locale, 'offer')
                ),
                'es' => sprintf(
                    'Descubre %s con una presentacion clara, con marca y acceso directo a la accion.',
                    $context['offerName'] ?? $this->bulkEmailText($locale, 'offer')
                ),
                default => sprintf(
                    'Discover %s with a clear branded presentation and a direct path to action.',
                    $context['offerName'] ?? $this->bulkEmailText($locale, 'offer')
                ),
            },
            self::OBJECTIVE_ANNOUNCEMENT => match ($locale) {
                'fr' => 'Une information importante vous est partagee dans un format clair, direct et brandé.',
                'es' => 'Se comparte una actualización importante contigo en un formato claro, directo y con marca.',
                default => 'An important update is being shared with you in a clear, direct, and branded format.',
            },
            default => trim($bodyPreview) !== ''
                ? Str::limit($bodyPreview, 180)
                : match ($locale) {
                    'fr' => 'Votre equipe vous contacte directement dans un format email clair et actionnable.',
                    'es' => 'Tu equipo se pone en contacto contigo en un formato claro y accionable.',
                    default => 'Your team is reaching out directly in a clear and actionable email format.',
                },
        };
    }

    private function bulkEmailHeroCaption(string $objective, string $locale): string
    {
        return match ($objective) {
            self::OBJECTIVE_PAYMENT_FOLLOWUP => match ($locale) {
                'fr' => 'Gardez un suivi clair de vos soldes, relances et operations client.',
                'es' => 'Mantén una vista clara de tus saldos, seguimientos y operaciones con clientes.',
                default => 'Keep a clear view of balances, follow-ups, and customer operations.',
            },
            self::OBJECTIVE_PROMOTION => match ($locale) {
                'fr' => 'Mettez en avant vos offres avec une presentation plus premium et directe.',
                'es' => 'Destaca tus ofertas con una presentacion mas premium y directa.',
                default => 'Highlight your offers with a more premium and direct presentation.',
            },
            self::OBJECTIVE_ANNOUNCEMENT => match ($locale) {
                'fr' => 'Diffusez vos annonces importantes dans un format plus clair et plus professionnel.',
                'es' => 'Comparte anuncios importantes en un formato mas claro y profesional.',
                default => 'Share important announcements in a clearer and more professional format.',
            },
            default => match ($locale) {
                'fr' => 'Gardez un suivi clair de vos actions, clients et operations.',
                'es' => 'Mantén una vista clara de tus acciones, clientes y operaciones.',
                default => 'Keep a clear view of your actions, customers, and operations.',
            },
        };
    }

    private function bulkEmailObjectiveLabel(string $objective, string $locale): string
    {
        return match ($objective) {
            self::OBJECTIVE_PAYMENT_FOLLOWUP => match ($locale) {
                'fr' => 'Relance paiement',
                'es' => 'Seguimiento de pago',
                default => 'Payment follow-up',
            },
            self::OBJECTIVE_PROMOTION => match ($locale) {
                'fr' => 'Promotion',
                'es' => 'Promocion',
                default => 'Promotion',
            },
            self::OBJECTIVE_ANNOUNCEMENT => match ($locale) {
                'fr' => 'Annonce',
                'es' => 'Anuncio',
                default => 'Announcement',
            },
            default => match ($locale) {
                'fr' => 'Notification',
                'es' => 'Notificacion',
                default => 'Notification',
            },
        };
    }

    private function offerTypeLabel(?Product $offer, string $locale): string
    {
        if (! $offer) {
            return '-';
        }

        if ($offer->item_type === Product::ITEM_TYPE_SERVICE) {
            return match ($locale) {
                'fr' => 'Service',
                'es' => 'Servicio',
                default => 'Service',
            };
        }

        return match ($locale) {
            'fr' => 'Produit',
            'es' => 'Producto',
            default => 'Product',
        };
    }

    private function bulkEmailText(string $locale, string $key): string
    {
        return match ($key) {
            'summary' => match ($locale) {
                'fr' => 'Resume',
                'es' => 'Resumen',
                default => 'Summary',
            },
            'message_heading' => match ($locale) {
                'fr' => 'Message',
                'es' => 'Mensaje',
                default => 'Message',
            },
            'customer' => match ($locale) {
                'fr' => 'Client',
                'es' => 'Cliente',
                default => 'Customer',
            },
            'company' => match ($locale) {
                'fr' => 'Entreprise',
                'es' => 'Empresa',
                default => 'Company',
            },
            'offer' => match ($locale) {
                'fr' => 'Offre',
                'es' => 'Oferta',
                default => 'Offer',
            },
            'type' => match ($locale) {
                'fr' => 'Type',
                'es' => 'Tipo',
                default => 'Type',
            },
            'price' => match ($locale) {
                'fr' => 'Prix',
                'es' => 'Precio',
                default => 'Price',
            },
            'category' => match ($locale) {
                'fr' => 'Categorie',
                'es' => 'Categoria',
                default => 'Category',
            },
            'reply_to' => match ($locale) {
                'fr' => 'Email de reponse',
                'es' => 'Correo de respuesta',
                default => 'Reply-to email',
            },
            'phone' => match ($locale) {
                'fr' => 'Telephone',
                'es' => 'Telefono',
                default => 'Phone',
            },
            'website' => match ($locale) {
                'fr' => 'Site web',
                'es' => 'Sitio web',
                default => 'Website',
            },
            'promo_code' => match ($locale) {
                'fr' => 'Code promo',
                'es' => 'Codigo promo',
                default => 'Promo code',
            },
            'open_invoices' => match ($locale) {
                'fr' => 'Factures ouvertes',
                'es' => 'Facturas abiertas',
                default => 'Open invoices',
            },
            'balance_due' => match ($locale) {
                'fr' => 'Solde du',
                'es' => 'Saldo pendiente',
                default => 'Balance due',
            },
            'channel' => match ($locale) {
                'fr' => 'Canal',
                'es' => 'Canal',
                default => 'Channel',
            },
            'email_channel' => 'Email',
            'message_metric' => match ($locale) {
                'fr' => 'Message',
                'es' => 'Mensaje',
                default => 'Message',
            },
            'cta_metric' => 'CTA',
            'promotion_metric' => match ($locale) {
                'fr' => 'Promo',
                'es' => 'Promo',
                default => 'Promo',
            },
            'contact_team' => match ($locale) {
                'fr' => 'Parler a l equipe',
                'es' => 'Hablar con el equipo',
                default => 'Talk to the team',
            },
            'view_offer' => match ($locale) {
                'fr' => 'Voir l offre',
                'es' => 'Ver oferta',
                default => 'View offer',
            },
            'view_update' => match ($locale) {
                'fr' => 'Voir l annonce',
                'es' => 'Ver anuncio',
                default => 'View update',
            },
            'book_now' => match ($locale) {
                'fr' => 'Reserver maintenant',
                'es' => 'Reservar ahora',
                default => 'Book now',
            },
            'reply_to_us' => match ($locale) {
                'fr' => 'Nous repondre',
                'es' => 'Responder',
                default => 'Reply to us',
            },
            default => '',
        };
    }

    private function normalizeEmailBodyHtml(string $body): string
    {
        $normalized = trim($body);
        if ($normalized === '') {
            return '';
        }

        if (! preg_match('/<[^>]+>/', $normalized)) {
            return nl2br(e($normalized));
        }

        $sanitized = RichTextSanitizer::sanitize($normalized);

        return $sanitized !== '' ? $sanitized : nl2br(e(strip_tags($normalized)));
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<\/div>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<string, mixed>
     */
    private function emailContent(
        string $objective,
        string $subject,
        string $body,
        ?Product $offer,
        string $locale
    ): array {
        $locale = LocalePreference::normalize($locale);

        [$headerBlocks, $bodyBlocks, $footerBlocks, $previewText] = match ($objective) {
            self::OBJECTIVE_PAYMENT_FOLLOWUP => $this->paymentFollowupEmailBlocks($locale, $body),
            self::OBJECTIVE_PROMOTION => $this->promotionEmailBlocks($locale, $body, $offer),
            default => $this->manualMessageEmailBlocks($locale, $body),
        };

        return [
            'subject' => $subject,
            'previewText' => $previewText,
            'editorMode' => 'builder',
            'templateKey' => 'customer-bulk-'.$objective,
            'schema' => [
                'sections' => $this->simpleSections($headerBlocks, $bodyBlocks, $footerBlocks),
            ],
        ];
    }

    /**
     * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, string>>, 2: array<int, array<string, string>>, 3: string}
     */
    private function paymentFollowupEmailBlocks(string $locale, string $body): array
    {
        return match ($locale) {
            'fr' => [
                [
                    $this->simpleBlock(
                        title: 'Rappel de paiement',
                        body: $body,
                        kicker: 'Suivi client',
                        buttonLabel: 'Nous contacter',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Situation actuelle',
                        body: "Factures ouvertes: {unpaidInvoiceCount}\nSolde du: {balanceDue}"
                    ),
                    $this->simpleBlock(
                        title: 'Besoin d aide ?',
                        body: "Repondez a cet email ou appelez {brandPhone}.\nNous pouvons vous renvoyer la facture si besoin."
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Regler ou poser une question',
                        body: 'Notre equipe reste disponible pour finaliser le paiement ou clarifier votre facture.',
                        buttonLabel: 'Parler a l equipe',
                        buttonUrl: '{brandSupportUrl}'
                    ),
                ],
                'Rappel de paiement et resume du solde du client.',
            ],
            'es' => [
                [
                    $this->simpleBlock(
                        title: 'Recordatorio de pago',
                        body: $body,
                        kicker: 'Seguimiento cliente',
                        buttonLabel: 'Contactarnos',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Situacion actual',
                        body: "Facturas abiertas: {unpaidInvoiceCount}\nSaldo pendiente: {balanceDue}"
                    ),
                    $this->simpleBlock(
                        title: 'Necesitas ayuda?',
                        body: "Responde a este correo o llama a {brandPhone}.\nPodemos reenviar la factura si hace falta."
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Pagar o hacer una pregunta',
                        body: 'Nuestro equipo sigue disponible para ayudarte con el pago o aclarar tu factura.',
                        buttonLabel: 'Hablar con el equipo',
                        buttonUrl: '{brandSupportUrl}'
                    ),
                ],
                'Recordatorio de pago y resumen del saldo pendiente.',
            ],
            default => [
                [
                    $this->simpleBlock(
                        title: 'Payment reminder',
                        body: $body,
                        kicker: 'Customer follow-up',
                        buttonLabel: 'Contact us',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Current status',
                        body: "Open invoices: {unpaidInvoiceCount}\nBalance due: {balanceDue}"
                    ),
                    $this->simpleBlock(
                        title: 'Need help?',
                        body: "Reply to this email or call {brandPhone}.\nWe can resend the invoice if needed."
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Pay or ask a question',
                        body: 'Our team is available to help you complete payment or clarify your invoice.',
                        buttonLabel: 'Talk to the team',
                        buttonUrl: '{brandSupportUrl}'
                    ),
                ],
                'Payment reminder and outstanding balance summary.',
            ],
        };
    }

    /**
     * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, string>>, 2: array<int, array<string, string>>, 3: string}
     */
    private function promotionEmailBlocks(string $locale, string $body, ?Product $offer): array
    {
        $isService = $offer?->item_type === Product::ITEM_TYPE_SERVICE;

        return match ($locale) {
            'fr' => [
                [
                    $this->simpleBlock(
                        title: '{offerName}',
                        body: $body,
                        kicker: $isService ? 'Service a la une' : 'Produit a la une',
                        buttonLabel: $isService ? 'Reserver maintenant' : 'Voir l offre',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                    $this->simpleBlock(
                        title: '{offerPrice}',
                        body: $isService
                            ? "{serviceCategory}\nContact direct avec votre equipe"
                            : "Promo: {promoPercent}\nCode: {promoCode}"
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Pourquoi maintenant',
                        body: $isService
                            ? "Prestation claire\nReservation simple\nEquipe facilement joignable"
                            : "Offre visible\nPrix clair\nAction immediate"
                    ),
                    $this->simpleBlock(
                        title: 'Besoin d un conseil ?',
                        body: 'Repondez a cet email si vous souhaitez plus de details avant de passer a l action.'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Passer a l action',
                        body: $isService
                            ? 'Dirigez vos clients vers la reservation ou un echange direct avec votre equipe.'
                            : 'Redirigez vos clients vers le produit ou votre boutique pour finaliser la decouverte.',
                        buttonLabel: $isService ? 'Prendre rendez-vous' : 'Continuer',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                'Mise en avant brandee d une offre produit ou service.',
            ],
            'es' => [
                [
                    $this->simpleBlock(
                        title: '{offerName}',
                        body: $body,
                        kicker: $isService ? 'Servicio destacado' : 'Producto destacado',
                        buttonLabel: $isService ? 'Reservar ahora' : 'Ver oferta',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                    $this->simpleBlock(
                        title: '{offerPrice}',
                        body: $isService
                            ? "{serviceCategory}\nContacto directo con tu equipo"
                            : "Promo: {promoPercent}\nCodigo: {promoCode}"
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Por que ahora',
                        body: $isService
                            ? "Servicio claro\nReserva sencilla\nEquipo facil de contactar"
                            : "Oferta visible\nPrecio claro\nAccion inmediata"
                    ),
                    $this->simpleBlock(
                        title: 'Necesitas consejo?',
                        body: 'Responde a este correo si quieres mas detalles antes de tomar una decision.'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Dar el siguiente paso',
                        body: $isService
                            ? 'Lleva a tus clientes a la reserva o a una conversacion directa con tu equipo.'
                            : 'Lleva a tus clientes al producto o a tu tienda para continuar.',
                        buttonLabel: $isService ? 'Reservar ahora' : 'Continuar',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                'Promocion de producto o servicio con formato de marca.',
            ],
            default => [
                [
                    $this->simpleBlock(
                        title: '{offerName}',
                        body: $body,
                        kicker: $isService ? 'Featured service' : 'Featured product',
                        buttonLabel: $isService ? 'Book now' : 'View offer',
                        buttonUrl: '{trackedCtaUrl}',
                        imageUrl: '{offerImageUrl}'
                    ),
                    $this->simpleBlock(
                        title: '{offerPrice}',
                        body: $isService
                            ? "{serviceCategory}\nDirect access to your team"
                            : "Promo: {promoPercent}\nCode: {promoCode}"
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Why now',
                        body: $isService
                            ? "Clear service\nEasy booking\nA team you can reach quickly"
                            : "Visible offer\nClear price\nImmediate action"
                    ),
                    $this->simpleBlock(
                        title: 'Need advice?',
                        body: 'Reply to this email if you would like more details before taking the next step.'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Take the next step',
                        body: $isService
                            ? 'Lead customers to booking or a direct conversation with your team.'
                            : 'Send customers to the product or your store to keep the momentum.',
                        buttonLabel: $isService ? 'Book now' : 'Continue',
                        buttonUrl: '{trackedCtaUrl}'
                    ),
                ],
                'Branded promotion for a product or service.',
            ],
        };
    }

    /**
     * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, string>>, 2: array<int, array<string, string>>, 3: string}
     */
    private function manualMessageEmailBlocks(string $locale, string $body): array
    {
        return match ($locale) {
            'fr' => [
                [
                    $this->simpleBlock(
                        title: 'Message de votre equipe',
                        body: $body,
                        kicker: '{brandName}',
                        buttonLabel: 'Nous repondre',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Restons en contact',
                        body: 'Vous pouvez repondre directement a cet email ou nous joindre au {brandPhone}.'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Besoin de plus d informations ?',
                        body: '{brandDescription}',
                        buttonLabel: 'Voir le site',
                        buttonUrl: '{brandWebsiteUrl}'
                    ),
                ],
                'Message direct envoye depuis la liste clients.',
            ],
            'es' => [
                [
                    $this->simpleBlock(
                        title: 'Mensaje de tu equipo',
                        body: $body,
                        kicker: '{brandName}',
                        buttonLabel: 'Responder',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Sigamos en contacto',
                        body: 'Puedes responder directamente a este correo o llamarnos al {brandPhone}.'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Necesitas mas informacion?',
                        body: '{brandDescription}',
                        buttonLabel: 'Ver sitio web',
                        buttonUrl: '{brandWebsiteUrl}'
                    ),
                ],
                'Mensaje directo enviado desde la lista de clientes.',
            ],
            default => [
                [
                    $this->simpleBlock(
                        title: 'A message from your team',
                        body: $body,
                        kicker: '{brandName}',
                        buttonLabel: 'Reply to us',
                        buttonUrl: '{brandContactUrl}'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Stay in touch',
                        body: 'You can reply directly to this email or reach us at {brandPhone}.'
                    ),
                ],
                [
                    $this->simpleBlock(
                        title: 'Need more information?',
                        body: '{brandDescription}',
                        buttonLabel: 'Visit the website',
                        buttonUrl: '{brandWebsiteUrl}'
                    ),
                ],
                'Direct message sent from the customer list.',
            ],
        };
    }

    /**
     * @param  array<int, array<string, string>>  $headerBlocks
     * @param  array<int, array<string, string>>  $bodyBlocks
     * @param  array<int, array<string, string>>  $footerBlocks
     * @return array<int, array<string, mixed>>
     */
    private function simpleSections(array $headerBlocks, array $bodyBlocks, array $footerBlocks): array
    {
        return [
            [
                'key' => 'header',
                'enabled' => true,
                'background_mode' => 'white',
                'text_align' => 'left',
                'spacing_top' => 'normal',
                'spacing_bottom' => 'normal',
                'cta_style' => 'solid',
                'column_count' => max(1, min(3, count($headerBlocks))),
                'columns' => $headerBlocks,
            ],
            [
                'key' => 'body',
                'enabled' => true,
                'background_mode' => 'white',
                'text_align' => 'left',
                'spacing_top' => 'normal',
                'spacing_bottom' => 'normal',
                'cta_style' => 'solid',
                'column_count' => max(1, min(3, count($bodyBlocks))),
                'columns' => $bodyBlocks,
            ],
            [
                'key' => 'footer',
                'enabled' => true,
                'background_mode' => 'white',
                'text_align' => 'left',
                'spacing_top' => 'normal',
                'spacing_bottom' => 'normal',
                'cta_style' => 'solid',
                'column_count' => max(1, min(3, count($footerBlocks))),
                'columns' => $footerBlocks,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function simpleBlock(
        string $title = '',
        string $body = '',
        string $kicker = '',
        string $buttonLabel = '',
        string $buttonUrl = '',
        string $imageUrl = ''
    ): array {
        return [
            'id' => (string) str()->uuid(),
            'kicker' => $kicker,
            'title' => $title,
            'body' => $body,
            'image_url' => $imageUrl,
            'button_label' => $buttonLabel,
            'button_url' => $buttonUrl,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function offerPreview(?Product $offer): ?array
    {
        if (! $offer) {
            return null;
        }

        return [
            'id' => $offer->id,
            'name' => $offer->name,
            'item_type' => $offer->item_type,
            'price' => $offer->price,
            'currency_code' => $offer->currency_code,
            'category_name' => $offer->category?->name,
        ];
    }

    private function offerUrl(User $accountOwner, ?Product $offer): string
    {
        if (! $offer) {
            return '';
        }

        $slug = trim((string) ($accountOwner->company_slug ?? ''));
        if ($slug === '') {
            return '';
        }

        if ($offer->item_type === Product::ITEM_TYPE_SERVICE) {
            $bookingUrl = trim((string) ($this->brandProfileService->resolve($accountOwner)['booking_url'] ?? ''));

            return $bookingUrl !== '' ? $bookingUrl : route('public.showcase.show', ['slug' => $slug]);
        }

        return route('public.store.show', ['slug' => $slug]);
    }

    private function resolveLocale(?string $locale, ?User $actor, User $accountOwner): string
    {
        if ($locale && LocalePreference::isSupported($locale)) {
            return LocalePreference::normalize($locale);
        }

        return LocalePreference::forUser($actor ?? $accountOwner);
    }

    private function sendSms(Customer $customer, string $destination, string $body): array
    {
        $result = $this->smsNotificationService->sendWithResult($destination, $body);
        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($result['reason'] ?? 'sms_error'),
            ];
        }

        return [
            'ok' => true,
        ];
    }

    private function rawDestination(Customer $customer, string $channel): string
    {
        return $channel === Campaign::CHANNEL_EMAIL
            ? trim((string) ($customer->email ?? ''))
            : trim((string) ($customer->phone ?? ''));
    }

    private function normalizeDestination(string $channel, ?string $destination): ?string
    {
        $value = trim((string) $destination);
        if ($value === '') {
            return null;
        }

        if ($channel === Campaign::CHANNEL_EMAIL) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : null;
        }

        if ($channel === Campaign::CHANNEL_SMS) {
            $digits = preg_replace('/\D+/', '', $value) ?: '';
            if ($digits === '') {
                return null;
            }

            if (str_starts_with($digits, '00') && strlen($digits) > 2) {
                $digits = substr($digits, 2);
            }

            if (strlen($digits) === 10) {
                return '+1'.$digits;
            }

            if (strlen($digits) >= 11) {
                return '+'.$digits;
            }
        }

        return null;
    }

    private function requiresConsent(string $objective): bool
    {
        return $objective !== self::OBJECTIVE_PAYMENT_FOLLOWUP;
    }

    private function requiresFatigueCheck(string $objective): bool
    {
        return $objective !== self::OBJECTIVE_PAYMENT_FOLLOWUP;
    }

    private function customerLabel(Customer $customer): string
    {
        $company = trim((string) ($customer->company_name ?? ''));
        if ($company !== '') {
            return $company;
        }

        $name = trim((string) (($customer->first_name ?? '').' '.($customer->last_name ?? '')));
        if ($name !== '') {
            return $name;
        }

        return 'Customer #'.$customer->id;
    }

    private function formatMoney(float $amount, string $currencyCode): string
    {
        return number_format($amount, 2, '.', ',').' '.$currencyCode;
    }

    private function resultMessage(int $successCount, int $failedCount, int $skippedCount): string
    {
        if ($successCount > 0 && $failedCount === 0 && $skippedCount === 0) {
            return "Message sent to {$successCount} customer(s).";
        }

        if ($successCount > 0) {
            return "Bulk contact completed. Sent: {$successCount}, failed: {$failedCount}, skipped: {$skippedCount}.";
        }

        return "No message was sent. Failed: {$failedCount}, skipped: {$skippedCount}.";
    }
}
