<?php

namespace App\Domain\Notification\Gateways;

use App\Domain\Notification\Channels\SmsDriver;
use App\Domain\Notification\Gateways\Contracts\ReveSmsResult;
use App\Domain\Notification\Gateways\Contracts\ReveSmsStatus;
use App\Domain\Notification\Support\SmsGatewayConfig;
use App\Jobs\SendNotificationJob;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * REVE Systems SMS gateway adapter (smpp.revesms.com) — the only class
 * that knows REVE's wire format. Everything above it
 * ({@see SmsDriver}, the DLR poller,
 * the balance command) speaks in message ids and delivery states.
 *
 * Endpoints, from the vendor's configuration sheet and Postman collection:
 *
 *   /sendtext        one `callerID` + `messageContent`, `toUser` comma-separated
 *   /send            `content[]` — several caller/message groups in one call
 *   /getstatus       one `messageid`
 *   /getmultistatus  `messageids` comma-separated
 *   /api/v2/balance  prepaid balance
 *
 * **The response format is verified**, against a live REVE deployment on
 * 2026-08-22 (no message was delivered — `/getstatus` and a send to an
 * unroutable number were used). It is:
 *
 *     {"Status":"0","Text":"ACCEPTD","Message_ID":"353406678"}
 *     {"Status":"109","Text":"Invalid api key/secret key","Message_ID":""}
 *     {"Status":"114","Text":"REJECTD","Message_ID":"","Delivery Time":"0"}
 *
 * Four things about it that the code below exists to survive, each seen
 * for real rather than guessed at:
 *
 * - **`Status` is authoritative, `Text` is not.** An authentication
 *   failure comes back as `Status 109` with `Text: REJECTD` — the same
 *   word a genuinely undeliverable message uses. Reading `Text` first on
 *   a status query would mark a perfectly healthy message `bounced`
 *   because the *request* was rejected. `Status` is checked first
 *   everywhere.
 * - **No `Content-Type` header at all.** `Response::json()` decodes on
 *   body alone, so this works, but nothing may branch on content type.
 * - **`Message_ID` is `""`, not absent**, on every error.
 * - **`/getmultistatus` answers `[,,]`** — not valid JSON — when it has
 *   nothing to report, and `/getstatus` answers with an empty body for a
 *   message that has no receipt yet. Neither is an error; both mean
 *   "no news".
 *
 * The wider tolerance in the parser is kept because the vendor runs many
 * deployments (`smpp.revesms.com`, `smpp.ajuratech.com`, the
 * `smsvaults.work` white-labels, per-tenant hosts and ports) and only one
 * of them has been observed.
 */
class ReveSmsClient
{
    /** @var array<int, string> */
    private const array MESSAGE_ID_KEYS = [
        'Message_ID', 'MessageID', 'Message_Id', 'MessageId', 'messageid',
        'message_id', 'msgid', 'msg_id', 'MsgId', 'id',
    ];

    /** @var array<int, string> */
    private const array STATUS_CODE_KEYS = [
        'Status', 'status', 'StatusCode', 'status_code', 'ErrorCode',
        'error_code', 'Error_Code', 'code',
    ];

    /** @var array<int, string> */
    private const array STATUS_TEXT_KEYS = [
        'Text', 'text', 'StatusText', 'status_text', 'Message', 'message',
        'Description', 'description', 'error', 'Error',
    ];

    /** @var array<int, string> */
    private const array RECIPIENT_KEYS = ['toUser', 'to_user', 'toUsers', 'msisdn', 'recipient', 'number'];

    /**
     * Status codes REVE uses for "accepted for delivery". `0` is the
     * conventional success code across their HTTP APIs; the words are what
     * the same field carries when it answers in text rather than numbers.
     *
     * @var array<int, string>
     */
    private const array SUCCESS_CODES = ['0', '00', '200', 'OK', 'SUCCESS', 'ACCEPTD', 'ACCEPTED', 'SUBMITTED', 'SENT'];

    /**
     * Codes seen from a live deployment. Only these two are confirmed, so
     * anything else falls through to whatever `Text` carried — inventing
     * meanings for unobserved codes would put a confident wrong reason in
     * front of an operator.
     *
     * PHP narrows numeric string keys to int, so this is keyed by
     * array-key rather than string — the lookup below casts to match.
     *
     * @var array<array-key, string>
     */
    private const array STATUS_MESSAGES = [
        '109' => 'Invalid api key/secret key',
        '114' => 'Inappropriate request parameter',
    ];

