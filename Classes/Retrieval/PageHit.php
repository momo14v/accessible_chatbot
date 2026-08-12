<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Retrieval;

/**
 * Eine Seite, die zur Frage passt (Konzept 4.4, Retrieval).
 *
 * $content ist bereits auf die Fundstellen gekuerzt - die Klasse traegt
 * genau das, was die KI zu sehen bekommt, und nichts darueber hinaus.
 */
final readonly class PageHit
{
    public function __construct(
        public int $pageUid,
        public string $title,
        public string $content,
        // Review Phase 4, V4: reines Diagnosefeld. Es dokumentiert, wie die
        // Bewertung aus RetrievalService::findHits() zu diesem Treffer kam
        // (z. B. fuer Log-Ausgaben oder kuenftige Fehlersuche) - es wird
        // aktuell nirgends ausgelesen und fliesst nicht in den Prompt ein.
        public int $score,
    ) {}
}
