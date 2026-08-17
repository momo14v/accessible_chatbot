<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Service;

use Extension14v\AccessibleChatbot\Retrieval\PageHit;
use Extension14v\AccessibleChatbot\Retrieval\RetrievalResult;
use Extension14v\AccessibleChatbot\Retrieval\SitemapEntry;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Log\Channel;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sucht im Inhaltsindex die Seiten, die zu einer Frage passen (Konzept 4.4).
 *
 * SICHERHEITSRELEVANT: Was hier NICHT gefunden wird, kann die KI nicht
 * kennen. Jede Abfrage ist deshalb dreifach eingegrenzt - Website, Sprache
 * und fe_groups. Der fe_groups-Filter steht von Anfang an drin, obwohl der
 * Indexer geschuetzte Seiten gar nicht erst aufnimmt: doppelter Boden.
 *
 * Bewusst kein FULLTEXT und keine Vektorsuche - LIKE-Kandidaten plus
 * Bewertung in PHP laeuft auf jeder von TYPO3 unterstuetzten Datenbank
 * (Konzept 4.4, Nicht-Ziele 11).
 */
final class RetrievalService
{
    /** Mehr Suchwoerter bringen kaum Trefferqualitaet, kosten aber ODER-Zweige. */
    private const MAX_KEYWORDS = 12;

    private const MIN_KEYWORD_LENGTH = 3;

    /**
     * Obergrenze der Kandidatenzeilen; schuetzt Speicher und Laufzeit.
     *
     * Review Phase 4, S6: die Abfrage sortiert nach "page_uid", nicht nach
     * Relevanz. Ab dieser Grenze sieht das Scoring nur noch die Seiten mit
     * den kleinsten UIDs - die eigentlich beste Seite kann dann fehlen. 500
     * statt 200 senkt das Risiko, ersetzt die eigentlich noetige
     * Relevanz-Sortierung aber nicht; siehe findHits() fuer das Protokoll,
     * das ein Erreichen dieser Grenze sichtbar macht.
     */
    private const MAX_CANDIDATES = 500;

    private const MAX_HITS = 5;

    /** Konzept 4.4: content je Treffer auf 1500 Zeichen kuerzen. */
    private const MAX_SNIPPET_LENGTH = 1500;
    private const SNIPPET_BEFORE = 200;
    private const SNIPPET_AFTER = 400;

    /**
     * Eine Seite, die ein Wort 500-mal enthaelt, darf nicht alles andere
     * verdraengen. Mehr als zehn Fundstellen pro Feld zaehlen nicht weiter.
     */
    private const MAX_OCCURRENCES_PER_FIELD = 10;

    private const DEFAULT_MAX_PAGES_FULL_SITEMAP = 150;

    /** Ab der Obergrenze bleibt nur der obere Seitenbaum uebrig. */
    private const SHORT_SITEMAP_MAX_LEVEL = 2;

    /** Harte Notbremse, damit der Prompt nie explodiert. */
    private const MAX_SITEMAP_ENTRIES = 400;

    /** Review Phase 4, V10: vereinheitlicht mit IndexService::MAX_TREE_DEPTH. */
    private const MAX_TREE_DEPTH = 99;
    private const CHUNK_SIZE = 500;

    /** Konzept 11, Phase 8, Aufgabe 6: Marke zum gezielten Leeren beim Indexieren. */
    public const SITEMAP_CACHE_TAG = 'accessible_chatbot_sitemap';

    /**
     * Gewichtung der Fundstellen (Konzept 4.4, Schritt 3).
     * nav_title zaehlt wie title - es ist ebenfalls ein Titel.
     *
     * @var array<string, int>
     */
    private const WEIGHTS = [
        'title' => 5,
        'nav_title' => 5,
        'keywords' => 4,
        'abstract' => 3,
        'content' => 1,
    ];

