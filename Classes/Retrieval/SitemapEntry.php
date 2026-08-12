<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Retrieval;

/**
 * Eine Zeile der Sitemap-Kompakt (Konzept 4.4).
 *
 * $level ist die Tiefe im Seitenbaum: die Startseite hat 0, ihre
 * Unterseiten 1, deren Unterseiten 2. Daraus baut der PromptBuilder die
 * Einrueckung.
 */
final readonly class SitemapEntry
{
    public function __construct(
        public int $pageUid,
        public string $title,
        public int $level,
    ) {}
}
