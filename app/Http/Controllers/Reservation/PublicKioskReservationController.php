<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\KioskCheckInRequest;
use App\Http\Requests\Reservation\KioskClientLookupRequest;
use App\Http\Requests\Reservation\KioskClientVerifyRequest;
use App\Http\Requests\Reservation\KioskTicketTrackRequest;
use App\Http\Requests\Reservation\KioskWalkInTicketRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\CompanyFeatureService;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationIntentGuardService;
use App\Services\ReservationQueueService;
use App\Services\SmsNotificationService;
use App\Support\ReservationPresetResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PublicKioskReservationController extends Controller
{
    private const VERIFICATION_CODE_LENGTH = 6;
    private const VERIFICATION_CODE_TTL_MINUTES = 10;
    private const VERIFIED_PHONE_TTL_MINUTES = 15;

    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationQueueService $queueService,
        private readonly ReservationIntentGuardService $intentGuard,
        private readonly CompanyFeatureService $featureService,
        private readonly SmsNotificationService $smsService
    ) {
    }

    public function show(Request $request)
    {
        $account = $this->resolveAccountFromRequest($request);
        $settings = $this->resolveKioskSettings($account);

        $services = Product::query()
            ->services()
            ->where('user_id', $account->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $service) => [
                'id' => (int) $service->id,
                'name' => (string) $service->name,
            ])
            ->values()
            ->all();

        $teamMembers = TeamMember::query()
            ->forAccount($account->id)
            ->active()
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (TeamMember $member) => [
                'id' => (int) $member->id,
                'name' => (string) ($member->user?->name ?? 'Member'),
                'title' => $member->title,
            ])
            ->values()
            ->all();

        return Inertia::render('Public/ReservationKiosk', [
            'company' => [
                'id' => (int) $account->id,
                'name' => $account->company_name ?: $account->name,
                'logo_url' => $account->company_logo_url,
                'phone' => $account->phone_number,
            ],
            'settings' => [
                'business_preset' => (string) ($settings['business_preset'] ?? 'service_general'),
                'queue_mode_enabled' => (bool) ($settings['queue_mode_enabled'] ?? false),
                'kiosk_require_sms_verification' => $this->requiresSmsVerification($account, $settings),
                'queue_grace_minutes' => (int) ($settings['queue_grace_minutes'] ?? 5),
            ],
            'services' => $services,
            'team_members' => $teamMembers,
            'endpoints' => $this->buildSignedEndpoints($account),
        ]);
    }

    public function walkInTicket(KioskWalkInTicketRequest $request)
    {
        $validated = $request->validated();
        $account = $this->resolveAccountFromRequest($request);
        $settings = $this->resolveKioskSettings($account);

        $phone = (string) $validated['phone'];
        $phoneNormalized = $this->normalizePhone($phone);
        if (!$phoneNormalized) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid phone number format.'],
            ]);
        }

        $customer = $this->findCustomerByPhone($account->id, $phone, $phoneNormalized);
        if ($customer) {
            $this->ensurePhoneVerified(
                $account,
                $settings,
                $phoneNormalized,
                $validated['verification_code'] ?? null
            );
        }

        $clientId = $customer ? (int) $customer->id : null;
        $clientUserId = $customer && $customer->portal_user_id ? (int) $customer->portal_user_id : null;
        if ($clientId || $clientUserId) {
            $activeTicket = $this->intentGuard->findActiveTicket($account->id, $clientId, $clientUserId);
            if ($activeTicket) {
                return $this->duplicateTicketResponse($account, $settings, $activeTicket);
            }
            $this->intentGuard->ensureCanCreateTicket($account->id, $clientId, $clientUserId, $settings);
        } else {
            $activeTicket = $this->intentGuard->findActiveTicketByGuestPhone($account->id, $phoneNormalized);
            if ($activeTicket) {
                return $this->duplicateTicketResponse($account, $settings, $activeTicket);
            }
            $this->intentGuard->ensureCanCreateGuestTicket($account->id, $phoneNormalized, $settings);
        }

        $item = $this->queueService->createTicket($account->id, [
            'service_id' => $validated['service_id'] ?? null,
            'team_member_id' => $validated['team_member_id'] ?? null,
            'estimated_duration_minutes' => $validated['estimated_duration_minutes'] ?? null,
            'party_size' => $validated['party_size'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'client_id' => $clientId,
            'client_user_id' => $clientUserId,
            'source' => $customer ? 'kiosk_client' : 'kiosk_guest',
            'metadata' => array_filter([
                'guest_name' => isset($validated['guest_name']) ? trim((string) $validated['guest_name']) : null,
                'guest_phone' => $phone,
                'guest_phone_normalized' => $phoneNormalized,
                'kiosk' => [
                    'flow' => $customer ? 'client_ticket' : 'walk_in',
                    'created_at' => now('UTC')->toIso8601String(),
                ],
            ], fn ($value) => $value !== null && $value !== ''),
        ], $account, $settings);

        $this->logKioskEvent('kiosk_ticket_created', [
            'account_id' => (int) $account->id,
            'queue_item_id' => (int) $item->id,
            'source' => (string) $item->source,
            'client_id' => $clientId,
            'phone_hash' => $this->phoneHash($phoneNormalized),
        ]);

        return response()->json([
            'message' => 'Queue ticket created.',
            'ticket' => $this->mapQueueItem($item),
            'linked_client' => $customer ? [
                'id' => (int) $customer->id,
                'name' => $this->displayClientName($customer),
            ] : null,
        ], 201);
    }

    public function lookupClient(KioskClientLookupRequest $request)
    {
        $validated = $request->validated();
        $account = $this->resolveAccountFromRequest($request);
        $settings = $this->resolveKioskSettings($account);

        $phone = (string) $validated['phone'];
        $phoneNormalized = $this->normalizePhone($phone);
        if (!$phoneNormalized) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid phone number format.'],
            ]);
        }

        $customer = $this->findCustomerByPhone($account->id, $phone, $phoneNormalized);
        if (!$customer) {
            $this->logKioskEvent('kiosk_lookup_not_found', [
                'account_id' => (int) $account->id,
                'phone_hash' => $this->phoneHash($phoneNormalized),
            ]);
            return response()->json([
                'found' => false,
                'verification_required' => false,
                'verified' => false,
                'client' => null,
                'intent' => [
                    'next_action' => 'create_guest_ticket',
                ],
            ]);
        }

        $verificationRequired = $this->requiresSmsVerification($account, $settings);
        if (!$verificationRequired || $this->hasVerifiedPhone($account->id, $phoneNormalized)) {
            $this->logKioskEvent('kiosk_lookup_found_verified', [
                'account_id' => (int) $account->id,
                'client_id' => (int) $customer->id,
                'phone_hash' => $this->phoneHash($phoneNormalized),
            ]);
            return response()->json([
                'found' => true,
                'verification_required' => $verificationRequired,
                'verified' => true,
                ...$this->buildClientIntentPayload($account, $customer, $settings),
            ]);
        }

        $verification = $this->issueVerificationCode(
            $account->id,
            $phoneNormalized,
            $phone,
            (bool) ($validated['send_verification'] ?? true)
        );

        $this->logKioskEvent('kiosk_lookup_verification_required', [
            'account_id' => (int) $account->id,
            'client_id' => (int) $customer->id,
            'phone_hash' => $this->phoneHash($phoneNormalized),
            'sms_sent' => (bool) ($verification['sent'] ?? false),
        ]);

        return response()->json([
            'found' => true,
            'verification_required' => true,
            'verified' => false,
            'client_hint' => $this->anonymizeClientName($this->displayClientName($customer)),
            'verification' => $verification,
        ]);
    }

    public function verifyClient(KioskClientVerifyRequest $request)
    {
        $validated = $request->validated();
        $account = $this->resolveAccountFromRequest($request);
        $settings = $this->resolveKioskSettings($account);

        $phoneNormalized = $this->normalizePhone((string) $validated['phone']);
        if (!$phoneNormalized) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid phone number format.'],
            ]);
        }

        $customer = $this->findCustomerByPhone($account->id, (string) $validated['phone'], $phoneNormalized);
        if (!$customer) {
            throw ValidationException::withMessages([
                'phone' => ['No existing client found for this phone number.'],
            ]);
        }

        if ($this->requiresSmsVerification($account, $settings)) {
            $this->consumeVerificationCode($account->id, $phoneNormalized, (string) $validated['code']);
        } else {
            Cache::put(
                $this->verifiedPhoneCacheKey($account->id, $phoneNormalized),
                true,
                now('UTC')->addMinutes(self::VERIFIED_PHONE_TTL_MINUTES)
            );
        }

        $this->logKioskEvent('kiosk_phone_verified', [
            'account_id' => (int) $account->id,
            'client_id' => (int) $customer->id,
            'phone_hash' => $this->phoneHash($phoneNormalized),
        ]);

        return response()->json([
            'found' => true,
            'verification_required' => $this->requiresSmsVerification($account, $settings),
            'verified' => true,
            ...$this->buildClientIntentPayload($account, $customer, $settings),
        ]);
    }

    public function checkIn(KioskCheckInRequest $request)
    {
        $validated = $request->validated();
        $account = $this->resolveAccountFromRequest($request);
        $settings = $this->resolveKioskSettings($account);

        $phone = (string) $validated['phone'];
        $phoneNormalized = $this->normalizePhone($phone);
        if (!$phoneNormalized) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid phone number format.'],
            ]);
        }

        $customer = $this->findCustomerByPhone($account->id, $phone, $phoneNormalized);
        if (!$customer) {
            throw ValidationException::withMessages([
                'phone' => ['No existing client found for this phone number.'],
            ]);
        }

        $this->ensurePhoneVerified(
            $account,
            $settings,
            $phoneNormalized,
            $validated['verification_code'] ?? null
        );

        $reservation = $this->resolveCheckInReservation(
            $account->id,
            $customer,
            isset($validated['reservation_id']) ? (int) $validated['reservation_id'] : null,
            $settings
        );

        $anchor = $reservation->starts_at ? $reservation->starts_at->copy()->utc() : now('UTC');
        $this->queueService->syncAppointmentsForWindow(
            $account->id,
            $anchor->copy()->startOfDay(),
            $anchor->copy()->endOfDay(),
            $settings
        );

        $item = ReservationQueueItem::query()
            ->forAccount($account->id)
            ->where('reservation_id', $reservation->id)
            ->first();

        if (!$item) {
            throw ValidationException::withMessages([
                'reservation' => ['Unable to create queue item for this reservation.'],
            ]);
        }

        $updated = $this->queueService->transition($item, 'check_in', $account, $settings, [
            'channel' => 'kiosk_client',
        ]);

        $this->logKioskEvent('kiosk_check_in_completed', [
            'account_id' => (int) $account->id,
            'reservation_id' => (int) $reservation->id,
            'queue_item_id' => (int) $updated->id,
            'client_id' => (int) $customer->id,
            'phone_hash' => $this->phoneHash($phoneNormalized),
        ]);

        return response()->json([
            'message' => 'Reservation checked in.',
            'reservation_id' => (int) $reservation->id,
            'queue_item' => $this->mapQueueItem($updated),
        ]);
    }

    public function trackTicket(KioskTicketTrackRequest $request)
    {
        $validated = $request->validated();
        $account = $this->resolveAccountFromRequest($request);
        $settings = $this->resolveKioskSettings($account);

        $phoneNormalized = $this->normalizePhone((string) $validated['phone']);
        if (!$phoneNormalized) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid phone number format.'],
            ]);
        }

        $customer = $this->findCustomerByPhone(
            $account->id,
            (string) $validated['phone'],
            $phoneNormalized
        );

        $query = ReservationQueueItem::query()
            ->forAccount($account->id)
            ->where('item_type', ReservationQueueItem::TYPE_TICKET)
            ->where(function (Builder $builder) {
                $builder->whereIn('status', ReservationQueueItem::ACTIVE_STATUSES)
                    ->orWhere('updated_at', '>=', now('UTC')->subDays(2));
            })
            ->with([
                'service:id,name',
                'teamMember.user:id,name',
                'client:id,first_name,last_name,company_name,email',
            ])
            ->orderByDesc('created_at');

        if (!empty($validated['queue_number'])) {
            $query->where('queue_number', trim((string) $validated['queue_number']));
        }
        if (!empty($validated['ticket_id'])) {
            $query->whereKey((int) $validated['ticket_id']);
        }

        $candidates = $query->limit(80)->get();
        $ticket = $candidates->first(fn (ReservationQueueItem $item) => $this->ticketMatchesPhone(
            $item,
            $phoneNormalized,
            $customer
        ));

        if (!$ticket) {
            $this->logKioskEvent('kiosk_ticket_track_not_found', [
                'account_id' => (int) $account->id,
                'phone_hash' => $this->phoneHash($phoneNormalized),
                'queue_number' => !empty($validated['queue_number']) ? trim((string) $validated['queue_number']) : null,
            ]);
            return response()->json([
                'found' => false,
            ], 404);
        }

        $this->queueService->refreshMetrics($account->id, $settings);

        $this->logKioskEvent('kiosk_ticket_track_found', [
            'account_id' => (int) $account->id,
            'queue_item_id' => (int) $ticket->id,
            'phone_hash' => $this->phoneHash($phoneNormalized),
        ]);

        return response()->json([
            'found' => true,
            'ticket' => $this->mapQueueItem($ticket->fresh([
                'service:id,name',
                'teamMember.user:id,name',
                'client:id,first_name,last_name,company_name,email',
            ]) ?: $ticket),
            'fetched_at' => now('UTC')->toIso8601String(),
        ]);
    }

    private function resolveAccountFromRequest(Request $request): User
    {
        $accountId = (int) $request->query('account');
        if ($accountId <= 0) {
            throw ValidationException::withMessages([
                'account' => ['Invalid account context.'],
            ]);
        }

        $account = User::query()->find($accountId);
        if (!$account) {
            abort(404);
        }

        return $account;
    }

    private function buildSignedEndpoints(User $account): array
    {
        $params = ['account' => $account->id];

        return [
            'walk_in_ticket' => URL::signedRoute('public.kiosk.reservations.walk-in.tickets.store', $params),
            'lookup_client' => URL::signedRoute('public.kiosk.reservations.clients.lookup', $params),
            'verify_client' => URL::signedRoute('public.kiosk.reservations.clients.verify', $params),
            'check_in' => URL::signedRoute('public.kiosk.reservations.check-in', $params),
            'track_ticket' => URL::signedRoute('public.kiosk.reservations.tickets.track.submit', $params),
        ];
    }

    private function resolveKioskSettings(User $account): array
    {
        if ($account->isSuspended()) {
            abort(404);
        }
        if (!$this->featureService->hasFeature($account, 'reservations')) {
            abort(404);
        }

        $settings = $this->availabilityService->resolveSettings($account->id, null);
        if (!ReservationPresetResolver::queueFeaturesEnabled((string) ($settings['business_preset'] ?? null))) {
            throw ValidationException::withMessages([
                'kiosk' => ['Public kiosk queue is only available for salon businesses.'],
            ]);
        }

        if (!($settings['queue_mode_enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'queue' => ['Queue mode is disabled for this account.'],
            ]);
        }

        $kioskEnabled = (bool) data_get($account->company_notification_settings, 'reservations.kiosk_enabled', true);
        if (!$kioskEnabled) {
            throw ValidationException::withMessages([
                'kiosk' => ['Kiosk is disabled for this account.'],
            ]);
        }

        $settings['queue_duplicate_window_minutes'] = max(
            10,
            (int) data_get(
                $account->company_notification_settings,
                'reservations.queue_duplicate_window_minutes',
                $settings['queue_duplicate_window_minutes'] ?? 120
            )
        );

        return $settings;
    }

    private function requiresSmsVerification(User $account, array $settings): bool
    {
        return (bool) data_get(
            $account->company_notification_settings,
            'reservations.kiosk_require_sms_verification',
            $settings['kiosk_require_sms_verification'] ?? false
        );
    }

    private function ensurePhoneVerified(
        User $account,
        array $settings,
        string $phoneNormalized,
        ?string $verificationCode
    ): void {
        if (!$this->requiresSmsVerification($account, $settings)) {
            return;
        }

        if ($this->hasVerifiedPhone($account->id, $phoneNormalized)) {
            return;
        }

        if ($verificationCode) {
            $this->consumeVerificationCode($account->id, $phoneNormalized, $verificationCode);
            return;
        }

        throw ValidationException::withMessages([
            'verification' => ['Phone verification is required before continuing.'],
        ]);
    }

    private function hasVerifiedPhone(int $accountId, string $phoneNormalized): bool
    {
        return (bool) Cache::get($this->verifiedPhoneCacheKey($accountId, $phoneNormalized), false);
    }

    private function findCustomerByPhone(int $accountId, string $phoneRaw, string $phoneNormalized): ?Customer
    {
        $raw = trim($phoneRaw);
        $digits = preg_replace('/\D+/', '', $raw) ?: '';

        $candidates = Customer::query()
            ->byUser($accountId)
            ->whereNotNull('phone')
            ->where(function (Builder $query) use ($raw, $digits, $phoneNormalized) {
                $query->where('phone', $raw)
                    ->orWhere('phone', $phoneNormalized)
                    ->orWhere('phone', '+' . $phoneNormalized);
                if ($digits !== '') {
                    $query->orWhere('phone', 'like', '%' . $digits);
                }
            })
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get([
                'id',
                'portal_user_id',
                'company_name',
                'first_name',
                'last_name',
                'email',
                'phone',
            ]);

        foreach ($candidates as $candidate) {
            if ($this->normalizePhone((string) $candidate->phone) === $phoneNormalized) {
                return $candidate;
            }
        }

        return $candidates->first();
    }

    private function normalizePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '1' . $digits;
        }

        if (strlen($digits) > 11) {
            return ltrim($digits, '0');
        }

        return $digits;
    }

    private function issueVerificationCode(
        int $accountId,
        string $phoneNormalized,
        string $phoneRaw,
        bool $sendSms
    ): array {
        $code = str_pad(
            (string) random_int(0, (10 ** self::VERIFICATION_CODE_LENGTH) - 1),
            self::VERIFICATION_CODE_LENGTH,
            '0',
            STR_PAD_LEFT
        );

        $expiresAt = now('UTC')->addMinutes(self::VERIFICATION_CODE_TTL_MINUTES);
        Cache::put(
            $this->verificationCodeCacheKey($accountId, $phoneNormalized),
            ['hash' => Hash::make($code)],
            $expiresAt
        );

        $sent = false;
        if ($sendSms) {
            $sent = $this->smsService->send(
                $phoneRaw,
                "Votre code de verification est {$code}. Il expire dans " . self::VERIFICATION_CODE_TTL_MINUTES . ' minutes.'
            );

            if (!$sent && !app()->environment(['local', 'testing'])) {
                throw ValidationException::withMessages([
                    'phone' => ['Unable to send verification code right now.'],
                ]);
            }
        }

        return array_filter([
            'sent' => $sent,
            'expires_at' => $expiresAt->toIso8601String(),
            'debug_code' => app()->environment(['local', 'testing']) ? $code : null,
        ], fn ($value) => $value !== null);
    }

    private function consumeVerificationCode(int $accountId, string $phoneNormalized, string $code): void
    {
        $cached = Cache::get($this->verificationCodeCacheKey($accountId, $phoneNormalized));
        $hash = is_array($cached) ? ($cached['hash'] ?? null) : null;
        if (!is_string($hash) || $hash === '' || !Hash::check($code, $hash)) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired verification code.'],
            ]);
        }

        Cache::forget($this->verificationCodeCacheKey($accountId, $phoneNormalized));
        Cache::put(
            $this->verifiedPhoneCacheKey($accountId, $phoneNormalized),
            true,
            now('UTC')->addMinutes(self::VERIFIED_PHONE_TTL_MINUTES)
        );
    }

    private function verificationCodeCacheKey(int $accountId, string $phoneNormalized): string
    {
        return 'reservation:kiosk:verification:' . $accountId . ':' . sha1($phoneNormalized);
    }

    private function verifiedPhoneCacheKey(int $accountId, string $phoneNormalized): string
    {
        return 'reservation:kiosk:verified:' . $accountId . ':' . sha1($phoneNormalized);
    }

    private function buildClientIntentPayload(User $account, Customer $customer, array $settings): array
    {
        $clientUserId = $customer->portal_user_id ? (int) $customer->portal_user_id : null;
        $activeTicket = $this->intentGuard->findActiveTicket($account->id, (int) $customer->id, $clientUserId);
        $nearbyReservation = $this->intentGuard->findNearbyActiveReservation(
            $account->id,
            (int) $customer->id,
            $clientUserId,
            $settings
        );

        $nextAction = $nearbyReservation
            ? 'check_in'
            : ($activeTicket ? 'track_ticket' : 'take_ticket');

        return [
            'client' => [
                'id' => (int) $customer->id,
                'name' => $this->displayClientName($customer),
                'phone' => $customer->phone,
            ],
            'intent' => [
                'next_action' => $nextAction,
                'nearby_reservation' => $nearbyReservation ? [
                    'id' => (int) $nearbyReservation->id,
                    'status' => (string) $nearbyReservation->status,
                    'starts_at' => $nearbyReservation->starts_at?->toIso8601String(),
                    'ends_at' => $nearbyReservation->ends_at?->toIso8601String(),
                ] : null,
                'active_ticket' => $activeTicket ? $this->mapQueueItem($activeTicket) : null,
            ],
        ];
    }

    private function resolveCheckInReservation(
        int $accountId,
        Customer $customer,
        ?int $reservationId,
        array $settings
    ): Reservation {
        $clientUserId = $customer->portal_user_id ? (int) $customer->portal_user_id : null;
        $reservation = null;

        if ($reservationId) {
            $reservation = Reservation::query()
                ->forAccount($accountId)
                ->whereKey($reservationId)
                ->whereIn('status', Reservation::ACTIVE_STATUSES)
                ->where(function (Builder $query) use ($customer, $clientUserId) {
                    $query->where('client_id', (int) $customer->id);
                    if ($clientUserId) {
                        $query->orWhere('client_user_id', $clientUserId);
                    }
                })
                ->first();
        }

        if (!$reservation) {
            $reservation = $this->intentGuard->findNearbyActiveReservation(
                $accountId,
                (int) $customer->id,
                $clientUserId,
                $settings
            );
        }

        if (!$reservation) {
            throw ValidationException::withMessages([
                'reservation' => ['No nearby active reservation found for this client.'],
            ]);
        }

        return $reservation;
    }

    private function ticketMatchesPhone(
        ReservationQueueItem $ticket,
        string $phoneNormalized,
        ?Customer $customer
    ): bool {
        if ($customer) {
            if (
                (int) ($ticket->client_id ?? 0) === (int) $customer->id
                || (
                    $customer->portal_user_id
                    && (int) ($ticket->client_user_id ?? 0) === (int) $customer->portal_user_id
                )
            ) {
                return true;
            }
        }

        $guestPhone = $this->normalizePhone((string) data_get($ticket->metadata, 'guest_phone_normalized'))
            ?: $this->normalizePhone((string) data_get($ticket->metadata, 'guest_phone'));

        return $guestPhone === $phoneNormalized;
    }

    private function duplicateTicketResponse(User $account, array $settings, ReservationQueueItem $ticket)
    {
        $this->queueService->refreshMetrics((int) $account->id, $settings);

        $freshTicket = $ticket->fresh([
            'service:id,name',
            'teamMember.user:id,name',
            'client:id,first_name,last_name,company_name,email',
        ]) ?: $ticket;
        $mapped = $this->mapQueueItem($freshTicket);

        $queueNumber = (string) ($mapped['queue_number'] ?? ('#' . $freshTicket->id));
        $position = $mapped['position'] ?? null;
        $positionLabel = $position !== null ? (string) $position : 'pending assignment';

        return response()->json([
            'message' => "An active queue ticket already exists for this phone number. Ticket {$queueNumber} is currently at position {$positionLabel}.",
            'duplicate_ticket' => true,
            'ticket' => $mapped,
            'intent' => [
                'next_action' => 'track_ticket',
                'active_ticket' => $mapped,
            ],
        ], 409);
    }

    private function mapQueueItem(ReservationQueueItem $item): array
    {
        $item->loadMissing([
            'service:id,name',
            'teamMember.user:id,name',
            'client:id,first_name,last_name,company_name,email',
        ]);

        $clientName = $item->client?->company_name
            ?: trim(($item->client?->first_name ?? '') . ' ' . ($item->client?->last_name ?? ''));
        if (!$clientName) {
            $clientName = trim((string) data_get($item->metadata, 'guest_name'));
        }
        if (!$clientName) {
            $clientName = trim((string) data_get($item->metadata, 'guest_phone'));
        }

        return [
            'id' => (int) $item->id,
            'queue_number' => $item->queue_number ?: ('#' . $item->id),
            'status' => (string) $item->status,
            'item_type' => (string) $item->item_type,
            'client_name' => $clientName !== '' ? $clientName : null,
            'service_name' => $item->service?->name,
            'team_member_name' => $item->teamMember?->user?->name,
            'position' => $item->position,
            'eta_minutes' => $item->eta_minutes,
            'call_expires_at' => $item->call_expires_at?->toIso8601String(),
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }

    private function displayClientName(Customer $customer): string
    {
        $name = $customer->company_name
            ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

        return $name !== '' ? $name : ((string) ($customer->email ?? 'Client'));
    }

    private function anonymizeClientName(string $value): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($value)) ?: []));
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1)) . ' ' . strtoupper(substr($parts[1], 0, 1)) . '.';
        }

        if ($value !== '') {
            return strtoupper(substr($value, 0, 1)) . '***';
        }

        return '***';
    }

    private function phoneHash(?string $phoneNormalized): ?string
    {
        if (!$phoneNormalized) {
            return null;
        }

        return sha1($phoneNormalized);
    }

    private function logKioskEvent(string $event, array $context = []): void
    {
        Log::info('Reservation kiosk event.', array_merge([
            'event' => $event,
        ], $context));
    }
}
