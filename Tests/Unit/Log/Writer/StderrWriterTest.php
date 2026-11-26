<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Tests\Unit\Log\Writer;

use B13\StderrLogging\Log\Writer\StderrWriter;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\Exception\InvalidLogWriterConfigurationException;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class StderrWriterTest extends UnitTestCase
{
    private string $streamFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->streamFile = (string)tempnam(sys_get_temp_dir(), 'stderr_logging_');
    }

    protected function tearDown(): void
    {
        @unlink($this->streamFile);
        parent::tearDown();
    }

    #[Test]
    public function writeLogWritesOneJsonLinePerRecord(): void
    {
        $writer = new StderrWriter(['stream' => $this->streamFile]);

        $writer->writeLog(new LogRecord('first', LogLevel::WARNING, 'First record'));
        $writer->writeLog(new LogRecord('second', LogLevel::ERROR, "Second\nrecord"));

        $lines = file($this->streamFile, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        self::assertSame('First record', json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR)['message']);
        self::assertSame("Second\nrecord", json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR)['message']);
    }

    #[Test]
    public function writeLogDoesNotFailWhenTheStreamCannotBeOpened(): void
    {
        $writer = new StderrWriter(['stream' => $this->streamFile . '/not/a/directory/log']);

        self::assertSame($writer, $writer->writeLog(new LogRecord('foo', LogLevel::ERROR, 'Lost record')));
    }

    #[Test]
    public function unknownOptionsAreRejected(): void
    {
        $this->expectException(InvalidLogWriterConfigurationException::class);

        new StderrWriter(['logFile' => 'var/log/typo3.log']);
    }
}
