<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "stderr_logging" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\StderrLogging\Tests\Unit\Configuration;

use B13\StderrLogging\Configuration\LogConfigurationRewriter;
use B13\StderrLogging\Log\Writer\StderrWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\Processor\IntrospectionProcessor;
use TYPO3\CMS\Core\Log\Writer\DatabaseWriter;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Log\Writer\RotatingFileWriter;
use TYPO3\CMS\Core\Log\Writer\SyslogWriter;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LogConfigurationRewriterTest extends UnitTestCase
{
    public static function rewriteDataProvider(): \Generator
    {
        yield 'TYPO3 default configuration' => [
            [
                'writerConfiguration' => [
                    LogLevel::WARNING => [FileWriter::class => []],
                ],
                'TYPO3' => [
                    'CMS' => [
                        'Core' => [
                            'Resource' => [
                                'ResourceStorage' => [
                                    'writerConfiguration' => [
                                        LogLevel::ERROR => [
                                            FileWriter::class => [],
                                            DatabaseWriter::class => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'deprecations' => [
                            'writerConfiguration' => [
                                LogLevel::NOTICE => [
                                    FileWriter::class => ['logFileInfix' => 'deprecations', 'disabled' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            null,
            [
                'writerConfiguration' => [
                    LogLevel::WARNING => [StderrWriter::class => []],
                ],
                'TYPO3' => [
                    'CMS' => [
                        'Core' => [
                            'Resource' => [
                                'ResourceStorage' => [
                                    'writerConfiguration' => [
                                        LogLevel::ERROR => [
                                            DatabaseWriter::class => [],
                                            StderrWriter::class => [],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'deprecations' => [
                            'writerConfiguration' => [
                                LogLevel::NOTICE => [StderrWriter::class => ['disabled' => true]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        yield 'file writers on two levels of one node become one writer on the most verbose level' => [
            [
                'writerConfiguration' => [
                    LogLevel::WARNING => [FileWriter::class => []],
                    LogLevel::INFO => [FileWriter::class => ['logFileInfix' => 'auth']],
                ],
            ],
            null,
            [
                'writerConfiguration' => [
                    LogLevel::INFO => [StderrWriter::class => []],
                ],
            ],
        ];
        yield 'subclasses of FileWriter are replaced, other writers and processors stay' => [
            [
                'writerConfiguration' => [
                    LogLevel::ERROR => [
                        RotatingFileWriter::class => ['interval' => 'daily'],
                        SyslogWriter::class => [],
                    ],
                ],
                'processorConfiguration' => [
                    LogLevel::ERROR => [IntrospectionProcessor::class => []],
                ],
            ],
            null,
            [
                'writerConfiguration' => [
                    LogLevel::ERROR => [
                        SyslogWriter::class => [],
                        StderrWriter::class => [],
                    ],
                ],
                'processorConfiguration' => [
                    LogLevel::ERROR => [IntrospectionProcessor::class => []],
                ],
            ],
        ];
        yield 'a minimum level replaces the level of every file writer' => [
            [
                'writerConfiguration' => [
                    LogLevel::WARNING => [FileWriter::class => []],
                ],
                'deprecations' => [
                    'writerConfiguration' => [
                        LogLevel::NOTICE => [FileWriter::class => ['disabled' => true]],
                    ],
                ],
            ],
            LogLevel::DEBUG,
            [
                'writerConfiguration' => [
                    LogLevel::DEBUG => [StderrWriter::class => []],
                ],
                'deprecations' => [
                    'writerConfiguration' => [
                        LogLevel::DEBUG => [StderrWriter::class => ['disabled' => true]],
                    ],
                ],
            ],
        ];
        yield 'a node without file writers stays as it is' => [
            [
                'writerConfiguration' => [
                    LogLevel::ERROR => [DatabaseWriter::class => []],
                ],
            ],
            null,
            [
                'writerConfiguration' => [
                    LogLevel::ERROR => [DatabaseWriter::class => []],
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $configuration
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('rewriteDataProvider')]
    #[Test]
    public function rewriteReplacesFileWritersInEveryNode(array $configuration, ?string $minimumLevel, array $expected): void
    {
        self::assertSame($expected, (new LogConfigurationRewriter($minimumLevel))->rewrite($configuration));
    }

    #[Test]
    public function rewriteTwiceChangesNothingMore(): void
    {
        $rewriter = new LogConfigurationRewriter();
        $once = $rewriter->rewrite(['writerConfiguration' => [LogLevel::WARNING => [FileWriter::class => []]]]);

        self::assertSame($once, $rewriter->rewrite($once));
    }
}
