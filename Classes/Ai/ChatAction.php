<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

/**
 * Was der Bot mit seiner Antwort erreichen will (Konzept 6.3).
 *
 * "navigate" ist seit Phase 6 verdrahtet: der Wert ist ein VORSCHLAG der KI.
 * Ausgeloest wird nie etwas dadurch - der ChatService prueft das Ziel gegen
 * die Whitelist dieser Anfrage, und selbst danach navigiert erst der Nutzer
 * durch Betaetigen des Knopfes.
 */
enum ChatAction: string
{
    case Answer = 'answer';
    case Navigate = 'navigate';
    case Clarify = 'clarify';
}
