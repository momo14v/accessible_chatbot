<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

/**
 * Eine einzelne Nachricht des Gespraechs.
 *
 * "final readonly" heisst: die Klasse kann nicht abgeleitet und ein Objekt
 * nach dem Erzeugen nicht mehr veraendert werden. Das macht den Datenfluss
 * nachvollziehbar - eine Nachricht, die einmal geprueft wurde, bleibt so.
 */
final readonly class ChatMessage
{
    public function __construct(
        public ChatRole $role,
        public string $text,
    ) {}
}
