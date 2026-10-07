<?php

use Cultpantry\SquareSync\Actions\Exceptions\SquareWebhookVerificationException;
use Cultpantry\SquareSync\Actions\VerifySquareWebhookSignature;
use Illuminate\Http\Request;

/*
 * Unit-ish, isolated tests for the signature math itself, independent of
 * routing, the database, or any other side effect -- see this action's own
 * docblock for why it's split out. Square signs the concatenation of the
 * subscribed notification_url and the raw body (not the body alone, unlike
 * Stripe), so every test here builds the signature the same way the action
 * verifies it: base64(hmac-sha256(notification_url . raw_body, key)).
 */

const TEST_NOTIFICATION_URL = 'https://example.test/webhooks/square';

const TEST_SIGNATURE_KEY = 'test-signature-key';

function squareRequestFor(string $body, ?string $signature): Request
{
    $request = Request::create('/webhooks/square', 'POST', content: $body);

    if ($signature !== null) {
        $request->headers->set(VerifySquareWebhookSignature::SIGNATURE_HEADER, $signature);
    }

    return $request;
}

function squareSignatureFor(string $notificationUrl, string $body, string $key): string
{
    return base64_encode(hash_hmac('sha256', $notificationUrl.$body, $key, true));
}

beforeEach(function () {
    config([
        'square-sync.notification_url' => TEST_NOTIFICATION_URL,
        'square-sync.webhook_signature_key' => TEST_SIGNATURE_KEY,
    ]);
});

it('passes for a valid signature', function () {
    $body = json_encode(['event_id' => 'evt_1', 'type' => 'inventory.count.updated']);
    $signature = squareSignatureFor(TEST_NOTIFICATION_URL, $body, TEST_SIGNATURE_KEY);

    $request = squareRequestFor($body, $signature);

    (new VerifySquareWebhookSignature)->handle($request);
})->throwsNoExceptions();

it('throws for a tampered body', function () {
    $originalBody = json_encode(['event_id' => 'evt_1', 'type' => 'inventory.count.updated']);
    $signature = squareSignatureFor(TEST_NOTIFICATION_URL, $originalBody, TEST_SIGNATURE_KEY);

    // The signature was computed over $originalBody, but the request
    // carries a body that was tampered with after signing.
    $tamperedBody = json_encode(['event_id' => 'evt_1', 'type' => 'inventory.count.updated', 'extra' => 'injected']);

    $request = squareRequestFor($tamperedBody, $signature);

    expect(fn () => (new VerifySquareWebhookSignature)->handle($request))
        ->toThrow(SquareWebhookVerificationException::class);

    try {
        (new VerifySquareWebhookSignature)->handle($request);
    } catch (SquareWebhookVerificationException $e) {
        expect($e->reason)->toBe(SquareWebhookVerificationException::INVALID_SIGNATURE);
    }
});

it('throws with the missing-header reason when the signature header is absent', function () {
    $body = json_encode(['event_id' => 'evt_1', 'type' => 'inventory.count.updated']);

    $request = squareRequestFor($body, null);

    try {
        (new VerifySquareWebhookSignature)->handle($request);
        test()->fail('Expected SquareWebhookVerificationException to be thrown.');
    } catch (SquareWebhookVerificationException $e) {
        expect($e->reason)->toBe(SquareWebhookVerificationException::MISSING_HEADER);
    }
});

it('throws with the missing-config reason when the signature key is not configured', function () {
    config(['square-sync.webhook_signature_key' => null]);

    $body = json_encode(['event_id' => 'evt_1', 'type' => 'inventory.count.updated']);
    $signature = squareSignatureFor(TEST_NOTIFICATION_URL, $body, TEST_SIGNATURE_KEY);

    $request = squareRequestFor($body, $signature);

    try {
        (new VerifySquareWebhookSignature)->handle($request);
        test()->fail('Expected SquareWebhookVerificationException to be thrown.');
    } catch (SquareWebhookVerificationException $e) {
        expect($e->reason)->toBe(SquareWebhookVerificationException::MISSING_CONFIG);
    }
});

it('throws with the missing-config reason when the notification url is not configured', function () {
    config(['square-sync.notification_url' => null]);

    $body = json_encode(['event_id' => 'evt_1', 'type' => 'inventory.count.updated']);
    $signature = squareSignatureFor(TEST_NOTIFICATION_URL, $body, TEST_SIGNATURE_KEY);

    $request = squareRequestFor($body, $signature);

    try {
        (new VerifySquareWebhookSignature)->handle($request);
        test()->fail('Expected SquareWebhookVerificationException to be thrown.');
    } catch (SquareWebhookVerificationException $e) {
        expect($e->reason)->toBe(SquareWebhookVerificationException::MISSING_CONFIG);
    }
});

it('rejects a signature that was computed for a different notification url', function () {
    // Proves notification_url is genuinely part of the signed input, not
    // just documentation -- a signature valid for another registered URL
    // (e.g. staging vs. production) must not verify here.
    $body = json_encode(['event_id' => 'evt_1', 'type' => 'inventory.count.updated']);
    $signature = squareSignatureFor('https://staging.example.test/webhooks/square', $body, TEST_SIGNATURE_KEY);

    $request = squareRequestFor($body, $signature);

    try {
        (new VerifySquareWebhookSignature)->handle($request);
        test()->fail('Expected SquareWebhookVerificationException to be thrown.');
    } catch (SquareWebhookVerificationException $e) {
        expect($e->reason)->toBe(SquareWebhookVerificationException::INVALID_SIGNATURE);
    }
});
