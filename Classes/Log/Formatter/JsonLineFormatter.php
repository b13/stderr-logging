<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Log\Formatter;

use TYPO3\CMS\Core\Log\LogRecord;

/**
 * Turns a log record into one line of JSON, as log collectors in
 * container platforms (Fluent Bit, Vector, Promtail, Datadog) expect it.
 */
final class JsonLineFormatter
{
    private const MAX_DEPTH = 5;
    private const MAX_TRACE_FRAMES = 20;
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<string, mixed>|null $server Server parameters, $_SERVER of the current request when null
     */
    public function __construct(
        private readonly ?array $server = null,
    ) {
    }

    public function format(LogRecord $record): string
    {
        $context = $record->getData();
        $exception = null;
        if (($context['exception'] ?? null) instanceof \Throwable) {
            $exception = $this->normalizeException($context['exception']);
            unset($context['exception']);
        }

        $line = [
            'time' => $this->formatTime((float)$record->getCreated()),
            'level' => strtolower($record->getLevel()),
            'channel' => (string)$record->getComponent(),
            'message' => $this->interpolate((string)$record->getMessage(), $context),
        ];
        $requestId = (string)$record->getRequestId();
        if ($requestId !== '') {
            $line['request_id'] = $requestId;
        }
        $httpRequestId = ($this->server ?? $_SERVER)['HTTP_X_REQUEST_ID'] ?? null;
        if (is_string($httpRequestId) && $httpRequestId !== '') {
            $line['http_request_id'] = substr($httpRequestId, 0, 200);
        }
        if ($context !== []) {
            $line['context'] = $this->normalize($context, 0);
        }
        if ($exception !== null) {
            $line['exception'] = $exception;
        }

        $json = json_encode($line, self::JSON_FLAGS);
        if ($json === false) {
            $json = json_encode(
                ['time' => $line['time'], 'level' => $line['level'], 'channel' => $line['channel'], 'message' => $line['message']],
                self::JSON_FLAGS
            );
        }
        return (string)$json;
    }

    private function formatTime(float $created): string
    {
        $time = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $created));
        if ($time === false) {
            return date('Y-m-d\TH:i:sP');
        }
        return $time
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * Replaces {placeholder} with context values, as PSR-3 describes it.
     *
     * @param array<array-key, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacement = match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string)$value,
                $value instanceof \Stringable => $this->stringify($value),
                $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339),
                default => null,
            };
            if ($replacement !== null) {
                $replacements['{' . $key . '}'] = $replacement;
            }
        }
        return strtr($message, $replacements);
    }

    /**
     * Reduces a context value to what JSON can carry, without walking into
     * objects: only their class is logged, so no service, request or user
     * object ends up in the log with all its properties.
     */
    private function normalize(mixed $value, int $depth): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[array]';
            }
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item, $depth + 1);
            }
            return $normalized;
        }
        if ($value instanceof \Throwable) {
            return $this->normalizeException($value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC3339_EXTENDED);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \JsonSerializable) {
            try {
                return $this->normalize($value->jsonSerialize(), $depth + 1);
            } catch (\Throwable) {
                return '[object ' . $value::class . ']';
            }
        }
        if ($value instanceof \Stringable) {
            return $this->stringify($value);
        }
        if (is_object($value)) {
            return '[object ' . $value::class . ']';
        }
        if (is_resource($value)) {
            return '[resource ' . get_resource_type($value) . ']';
        }
        return '[' . get_debug_type($value) . ']';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeException(\Throwable $exception): array
    {
        $normalized = [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
        ];
        $trace = explode("\n", $exception->getTraceAsString());
        if (count($trace) > self::MAX_TRACE_FRAMES) {
            $trace = array_slice($trace, 0, self::MAX_TRACE_FRAMES);
            $trace[] = '...';
        }
        $normalized['trace'] = $trace;
        if ($exception->getPrevious() !== null) {
            $normalized['previous'] = $this->normalizeException($exception->getPrevious());
        }
        return $normalized;
    }

    private function stringify(\Stringable $value): string
    {
        try {
            return (string)$value;
        } catch (\Throwable) {
            return '[object ' . $value::class . ']';
        }
    }
}
