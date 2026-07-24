<?php

declare(strict_types=1);

namespace PureSms;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * A synchronous client for the PureSMS REST API.
 *
 * @see https://the.divergent.guide/puresms/developers/
 */
final class PureSms
{
    private const DEFAULT_BASE_URL = 'https://connect-api.divergent.cloud';

    /** @var list<string> */
    private const SINGLE_SEND_OPTIONS = [
        'sendAtUtc',
        'clientReference',
        'unicode',
        'enableLinkShortening',
    ];

    /** @var list<string> */
    private const BATCH_OPTIONS = [
        'sendAtUtc',
        'enableLinkShortening',
    ];

    /** @var list<string> */
    private const BATCH_MESSAGE_OPTIONS = [
        'sender',
        'recipient',
        'content',
        'unicode',
        'clientReference',
    ];

    /** @var list<string> */
    private const UNICODE_MODES = ['Allow', 'Deny', 'Strip'];

    private string $apiKey;

    private ?string $defaultSender;

    private string $baseUrl;

    private int $timeout;

    /**
     * @param string      $apiKey        A PureSMS workspace API key.
     * @param string|null $defaultSender Sender to use when a send call omits one.
     * @param string      $baseUrl       API base URL; configurable for testing.
     * @param int         $timeout       Request timeout in seconds.
     */
    public function __construct(
        string $apiKey,
        ?string $defaultSender = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = 30
    ) {
        $this->apiKey = self::requireNonEmptyString($apiKey, 'API key');
        $this->defaultSender = $defaultSender === null
            ? null
            : self::requireNonEmptyString($defaultSender, 'Default sender');

        $normalisedBaseUrl = rtrim(trim($baseUrl), '/');
        if ($normalisedBaseUrl === '' || filter_var($normalisedBaseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Base URL must be a valid URL.');
        }

        if ($timeout < 1) {
            throw new InvalidArgumentException('Timeout must be at least one second.');
        }

        $this->baseUrl = $normalisedBaseUrl;
        $this->timeout = $timeout;
    }

    /**
     * Send one SMS message.
     *
     * Supported options are `sendAtUtc` (DateTimeInterface), `clientReference`
     * (string), `unicode` (Allow, Deny, or Strip), and `enableLinkShortening`
     * (bool).
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function send(string $recipient, string $content, ?string $sender = null, array $options = []): array
    {
        $this->assertAllowedOptions($options, self::SINGLE_SEND_OPTIONS, 'Send options');

        $payload = [
            'sender' => $this->resolveSender($sender),
            'recipient' => self::requireNonEmptyString($recipient, 'Recipient'),
            'content' => self::requireNonEmptyString($content, 'Content'),
        ];

        if (array_key_exists('sendAtUtc', $options)) {
            $payload['sendAtUtc'] = $this->formatScheduledTime($options['sendAtUtc']);
        }

        if (array_key_exists('clientReference', $options)) {
            $payload['clientReference'] = self::requireStringOption($options['clientReference'], 'clientReference');
        }

        if (array_key_exists('unicode', $options)) {
            $payload['unicode'] = $this->validateUnicodeMode($options['unicode']);
        }

        if (array_key_exists('enableLinkShortening', $options)) {
            $payload['enableLinkShortening'] = self::requireBooleanOption(
                $options['enableLinkShortening'],
                'enableLinkShortening'
            );
        }

        return $this->request('POST', '/sms/send', $payload);
    }

    /**
     * Send a batch of SMS messages.
     *
     * Every message must contain `recipient` and `content`. A missing `sender`
     * is filled from the default sender passed to the constructor.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options Supported keys: sendAtUtc, enableLinkShortening.
     *
     * @return array<string, mixed>
     */
    public function sendBatch(array $messages, array $options = []): array
    {
        if ($messages === []) {
            throw new InvalidArgumentException('Messages must contain at least one message.');
        }

        $this->assertAllowedOptions($options, self::BATCH_OPTIONS, 'Batch options');

        $payload = [
            'messages' => [],
        ];

        if (array_key_exists('sendAtUtc', $options)) {
            $payload['sendAtUtc'] = $this->formatScheduledTime($options['sendAtUtc']);
        }

        if (array_key_exists('enableLinkShortening', $options)) {
            $payload['enableLinkShortening'] = self::requireBooleanOption(
                $options['enableLinkShortening'],
                'enableLinkShortening'
            );
        }

        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                throw new InvalidArgumentException(sprintf('Message at index %s must be an array.', (string) $index));
            }

            $this->assertAllowedOptions($message, self::BATCH_MESSAGE_OPTIONS, sprintf('Message at index %s', (string) $index));

            if (!array_key_exists('recipient', $message) || !array_key_exists('content', $message)) {
                throw new InvalidArgumentException(sprintf('Message at index %s must include recipient and content.', (string) $index));
            }

            $messageSender = null;
            if (array_key_exists('sender', $message)) {
                $messageSender = self::requireStringOption($message['sender'], sprintf('sender for message at index %s', (string) $index));
            }

            $normalisedMessage = [
                'sender' => $this->resolveSender($messageSender),
                'recipient' => self::requireStringOption($message['recipient'], sprintf('recipient for message at index %s', (string) $index)),
                'content' => self::requireStringOption($message['content'], sprintf('content for message at index %s', (string) $index)),
            ];

            if (array_key_exists('unicode', $message)) {
                $normalisedMessage['unicode'] = $this->validateUnicodeMode($message['unicode']);
            }

            if (array_key_exists('clientReference', $message)) {
                $normalisedMessage['clientReference'] = self::requireStringOption(
                    $message['clientReference'],
                    sprintf('clientReference for message at index %s', (string) $index)
                );
            }

            $payload['messages'][] = $normalisedMessage;
        }

