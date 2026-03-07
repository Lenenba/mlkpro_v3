# Phase 6 Queue Strategy

## Goal

Keep HTTP requests focused on business-critical writes and move asynchronous side effects onto explicit queues with measurable health.

## Workloads

Defined in [async.php](c:\Users\060507CA8\Herd\mlkpro_v3\config\async.php):

- `notifications`
- `leads`
- `works`
- `campaigns_dispatch`
- `campaigns_send`
- `campaigns_maintenance`

Queue names are now centralized instead of being scattered across jobs and notifications.

## Applied Routing

Jobs now bind to explicit workloads:

- `GenerateWorkTasks` -> `works`
- `RetryLeadQuoteEmailJob` -> `leads`
- `DispatchCampaignRunJob` -> `campaigns_dispatch`
- `SendCampaignRecipientJob` -> `campaigns_send`
- `ReconcileDeliveryReportsJob` -> `campaigns_maintenance`
- `ComputeInterestScoresJob` -> `campaigns_maintenance`

Queued notifications now bind to `notifications`, including:

- action emails
- quote emails
- lead notifications
- invites
- reservation database notifications
- campaign in-app notifications
- supplier stock requests
- welcome emails
- platform admin digests

## Retry Policy

Backoff policies are centralized in `config/async.php` and resolved through [QueueWorkload.php](c:\Users\060507CA8\Herd\mlkpro_v3\app\Support\QueueWorkload.php).

This keeps queue timing changes out of individual classes.

## Health Visibility

Queue health is now exposed through:

- [QueueHealthService.php](c:\Users\060507CA8\Herd\mlkpro_v3\app\Services\QueueHealthService.php)
- `php artisan queue:health`
- `php artisan queue:health --json`

Reported metrics:

- queue connection / driver
- pending jobs total
- pending jobs by queue
- oldest queued job age
- failed jobs over 24h and 7d

Superadmin health metrics now reuse the same queue health service instead of duplicating queue queries.

## Worker Strategy

Recommended worker split:

```bash
php artisan queue:work --queue=notifications,leads,works --tries=1
php artisan queue:work --queue=campaigns-dispatch,campaigns-send,campaigns-maintenance --tries=1
```

Reason:

- user-facing notifications stay isolated from marketing volume
- campaigns cannot starve CRM and operational queues
- work generation stays independent from mail-heavy workloads

Job-specific retry behavior remains defined in code and config.

## Notes

- This phase does not change user-facing behavior.
- This phase makes queue behavior explicit and measurable.
- Future phases can build on this for alerting, autoscaling, and backlog SLOs.
