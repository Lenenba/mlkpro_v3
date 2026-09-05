export const createPaymentIdempotencyKey = () => Array.from(
    globalThis.crypto.getRandomValues(new Uint8Array(16)),
    (value) => value.toString(16).padStart(2, '0'),
).join('');
