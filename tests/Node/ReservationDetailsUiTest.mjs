import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const read = (path) => readFileSync(resolve(path), 'utf8');

const valueAt = (object, path) => path
    .split('.')
    .reduce((current, segment) => current?.[segment], object);

test('reservation detail copy is complete in every supported locale', () => {
    const requiredPaths = [
        'eyebrow',
        'title',
        'subtitle',
        'description',
        'reference',
        'close',
        'sections.schedule',
        'sections.client',
        'sections.team_member',
        'sections.service',
        'sections.notes',
        'sections.resources',
        'sections.milestones',
        'sections.payment',
        'schedule',
        'client',
        'customer',
        'people',
        'team_member',
        'assigned_team_member',
        'service',
        'date',
        'time',
        'duration',
        'minutes',
        'party_size_value',
        'catalog_price',
        'catalogue_price',
        'notes',
        'client_notes',
        'internal_notes',
        'no_notes',
        'no_contact',
        'unavailable',
        'resources',
        'resource_quantity',
        'landmarks',
        'milestones.created',
        'milestones.scheduled',
        'milestones.cancelled',
        'milestones.auto_closed',
        'created_at',
        'scheduled_for',
        'cancelled_at',
        'auto_closed_at',
        'created_by',
        'cancelled_by',
        'cancellation_reason',
        'auto_closed_reason',
        'source',
        'sources.staff',
        'sources.client',
        'sources.api',
        'sources.public_booking',
        'sources.unknown',
        'vip',
        'loading',
        'error',
        'error_title',
        'error_description',
        'retry',
        'payment_states.refunded',
        'labels.contact',
        'labels.category',
        'labels.resource',
        'service_fallback',
        'service_image_alt',
        'customer_fallback',
        'team_member_fallback',
        'payment',
        'deposit',
        'no_show_fee',
        'payment_status',
        'no_payment',
        'payment_states.required',
        'payment_states.not_required',
        'payment_states.due_on_invoice',
        'payment_states.forfeited',
        'payment_states.refundable',
        'payment_states.not_applied',
        'payment_states.not_applicable',
        'payment_states.charge_required',
        'payment_states.waived',
        'payment_states.paid',
        'conversion.public_booking_title',
        'conversion.public_booking_description',
        'conversion.title',
        'conversion.description',
        'conversion.fields.name',
        'conversion.fields.client_name',
        'conversion.fields.email',
        'conversion.fields.phone',
        'conversion.fields.company',
        'conversion.actions.check',
        'conversion.actions.checking',
        'conversion.actions.link',
        'conversion.actions.linking',
        'conversion.actions.create',
        'conversion.actions.creating',
        'conversion.states.loading',
        'conversion.states.converting',
        'conversion.states.no_matches',
        'conversion.states.ready',
        'conversion.states.already_converted',
        'conversion.success.converted',
        'conversion.errors.load',
        'conversion.errors.convert',
        'conversion.errors.validation',
    ];

    for (const locale of ['fr', 'en', 'es']) {
        const messages = JSON.parse(read(`resources/js/i18n/modules/${locale}/reservations.json`));
        const details = messages.reservations?.details;

        assert.equal(typeof details, 'object', `${locale}:reservations.details`);

        for (const path of requiredPaths) {
            const value = valueAt(details, path);

            assert.equal(typeof value, 'string', `${locale}:reservations.details.${path}`);
            assert.notEqual(value.trim(), '', `${locale}:reservations.details.${path}`);
        }

        assert.match(details.reference, /\{id\}/, `${locale}:reference interpolation`);
        assert.match(details.minutes, /\{count\}/, `${locale}:minutes interpolation`);
        assert.match(details.party_size_value, /\{count\}/, `${locale}:party size interpolation`);
    }
});

