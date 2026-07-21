# Webhooks

PureSMS posts JSON webhook envelopes to endpoints configured in the workspace. Configure an endpoint-specific secret and validate every request before trusting its JSON data.

## Validation

PureSMS supplies:

- `X-Webhook-Signature`: a Base64 HMAC-SHA256 signature.
- `X-Webhook-Timestamp`: Unix timestamp in seconds.

The signed value is exactly `{timestamp}.{raw JSON body}`. Pass the untouched request body to `PureSms::verifyWebhookSignature()` before decoding JSON. The method uses `hash_equals()` and returns a boolean; it deliberately does not reject an old timestamp. Applications that need replay protection should store event IDs and reject timestamps outside their own policy window.

## Envelope

Every event includes an `id`, ISO-8601 `timestamp`, `workspaceId`, integer `eventType`, and event-specific `data`.

| Event type | Event | Key data |
| --- | --- | --- |
| `1` | Delivery receipt | `messageId`, `clientReference`, `deliveryStatus`, `errorCode`, `processedAt`, `deliveredAt`, optional `smsParts`, `costEur`, and `costGbp`. |
| `2` | Inbound SMS | `messageId`, `inboundNumber`, `sender`, `body`, and `receivedAt`. |

Delivery status can progress through `Queued` and `Dispatched` before a final result such as `Delivered`, `Failed`, `Expired`, `Rejected`, `Cancelled`, or `Deleted`. Treat `id` as the event deduplication key.

## Endpoint behaviour

Return a 2xx response promptly, ideally after queuing application work. PureSMS documents a 45-second timeout, retries on failure, and endpoint disablement after repeated failures. Use HTTPS, preserve raw payloads only as long as required, and avoid logging secrets or personal message data.

This package verifies signatures only; webhook routing, event parsing, persistence, deduplication, and asynchronous processing belong to the host application.
