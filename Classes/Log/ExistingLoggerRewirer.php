<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Log;

use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;

/**
 * Loggers created before ext_localconf.php is loaded (error handler,
 * exception handler, deprecations, package and cache bootstrap) keep the
 * writers they were built with. This swaps their file writers for the
 * given writer, so these early loggers write to stderr as well.
 *
 * TYPO3 v13 and v14 offer no API to remove a writer from a logger, so the
 * writer list is replaced through reflection.
 */
final class ExistingLoggerRewirer
{
    /**
     * @param iterable<mixed> $loggers
     * @param string|null $minimumLevel Level for the new writer, the level of the replaced file writer when null
     * @return int Number of loggers that were rewired
     */
    public function rewire(iterable $loggers, WriterInterface $writer, ?string $minimumLevel = null): int
    {
        $writersProperty = new \ReflectionProperty(Logger::class, 'writers');
        $rewired = 0;
        foreach ($loggers as $logger) {
            if (!$logger instanceof Logger) {
                continue;
            }
            $remainingWriters = [];
            $replacedLevel = null;
            foreach ($logger->getWriters() as $levelName => $levelWriters) {
                foreach ($levelWriters as $levelWriter) {
                    if ($levelWriter instanceof FileWriter) {
                        $normalizedLevel = LogLevel::normalizeLevel($levelName);
                        $replacedLevel = max($replacedLevel ?? $normalizedLevel, $normalizedLevel);
                        continue;
                    }
                    $remainingWriters[$levelName][] = $levelWriter;
                }
            }
            if ($replacedLevel === null) {
                continue;
            }
            $writersProperty->setValue($logger, $remainingWriters);
            $logger->addWriter($minimumLevel ?? LogLevel::getInternalName($replacedLevel), $writer);
            $rewired++;
        }
        return $rewired;
    }
}
