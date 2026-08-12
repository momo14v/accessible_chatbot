<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Retrieval;

/**
 * Das vollstaendige Ergebnis einer Retrieval-Anfrage (Konzept 4.2, Schritt 3).
 *
 * Enthaelt beides: die Trefferliste mit Inhalten UND die kompakte Seitenliste.
 * Beides zusammen ist die EINZIGE Wissensquelle, die die KI bekommt.
 */
final readonly class RetrievalResult
{
    /**
     * @param list<PageHit> $hits
     * @param list<SitemapEntry> $sitemap
     * @param bool $sitemapTruncated true, wenn die Seitenliste gekuerzt wurde
     */
    public function __construct(
        public array $hits,
        public array $sitemap,
        public bool $sitemapTruncated,
    ) {}
}