    /**
     * Kleine eingebaute Stoppwortliste DE/EN (Konzept 4.4, Schritt 1).
     * Woerter unter drei Zeichen fliegen ohnehin vorher raus, deshalb
     * stehen sie hier nicht.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        // Deutsch
        'aber', 'alle', 'allem', 'allen', 'aller', 'alles', 'als', 'also', 'auch', 'auf', 'aus',
        'bei', 'beim', 'bin', 'bis', 'bist', 'dann', 'das', 'dass', 'dazu', 'dein', 'deine',
        'dem', 'den', 'denn', 'der', 'des', 'dich', 'die', 'dies', 'diese', 'diesem', 'diesen',
        'dieser', 'dieses', 'dir', 'doch', 'dort', 'durch', 'ein', 'eine', 'einem', 'einen',
        'einer', 'eines', 'etwas', 'euch', 'euer', 'fuer', 'für', 'gab', 'gegen', 'gibt', 'habe',
        'haben', 'habt', 'hat', 'hatte', 'hatten', 'hier', 'ich', 'ihr', 'ihre', 'ihrem', 'ihren',
        'ihrer', 'ihres', 'immer', 'ist', 'jede', 'jedem', 'jeden', 'jeder', 'jedes', 'jetzt',
        'kann', 'kein', 'keine', 'koennen', 'können', 'mal', 'man', 'mein', 'meine', 'mich',
        'mir', 'mit', 'muss', 'nach', 'nicht', 'nichts', 'noch', 'nun', 'nur', 'oder', 'ohne',
        'schon', 'sehr', 'sein', 'seine', 'sich', 'sie', 'sind', 'soll', 'sollen', 'sonst',
        'ueber', 'über', 'und', 'uns', 'unser', 'unter', 'viel', 'vom', 'von', 'vor', 'wann',
        'war', 'waren', 'warum', 'was', 'weil', 'welche', 'welcher', 'welches', 'wenn', 'werde',
        'werden', 'weshalb', 'wie', 'wieder', 'wieso', 'wir', 'wird', 'wirst', 'wollen', 'wurde',
        'wurden', 'zum', 'zur', 'zwischen',
        // Englisch
        'about', 'after', 'all', 'also', 'and', 'any', 'are', 'because', 'been', 'but', 'can',
        'could', 'did', 'does', 'for', 'from', 'get', 'had', 'has', 'have', 'her', 'here', 'him',
        'his', 'how', 'into', 'its', 'just', 'like', 'many', 'may', 'more', 'most', 'much',
        'must', 'not', 'now', 'one', 'only', 'other', 'our', 'out', 'over', 'own', 'please',
        'said', 'same', 'she', 'should', 'some', 'such', 'than', 'that', 'the', 'their', 'them',
        'then', 'there', 'these', 'they', 'this', 'those', 'through', 'too', 'use', 'very',
        'was', 'way', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'why', 'will',
        'with', 'would', 'you', 'your',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FrontendContextFactory $contextFactory,
        private readonly RootLineAccessChecker $rootLineChecker,
        private readonly FrontendInterface $cache,
        #[Channel('accessible_chatbot')]
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Liefert beides in einem Rutsch: Trefferliste und Sitemap-Kompakt.
     *
     * Der Context wird genau EINMAL gebaut und an beide Pruefungen
     * durchgereicht (Review Phase 4, B1) - Treffer UND Seitenliste muessen
     * gegen dieselbe, aktuelle Sichtbarkeit geprueft werden.
     */
    public function retrieve(string $question, Site $site, SiteLanguage $language): RetrievalResult
    {
        $context = $this->contextFactory->create($language);

        $keywords = $this->keywords($question);
        $hits = $keywords === [] ? [] : $this->findHits($keywords, $site, $language);
        $hits = $this->stillVisible($hits, $context);
        [$sitemap, $truncated] = $this->cachedSitemap($site, $language, $context);

        return new RetrievalResult($hits, $sitemap, $truncated);
    }