    public static function describeStatus(?string $code, ?string $text): ?string
    {
        if ($code === null) {
            return $text;
        }

        $known = self::STATUS_MESSAGES[$code] ?? null;

        if ($known !== null) {
            return $code.' '.$known;
        }

        return trim($code.' '.(string) $text) ?: $code;
    }

    /**
     * Send one message body to one or more recipients (`/sendtext`).
     *
     * @param  array<int, string>  $recipients  MSISDNs, already formatted
     * @return array<int, ReveSmsResult> one entry per recipient the gateway
     *                                   reported on; a gateway that answers
     *                                   with a single result for a
     *                                   multi-recipient send yields one entry
     */
    public function sendText(string $senderId, array $recipients, string $message): array
    {
        if ($recipients === []) {
            throw new RuntimeException('Cannot send an SMS with no recipients.');
        }

        $response = $this->call('sendtext', [
            'callerID' => $senderId,
            'toUser' => implode(',', $recipients),
            'messageContent' => $message,
        ]);

        return $this->parseSendResponse($response, $recipients);
    }

    /**
     * Several caller/message groups in one call (`/send`). Used by nothing
     * in the outbox path — {@see SendNotificationJob} drains one
     * row at a time, so batching would mean one queue job owning several
     * rows' state — but it is the endpoint an operator-initiated broadcast
     * would want, and it is cheaper per message at volume.
     *
     * @param  array<int, array{callerID: string, toUser: string, messageContent: string}>  $groups
     * @return array<int, ReveSmsResult>
     */
    public function send(array $groups): array
    {
        if ($groups === []) {
            throw new RuntimeException('Cannot send a bulk SMS with no content groups.');
        }

        $response = $this->call('send', ['content' => $groups], jsonEncodeContent: true);

        return $this->parseSendResponse($response, []);
    }

    public function status(string $messageId): ReveSmsStatus
    {
        $response = $this->call('getstatus', ['messageid' => $messageId]);

        $statuses = $this->parseStatusResponse($response, [$messageId]);

        return $statuses[$messageId] ?? new ReveSmsStatus(
            messageId: $messageId,
            state: ReveSmsDeliveryState::PENDING,
            providerStatus: null,
            raw: $this->decode($response),
        );
    }

    /**
     * @param  array<int, string>  $messageIds
     * @return array<string, ReveSmsStatus> keyed by message id
     */
    public function multiStatus(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $response = $this->call('getmultistatus', ['messageids' => implode(',', $messageIds)]);

        return $this->parseStatusResponse($response, $messageIds);
    }

    /**
     * Prepaid balance. `clienttransid` is REVE's per-request correlation id
     * and is echoed back rather than meaning anything to us.
     *
     * @return array{balance: float|null, raw: array<string, mixed>}
     */
    public function balance(): array
    {
        $response = $this->call('api/v2/balance', [
            'clienttransid' => (string) now()->getTimestampMs(),
        ]);

        $decoded = $this->decode($response);
        $balance = null;

        foreach (['balance', 'Balance', 'currentBalance', 'amount', 'Amount'] as $key) {
            if (isset($decoded[$key]) && is_numeric($decoded[$key])) {
                $balance = (float) $decoded[$key];
                break;
            }
        }

        return ['balance' => $balance, 'raw' => $decoded];
    }

    public static function isConfigured(): bool
    {
        return self::missingCredentials() === [];
    }