        return $this->request('POST', '/sms/send/bulk', $payload);
    }

    /**
     * Cancel one scheduled SMS.
     */
    public function cancelScheduledSms(string|int $id): bool
    {
        $this->request('DELETE', '/sms/send/' . rawurlencode($this->normaliseId($id)), null, false);

        return true;
    }

    /**
     * Cancel a scheduled batch and return its cancellation summary.
     *
     * @return array<string, mixed>
     */
    public function cancelScheduledBatch(string|int $id): array
    {
        return $this->request('DELETE', '/sms/send/bulk/' . rawurlencode($this->normaliseId($id)));
    }

    /**
     * Validate a PureSMS HMAC-SHA256 webhook signature.
     *
     * The payload must be the unchanged raw HTTP request body.
     */
    public static function verifyWebhookSignature(
        string $rawPayload,
        string $signature,
        string $timestamp,
        string $secret
    ): bool {
        $expectedSignature = base64_encode(hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret, true));

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Convert a phone number to E.164 format.
     *
     * UK country code 44 is used for numbers without an international prefix.
     * Pass another country calling code when normalising a national number from
     * elsewhere. Numbers prefixed with + or 00 retain their country calling
     * code. Spaces, hyphens, parentheses, and full stops are ignored.
     *
     * @param string|int $number      A phone number in national or international form.
     * @param string|int $countryCode Country calling code for national numbers; defaults to 44.
     */
    public static function toE164(string|int $number, string|int $countryCode = '44'): string
    {
        $normalisedCountryCode = self::normaliseCountryCallingCode($countryCode);
        $value = trim((string) $number);

        if ($value === '') {
            throw new InvalidArgumentException('Phone number must not be empty.');
        }

        if (preg_match('/^\\+?[0-9 .()\\-]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Phone number must contain only digits and common phone-number separators.');
        }

        $hasInternationalPrefix = $value[0] === '+' || str_starts_with($value, '00');
        $digits = preg_replace('/[^0-9]/', '', $value);
        if ($digits === null || $digits === '') {
            throw new InvalidArgumentException('Phone number must contain at least one digit.');
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, $normalisedCountryCode)) {
            $subscriberNumber = substr($digits, strlen($normalisedCountryCode));
            if (str_starts_with($subscriberNumber, '0')) {
                $subscriberNumber = substr($subscriberNumber, 1);
            }

            if ($subscriberNumber === '') {
                throw new InvalidArgumentException('Phone number must include digits after the country calling code.');
            }

            $normalisedNumber = '+' . $normalisedCountryCode . $subscriberNumber;
        } elseif ($hasInternationalPrefix) {
            $normalisedNumber = '+' . $digits;
        } else {
            $subscriberNumber = str_starts_with($digits, '0') ? substr($digits, 1) : $digits;
            if ($subscriberNumber === '') {
                throw new InvalidArgumentException('Phone number must include digits after the national prefix.');
            }

            $normalisedNumber = '+' . $normalisedCountryCode . $subscriberNumber;
        }

        if (preg_match('/^\\+[1-9][0-9]{1,14}$/', $normalisedNumber) !== 1) {
            throw new InvalidArgumentException('Phone number must be a valid-length E.164 number.');
        }

        return $normalisedNumber;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null, bool $expectJson = true): array
    {
        $curl = curl_init($this->baseUrl . $path);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialise the cURL request.');
        }

        try {
            $headers = [
                'Accept: application/json',
                'X-Api-Key: ' . $this->apiKey,
            ];

            $curlOptions = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min($this->timeout, 10),
            ];

            if ($payload !== null) {
                try {
                    $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Unable to encode the PureSMS request as JSON.', 0, $exception);
                }

                $curlOptions[CURLOPT_POSTFIELDS] = $encodedPayload;
                $headers[] = 'Content-Type: application/json';
            }

            $curlOptions[CURLOPT_HTTPHEADER] = $headers;

            if (curl_setopt_array($curl, $curlOptions) === false) {
                throw new RuntimeException('Unable to configure the cURL request.');
            }

            $responseBody = curl_exec($curl);
            if ($responseBody === false) {
                throw new RuntimeException(sprintf(
                    'PureSMS request failed: cURL error %d: %s',
                    curl_errno($curl),
                    curl_error($curl)
                ));
            }

            $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new RuntimeException($this->httpFailureMessage($statusCode, $responseBody));
            }

            if (!$expectJson) {
                return [];
            }

            try {
                $decodedResponse = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException(
                    sprintf('PureSMS API returned invalid JSON for HTTP %d.', $statusCode),
                    0,
                    $exception
                );
            }

            if (!is_array($decodedResponse)) {
                throw new RuntimeException(sprintf('PureSMS API returned a non-object JSON response for HTTP %d.', $statusCode));
            }

            return $decodedResponse;
        } finally {
            unset($curl);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>          $allowedOptions
     */
    private function assertAllowedOptions(array $options, array $allowedOptions, string $name): void
    {
        foreach (array_keys($options) as $option) {
            if (!is_string($option) || !in_array($option, $allowedOptions, true)) {
                throw new InvalidArgumentException(sprintf('%s contains unsupported option %s.', $name, (string) $option));
            }
        }
    }

    private function resolveSender(?string $sender): string
    {
        if ($sender !== null) {
            return self::requireNonEmptyString($sender, 'Sender');
        }

        if ($this->defaultSender === null) {
            throw new InvalidArgumentException('A sender is required when no default sender is configured.');
        }

        return $this->defaultSender;
    }

    private function formatScheduledTime(mixed $value): string
    {
        if (!$value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('sendAtUtc must be a DateTimeInterface instance.');
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s\\Z');
    }

    private function validateUnicodeMode(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::UNICODE_MODES, true)) {
            throw new InvalidArgumentException('unicode must be Allow, Deny, or Strip.');
        }

        return $value;
    }

    private function normaliseId(string|int $id): string
    {
        return self::requireNonEmptyString((string) $id, 'ID');
    }

    private static function requireNonEmptyString(string $value, string $name): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($name . ' must not be empty.');
        }

        return $value;
    }

    private static function normaliseCountryCallingCode(string|int $countryCode): string
    {
        $value = trim((string) $countryCode);
        if (preg_match('/^\\+?[1-9][0-9]{0,2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Country calling code must contain one to three digits and may begin with +.');
        }

        return ltrim($value, '+');
    }

    private static function requireStringOption(mixed $value, string $name): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($name . ' must be a non-empty string.');
        }

        return $value;
    }

    private static function requireBooleanOption(mixed $value, string $name): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException($name . ' must be a boolean.');
        }

        return $value;
    }

    private function httpFailureMessage(int $statusCode, string $responseBody): string
    {
        $safeResponse = trim(str_replace($this->apiKey, '[REDACTED]', $responseBody));
        if ($safeResponse === '') {
            return sprintf('PureSMS API request failed with HTTP %d.', $statusCode);
        }

        return sprintf(
            'PureSMS API request failed with HTTP %d: %s',
            $statusCode,
            substr($safeResponse, 0, 1024)
        );
    }
}
