<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Stderr Logging',
    'description' => 'Sends all TYPO3 log output as JSON lines to stderr instead of var/log/, so Kubernetes and other container platforms collect it. Install it, done: no configuration needed.',
    'category' => 'misc',
    'author' => 'b13 GmbH',
    'author_email' => 'typo3@b13.com',
    'author_company' => 'b13 GmbH',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-15.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
