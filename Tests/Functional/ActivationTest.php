<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Tests\Functional;

use B13\StderrLogging\Log\Writer\StderrWriter;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ActivationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['b13/stderr-logging'];

    protected bool $initializeDatabase = false;

    #[Test]
    public function loggersCreatedDuringBootstrapWriteToStderr(): void
    {
        $logManager = $this->get(LogManager::class);
        // The core cache backend gets its logger in Bootstrap::init(), before ext_localconf.php files are loaded
        $earlyLoggerName = str_replace('\\', '.', SimpleFileBackend::class);
        self::assertContains($earlyLoggerName, $logManager->getLoggerNames());

        $earlyLogger = $logManager->getLogger($earlyLoggerName);

        self::assertInstanceOf(Logger::class, $earlyLogger);
        self::assertInstanceOf(StderrWriter::class, $earlyLogger->getWriters()[LogLevel::WARNING][0] ?? null);
    }

    #[Test]
    public function noLoggerWritesToFilesAnymore(): void
    {
        $logManager = $this->get(LogManager::class);
        $logManager->getLogger('B13.StderrLogging.Tests.LaterLogger');

        $fileWriters = [];
        foreach ($logManager->getLoggerNames() as $name) {
            $logger = $logManager->getLogger((string)$name);
            self::assertInstanceOf(Logger::class, $logger);
            foreach ($logger->getWriters() as $writers) {
                foreach ($writers as $writer) {
                    if ($writer instanceof FileWriter) {
                        $fileWriters[] = $name;
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($fileWriters)));
    }
}
