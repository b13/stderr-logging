<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Tests\Unit;

use B13\StderrLogging\Log\Writer\StderrWriter;
use B13\StderrLogging\StderrLogging;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class StderrLoggingTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'stderr_logging_');
    }

    protected function tearDown(): void
    {
        putenv(StderrLogging::ENV_ENABLED);
        putenv(StderrLogging::ENV_LEVEL);
        @unlink($this->logFile);
        parent::tearDown();
    }

    public static function isEnabledDataProvider(): \Generator
    {
        yield 'not set' => [null, true];
        yield 'empty' => ['', true];
        yield 'one' => ['1', true];
        yield 'zero' => ['0', false];
        yield 'false' => ['false', false];
        yield 'off with spaces and capitals' => [' Off ', false];
        yield 'no' => ['no', false];
    }

    #[DataProvider('isEnabledDataProvider')]
    #[Test]
    public function isEnabledIsTrueUnlessSwitchedOff(?string $value, bool $expected): void
    {
        putenv($value === null ? StderrLogging::ENV_ENABLED : StderrLogging::ENV_ENABLED . '=' . $value);

        self::assertSame($expected, StderrLogging::isEnabled());
    }

    public static function getMinimumLevelDataProvider(): \Generator
    {
        yield 'not set' => [null, null];
        yield 'debug' => ['debug', LogLevel::DEBUG];
        yield 'upper case' => ['ERROR', LogLevel::ERROR];
        yield 'invalid' => ['verbose', null];
    }

    #[DataProvider('getMinimumLevelDataProvider')]
    #[Test]
    public function getMinimumLevelReadsTheEnvironment(?string $value, ?string $expected): void
    {
        putenv($value === null ? StderrLogging::ENV_LEVEL : StderrLogging::ENV_LEVEL . '=' . $value);

        self::assertSame($expected, StderrLogging::getMinimumLevel());
    }

    #[Test]
    public function activateRewiresConfigurationAndLoggersCreatedBefore(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [
            'writerConfiguration' => [
                LogLevel::WARNING => [FileWriter::class => ['logFile' => $this->logFile]],
            ],
        ];
        $logManager = new LogManager();
        $this->provideLogManager($logManager);
        $earlyLogger = $logManager->getLogger('Early.Logger');
        self::assertInstanceOf(Logger::class, $earlyLogger);

        StderrLogging::activate();

        self::assertSame(
            ['writerConfiguration' => [LogLevel::WARNING => [StderrWriter::class => []]]],
            $GLOBALS['TYPO3_CONF_VARS']['LOG']
        );
        self::assertInstanceOf(StderrWriter::class, $earlyLogger->getWriters()[LogLevel::WARNING][0] ?? null);
        $laterLogger = $logManager->getLogger('Later.Logger');
        self::assertInstanceOf(Logger::class, $laterLogger);
        self::assertInstanceOf(StderrWriter::class, $laterLogger->getWriters()[LogLevel::WARNING][0] ?? null);
    }

    #[Test]
    public function configureRewritesTheConfigurationWithoutCreatingALogManager(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = [
            'writerConfiguration' => [
                LogLevel::WARNING => [FileWriter::class => ['logFile' => $this->logFile]],
            ],
        ];

        StderrLogging::configure();

        self::assertSame(
            ['writerConfiguration' => [LogLevel::WARNING => [StderrWriter::class => []]]],
            $GLOBALS['TYPO3_CONF_VARS']['LOG']
        );
        self::assertArrayNotHasKey(LogManager::class, GeneralUtility::getSingletonInstances());
    }

    #[Test]
    public function activateDoesNothingWhenSwitchedOff(): void
    {
        putenv(StderrLogging::ENV_ENABLED . '=0');
        $configuration = [
            'writerConfiguration' => [
                LogLevel::WARNING => [FileWriter::class => ['logFile' => $this->logFile]],
            ],
        ];
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = $configuration;

        StderrLogging::activate();

        self::assertSame($configuration, $GLOBALS['TYPO3_CONF_VARS']['LOG']);
    }

    /**
     * Makes makeInstance(LogManager::class) in StderrLogging::activate()
     * return the given instance. TYPO3 v13 and v14 mark the LogManager as
     * SingletonInterface, which only setSingletonInstance() accepts; since
     * TYPO3 v15 it is a plain service, which only addInstance() accepts.
     */
    private function provideLogManager(LogManager $logManager): void
    {
        if ($logManager instanceof SingletonInterface) {
            GeneralUtility::setSingletonInstance(LogManager::class, $logManager);
        } else {
            GeneralUtility::addInstance(LogManager::class, $logManager);
        }
    }
}
