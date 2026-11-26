<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging;

use B13\StderrLogging\Configuration\LogConfigurationRewriter;
use B13\StderrLogging\Log\ExistingLoggerRewirer;
use B13\StderrLogging\Log\Writer\StderrWriter;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sends everything TYPO3 would write to var/log/ to stderr instead.
 *
 * activate() is called from ext_localconf.php, so installing the extension
 * is all it takes. configure() in config/system/additional.php also covers
 * the install tool, which does not load ext_localconf.php. Using both does
 * no harm.
 */
final class StderrLogging
{
    /**
     * Set to "0", "false", "off" or "no" to keep TYPO3's file logging
     */
    public const ENV_ENABLED = 'TYPO3_STDERR_LOGGING';

    /**
     * Minimum level for all output, e.g. "debug" or "error"
     */
    public const ENV_LEVEL = 'TYPO3_STDERR_LOGGING_LEVEL';

    /**
     * Rewrites the log configuration and switches loggers that already
     * exist over to stderr. For ext_localconf.php.
     */
    public static function activate(): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::configure();
        $logManager = GeneralUtility::makeInstance(LogManager::class);
        (new ExistingLoggerRewirer())->rewire(self::getExistingLoggers($logManager), new StderrWriter(), self::getMinimumLevel());
    }

    /**
     * Rewrites the log configuration only. For config/system/additional.php,
     * which TYPO3 loads before it creates the first logger.
     */
    public static function configure(): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $GLOBALS['TYPO3_CONF_VARS']['LOG'] = (new LogConfigurationRewriter(self::getMinimumLevel()))
            ->rewrite($GLOBALS['TYPO3_CONF_VARS']['LOG'] ?? []);
    }

    public static function isEnabled(): bool
    {
        $value = getenv(self::ENV_ENABLED);
        if ($value === false || trim($value) === '') {
            return true;
        }
        return !in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no'], true);
    }

    public static function getMinimumLevel(): ?string
    {
        $value = getenv(self::ENV_LEVEL);
        if ($value === false || trim($value) === '') {
            return null;
        }
        try {
            $level = LogLevel::normalizeLevel(strtolower(trim($value)));
            return LogLevel::getInternalName($level);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return iterable<mixed>
     */
    private static function getExistingLoggers(LogManager $logManager): iterable
    {
        foreach ($logManager->getLoggerNames() as $name) {
            yield $logManager->getLogger((string)$name);
        }
    }
}
