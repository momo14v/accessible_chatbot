<?php

declare(strict_types=1);

use Extension14v\AccessibleChatbot\Middleware\ChatEndpointMiddleware;

/*
 * Registrierung der Chat-Endpunkt-Middleware im Frontend-Stack.
 *
 * Reihenfolge (wichtig!):
 * - after site/authentication: erst danach liegen die Request-Attribute
 *   "site", "language" und "routing" vor.
 * - before page-resolver: der PageResolver wuerde fuer unseren Pfad eine
 *   404-Seite erzeugen, weil es dazu keine Seite im Seitenbaum gibt.
 */
return [
    'frontend' => [
        'extension14v/accessible-chatbot/chat-endpoint' => [
            'target' => ChatEndpointMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
