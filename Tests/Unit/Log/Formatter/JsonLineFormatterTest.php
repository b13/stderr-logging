<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Tests\Unit\Log\Formatter;

use B13\StderrLogging\Log\Formatter\JsonLineFormatter;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class JsonLineFormatterTest extends UnitTestCase
{
    #[Test]
    public function formatReturnsOneLineOfJsonWithTheRecordFields(): void
    {
        $record = new LogRecord('TYPO3.CMS.Core.Foo', LogLevel::WARNING, "Something\nhappened", ['uid' => 42], 'abc123');
        $record->setCreated(1764151200.123456);

        $line = (new JsonLineFormatter([]))->format($record);

        self::assertStringNotContainsString("\n", $line);
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('warning', $decoded['level']);
        self::assertSame('TYPO3.CMS.Core.Foo', $decoded['channel']);
        self::assertSame("Something\nhappened", $decoded['message']);
        self::assertSame('abc123', $decoded['request_id']);
        self::assertSame(['uid' => 42], $decoded['context']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.123[+-]\d{2}:\d{2}$/', $decoded['time']);
    }

    #[Test]
    public function formatInterpolatesPlaceholders(): void
    {
        $record = new LogRecord('foo', LogLevel::INFO, 'User {username} logged in from {ip}, admin: {admin}, missing: {missing}', [
            'username' => 'editor',
            'ip' => '192.0.2.1',
            'admin' => false,
        ]);

        $decoded = json_decode((new JsonLineFormatter([]))->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('User editor logged in from 192.0.2.1, admin: false, missing: {missing}', $decoded['message']);
    }

    #[Test]
    public function formatLeavesOutEmptyOptionalFields(): void
    {
        $record = new LogRecord('foo', LogLevel::ERROR, 'Plain message');

        $decoded = json_decode((new JsonLineFormatter([]))->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['time', 'level', 'channel', 'message'], array_keys($decoded));
    }

    #[Test]
    public function formatAddsTheHttpRequestIdOfTheIncomingRequest(): void
    {
        $record = new LogRecord('foo', LogLevel::ERROR, 'Plain message');

        $decoded = json_decode((new JsonLineFormatter(['HTTP_X_REQUEST_ID' => 'ingress-4711']))->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ingress-4711', $decoded['http_request_id']);
    }

    #[Test]
    public function formatMovesTheExceptionOutOfTheContext(): void
    {
        $previous = new \LogicException('Inner problem', 1764151201);
        $exception = new \RuntimeException('Outer problem', 1764151202, $previous);
        $record = new LogRecord('foo', LogLevel::CRITICAL, 'Request failed', ['exception' => $exception, 'page' => 1]);

        $decoded = json_decode((new JsonLineFormatter([]))->format($record), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['page' => 1], $decoded['context']);
        self::assertSame(\RuntimeException::class, $decoded['exception']['class']);
        self::assertSame('Outer problem', $decoded['exception']['message']);
        self::assertSame(1764151202, $decoded['exception']['code']);
        self::assertSame(__FILE__ . ':' . $exception->getLine(), $decoded['exception']['file']);
        self::assertIsArray($decoded['exception']['trace']);
        self::assertSame(\LogicException::class, $decoded['exception']['previous']['class']);
    }

    #[Test]
    public function formatReducesObjectsAndResourcesToSomethingJsonCanCarry(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);
        $record = new LogRecord('foo', LogLevel::INFO, 'Context types', [
            'object' => new \ArrayObject(['secret' => 'not logged']),
            'stringable' => new class() implements \Stringable {
                public function __toString(): string
                {
                    return 'as string';
                }
            },
            'date' => new \DateTimeImmutable('2026-11-26T10:00:00+00:00'),
            'resource' => $resource,
            'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too deep']]]]]],
            'invalidUtf8' => "\xB1\x31",
        ]);

        $line = (new JsonLineFormatter([]))->format($record);
        fclose($resource);
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('[object ArrayObject]', $decoded['context']['object']);
        self::assertSame('as string', $decoded['context']['stringable']);
        self::assertSame('2026-11-26T10:00:00.000+00:00', $decoded['context']['date']);
        self::assertSame('[resource stream]', $decoded['context']['resource']);
        self::assertSame('[array]', $decoded['context']['deep']['a']['b']['c']['d']);
        self::assertSame("\u{FFFD}1", $decoded['context']['invalidUtf8']);
    }
}