    /**
     * Prueft die (hoechstens fuenf) Treffer erneut gegen die aktuelle
     * Sichtbarkeit (Review Phase 4, S7).
     *
     * Der Index wird nur beim Speichern im Backend aktualisiert. Laeuft eine
     * "endtime" ab oder beginnt eine "starttime", aendert sich am Index
     * nichts - ohne diese Pruefung wuerde der Bot den Inhalt einer inzwischen
     * unsichtbaren Seite trotzdem an die KI liefern und verlinken (Konzept
     * 3.4). Verwendet denselben anonymen Frontend-Context wie der Indexer
     * (FrontendContextFactory), damit hier exakt dieselben Regeln gelten.
     *
     * page_uid im Index ist immer die UID der Standardsprache (siehe
     * IndexService::buildRows()); PageRepository::getPage() prueft genau
     * dagegen deleted/hidden/starttime/endtime/fe_group und liefert ein
     * leeres Array, sobald eine dieser Bedingungen nicht mehr zutrifft.
     *
     * Review Phase 4, F2: PageRepository::getPage() prueft nur die Seite
     * SELBST, nicht ihre Vorfahrenkette. Eine Elternseite mit
     * "extendToSubpages" und z. B. abgelaufener "endtime" versteckt im
     * Frontend den kompletten Unterbaum, ohne dass sich an der Kind-Seite
     * etwas aendert - deshalb zusaetzlich RootLineAccessChecker befragen,
     * mit exakt derselben Regel, die auch der Indexer anwendet.
     *
     * @param list<PageHit> $hits
     * @return list<PageHit>
     */
    private function stillVisible(array $hits, Context $context): array
    {
        if ($hits === []) {
            return [];
        }

        $this->rootLineChecker->reset();
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);

