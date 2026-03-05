<?php

namespace App\Services\Campaigns;

use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TemplateLibraryService
{
    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, MessageTemplate>
     */
    public function list(User $accountOwner, array $filters = []): Collection
    {
        return MessageTemplate::query()
            ->where('user_id', $accountOwner->id)
            ->when($filters['channel'] ?? null, function (Builder $query, mixed $channel): void {
                $query->where('channel', strtoupper((string) $channel));
            })
            ->when($filters['campaign_type'] ?? null, function (Builder $query, mixed $campaignType): void {
                $query->where('campaign_type', strtoupper((string) $campaignType));
            })
            ->when(array_key_exists('language', $filters), function (Builder $query) use ($filters): void {
                $language = $filters['language'];
                if ($language === null || trim((string) $language) === '') {
                    $query->whereNull('language');
                    return;
                }

                $query->where('language', strtoupper(trim((string) $language)));
            })
            ->when($filters['search'] ?? null, function (Builder $query, mixed $search): void {
                $value = trim((string) $search);
                if ($value !== '') {
                    $query->where('name', 'like', '%' . $value . '%');
                }
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function save(
        User $accountOwner,
        User $actor,
        array $payload,
        ?MessageTemplate $template = null
    ): MessageTemplate {
        $channel = strtoupper((string) ($payload['channel'] ?? ''));
        if ($channel === '') {
            throw ValidationException::withMessages([
                'channel' => 'Channel is required.',
            ]);
        }

        $campaignType = $payload['campaign_type'] ?? null;
        $campaignType = $campaignType !== null && trim((string) $campaignType) !== ''
            ? strtoupper((string) $campaignType)
            : null;

        $language = $payload['language'] ?? null;
        $language = $language !== null && trim((string) $language) !== ''
            ? strtoupper((string) $language)
            : null;

        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $normalizedContent = $this->normalizeContent($channel, $content);

        $model = $template ?? new MessageTemplate();
        if ($model->exists && (int) $model->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'template' => 'Template does not belong to this tenant.',
            ]);
        }

        $model->fill([
            'user_id' => $accountOwner->id,
            'created_by_user_id' => $model->created_by_user_id ?: $actor->id,
            'updated_by_user_id' => $actor->id,
            'name' => trim((string) ($payload['name'] ?? '')),
            'channel' => $channel,
            'campaign_type' => $campaignType,
            'language' => $language,
            'content' => $normalizedContent,
            'is_default' => (bool) ($payload['is_default'] ?? false),
            'tags' => is_array($payload['tags'] ?? null) ? array_values($payload['tags']) : null,
        ]);
        $model->save();

        if ($model->is_default) {
            $this->demoteSiblings($model);
        }

        return $model->fresh();
    }

    public function delete(User $accountOwner, MessageTemplate $template): void
    {
        if ((int) $template->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'template' => 'Template does not belong to this tenant.',
            ]);
        }

        $template->delete();
    }

    public function resolveDefault(
        User $accountOwner,
        string $channel,
        ?string $campaignType = null,
        ?string $language = null
    ): ?MessageTemplate {
        $channel = strtoupper(trim($channel));
        $campaignType = $campaignType !== null && trim($campaignType) !== '' ? strtoupper(trim($campaignType)) : null;
        $language = $language !== null && trim($language) !== '' ? strtoupper(trim($language)) : null;

        $candidates = [
            [$campaignType, $language],
            [$campaignType, null],
            [null, $language],
            [null, null],
        ];

        foreach ($candidates as [$candidateType, $candidateLanguage]) {
            $template = MessageTemplate::query()
                ->where('user_id', $accountOwner->id)
                ->where('channel', $channel)
                ->where('is_default', true)
                ->when($candidateType !== null, fn (Builder $query) => $query->where('campaign_type', $candidateType))
                ->when($candidateType === null, fn (Builder $query) => $query->whereNull('campaign_type'))
                ->when($candidateLanguage !== null, fn (Builder $query) => $query->where('language', $candidateLanguage))
                ->when($candidateLanguage === null, fn (Builder $query) => $query->whereNull('language'))
                ->orderByDesc('updated_at')
                ->first();

            if ($template) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function normalizeContent(string $channel, array $content): array
    {
        $normalized = match (strtoupper($channel)) {
            'EMAIL' => [
                'subject' => trim((string) ($content['subject'] ?? '')),
                'previewText' => trim((string) ($content['previewText'] ?? $content['preview_text'] ?? '')),
                'html' => (string) ($content['html'] ?? $content['body'] ?? ''),
            ],
            'SMS' => [
                'text' => (string) ($content['text'] ?? $content['body'] ?? ''),
                'shortener' => (bool) ($content['shortener'] ?? false),
            ],
            'IN_APP' => [
                'title' => trim((string) ($content['title'] ?? '')),
                'body' => (string) ($content['body'] ?? ''),
                'deepLink' => trim((string) ($content['deepLink'] ?? $content['deep_link'] ?? '')),
                'image' => trim((string) ($content['image'] ?? $content['imageUrl'] ?? '')),
            ],
            default => $content,
        };

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function extractChannelTemplates(MessageTemplate $template): array
    {
        $content = $this->normalizeContent((string) $template->channel, (array) ($template->content ?? []));
        $channel = strtoupper((string) $template->channel);

        if ($channel === 'EMAIL') {
            return [
                'subject_template' => $this->nullableString($content['subject'] ?? null),
                'title_template' => null,
                'body_template' => $this->nullableString($content['html'] ?? null),
                'metadata' => [
                    'preview_text' => $this->nullableString($content['previewText'] ?? null),
                ],
            ];
        }

        if ($channel === 'SMS') {
            return [
                'subject_template' => null,
                'title_template' => null,
                'body_template' => $this->nullableString($content['text'] ?? null),
                'metadata' => [
                    'shortener' => (bool) ($content['shortener'] ?? false),
                ],
            ];
        }

        if ($channel === 'IN_APP') {
            return [
                'subject_template' => null,
                'title_template' => $this->nullableString($content['title'] ?? null),
                'body_template' => $this->nullableString($content['body'] ?? null),
                'metadata' => [
                    'deep_link' => $this->nullableString($content['deepLink'] ?? null),
                    'image' => $this->nullableString($content['image'] ?? null),
                ],
            ];
        }

        return [
            'subject_template' => null,
            'title_template' => null,
            'body_template' => null,
            'metadata' => null,
        ];
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function preview(
        string $channel,
        array $content,
        array $context,
        TemplateRenderer $renderer
    ): array {
        $normalized = $this->normalizeContent($channel, $content);
        $subject = null;
        $title = null;
        $body = null;
        $invalid = [];

        if (strtoupper($channel) === 'EMAIL') {
            $subjectTemplate = (string) ($normalized['subject'] ?? '');
            $bodyTemplate = (string) ($normalized['html'] ?? '');
            $subject = $renderer->render($subjectTemplate, $context, false);
            $body = $renderer->render($bodyTemplate, $context, true);
            $invalid = array_merge(
                $renderer->validateTemplate($subjectTemplate),
                $renderer->validateTemplate($bodyTemplate)
            );
        } elseif (strtoupper($channel) === 'SMS') {
            $bodyTemplate = (string) ($normalized['text'] ?? '');
            $body = $renderer->render($bodyTemplate, $context, false);
            $invalid = $renderer->validateTemplate($bodyTemplate);
        } elseif (strtoupper($channel) === 'IN_APP') {
            $titleTemplate = (string) ($normalized['title'] ?? '');
            $bodyTemplate = (string) ($normalized['body'] ?? '');
            $title = $renderer->render($titleTemplate, $context, false);
            $body = $renderer->render($bodyTemplate, $context, false);
            $invalid = array_merge(
                $renderer->validateTemplate($titleTemplate),
                $renderer->validateTemplate($bodyTemplate)
            );
        }

        return [
            'channel' => strtoupper($channel),
            'subject' => $subject,
            'title' => $title,
            'body' => $body,
            'invalid_tokens' => array_values(array_unique($invalid)),
            'character_count' => $body !== null ? mb_strlen($body) : 0,
        ];
    }

    private function demoteSiblings(MessageTemplate $template): void
    {
        MessageTemplate::query()
            ->where('user_id', $template->user_id)
            ->where('channel', $template->channel)
            ->where('id', '!=', $template->id)
            ->where(function (Builder $query) use ($template): void {
                if ($template->campaign_type === null) {
                    $query->whereNull('campaign_type');
                } else {
                    $query->where('campaign_type', $template->campaign_type);
                }
            })
            ->where(function (Builder $query) use ($template): void {
                if ($template->language === null) {
                    $query->whereNull('language');
                } else {
                    $query->where('language', $template->language);
                }
            })
            ->update(['is_default' => false]);
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);
        return $string !== '' ? $string : null;
    }
}
