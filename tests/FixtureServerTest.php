<?php

declare(strict_types=1);

namespace PureSms\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PureSms\PureSms;
use RuntimeException;

final class FixtureServerTest extends TestCase
{
    /** @var resource|null */
    private static $serverProcess;

    private static string $baseUrl;

    private static string $logPath;

    public static function setUpBeforeClass(): void
    {
        self::$logPath = tempnam(sys_get_temp_dir(), 'puresms-test-');
        if (self::$logPath === false) {
            self::fail('Unable to create fixture log file.');
        }

        $port = self::findAvailablePort();
        putenv('PURESMS_FIXTURE_LOG=' . self::$logPath);

        $outputPath = tempnam(sys_get_temp_dir(), 'puresms-server-output-');
        if ($outputPath === false) {
            self::fail('Unable to create fixture server output file.');
        }

        $pipes = [];
        self::$serverProcess = proc_open(
            [
                PHP_BINARY,
                '-S',
                '127.0.0.1:' . $port,
                __DIR__ . '/fixtures/router.php',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $outputPath, 'a'],
                2 => ['file', $outputPath, 'a'],
            ],
            $pipes,
            __DIR__ . '/fixtures'
        );

        if (!is_resource(self::$serverProcess)) {
            self::fail('Unable to start the fixture server.');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        self::waitForServer($port);
        self::$baseUrl = 'http://127.0.0.1:' . $port;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }

        if (isset(self::$logPath)) {
            @unlink(self::$logPath);
        }
    }

    protected function setUp(): void
    {
        file_put_contents(self::$logPath, '');
    }

    public function testItSendsASingleSmsWithNormalisedOptions(): void
    {
        $client = new PureSms('test-api-key', 'DefaultSender', self::$baseUrl);
        $response = $client->send(
            '+447700900123',
            'Hello from PHP',
            null,
            [
                'sendAtUtc' => new DateTimeImmutable('2026-07-21 13:45:00', new DateTimeZone('Europe/London')),
                'clientReference' => 'order-42',
                'unicode' => 'Allow',
                'enableLinkShortening' => true,
            ]
        );

        self::assertSame(['id' => '123', 'countryCode' => 'GB'], $response);

        $request = $this->lastRequest();
        self::assertSame('POST', $request['method']);
        self::assertSame('/sms/send', $request['path']);
        self::assertSame('test-api-key', $request['headers']['x-api-key']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertSame([
            'sender' => 'DefaultSender',
            'recipient' => '+447700900123',
            'content' => 'Hello from PHP',
            'sendAtUtc' => '2026-07-21T12:45:00Z',
            'clientReference' => 'order-42',
            'unicode' => 'Allow',
            'enableLinkShortening' => true,
        ], $request['json']);
    }

    public function testItSendsABatchAndAcceptsMultiStatus(): void
    {
        $client = new PureSms('test-api-key', 'DefaultSender', self::$baseUrl);
        $response = $client->sendBatch(
            [
                [
                    'recipient' => '+447700900123',
                    'content' => 'First message',
                    'clientReference' => 'first',
                    'unicode' => 'Strip',
                ],
            ],
            [
                'sendAtUtc' => new DateTimeImmutable('2026-07-21 12:00:00', new DateTimeZone('UTC')),
                'enableLinkShortening' => false,
            ]
        );

        self::assertSame([
            'batchId' => '456',
            'messageCount' => 1,
            'errors' => [
                ['index' => 1, 'error' => 'example validation error'],
            ],
        ], $response);

        $request = $this->lastRequest();
        self::assertSame('/sms/send/bulk', $request['path']);
        self::assertSame([
            'messages' => [
                [
                    'sender' => 'DefaultSender',
                    'recipient' => '+447700900123',
                    'content' => 'First message',
                    'unicode' => 'Strip',
                    'clientReference' => 'first',
                ],
            ],
            'sendAtUtc' => '2026-07-21T12:00:00Z',
            'enableLinkShortening' => false,
        ], $request['json']);
    }

    public function testItCancelsScheduledMessagesAndBatches(): void
    {
        $client = new PureSms('test-api-key', 'DefaultSender', self::$baseUrl);

        self::assertTrue($client->cancelScheduledSms(123));
        self::assertSame('/sms/send/123', $this->lastRequest()['path']);
        self::assertSame('DELETE', $this->lastRequest()['method']);

        self::assertSame([
            'cancelledCount' => 2,
            'reason' => null,
            'errors' => [],
        ], $client->cancelScheduledBatch('456'));
        self::assertSame('/sms/send/bulk/456', $this->lastRequest()['path']);
        self::assertSame('DELETE', $this->lastRequest()['method']);
    }

    public function testItThrowsForInvalidJsonAndHttpFailures(): void
    {
        $invalidJsonClient = new PureSms('test-api-key', 'DefaultSender', self::$baseUrl . '/invalid-json');
        try {
            $invalidJsonClient->send('+447700900123', 'Hello');
            self::fail('Expected invalid JSON failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('invalid JSON', $exception->getMessage());
        }

        $httpFailureClient = new PureSms('test-api-key', 'DefaultSender', self::$baseUrl . '/error');
        try {
            $httpFailureClient->send('+447700900123', 'Hello');
            self::fail('Expected HTTP failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('HTTP 401', $exception->getMessage());
            self::assertStringNotContainsString('test-api-key', $exception->getMessage());
        }
    }

    public function testItThrowsForCurlConnectionFailures(): void
    {
        $client = new PureSms('test-api-key', 'DefaultSender', 'http://127.0.0.1:1', 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cURL error');

        $client->send('+447700900123', 'Hello');
    }

    public function testItRejectsInvalidInputBeforeMakingARequest(): void
    {
        $client = new PureSms('test-api-key', 'DefaultSender', self::$baseUrl);

        try {
            $client->send('', 'Hello');
            self::fail('Expected an empty recipient error.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Recipient', $exception->getMessage());
        }

        try {
            $client->send('+447700900123', 'Hello', null, ['sendAtUtc' => '2026-07-21T12:00:00Z']);
            self::fail('Expected an invalid date error.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('DateTimeInterface', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unicode');

        $client->send('+447700900123', 'Hello', null, ['unicode' => 'allow']);
    }

    public function testItRejectsMissingBatchFieldsAndUnsupportedOptions(): void
    {
        $client = new PureSms('test-api-key', 'DefaultSender', self::$baseUrl);

        try {
            $client->sendBatch([['content' => 'Missing recipient']]);
            self::fail('Expected a missing batch field error.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('recipient and content', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported option');
        $client->send('+447700900123', 'Hello', null, ['unknown' => true]);
    }

    public function testItVerifiesWebhookSignaturesInConstantTime(): void
    {
        $payload = '{"id":"evt_123","eventType":1}';
        $timestamp = '1736937000';
        $secret = 'webhook-secret';
        $signature = 'xsSOjC8SdEID77BwImeaD/TKPdXNtrfLSbeCLFKasjI=';

        self::assertTrue(PureSms::verifyWebhookSignature($payload, $signature, $timestamp, $secret));
        self::assertFalse(PureSms::verifyWebhookSignature($payload, $signature . 'x', $timestamp, $secret));
    }

    /** @return array{method: string, path: string, headers: array<string, string>, json: array<string, mixed>|null} */
    private function lastRequest(): array
    {
        $lines = file(self::$logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($lines);
        self::assertNotSame([], $lines, 'Fixture server did not receive a request.');

        $request = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($request);

        return $request;
    }

    private static function findAvailablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            self::fail('Unable to reserve a local port: ' . $errorMessage);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private static function waitForServer(int $port): void
    {
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $connection = @fsockopen('127.0.0.1', $port);
            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::fail('Fixture server did not start.');
    }
}