    /**
     * Which of the three required values is not set, in the words the
     * Settings screen uses for them.
     *
     * This exists because "not configured" on its own is the least helpful
     * thing this integration can say: all three are required, two of them
     * are invisible once saved, and the operator has no way to tell which
     * one is missing. Naming it turns a dead end into one field to fill.
     *
     * @return array<int, string>
     */
    public static function missingCredentials(): array
    {
        $required = [
            'api_key' => 'SMS API key',
            'secret_key' => 'SMS secret key',
            'sender_id' => 'SMS sender ID',
        ];

        return array_values(array_filter(
            $required,
            fn (string $_, string $key): bool => self::config($key) === null,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public static function defaultSenderId(): string
    {
        $senderId = self::config('sender_id');

        if ($senderId === null) {
            throw new RuntimeException('No SMS sender ID is set — REVE rejects a send with no callerID. Set it in Settings → SMS gateway.');
        }

        return $senderId;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function call(string $path, array $parameters, bool $jsonEncodeContent = false): Response
    {
        $apiKey = self::config('api_key');
        $secretKey = self::config('secret_key');

        if ($apiKey === null || $secretKey === null) {
            throw new RuntimeException('REVESMS_API_KEY and REVESMS_SECRET_KEY must both be set.');
        }

        $authStyle = self::config('auth_style', 'body');
        $url = rtrim((string) self::config('base_url', ''), '/').'/'.$path;
        $basicAuth = false;

        // All three are in the vendor's collection and all three were
        // confirmed working against a live deployment, so the account's
        // configuration decides rather than this class.
        match ($authStyle) {
            'path' => $url .= '/'.rawurlencode($apiKey).'/'.rawurlencode($secretKey),
            'basic' => $basicAuth = true,
            default => $parameters = ['apikey' => $apiKey, 'secretkey' => $secretKey] + $parameters,
        };

        // `/send` carries its groups as a JSON *string* in a `content`
        // parameter, not as a nested array — the vendor's own GET example
        // is `...&content=[{"callerID":…}]`, and the POST body in their
        // collection keeps the same key. Encoding it here rather than
        // relying on the HTTP client's array handling keeps both transports
        // sending the identical thing.
        if ($jsonEncodeContent && isset($parameters['content']) && is_array($parameters['content'])) {
            $parameters['content'] = json_encode(array_values($parameters['content']), JSON_UNESCAPED_UNICODE);
        }

        $request = $this->request();

        if ($basicAuth) {
            $request = $request->withBasicAuth($apiKey, $secretKey);
        }

        $response = match (self::config('method', 'post')) {
            'get' => $request->get($url, $parameters),
            // The collection's x-www-form-urlencoded variant. Some
            // deployments sit behind a proxy that will not forward a JSON
            // body, so this is the fallback that always reaches the app.
            'form' => $request->asForm()->post($url, $parameters),
            default => $request->asJson()->post($url, $parameters),
        };

        if ($response->serverError()) {
            throw new RuntimeException("REVE SMS gateway returned HTTP {$response->status()} for /{$path}.");
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::timeout((int) self::config('timeout', '15'))
            ->acceptJson()
            ->connectTimeout(10);
        // Deliberately no `->retry()`. A send is not idempotent at the
        // gateway — REVE has no client-supplied request id to deduplicate
        // on — so a retried request that the first attempt had already
        // accepted delivers the message twice and bills for it twice.
        // SendNotificationJob owns the retry schedule instead, and it
        // retries against an outbox row whose state records whether the
        // previous attempt landed.
    }

    /**
     * @param  array<int, string>  $recipients
     * @return array<int, ReveSmsResult>
     */
    private function parseSendResponse(Response $response, array $recipients): array
    {
        $decoded = $this->decode($response);

        // A gateway that reports per recipient answers with a list.
        $rows = $this->rowsFrom($decoded);
        $results = [];

        foreach ($rows as $index => $row) {
            $messageId = $this->pluck($row, self::MESSAGE_ID_KEYS);
            $statusCode = $this->pluck($row, self::STATUS_CODE_KEYS);
            $statusText = $this->pluck($row, self::STATUS_TEXT_KEYS);

            $results[] = new ReveSmsResult(
                accepted: $response->successful() && $this->indicatesSuccess($statusCode, $statusText, $messageId),
                messageId: $messageId,
                recipient: $this->pluck($row, self::RECIPIENT_KEYS) ?? ($recipients[$index] ?? null),
                statusCode: $statusCode,
                statusText: $statusText,
                raw: $row,
            );
        }

        if ($results === []) {
            // Seen for real: pointing at another operator's REVE instance
            // returns HTTP 200 with a completely empty body. Reported as
            // "the gateway said 200" that is indistinguishable from a
            // parser bug, so it names the likely cause instead — a wrong
            // host is by far the most common way to get here.
            $body = trim($response->body());

            $results[] = new ReveSmsResult(
                accepted: false,
                messageId: null,
                recipient: $recipients[0] ?? null,
                statusCode: (string) $response->status(),
                statusText: $body === ''
                    ? 'the gateway returned an empty response — check the SMS gateway URL, this usually means the host belongs to a different REVE account'
                    : $this->truncate($body),
                raw: $decoded,
            );
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $messageIds
     * @return array<string, ReveSmsStatus>
     */
    private function parseStatusResponse(Response $response, array $messageIds): array
    {
        $decoded = $this->decode($response);
        $statuses = [];

        foreach ($this->rowsFrom($decoded) as $index => $row) {
            $statusCode = $this->pluck($row, self::STATUS_CODE_KEYS);
            $providerStatus = $this->pluck($row, self::STATUS_TEXT_KEYS);

            // `Status` first, and this is the bug it exists to prevent: a
            // rejected *request* — bad credentials (109), an id that is not
            // ours (114) — answers `Text: REJECTD`, the same word an
            // undeliverable message uses. Trusting `Text` here would settle
            // a healthy message as `bounced` on the strength of an auth
            // failure, and `bounced` is terminal. A request that did not
            // succeed carries no verdict about the message at all.
            //
            // Only a *numeric* code is a request result, which is what every
            // observed response uses. A deployment that puts the delivery
            // word itself in this field (`status: DELIVRD`) is reporting the
            // receipt, not an error, so that reads as a status below rather
            // than discarding the row.
            $isRequestCode = $statusCode !== null && is_numeric($statusCode);

            if ($isRequestCode && ! in_array($statusCode, self::SUCCESS_CODES, true)) {
                continue;
            }

            $messageId = $this->pluck($row, self::MESSAGE_ID_KEYS) ?? ($messageIds[$index] ?? null);

            if ($messageId === null) {
                continue;
            }

            $providerStatus ??= $isRequestCode ? null : $statusCode;
            $providerStatus ??= $this->pluck($row, ['dlr', 'DLR', 'deliveryStatus']);

            $statuses[$messageId] = new ReveSmsStatus(
                messageId: $messageId,
                state: ReveSmsDeliveryState::fromProviderStatus($providerStatus),
                providerStatus: $providerStatus,
                raw: $row,
            );
        }

        return $statuses;
    }

    /**
     * Normalises whatever came back into a list of flat rows, so the
     * pluckers above have one shape to read.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(array $decoded): array
    {
        if ($decoded === []) {
            return [];
        }

        // `{"data": [...]}` / `{"response": [...]}` wrappers.
        foreach (['data', 'Data', 'response', 'Response', 'result', 'Result', 'content', 'messages'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $decoded = $decoded[$key];
                break;
            }
        }

        if (array_is_list($decoded)) {
            $rows = [];

            foreach ($decoded as $entry) {
                $rows[] = is_array($entry) ? $entry : ['value' => $entry];
            }

            return $rows;
        }

        return [$decoded];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function pluck(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function indicatesSuccess(?string $statusCode, ?string $statusText, ?string $messageId): bool
    {
        if ($statusCode !== null) {
            return in_array(strtoupper($statusCode), self::SUCCESS_CODES, true);
        }

        if ($statusText !== null && in_array(strtoupper($statusText), self::SUCCESS_CODES, true)) {
            return true;
        }

        // No status field at all: a message id is the gateway saying it took
        // the message. Nothing else it returns means that.
        return $messageId !== null;
    }

    /**
     * Decodes a response that may not be JSON at all. A gateway answering
     * `1373104` or `ACCEPTD|1373104` in `text/plain` is a real possibility
     * here — the vendor material shows no response format — and losing
     * that to a JSON decode would make every send look like a failure.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = $response->json();

        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        $body = trim($response->body());

        // Empty body, or `[,,]` — what /getstatus and /getmultistatus
        // really answer when they have no receipt yet. Neither is an
        // error and neither is a status; both mean "no news", so they must
        // decode to nothing rather than to a row carrying `[,,]` as a
        // delivery state.
        if ($body === '' || preg_match('/^\[\s*,*\s*\]$/', $body) === 1) {
            return [];
        }

        if (preg_match('/^\d{4,}$/', $body) === 1) {
            return ['Message_ID' => $body];
        }

        // `ACCEPTD|1373104`, `0|1373104`, `1373104|DELIVRD` — a two-field
        // pipe/colon line, whichever way round it puts them.
        if (preg_match('/^([A-Za-z0-9_ -]+)[|:]([A-Za-z0-9_ -]+)$/', $body, $matches) === 1) {
            [$left, $right] = [trim($matches[1]), trim($matches[2])];

            return preg_match('/^\d{4,}$/', $left) === 1
                ? ['Message_ID' => $left, 'Text' => $right]
                : ['Status' => $left, 'Message_ID' => $right];
        }

        return ['Text' => $this->truncate($body)];
    }

    private function truncate(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > 480 ? mb_substr($value, 0, 477).'...' : $value;
    }

    /**
     * Credentials come from {@see SmsGatewayConfig}, not straight from
     * `config()` — the settings screen has to be able to beat the value
     * baked into the deployed image, or "set the API key from the
     * dashboard" would silently do nothing.
     */
    private static function config(string $key, ?string $default = null): ?string
    {
        return app(SmsGatewayConfig::class)->get($key, $default);
    }
}
