<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Accessible Chatbot',
    'description' => 'Barrierefreier Chatbot für TYPO3: beantwortet Fragen ausschließlich aus sichtbaren Website-Inhalten und navigiert Nutzer auf Wunsch (WCAG 2.1 AA).',
    'category' => 'fe',
    'author' => 'Momo Trenz',
    'author_email' => 'mt@14v.de',
    'state' => 'alpha',
    'version' => '0.1.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
