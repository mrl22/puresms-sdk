# PureSMS PHP API

[![CI](https://github.com/mrl22/puresms-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/mrl22/puresms-sdk/actions/workflows/ci.yml)

An unofficial, dependency-free PHP 8.1+ client for the [PureSMS API](https://puresms.uk/Developers). It provides synchronous single and batch sending, scheduled-message cancellation, and webhook HMAC verification.

> This is an independent community project. It is not affiliated with, endorsed by, or supported by PureSMS or Divergent.

## Requirements

- PHP 8.1 or later
- PHP's `ext-curl` extension

## Installation

After the package has been registered on Packagist:

```sh
composer require mrl22/puresms-sdk
```

Until then, install from the public GitHub repository with a Composer VCS repository definition. See [the release guide](docs/RELEASING.md).

Create a PureSMS workspace, sender, and API key in the PureSMS dashboard before sending messages. Test mode currently has provider-specific restrictions; consult the [official developer documentation](https://the.divergent.guide/puresms/developers/).

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PureSms\PureSms;

$sms = new PureSms(
    apiKey: getenv('PURESMS_API_KEY'),
    defaultSender: 'YourSender'
);

$result = $sms->send('+447700900123', 'Your verification code is 123456');

echo $result['id'];
```

Recipient numbers should be supplied in E.164 form. The client verifies required non-empty fields and option types; PureSMS remains the authority for sender approval, destination, message-content, and other provider validation.

## Sending SMS

```php
use DateTimeImmutable;
use DateTimeZone;

$result = $sms->send(
    recipient: '+447700900123',
    content: 'Your appointment is tomorrow at 10am.',
    options: [
        'sendAtUtc' => new DateTimeImmutable('2026-07-22 10:00:00', new DateTimeZone('UTC')),
        'clientReference' => 'appointment-123',
        'unicode' => 'Allow',
        'enableLinkShortening' => true,
    ]
);
```

`send()` returns PureSMS’s decoded JSON response, including `id` and `countryCode`.

To send a batch, make each item an associative array. The constructor’s `defaultSender` fills in a missing per-message sender.

```php
$result = $sms->sendBatch(
    messages: [
        [
            'recipient' => '+447700900123',
            'content' => 'First message',
            'clientReference' => 'customer-1',
        ],
        [
            'sender' => 'OtherSender',
            'recipient' => '+447700900124',
            'content' => 'Second message',
            'unicode' => 'Strip',
        ],
    ],
    options: [
        'enableLinkShortening' => false,
    ]
);

// A 207 multi-status response is returned normally:
var_dump($result['batchId'], $result['messageCount'], $result['errors']);
```

See [the API reference](docs/API.md) for all option keys, response shapes, and endpoint mappings.

## Cancelling scheduled sends

```php
$sms->cancelScheduledSms('12345678');

$batchResult = $sms->cancelScheduledBatch('87654321');
echo $batchResult['cancelledCount'];
```

Only a scheduled message or batch that PureSMS considers cancellable can be cancelled.

## Webhook signatures

Read the request body before JSON decoding or otherwise transforming it. PureSMS signs the exact raw JSON body.

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

if (!PureSms::verifyWebhookSignature(
    $payload,
    $signature,
    $timestamp,
    getenv('PURESMS_WEBHOOK_SECRET')
)) {
    http_response_code(401);
    exit;
}

$event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
http_response_code(204);
```

`verifyWebhookSignature()` performs a constant-time comparison but does not parse the event or apply timestamp/replay policy. See [webhook guidance](docs/WEBHOOKS.md) for expected event data and delivery behaviour.

## Errors and retries

- `InvalidArgumentException` indicates invalid local input, such as an empty sender or unsupported option.
- `RuntimeException` indicates cURL, JSON-encoding/decoding, or non-2xx HTTP failures. The HTTP status and a safely redacted, truncated response body are included when available.
- No automatic retries occur. Retrying an uncertain SMS send could create duplicate messages; implement idempotency and retry policy at the application layer.

Do not commit API keys or webhook secrets. See [SECURITY.md](SECURITY.md) for reporting guidance.

## Development

```sh
composer install
composer validate --strict
composer lint
composer test
```

Contributions are welcome under the [contribution guide](CONTRIBUTING.md). Releases follow [the release checklist](docs/RELEASING.md).
