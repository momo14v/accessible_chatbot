<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

/**
 * Was der Bot mit seiner Antwort erreichen will (Konzept 6.3).
 *
 * "navigate" ist bereits vorgesehen, wird aber erst in Phase 5 verdrahtet:
 * Bis dahin verbietet der Systemprompt diesen Wert ausdruecklich, und der
 * Server tut mit einem trotzdem gelieferten Ziel nichts.
 */
enum ChatAction: string
{
    case Answer = 'answer';
    case Navigate = 'navigate';
    case Clarify = 'clarify';
}
