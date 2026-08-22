<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Gateways\ReveSmsClient;
use App\Domain\Notification\Gateways\ReveSmsDeliveryState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The REVE wire format.
 *
 * Every *request* here is pinned against the vendor's own material
 * (`smpp.revesms.com.txt` and the two Postman collections) — endpoint
 * paths, parameter names, the comma-joined `toUser`, the JSON-string
 * `content`. Those are documented and there is no excuse for getting them
 * wrong.
 *
 * The *responses* are not documented anywhere in that material, which is
 * why {@see ReveSmsClient} parses several shapes and why these tests
 * exercise all of them rather than picking one. That is the honest state
 * of this integration until `php artisan sms:test` has made a real call —
 * the same position `SslCommerzClient` was in, where the first live call
 * found two real defects. When a real response is in hand, narrow the
 * parser and delete the shapes it turns out never to send.
 */
class ReveSmsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A stale fake pattern must fail loudly rather than quietly placing
        // a real call to the vendor — the exact defect found in
        // SslCommerzClientTest after its host changed.
        Http::preventStrayRequests();

        config([
            'services.revesms.base_url' => 'https://smpp.revesms.com:7790',
            'services.revesms.api_key' => 'test-api-key',
            'services.revesms.secret_key' => 'test-secret',
            'services.revesms.sender_id' => 'DEC100',
            'services.revesms.auth_style' => 'body',
            'services.revesms.method' => 'post',
        ]);
    }

    public function test_it_posts_the_documented_sendtext_parameters(): void
    {
        Http::fake([
            'smpp.revesms.com:7790/sendtext' => Http::response(['Status' => '0', 'Message_ID' => '1373104']),
        ]);

        $results = app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hello');

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://smpp.revesms.com:7790/sendtext'
                && $request->method() === 'POST'
                && $body['apikey'] === 'test-api-key'
                && $body['secretkey'] === 'test-secret'
                && $body['callerID'] === 'DEC100'
                && $body['toUser'] === '8801711223344'
                && $body['messageContent'] === 'Hello';
        });

        $this->assertTrue($results[0]->accepted);
        $this->assertSame('1373104', $results[0]->messageId);
    }

    public function test_several_recipients_are_joined_with_commas(): void
    {
        Http::fake(['*' => Http::response(['Status' => '0', 'Message_ID' => '1373104'])]);

        app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344', '8801811223344'], 'Hello');

        Http::assertSent(fn (Request $r): bool => $r->data()['toUser'] === '8801711223344,8801811223344');
    }

    public function test_path_auth_moves_the_keys_out_of_the_body(): void
    {
        config(['services.revesms.auth_style' => 'path']);
        Http::fake(['*' => Http::response(['Status' => '0', 'Message_ID' => '1'])]);

        app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hello');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://smpp.revesms.com:7790/sendtext/test-api-key/test-secret'
                && ! array_key_exists('apikey', $request->data())
                && ! array_key_exists('secretkey', $request->data());
        });
    }

    public function test_get_transport_puts_everything_in_the_query_string(): void
    {
        config(['services.revesms.method' => 'get']);
        Http::fake(['*' => Http::response(['Status' => '0', 'Message_ID' => '1'])]);

        app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hi');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'apikey=test-api-key')
                && str_contains($request->url(), 'messageContent=Hi');
        });
    }

    public function test_bulk_send_encodes_content_as_a_json_string(): void
    {
        Http::fake(['*' => Http::response([['Status' => '0', 'Message_ID' => '1'], ['Status' => '0', 'Message_ID' => '2']])]);

        $results = app(ReveSmsClient::class)->send([
            ['callerID' => '8801847', 'toUser' => '8801149,8801182', 'messageContent' => 'SMS1'],
            ['callerID' => '9101847', 'toUser' => '8801147', 'messageContent' => 'SMS2'],
        ]);

        Http::assertSent(function (Request $request): bool {
            $content = $request->data()['content'];

            // The vendor's own example is `content=[{...}]` — a JSON string,
            // not a nested structure the HTTP client flattens its own way.
            return is_string($content)
                && json_decode($content, true)[1]['messageContent'] === 'SMS2';
        });

        $this->assertCount(2, $results);
        $this->assertSame('2', $results[1]->messageId);
    }

    public function test_a_plain_text_message_id_is_still_a_successful_send(): void
    {
        // No documented response format means text/plain is a live
        // possibility; losing it to a JSON decode would report every
        // successful send as a failure.
        Http::fake(['*' => Http::response('1373104', 200, ['Content-Type' => 'text/plain'])]);

        $results = app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hello');

        $this->assertTrue($results[0]->accepted);
        $this->assertSame('1373104', $results[0]->messageId);
    }

    public function test_a_pipe_delimited_response_is_split_into_status_and_id(): void
    {
        Http::fake(['*' => Http::response('ACCEPTD|1373104', 200, ['Content-Type' => 'text/plain'])]);

        $results = app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hello');

        $this->assertTrue($results[0]->accepted);
        $this->assertSame('1373104', $results[0]->messageId);
        $this->assertSame('ACCEPTD', $results[0]->statusCode);
    }

    public function test_a_non_zero_status_is_a_rejection_even_with_http_200(): void
    {
        // Gateways of this generation answer 200 with an error in the body.
        Http::fake(['*' => Http::response(['Status' => '105', 'Text' => 'Invalid callerID'])]);

        $results = app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hello');

        $this->assertFalse($results[0]->accepted);
        $this->assertSame('Invalid callerID', $results[0]->statusText);
    }

    public function test_a_response_with_neither_status_nor_id_is_a_rejection(): void
    {
        Http::fake(['*' => Http::response('Authentication failed', 200, ['Content-Type' => 'text/plain'])]);

        $results = app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hello');

        $this->assertFalse($results[0]->accepted);
        $this->assertNull($results[0]->messageId);
        $this->assertSame('Authentication failed', $results[0]->statusText);
    }

    public function test_a_wrapped_list_is_unwrapped_and_reported_per_recipient(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['toUser' => '8801711223344', 'Status' => '0', 'Message_ID' => '11'],
            ['toUser' => '8801811223344', 'Status' => '105', 'Text' => 'Blocked'],
        ]])]);

        $results = app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344', '8801811223344'], 'Hello');

        $this->assertTrue($results[0]->accepted);
        $this->assertSame('8801711223344', $results[0]->recipient);
        $this->assertFalse($results[1]->accepted);
        $this->assertSame('8801811223344', $results[1]->recipient);
    }

    public function test_multi_status_asks_for_the_ids_and_maps_the_receipts(): void
    {
        Http::fake(['smpp.revesms.com:7790/getmultistatus' => Http::response([
            ['messageid' => '7331', 'status' => 'DELIVRD'],
            ['messageid' => '7332', 'status' => 'UNDELIV'],
            ['messageid' => '7333', 'status' => 'ACCEPTD'],
        ])]);

        $statuses = app(ReveSmsClient::class)->multiStatus(['7331', '7332', '7333']);

        Http::assertSent(fn (Request $r): bool => $r->data()['messageids'] === '7331,7332,7333');

        $this->assertSame(ReveSmsDeliveryState::DELIVERED, $statuses['7331']->state);
        $this->assertSame(ReveSmsDeliveryState::FAILED, $statuses['7332']->state);
        // Accepted by the carrier is not delivered to a handset.
        $this->assertSame(ReveSmsDeliveryState::PENDING, $statuses['7333']->state);
    }

    public function test_an_unknown_receipt_stays_pending_rather_than_being_guessed(): void
    {
        Http::fake(['*' => Http::response(['messageid' => '7331', 'status' => 'SOMETHING_NEW'])]);

        $status = app(ReveSmsClient::class)->status('7331');

        $this->assertSame(ReveSmsDeliveryState::PENDING, $status->state);
        $this->assertSame('SOMETHING_NEW', $status->providerStatus);
    }

    public function test_a_receipt_written_as_an_smpp_line_is_parsed(): void
    {
        Http::fake(['*' => Http::response(['messageid' => '7331', 'status' => 'id:7331 stat:DELIVRD err:000'])]);

        $this->assertSame(
            ReveSmsDeliveryState::DELIVERED,
            app(ReveSmsClient::class)->status('7331')->state,
        );
    }

    public function test_a_gateway_5xx_throws_rather_than_reporting_a_send(): void
    {
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $this->expectExceptionMessage('HTTP 502');

        app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hello');
    }

    public function test_it_reports_as_unconfigured_when_a_credential_is_missing(): void
    {
        $this->assertTrue(ReveSmsClient::isConfigured());

        config(['services.revesms.sender_id' => null]);

        // A missing sender id is as fatal as a missing key — REVE rejects a
        // send with an unapproved callerID, so this must not count as
        // configured or the resolver would hand out a driver that fails on
        // every message.
        $this->assertFalse(ReveSmsClient::isConfigured());
    }

    // --- Shapes confirmed against a live REVE deployment, 2026-08-22 -------
    //
    // These are no longer "plausible" responses: each body below was copied
    // from a real call. The tests above keep the wider tolerance because the
    // vendor runs many per-reseller deployments and only one was observed.

    public function test_the_real_accepted_response_is_parsed(): void
    {
        Http::fake(['*' => Http::response('{"Status":"0","Text":"ACCEPTD","Message_ID":"353406678"}', 200, [])]);

        $results = app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hello');

        $this->assertTrue($results[0]->accepted);
        $this->assertSame('353406678', $results[0]->messageId);
        $this->assertSame('ACCEPTD', $results[0]->statusText);
    }

    public function test_a_response_with_no_content_type_header_is_still_decoded(): void
    {
        // The live gateway sends no Content-Type at all. Nothing may branch
        // on it.
        Http::fake(['*' => Http::response('{"Status":"0","Message_ID":"353406678"}', 200, ['Content-Type' => ''])]);

        $this->assertTrue(app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hi')[0]->accepted);
    }

    public function test_bad_credentials_report_the_gateway_reason(): void
    {
        Http::fake(['*' => Http::response(['Status' => '109', 'Text' => 'Invalid api key/secret key', 'Message_ID' => ''])]);

        $result = app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hi')[0];

        $this->assertFalse($result->accepted);
        // Message_ID comes back as "" rather than absent on every error.
        $this->assertNull($result->messageId);
        $this->assertSame('Invalid api key/secret key', $result->statusText);
    }

    public function test_an_empty_body_names_the_wrong_host_as_the_likely_cause(): void
    {
        // Exactly what another operator's REVE instance returns for valid
        // credentials that are not valid *there*: HTTP 200, no body at all.
        // "The gateway said 200" reads as a parser bug; this reads as the
        // misconfiguration it is.
        Http::fake(['*' => Http::response('', 200)]);

        $result = app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hi')[0];

        $this->assertFalse($result->accepted);
        $this->assertStringContainsString('SMS gateway URL', (string) $result->statusText);
    }

    public function test_a_rejected_status_query_is_not_a_delivery_verdict(): void
    {
        // The bug this pins: an auth failure answers `Text: REJECTD` — the
        // same word an undeliverable message uses. Reading Text first would
        // settle a healthy message as `bounced`, which is terminal.
        Http::fake(['*' => Http::response(['Status' => '109', 'Text' => 'REJECTD', 'Message_ID' => '', 'Delivery Time' => '0'])]);

        $this->assertSame([], app(ReveSmsClient::class)->multiStatus(['353406678']));
    }

    public function test_an_unknown_message_id_yields_no_status_rather_than_a_failure(): void
    {
        Http::fake(['*' => Http::response(['Status' => '114', 'Text' => 'REJECTD', 'Message_ID' => '', 'Delivery Time' => '0'])]);

        $this->assertSame(ReveSmsDeliveryState::PENDING, app(ReveSmsClient::class)->status('353406678')->state);
    }

    public function test_no_news_from_the_status_endpoints_is_not_a_status(): void
    {
        // Real behaviour: /getmultistatus answers `[,,]` — not valid JSON —
        // and /getstatus answers with an empty body, when there is no
        // receipt yet. Neither is an error and neither is a verdict.
        Http::fake(['*' => Http::response('[,,]', 200)]);
        $this->assertSame([], app(ReveSmsClient::class)->multiStatus(['1', '2', '3']));

        Http::fake(['*' => Http::response('', 200)]);
        $this->assertSame(ReveSmsDeliveryState::PENDING, app(ReveSmsClient::class)->status('1')->state);
    }

    public function test_basic_auth_keeps_the_keys_out_of_the_request_entirely(): void
    {
        config(['services.revesms.auth_style' => 'basic']);
        Http::fake(['*' => Http::response(['Status' => '0', 'Message_ID' => '1'])]);

        app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hi');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Basic '.base64_encode('test-api-key:test-secret'))
                && ! array_key_exists('apikey', $request->data());
        });
    }

    public function test_form_transport_sends_urlencoded(): void
    {
        config(['services.revesms.method' => 'form']);
        Http::fake(['*' => Http::response(['Status' => '0', 'Message_ID' => '1'])]);

        app(ReveSmsClient::class)->sendText('DEC100', ['8801711223344'], 'Hi');

        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $r->data()['messageContent'] === 'Hi');
    }

    public function test_it_names_which_credential_is_missing(): void
    {
        config(['services.revesms.sender_id' => null]);

        // "Not configured" alone is the least helpful thing this can say:
        // all three are required and two are invisible once saved.
        $this->assertSame(['SMS sender ID'], ReveSmsClient::missingCredentials());

        config(['services.revesms.api_key' => null]);
        $this->assertSame(['SMS API key', 'SMS sender ID'], ReveSmsClient::missingCredentials());
    }
}
