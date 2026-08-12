<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Eigener Zwischenspeicher fuer die Zaehler des Rate-Limiters.
//
// Typo3DatabaseBackend prueft die Ablaufzeit BEIM LESEN und ist deshalb auch
// ohne laufende Garbage Collection zuverlaessig. SimpleFileBackend waere hier
// falsch: dieses Backend beachtet die Lebensdauer nicht.
//
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['accessible_chatbot_ratelimit'] ??= [
    'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'options' => [
        'defaultLifetime' => 60,
    ],
    // Bewusst KEINE Gruppe: die Zaehler duerfen durch ein normales Cacheleeren
    // im Backend nicht zurueckgesetzt werden, sonst waere das Rate-Limit mit
    // einem Klick ausgehebelt. (Die Admin-Aktion "Alle Caches leeren" leert
    // trotzdem alles - das ist eine bewusste Handlung und akzeptabel.)
    'groups' => [],
];
