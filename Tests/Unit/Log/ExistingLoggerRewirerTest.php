<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Tests\Unit\Log;

use B13\StderrLogging\Log\ExistingLoggerRewirer;
use B13\StderrLogging\Log\Writer\StderrWriter;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Log\Writer\NullWriter;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExistingLoggerRewirerTest extends UnitTestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'stderr_logging_');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    #[Test]
    public function rewireReplacesFileWritersAndKeepsOtherWriters(): void
    {
        $otherWriter = new NullWriter();
        $logger = new Logger('Early.Logger');
        $logger->addWriter(LogLevel::WARNING, new FileWriter(['logFile' => $this->logFile]));
        $logger->addWriter(LogLevel::ERROR, $otherWriter);
        $stderrWriter = new StderrWriter();

        $rewired = (new ExistingLoggerRewirer())->rewire([$logger], $stderrWriter);

        self::assertSame(1, $rewired);
        self::assertSame([$otherWriter, $stderrWriter], $logger->getWriters()[LogLevel::ERROR]);
        self::assertSame([$stderrWriter], $logger->getWriters()[LogLevel::WARNING]);
        self::assertArrayNotHasKey(LogLevel::NOTICE, $logger->getWriters());
        self::assertSame(0, $this->countWritersOfType($logger, FileWriter::class));
    }

    #[Test]
    public function rewireAddsOneWriterForFileWritersOnSeveralLevels(): void
    {
        $logger = new Logger('Early.Logger');
        $logger->addWriter(LogLevel::WARNING, new FileWriter(['logFile' => $this->logFile]));
        $logger->addWriter(LogLevel::INFO, new FileWriter(['logFile' => $this->logFile]));
        $stderrWriter = new StderrWriter();

        (new ExistingLoggerRewirer())->rewire([$logger], $stderrWriter);

        self::assertSame([$stderrWriter], $logger->getWriters()[LogLevel::EMERGENCY]);
        self::assertSame([$stderrWriter], $logger->getWriters()[LogLevel::INFO]);
        self::assertArrayNotHasKey(LogLevel::DEBUG, $logger->getWriters());
    }

    #[Test]
    public function rewireUsesTheGivenMinimumLevel(): void
    {
        $logger = new Logger('Early.Logger');
        $logger->addWriter(LogLevel::WARNING, new FileWriter(['logFile' => $this->logFile]));
        $stderrWriter = new StderrWriter();

        (new ExistingLoggerRewirer())->rewire([$logger], $stderrWriter, LogLevel::DEBUG);

        self::assertSame([$stderrWriter], $logger->getWriters()[LogLevel::DEBUG]);
    }

    #[Test]
    public function rewireLeavesLoggersWithoutFileWritersAlone(): void
    {
        $otherWriter = new NullWriter();
        $logger = new Logger('Other.Logger');
        $logger->addWriter(LogLevel::ERROR, $otherWriter);

        $rewired = (new ExistingLoggerRewirer())->rewire([$logger, new NullLogger(), null], new StderrWriter());

        self::assertSame(0, $rewired);
        self::assertSame([$otherWriter], $logger->getWriters()[LogLevel::ERROR]);
    }

    /**
     * @param class-string<WriterInterface> $className
     */
    private function countWritersOfType(Logger $logger, string $className): int
    {
        $count = 0;
        foreach ($logger->getWriters() as $writers) {
            foreach ($writers as $writer) {
                if ($writer instanceof $className) {
                    $count++;
                }
            }
        }
        return $count;
    }
}
