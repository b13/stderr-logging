<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Configuration;

use B13\StderrLogging\Log\Writer\StderrWriter;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;

/**
 * Replaces every file writer in $GLOBALS['TYPO3_CONF_VARS']['LOG'] by the
 * StderrWriter, in every node of the tree: the most specific node wins in
 * TYPO3, so a node left alone would keep writing to var/log.
 *
 * Other writers (DatabaseWriter, SyslogWriter, custom ones) stay as they are.
 */
final class LogConfigurationRewriter
{
    /**
     * @param string|null $minimumLevel Level for every StderrWriter, the level of the replaced file writer when null
     */
    public function __construct(
        private readonly ?string $minimumLevel = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $logConfiguration
     * @return array<array-key, mixed>
     */
    public function rewrite(array $logConfiguration): array
    {
        foreach ($logConfiguration as $key => $value) {
            if (!is_array($value) || $key === 'processorConfiguration') {
                continue;
            }
            $logConfiguration[$key] = $key === 'writerConfiguration'
                ? $this->rewriteWriterConfiguration($value)
                : $this->rewrite($value);
        }
        return $logConfiguration;
    }

    /**
     * One node holds writers per level, and every writer receives its level
     * and all more severe ones. Two file writers on different levels would
     * print a record twice, so one StderrWriter on the most verbose of their
     * levels replaces them all.
     *
     * @param array<array-key, mixed> $writerConfiguration
     * @return array<array-key, mixed>
     */
    private function rewriteWriterConfiguration(array $writerConfiguration): array
    {
        $result = [];
        $enabledLevel = null;
        $disabledLevel = null;
        foreach ($writerConfiguration as $level => $writers) {
            if (!is_array($writers)) {
                $result[$level] = $writers;
                continue;
            }
            foreach ($writers as $writerClassName => $options) {
                if (!is_string($writerClassName) || !is_a($writerClassName, FileWriter::class, true)) {
                    $result[$level][$writerClassName] = $options;
                    continue;
                }
                $normalizedLevel = $this->normalizeLevel($level);
                if ($normalizedLevel === null) {
                    $result[$level][$writerClassName] = $options;
                    continue;
                }
                if (is_array($options) && ($options['disabled'] ?? false)) {
                    $disabledLevel = max($disabledLevel ?? $normalizedLevel, $normalizedLevel);
                } else {
                    $enabledLevel = max($enabledLevel ?? $normalizedLevel, $normalizedLevel);
                }
            }
        }
        if ($enabledLevel !== null) {
            $result[$this->minimumLevel ?? LogLevel::getInternalName($enabledLevel)][StderrWriter::class] = [];
        } elseif ($disabledLevel !== null) {
            $result[$this->minimumLevel ?? LogLevel::getInternalName($disabledLevel)][StderrWriter::class] = ['disabled' => true];
        }
        return $result;
    }

    private function normalizeLevel(int|string $level): ?int
    {
        try {
            $normalized = LogLevel::normalizeLevel($level);
            return LogLevel::isValidLevel($normalized) ? $normalized : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