test('the reservation detail panel is a responsive accessible drawer with resilient media and request states', () => {
    const panel = read('resources/js/Components/Reservation/ReservationDetailsPanel.vue');
    const page = read('resources/js/Pages/Reservation/Index.vue');
    const modal = read('resources/js/Components/Modal.vue');
    const avatar = read('resources/js/Components/UI/EntityAvatar.vue');
    const surface = `${page}\n${panel}`;
    const mediaSurface = `${panel}\n${avatar}`;
    const describedById = surface.match(/aria-describedby="(reservation-details-(?:description|subtitle))"/)?.[1];

    assert.match(panel, /data-reservation-details-panel/);
    assert.match(surface, /presentation="drawer"/);
    assert.match(modal, /['"]dialog['"],\s*['"]drawer['"]/);
    assert.match(modal, /isDrawer/);
    assert.match(modal, /justify-end/);
    assert.match(modal, /translate-x-full/);
    assert.match(modal, /h-dvh/);
    assert.match(surface, /aria-labelledby="reservation-details-title"/);
    assert.ok(describedById, 'the drawer exposes a stable description id');
    assert.match(surface, /id="reservation-details-title"/);
    assert.match(surface, new RegExp(`id="${describedById}"`));
    assert.match(panel, /reservations\.details\.close/);

    assert.match(panel, /role="status"/);
    assert.match(panel, /aria-live="polite"/);
    assert.match(panel, /role="alert"/);
    assert.match(panel, /reservations\.details\.(?:error|error_description)/);
    assert.match(panel, /reservations\.details\.retry/);
    assert.match(panel, /emit\(['"]retry['"]\)/);

    assert.match(mediaSurface, /has_image/);
    assert.match(mediaSurface, /image_url/);
    assert.match(mediaSurface, /@error=/);
    assert.match(mediaSurface, /service[_-]fallback|serviceFallback|ImageOff|Sparkles/i);
    assert.doesNotMatch(mediaSurface, /bg-gradient-to-|(?:dark:)?(?:from|via|to)-(?:emerald|teal|amber|stone|neutral|white)/);
    assert.match(panel, /EntityAvatar/);
    assert.match(panel, /ReservationStatusBadge/);
    assert.match(panel, /reservation\.outcome_review_required_at/);
    assert.match(panel, /reservations\.outcome_review\.description/);
    assert.match(panel, /client_notes/);
    assert.match(panel, /prospect/);
    assert.match(panel, /cancel_reason/);
    assert.match(panel, /party_size/);
    assert.match(panel, /<time\b/);
    assert.match(panel, /:datetime=/);
    assert.match(panel, /<footer[\s\S]{0,260}(?:shrink-0|sticky[^"']*bottom-0|fixed[^"']*bottom-0)/);
});

test('the staff reservation page fetches canonical details, cancels stale requests, and supports deep links', () => {
    const page = read('resources/js/Pages/Reservation/Index.vue');
    const routes = read('routes/web.php');
    const controller = read('app/Http/Controllers/Reservation/StaffReservationController.php');

    assert.match(page, /import ReservationDetailsPanel from/);
    assert.match(page, /<ReservationDetailsPanel\b/);
    assert.match(page, /:reservation="activeReservation"/);
    assert.match(page, /:loading="detailsLoading"/);
    assert.match(page, /:error="detailsLoadError"/);
    assert.match(page, /@close="closeDetails"/);
    assert.match(page, /@retry="retryReservationDetails"/);

    assert.match(page, /new AbortController\(\)/);
    assert.match(page, /detailsAbortController\?\.abort\(\)/);
    assert.match(page, /route\(['"]reservation\.show['"],\s*id\)/);
    assert.match(page, /signal:\s*detailsAbortController\.signal/);
    assert.match(page, /detailsRequestSequence/);

    assert.match(page, /focusReservationId/);
    assert.match(page, /openDetails\(reservationMap\.value\.get\(id\)\s*\|\|\s*\{\s*id\s*\}\)/);
    assert.match(routes, /StaffReservationController::class,\s*['"]show['"][\s\S]{0,180}name\(['"]reservation\.show['"]\)/);
    assert.match(controller, /public function show\([^)]*Reservation \$reservation[^)]*\)/);
    assert.match(controller, /['"]reservation['"]\s*=>/);

    assert.match(page, /reservations\.details\.conversion\.errors\.load/);
    assert.match(page, /reservations\.details\.conversion\.success\.converted/);
    assert.doesNotMatch(page, />\s*(?:Reservation publique|Conversion client|Verifier|Lier|Creer le client)\s*</);
});
