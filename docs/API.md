# API reference

`PureSms\PureSms` is a synchronous client for `https://connect-api.divergent.cloud`. Its optional constructor base URL is intended for test environments and compatible proxies.

```php
new PureSms\PureSms(
    string $apiKey,
    ?string $defaultSender = null,
    string $baseUrl = 'https://connect-api.divergent.cloud',
    int $timeout = 30,
);
```

Every request uses `X-Api-Key` and `Accept: application/json`. POST requests also use `Content-Type: application/json`.

## `send()`

```php
send(string $recipient, string $content, ?string $sender = null, array $options = []): array
```

Issues `POST /sms/send`.

| PHP input | JSON field | Notes |
| --- | --- | --- |
| `$sender` | `sender` | Required after the default sender fallback. |
| `$recipient` | `recipient` | Required; PureSMS expects E.164. |
| `$content` | `content` | Required message body. |
| `options['sendAtUtc']` | `sendAtUtc` | A `DateTimeInterface`; serialised as a UTC ISO-8601 timestamp. |
| `options['clientReference']` | `clientReference` | Non-empty string used for reconciliation. |
| `options['unicode']` | `unicode` | Exact value: `Allow`, `Deny`, or `Strip`. |
| `options['enableLinkShortening']` | `enableLinkShortening` | Boolean workspace-setting override. |

The response is the decoded PureSMS object, normally containing an `id` and `countryCode`.

## `sendBatch()`

```php
sendBatch(array $messages, array $options = []): array
```

Issues `POST /sms/send/bulk`. Each message must contain non-empty `recipient` and `content`; it may include `sender`, `unicode`, and `clientReference`. A missing `sender` uses the constructor’s default sender.

Batch-level options are:

| PHP input | JSON field | Notes |
| --- | --- | --- |
| `options['sendAtUtc']` | `sendAtUtc` | A UTC send time applied to the batch. |
| `options['enableLinkShortening']` | `enableLinkShortening` | Boolean workspace-setting override. |

The response contains `batchId`, `messageCount`, and optionally `errors`. A `207 Multi-Status` response is successful at the transport level and is returned normally, allowing the caller to inspect accepted messages and validation failures.

## Cancellation

| Method | HTTP endpoint | Return value |
| --- | --- | --- |
| `cancelScheduledSms(string\|int $id)` | `DELETE /sms/send/{id}` | `true` for any 2xx response. |
| `cancelScheduledBatch(string\|int $id)` | `DELETE /sms/send/bulk/{id}` | Decoded response containing `cancelledCount` and optional `reason`/`errors`. |

PureSMS returns an error when a message or batch is missing or no longer cancellable. The client raises a `RuntimeException` for this non-2xx response.

## Errors

The methods return associative arrays on successful JSON calls. They raise:

- `InvalidArgumentException` for local required-field, option-key/type, Unicode-mode, ID, URL, or timeout validation errors.
- `RuntimeException` for cURL setup/transport errors, JSON serialisation/deserialisation errors, or HTTP statuses outside 200–299.

HTTP error text is capped at 1,024 characters and redacts the configured API key. No call is retried automatically.
