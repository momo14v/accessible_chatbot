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

// ---------------------------------------------------------------------------
// Inkrementelle Aktualisierung des Inhaltsindex (Konzept 4.4).
//
// TYPO3 13.4 bietet fuer diese beiden Zeitpunkte KEIN PSR-14-Event an - die
// klassischen DataHandler-Hooks sind der einzige Weg.
//
// Beide Eintraege stehen bewusst nebeneinander an genau EINER Stelle: sollte
// TYPO3 v14 hier Events einfuehren, wird nur dieser Block ersetzt.
//
// Warum zwei: Verstecken laeuft ueber die Datamap (ein Feldwert), Loeschen
// und Verschieben ueber die Cmdmap (ein Befehl).
// ---------------------------------------------------------------------------
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['accessible_chatbot']
    = \Extension14v\AccessibleChatbot\Hook\IndexUpdateHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['accessible_chatbot']
    = \Extension14v\AccessibleChatbot\Hook\IndexUpdateHook::class;

// Zwischenspeicher fuer die Sitemap-Kompakt (Konzept 4.4, Schritt 4).
//
// Ohne eigene Angaben nimmt TYPO3 VariableFrontend + Typo3DatabaseBackend -
// beides ist hier genau richtig, deshalb steht nur die Lebensdauer hier.
//
// defaultLifetime hat zwei Aufgaben: es ist das Sicherheitsnetz, falls die
// Invalidierung beim Indexieren einmal ausfaellt, UND es begrenzt, wie lange
// eine inzwischen abgelaufene Seite noch in der Seitenliste stehen kann
// (Konzept 4.4, Schritt 5). Eine Stunde ist die Festlegung von Momo vom
// 2026-08-17: kurz genug, dass zeitgesteuerte Sichtbarkeit nicht lange
// falsch bleibt, lang genug, um die Datenbank spuerbar zu entlasten.
//
// ACHTUNG bei set(): $lifetime = null bedeutet "Standard benutzen",
// $lifetime = 0 bedeutet UNBEGRENZT - nicht "sofort abgelaufen".
// Der Aufruf im RetrievalService uebergibt deshalb gar keine Lebensdauer.
//
// Gruppe bewusst NICHT gesetzt: der Standard ist ['all'], ein normales
// "Alle Caches leeren" raeumt den Zwischenspeicher also mit auf. Das ist hier
// - anders als beim Rate-Limiter - ausdruecklich erwuenscht.
//
// Das Backend muss "taggable" sein, weil die Invalidierung ueber
// flushByTag() laeuft. Typo3DatabaseBackend (der Standard hier) ist es;
// ein spaeter in settings.php eingetragenes SimpleFileBackend waere es
// nicht und wuerde beim Indexieren eine Ausnahme werfen.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['accessible_chatbot_sitemap'] ??= [
    'options' => [
        'defaultLifetime' => 3600,
    ],
];
