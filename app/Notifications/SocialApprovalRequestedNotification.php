<?php

namespace App\Notifications;

use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\SocialPostVisualPreviewService;
use App\Support\QueueWorkload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SocialApprovalRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SocialPost $post,
        public SocialApprovalRequest $approvalRequest
    ) {
        $this->onQueue(QueueWorkload::queue('notifications'));
    }

    public function backoff(): array
    {
        return QueueWorkload::backoff('notifications', [60, 300, 900]);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $post = $this->post->fresh([
            'user',
            'automationRule',
            'targets.socialAccountConnection',
            'latestApprovalRequest.requestedBy',
        ]) ?? $this->post;

        $owner = $post->user instanceof User
            ? $post->user
            : User::query()->find((int) $post->user_id);

        $recipient = $notifiable instanceof User ? $notifiable : $owner;
        $owner ??= $recipient;

        $payload = app(SocialPostVisualPreviewService::class)->approvalEmailPayload(
            $post,
            $owner,
            $recipient,
            $this->approvalRequest
        );

        return (new MailMessage)
            ->subject((string) $payload['subject'])
            ->view('emails.social.approval-requested', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Pulse: post a valider',
            'message' => 'Un post Pulse attend votre validation.',
            'action_url' => route('social.approvals.index'),
            'event' => 'social_approval_requested',
            'social_post_id' => $this->post->id,
            'social_approval_request_id' => $this->approvalRequest->id,
        ];
    }
}
