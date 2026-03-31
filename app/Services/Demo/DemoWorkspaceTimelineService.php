<?php

namespace App\Services\Demo;

use App\Models\ActivityLog;
use App\Models\DemoWorkspace;
use App\Models\User;
use Illuminate\Support\Collection;

class DemoWorkspaceTimelineService
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        DemoWorkspace $workspace,
        string $action,
        ?User $actor = null,
        array $properties = [],
        ?string $description = null
    ): ActivityLog {
        return ActivityLog::record(
            $actor,
            $workspace,
            $action,
            $properties,
            $description ?? $this->descriptionForAction($action)
        );
    }

    /**
     * @param  iterable<int, ActivityLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    public function present(iterable $logs): array
    {
        return collect($logs)
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'label' => $this->labelForAction($log->action),
                'description' => $log->description ?: $this->descriptionForAction($log->action),
                'created_at' => $log->created_at?->toIso8601String(),
                'actor' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                ] : null,
                'properties' => $log->properties ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ActivityLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    public function presentCollection(Collection $logs): array
    {
        return $this->present($logs);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function recordLoginForUser(User $user, array $properties = []): ?ActivityLog
    {
        $tracking = $this->resolveLoginTracking($user);

        if (! $tracking) {
            return null;
        }

        $description = ($tracking['properties']['login_source'] ?? null) === 'owner'
            ? 'Demo owner login detected.'
            : (($tracking['properties']['login_role_label'] ?? 'Demo role').' login detected.');

        return $this->record(
            $tracking['workspace'],
            'demo_workspace.login_detected',
            $user,
            [
                ...$tracking['properties'],
                ...$properties,
            ],
            $description
        );
    }

    private function labelForAction(string $action): string
    {
        return match ($action) {
            'demo_workspace.draft_saved' => 'Draft saved',
            'demo_workspace.queued' => 'Queued',
            'demo_workspace.provisioning_started' => 'Provisioning started',
            'demo_workspace.ready' => 'Ready',
            'demo_workspace.failed' => 'Failed',
            'demo_workspace.login_detected' => 'Login detected',
            'demo_workspace.sent' => 'Access sent',
            'demo_workspace.access_email_sent' => 'Access email sent',
            'demo_workspace.unsent' => 'Marked not sent',
            'demo_workspace.expiration_updated' => 'Expiration updated',
            'demo_workspace.expiration_extended' => 'Expiration extended',
            'demo_workspace.baseline_saved' => 'Baseline saved',
            'demo_workspace.reset_queued' => 'Reset queued',
            'demo_workspace.reset_to_baseline' => 'Reset completed',
            'demo_workspace.cloned' => 'Cloned',
            'demo_workspace.expired' => 'Expired',
            'demo_workspace.sales_status_changed' => 'Sales status updated',
            'demo_workspace.prefill_applied' => 'Prefill applied',
            'demo_workspace.extra_access_revoked' => 'Extra access revoked',
            'demo_workspace.extra_access_regenerated' => 'Extra access regenerated',
            'demo_workspace.purged' => 'Purged',
            'demo_workspace.deleted' => 'Deleted',
            default => str($action)
                ->replace(['demo_workspace.', '_'], ['', ' '])
                ->title()
                ->toString(),
        };
    }

    private function descriptionForAction(string $action): string
    {
        return match ($action) {
            'demo_workspace.draft_saved' => 'Demo workspace saved as a draft without provisioning.',
            'demo_workspace.queued' => 'Demo workspace queued for provisioning.',
            'demo_workspace.provisioning_started' => 'Provisioning started in background.',
            'demo_workspace.ready' => 'Demo workspace is ready for the prospect.',
            'demo_workspace.failed' => 'Provisioning failed and needs admin review.',
            'demo_workspace.login_detected' => 'A demo user signed in to the workspace.',
            'demo_workspace.sent' => 'Access kit marked as sent to the prospect.',
            'demo_workspace.access_email_sent' => 'Access email was sent to the prospect.',
            'demo_workspace.unsent' => 'Access kit marked as not sent.',
            'demo_workspace.expiration_updated' => 'Expiration date updated.',
            'demo_workspace.expiration_extended' => 'Expiration date extended.',
            'demo_workspace.baseline_saved' => 'Baseline snapshot refreshed.',
            'demo_workspace.reset_queued' => 'Workspace reset queued from baseline.',
            'demo_workspace.reset_to_baseline' => 'Workspace reset to its saved baseline.',
            'demo_workspace.cloned' => 'Demo cloned from an existing workspace.',
            'demo_workspace.expired' => 'Demo workspace reached its expiration date.',
            'demo_workspace.sales_status_changed' => 'Sales lifecycle updated.',
            'demo_workspace.prefill_applied' => 'Discovery or CRM prefill captured.',
            'demo_workspace.extra_access_revoked' => 'An extra demo login was revoked.',
            'demo_workspace.extra_access_regenerated' => 'An extra demo login was regenerated with a new password.',
            'demo_workspace.purged' => 'Demo workspace was purged while keeping lifecycle history.',
            'demo_workspace.deleted' => 'Demo workspace and tenant data deleted.',
            default => 'Demo workspace activity recorded.',
        };
    }

    /**
     * @return array{workspace: DemoWorkspace, properties: array<string, mixed>}|null
     */
    private function resolveLoginTracking(User $user): ?array
    {
        $workspace = $user->relationLoaded('demoWorkspace')
            ? $user->demoWorkspace
            : $user->demoWorkspace()->first();

        if ($workspace) {
            return [
                'workspace' => $workspace,
                'properties' => [
                    'login_source' => 'owner',
                    'login_role_key' => 'owner',
                    'login_role_label' => 'Owner',
                ],
            ];
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        if (! $membership?->account_id) {
            return null;
        }

        $workspace = DemoWorkspace::query()
            ->where('owner_user_id', $membership->account_id)
            ->first();

        if (! $workspace) {
            return null;
        }

        return [
            'workspace' => $workspace,
            'properties' => [
                'login_source' => 'team_member',
                'login_role_key' => (string) ($membership->role ?? 'team_member'),
                'login_role_label' => str((string) ($membership->role ?? 'team member'))
                    ->replace('_', ' ')
                    ->title()
                    ->toString(),
                'login_title' => (string) ($membership->title ?? ''),
            ],
        ];
    }
}
