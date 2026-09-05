import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createPaymentIdempotencyKey } from '../../resources/js/utils/paymentIdempotency.js';

const paymentSubmitter = (path, environment) => {
    const source = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
    const submit = source.match(/const submitPayment = [\s\S]*?\n\};/u)?.[0];
    assert.ok(submit, 'The payment submission handler must exist.');
    return new Function(...Object.keys(environment), `${submit}\nreturn submitPayment;`)(...Object.values(environment));
};

test('payment keys contain cryptographic bytes and fit the request contract', () => {
    assert.match(createPaymentIdempotencyKey(), /^[a-f0-9]{32}$/u);
});

test('public payment submission sends card payments to checkout without a manual payment', () => {
    let checkoutCalls = 0;
    const submit = paymentSubmitter('resources/js/Pages/Public/InvoicePay.vue', {
        form: { method: 'card', processing: false, post: () => assert.fail('Card must use checkout.') },
        canSubmitPayment: { value: true },
        startStripeCheckout: () => { checkoutCalls += 1; },
    });

    submit();

    assert.equal(checkoutCalls, 1);
});

for (const [label, path] of [
    ['public', 'resources/js/Pages/Public/InvoicePay.vue'],
    ['owner', 'resources/js/Pages/Invoice/Show.vue'],
]) {
    test(`${label} form keeps its payment key after a failed attempt and renews it after success`, () => {
        const requests = [];
        const form = {
            method: 'cash', processing: false, idempotency_key: 'initial-intention',
            post: (url, options) => requests.push({ key: form.idempotency_key, options }),
            reset: () => {},
        };
        const submit = paymentSubmitter(path, {
            form,
            props: { paymentUrl: '/pay', invoice: { id: 1 } },
            canSubmitPayment: { value: true },
            paymentAmount: { value: 20 },
            exceedsBalanceDue: { value: false },
            route: () => '/pay',
            applyTipPayloadToForm: () => {},
            createPaymentIdempotencyKey: () => 'new-intention',
            dispatchDemoEvent: () => {},
        });

        submit();
        submit();
        assert.deepEqual(requests.map(({ key }) => key), ['initial-intention', 'initial-intention']);
        requests[1].options.onSuccess();
        submit();
        assert.equal(requests[2].key, 'new-intention');
        form.processing = true;
        submit();
        assert.equal(requests.length, 3);
    });
}

test('portal retries preserve the invoice intention and concurrent clicks cannot enqueue another payment', () => {
    const requests = [];
    const invoice = { id: 5 };
    const paymentRequestKeys = {};
    const paymentProcessing = {};
    const paymentErrors = {};
    const paymentMethods = { 5: 'cash' };
    let generatedKeys = 0;
    let checkoutCalls = 0;
    const submit = paymentSubmitter('resources/js/Pages/DashboardClient.vue', {
        paymentRequestKeys, paymentProcessing, paymentErrors, paymentMethods,
        defaultPaymentMethod: { value: 'cash' },
        canSubmitInvoicePayment: () => true,
        startStripePayment: () => { checkoutCalls += 1; },
        createPaymentIdempotencyKey: () => `intention-${++generatedKeys}`,
        invoiceAmountValue: () => 20,
        tipPayload: () => ({}),
        route: () => '/pay',
        router: { post: (url, payload, options) => requests.push({ payload, options }) },
    });

    submit(invoice);
    submit(invoice);
    assert.equal(requests.length, 1);
    requests[0].options.onError({ amount: 'Invalid amount' });
    requests[0].options.onFinish();
    assert.equal(paymentErrors[5], 'Invalid amount');
    submit(invoice);
    assert.equal(requests[1].payload.idempotency_key, requests[0].payload.idempotency_key);
    requests[1].options.onSuccess();
    requests[1].options.onFinish();
    submit(invoice);
    assert.equal(requests[2].payload.idempotency_key, 'intention-2');
    requests[2].options.onFinish();
    paymentMethods[5] = 'card';
    submit(invoice);
    assert.equal(checkoutCalls, 1);
    assert.equal(requests.length, 3);
});

test('payment declarations explain pending confirmation in all supported locales', () => {
    for (const locale of ['en', 'fr', 'es']) {
        const catalog = JSON.parse(readFileSync(new URL(`../../resources/js/i18n/modules/${locale}/public_invoice.json`, import.meta.url), 'utf8')).public_invoice;
        for (const message of [catalog.actions.declare_payment, catalog.confirmation_required, catalog.secure_checkout_unavailable]) {
            assert.equal(typeof message, 'string');
            assert.ok(message.length > 0);
        }
    }
});