        return array_values(array_filter($hits, function (PageHit $hit) use ($pageRepository, $context): bool {
            $page = $pageRepository->getPage($hit->pageUid);
            if ($page === []) {
                return false;
            }

            return $this->rootLineChecker->accessGranted((int)($page['pid'] ?? 0), $context);
        }));
    }

    /* ---------------------------------------------------------------- *
     * Schritt 1: Frage normalisieren
     * ---------------------------------------------------------------- */

    /**
     * Kleinschreibung, Wortliste, kurze Woerter und Stoppwoerter raus.
     *
     * Besteht die Frage NUR aus Stoppwoertern ("Wann habt ihr auf?"), wird
     * die Stoppwortliste fallengelassen - lieber unscharf suchen als gar
     * nicht. Review Phase 4, S6: im Fallback fliegen zusaetzlich Woerter
     * unter vier Zeichen raus ("wie", "wer" & Co. treffen sonst fast jede
     * Zeile der Tabelle). Bleibt danach nichts mehr uebrig, liefert diese
     * Methode eine leere Liste - retrieve() ueberspringt findHits() dann
     * komplett, die Sitemap allein reicht in diesem Fall.
     *
     * @return list<string>
     */
    private function keywords(string $question): array
    {
        $text = mb_strtolower(trim($question));

        // Alles, was kein Buchstabe und keine Ziffer ist, trennt Woerter.
        // \p{L} erfasst auch Umlaute und Akzente - str_word_count nicht.
        $text = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        $words = [];
        foreach (explode(' ', $text) as $word) {
            if ($word !== '' && mb_strlen($word) >= self::MIN_KEYWORD_LENGTH) {
                $words[] = $word;
            }
        }
        $words = array_values(array_unique($words));

        $keywords = array_values(array_filter(
            $words,
            static fn(string $word): bool => !in_array($word, self::STOPWORDS, true)
        ));

        if ($keywords === []) {
            $keywords = array_values(array_filter(
                $words,
                static fn(string $word): bool => mb_strlen($word) >= 4
            ));
        }

        return array_slice($keywords, 0, self::MAX_KEYWORDS);
    }

    /* ---------------------------------------------------------------- *
     * Schritt 2 + 3: Kandidaten holen, in PHP bewerten
     * ---------------------------------------------------------------- */

    /**
     * @param list<string> $keywords
     * @return list<PageHit>
     */
    private function findHits(array $keywords, Site $site, SiteLanguage $language): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(IndexService::TABLE);
        $expr = $queryBuilder->expr();

        $likeConditions = [];
        foreach ($keywords as $keyword) {
            // escapeLikeWildcards macht aus "%" und "_" harmlose Zeichen -
            // sonst koennte eine Frage wie "100%" die halbe Tabelle treffen.
            $parameter = $queryBuilder->createNamedParameter(
                '%' . $queryBuilder->escapeLikeWildcards($keyword) . '%'
            );
            foreach (array_keys(self::WEIGHTS) as $field) {
                $likeConditions[] = $expr->like($field, $parameter);
            }
        }

        $rows = $queryBuilder
            ->select('page_uid', 'title', 'nav_title', 'abstract', 'keywords', 'content')
            ->from(IndexService::TABLE)
            ->where(
                $expr->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($site->getIdentifier())
                ),
                $expr->eq(
                    'language_uid',
                    $queryBuilder->createNamedParameter($language->getLanguageId(), Connection::PARAM_INT)
                ),
                // fe_groups-Filter (Konzept 7): anonyme Besucher sehen Seiten
                // ohne Zugriffsgruppe UND Seiten mit "-1" ("Bei Login
                // verbergen") - fuer Nicht-Angemeldete sind die sichtbar,
                // siehe PageRepository::getMultipleGroupsWhereClause().
                $expr->in(
                    'fe_groups',
                    $queryBuilder->createNamedParameter(['', '-1'], Connection::PARAM_STR_ARRAY)
                ),
                $expr->or(...$likeConditions)
            )
            ->orderBy('page_uid')
            ->setMaxResults(self::MAX_CANDIDATES)
            ->executeQuery()
            ->fetchAllAssociative();

        if (count($rows) >= self::MAX_CANDIDATES) {
            // Review Phase 4, S6: die Abfrage sortiert nach "page_uid", nicht
            // nach Relevanz - ab hier kann eine eigentlich bessere Seite mit
            // groesserer UID fehlen. Ohne dieses Protokoll wuerde das nie
            // auffallen.
            $this->logger->info(
                'Retrieval hit the candidate limit ({limit}) for site {site} - results may be incomplete.',
                ['limit' => self::MAX_CANDIDATES, 'site' => $site->getIdentifier()]
            );
        }

        $scored = [];
        foreach ($rows as $row) {
            $score = 0;
            foreach (self::WEIGHTS as $field => $weight) {
                $haystack = mb_strtolower((string)($row[$field] ?? ''));
                if ($haystack === '') {
                    continue;
                }
                foreach ($keywords as $keyword) {
                    $count = mb_substr_count($haystack, $keyword);
                    if ($count > 0) {
                        $score += $weight * min($count, self::MAX_OCCURRENCES_PER_FIELD);
                    }
                }
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'row' => $row];
            }
        }

        // Hoechste Punktzahl zuerst; bei Gleichstand die kleinere Seiten-UID,
        // damit dieselbe Frage immer dieselbe Reihenfolge ergibt.
        usort(
            $scored,
            static fn(array $a, array $b): int =>
                [$b['score'], (int)$a['row']['page_uid']] <=> [$a['score'], (int)$b['row']['page_uid']]
        );

        $hits = [];
        foreach (array_slice($scored, 0, self::MAX_HITS) as $entry) {
            $row = $entry['row'];
            $navTitle = trim((string)($row['nav_title'] ?? ''));
            $title = $navTitle !== '' ? $navTitle : trim((string)($row['title'] ?? ''));

            $hits[] = new PageHit(
                (int)$row['page_uid'],
                $title,
                $this->snippet((string)($row['content'] ?? ''), $keywords),
                $entry['score'],
            );
        }

        return $hits;
    }

    /**
     * Kuerzt den Seiteninhalt auf Ausschnitte rund um die Fundstellen.
     *
     * Warum nicht einfach die ersten 1500 Zeichen: die Oeffnungszeiten
     * stehen selten ganz oben auf der Seite. Ausgelassene Stellen werden
     * mit " … " markiert, damit die KI erkennt, dass Text fehlt.
     *
     * @param list<string> $keywords
     */
    private function snippet(string $content, array $keywords): string
    {
        $content = trim($content);
        $length = mb_strlen($content);

        if ($content === '' || $length <= self::MAX_SNIPPET_LENGTH) {
            return $content;
        }

        /** @var list<array{0: int, 1: int}> $windows */
        $windows = [];
        foreach ($keywords as $keyword) {
            $position = mb_stripos($content, $keyword);
            if ($position === false) {
                continue;
            }
            $windows[] = [
                max(0, $position - self::SNIPPET_BEFORE),
                min($length, $position + mb_strlen($keyword) + self::SNIPPET_AFTER),
            ];
        }

        if ($windows === []) {
            return rtrim(mb_substr($content, 0, self::MAX_SNIPPET_LENGTH)) . ' …';
        }

        sort($windows);

        // Ueberlappende Fenster zusammenfassen.
        $merged = [];
        foreach ($windows as $window) {
            $lastKey = $merged === [] ? null : array_key_last($merged);
            if ($lastKey !== null && $window[0] <= $merged[$lastKey][1]) {
                $merged[$lastKey][1] = max($merged[$lastKey][1], $window[1]);
                continue;
            }
            $merged[] = $window;
        }

        $parts = [];
        $budget = self::MAX_SNIPPET_LENGTH;
        $reachedEnd = false;
        foreach ($merged as $window) {
            if ($budget <= 0) {
                break;
            }
            $take = min($window[1] - $window[0], $budget);
            $parts[] = trim(mb_substr($content, $window[0], $take));
            $budget -= $take;
            $reachedEnd = ($window[0] + $take) >= $length;
        }

        return ($merged[0][0] > 0 ? '… ' : '')
            . implode(' … ', $parts)
            . ($reachedEnd ? '' : ' …');
    }

    /* ---------------------------------------------------------------- *
     * Schritt 4: Sitemap-Kompakt
     * ---------------------------------------------------------------- */

    /**
     * Sitemap-Kompakt aus dem Zwischenspeicher, sonst neu bauen.
     *
     * Der Schluessel enthaelt Website, Sprache UND die Einstellung
     * maxIndexPagesFullSitemap: das Retrieval filtert immer auf Website und
     * Sprache gemeinsam, und eine geaenderte Einstellung ergibt eine andere
     * Liste. Stuende sie nicht im Schluessel, bekaeme man nach einer
     * Umstellung weiter die alte Liste. Der Wert wird gehasht, weil ein
     * Cache-Schluessel nur bestimmte Zeichen enthalten darf - eine
     * site_identifier darf aber auch Punkte enthalten.
     *
     * Bewusst KEINE Lebensdauer im Aufruf: 0 hiesse "unbegrenzt", null hiesse
     * "Standard". Die Lebensdauer steht an genau einer Stelle, in
     * ext_localconf.php.
     *
     * @return array{0: list<SitemapEntry>, 1: bool}
     */
    private function cachedSitemap(Site $site, SiteLanguage $language, Context $context): array
    {
        $maxFull = $this->maxPagesFullSitemap($site);
        $identifier = 'sitemap_' . sha1(
            $site->getIdentifier() . '|' . $language->getLanguageId() . '|' . $maxFull
        );

        $cached = $this->cache->get($identifier);

        // Formpruefung statt blindem Vertrauen: nach einem Update koennte im
        // Speicher noch eine aeltere Struktur liegen.
        if (is_array($cached) && count($cached) === 2 && is_array($cached[0]) && is_bool($cached[1])) {
            return $cached;
        }

        $result = $this->buildSitemap($site, $language, $context, $maxFull);
        $this->cache->set($identifier, $result, [self::SITEMAP_CACHE_TAG]);

        return $result;
    }

    /**
     * Hierarchische Liste aller navigierbaren Seiten dieser Website/Sprache.
     *
     * Grundlage ist der Inhaltsindex - er kann aber VERALTETE Zeilen
     * enthalten (eine abgelaufene "endtime" wird erst beim naechtlichen
     * Voll-Reindex entfernt, siehe README). Review Phase 4, B1: die
     * Baumstruktur wird deshalb NICHT blind aus dem Index abgeleitet - jede
     * Seite wird zusaetzlich live gegen "pages" geprueft, mit demselben
     * anonymen Frontend-Context wie ueberall sonst in dieser Extension.
     *
     * @return array{0: list<SitemapEntry>, 1: bool}
     */
    private function buildSitemap(Site $site, SiteLanguage $language, Context $context, int $maxFull): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(IndexService::TABLE);
        $expr = $queryBuilder->expr();

        $rows = $queryBuilder
            ->select('page_uid', 'title', 'nav_title')
            ->from(IndexService::TABLE)
            ->where(
                $expr->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($site->getIdentifier())
                ),
                $expr->eq(
                    'language_uid',
                    $queryBuilder->createNamedParameter($language->getLanguageId(), Connection::PARAM_INT)
                ),
                // fe_groups-Filter (Konzept 7): siehe findHits() fuer die
                // Begruendung von "-1" ("Bei Login verbergen").
                $expr->in(
                    'fe_groups',
                    $queryBuilder->createNamedParameter(['', '-1'], Connection::PARAM_STR_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $titles = [];
        foreach ($rows as $row) {
            $pageUid = (int)$row['page_uid'];
            $navTitle = trim((string)($row['nav_title'] ?? ''));
            $title = $navTitle !== '' ? $navTitle : trim((string)($row['title'] ?? ''));
            $titles[$pageUid] = $title !== '' ? $title : '#' . $pageUid;
        }

        if ($titles === []) {
            return [[], false];
        }

        ksort($titles);

        [$children, $visible] = $this->childrenMap(array_keys($titles), $context);

        // Nur Seiten uebernehmen, die JETZT noch sichtbar sind (deleted,
        // Workspace, hidden, starttime, endtime, fe_group der Seite selbst).
        $titles = array_intersect_key($titles, $visible);
        if ($titles === []) {
            return [[], false];
        }

        // Review Phase 4, F2: FrontendRestrictionContainer prueft nur die
        // Seite SELBST. "extendToSubpages" auf einer Vorfahrenseite versteckt
        // aber den kompletten Unterbaum, ohne dass sich an der Kind-Seite
        // etwas aendert - deshalb zusaetzlich RootLineAccessChecker befragen,
        // mit derselben Regel wie fuer die Treffer (stillVisible()) und wie
        // im Indexer selbst. Die PID jeder Seite steht bereits in $children
        // (dort nach PID gruppiert) - eine weitere Abfrage ist dafuer nicht
        // noetig.
        $parentOf = [];
        foreach ($children as $pid => $childUids) {
            foreach ($childUids as $childUid) {
                $parentOf[$childUid] = $pid;
            }
        }

        $titles = array_filter(
            $titles,
            fn(int $pageUid): bool => $this->rootLineChecker->accessGranted($parentOf[$pageUid] ?? 0, $context),
            ARRAY_FILTER_USE_KEY
        );
        if ($titles === []) {
            return [[], false];
        }

        $entries = [];
        $visited = [];
        $this->appendBranch($site->getRootPageId(), 0, $children, $titles, $entries, $visited);

        // Seiten, deren Elternseite nicht im Index steht (z. B. Seiten
        // unterhalb eines Ordners), sind vom Wurzelknoten aus nicht
        // erreichbar. Sie werden ohne Einrueckung angehaengt, damit sie
        // nicht komplett fehlen.
        foreach ($titles as $pageUid => $title) {
            if (!isset($visited[$pageUid])) {
                $entries[] = new SitemapEntry($pageUid, $title, 0);
            }
        }

        $truncated = false;

        if (count($entries) > $maxFull) {
            $shortened = array_values(array_filter(
                $entries,
                static fn(SitemapEntry $entry): bool => $entry->level <= self::SHORT_SITEMAP_MAX_LEVEL
            ));

            // Review Phase 4, S3: greift die Ebenen-Kuerzung nicht (z. B.
            // weil die Wurzelseite nicht im Index steht und appendBranch()
            // deshalb nichts erreicht - alle Seiten landen dann ueber die
            // Waisen-Schleife auf Ebene 0), hart auf die erlaubte Anzahl
            // kappen statt die volle Liste unveraendert durchzureichen.
            if (count($shortened) >= count($entries)) {
                $shortened = array_slice($entries, 0, $maxFull);
            }

            $truncated = count($shortened) < count($entries);
            $entries = $shortened;
        }

        if (count($entries) > self::MAX_SITEMAP_ENTRIES) {
            $entries = array_slice($entries, 0, self::MAX_SITEMAP_ENTRIES);
            $truncated = true;
        }

        return [$entries, $truncated];
    }

    /**
     * @param array<int, string> $titles
     * @param array<int, list<int>> $children
     * @param list<SitemapEntry> $entries
     * @param array<int, true> $visited
     */
    private function appendBranch(
        int $pageUid,
        int $level,
        array $children,
        array $titles,
        array &$entries,
        array &$visited
    ): void {
        if ($level > self::MAX_TREE_DEPTH || isset($visited[$pageUid]) || !isset($titles[$pageUid])) {
            return;
        }

        $visited[$pageUid] = true;
        $entries[] = new SitemapEntry($pageUid, $titles[$pageUid], $level);

        foreach ($children[$pageUid] ?? [] as $childUid) {
            $this->appendBranch($childUid, $level + 1, $children, $titles, $entries, $visited);
        }
    }

    /**
     * Elternseite und Reihenfolge stehen nur in "pages" - der Index kennt
     * sie nicht. Abgefragt werden ausschliesslich Seiten, die ohnehin schon
     * im Index stehen.
     *
     * Review Phase 4, B1: die Abfrage lief bisher ausschliesslich mit den
     * Standard-Restrictions (deleted/hidden/start/end, aber weder fe_groups
     * noch Workspace). Der eigene Context MUSS uebergeben werden - sonst
     * gelten nur die Standard-Restrictions, und im CLI sogar die des
     * globalen Contexts (siehe FrontendContextFactory). Zusaetzlich zur
     * Baumstruktur wird deshalb zurueckgegeben, WELCHE der angefragten
     * Seiten diese Pruefung besteht ($visible) - der Aufrufer entfernt damit
     * veraltete Index-Zeilen aus der Sitemap, statt sie ungeprueft zu
     * uebernehmen.
     *
     * @param list<int> $pageUids
     * @return array{0: array<int, list<int>>, 1: array<int, true>} pid => Kind-UIDs in Baum-Reihenfolge; uid => true fuer aktuell sichtbare Seiten
     */
    private function childrenMap(array $pageUids, Context $context): array
    {
        $rows = [];
        foreach (array_chunk($pageUids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            // Der eigene Context MUSS uebergeben werden - sonst gelten nur die
            // Standard-Restrictions (kein fe_group, kein Workspace) und im
            // CLI sogar die des globalen Contexts.
            $queryBuilder->setRestrictions(
                GeneralUtility::makeInstance(FrontendRestrictionContainer::class, $context)
            );
            $result = $queryBuilder
                ->select('uid', 'pid', 'sorting')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'uid',
                        $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                    )
                )
                ->executeQuery()
                ->fetchAllAssociative();

            $rows = array_merge($rows, $result);
        }

        // Erst nach dem Zusammenfuehren aller Pakete sortieren, sonst waere
        // die Reihenfolge nur innerhalb eines Pakets richtig.
        usort(
            $rows,
            static fn(array $a, array $b): int =>
                [(int)$a['sorting'], (int)$a['uid']] <=> [(int)$b['sorting'], (int)$b['uid']]
        );

        $children = [];
        $visible = [];
        foreach ($rows as $row) {
            $children[(int)$row['pid']][] = (int)$row['uid'];
            $visible[(int)$row['uid']] = true;
        }

        return [$children, $visible];
    }

    private function maxPagesFullSitemap(Site $site): int
    {
        $value = $site->getSettings()->get(
            'accessiblechatbot.maxIndexPagesFullSitemap',
            self::DEFAULT_MAX_PAGES_FULL_SITEMAP
        );

        $value = is_numeric($value) ? (int)$value : 0;

        return $value > 0 ? $value : self::DEFAULT_MAX_PAGES_FULL_SITEMAP;
    }
}
