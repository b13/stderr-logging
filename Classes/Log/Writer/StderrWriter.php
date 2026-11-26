<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Log\Writer;

use B13\StderrLogging\Log\Formatter\JsonLineFormatter;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\AbstractWriter;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;

/**
 * Writes every log record as one line of JSON to the standard error
 * stream of the process, where container platforms collect it.
 */
final class StderrWriter extends AbstractWriter
{
    /**
     * @var array<string, resource>
     */
    private static array $handles = [];

    private string $stream = 'php://stderr';

    private readonly JsonLineFormatter $formatter;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->formatter = new JsonLineFormatter();
        parent::__construct($options);
    }

    /**
     * Another stream than php://stderr, e.g. php://stdout
     */
    public function setStream(string $stream): void
    {
        $this->stream = $stream;
    }

    public function writeLog(LogRecord $record): WriterInterface
    {
        $handle = $this->getHandle();
        if ($handle !== null) {
            // Logging must never break the request it reports on
            @fwrite($handle, $this->formatter->format($record) . "\n");
        }
        return $this;
    }

    /**
     * @return resource|null
     */
    private function getHandle()
    {
        if (!isset(self::$handles[$this->stream])) {
            $handle = @fopen($this->stream, 'ab');
            if (!is_resource($handle)) {
                return null;
            }
            self::$handles[$this->stream] = $handle;
        }
        return self::$handles[$this->stream];
    }
}
