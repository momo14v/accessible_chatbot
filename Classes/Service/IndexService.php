<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Service;

use Extension14v\AccessibleChatbot\Configuration\ConfigurationProvider;
use Extension14v\AccessibleChatbot\Event\ModifyPageIndexRecordEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Log\Channel;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Baut den Inhaltsindex auf (Konzept 4.4).
 *
 * SICHERHEITSKRITISCH. Der Bot darf ausschliesslich Inhalte kennen, die ein
 * anonymer Besucher auch selbst sehen koennte. Deshalb gilt hier:
 *
 * 1. Es wird NIEMALS roh in "pages"/"tt_content" abgefragt, ohne dass eine
 *    Core-Sichtbarkeitspruefung darueberliegt.
 * 2. Der globale Context wird NICHT benutzt, sondern eine Kopie, in der jeder
 *    relevante Aspekt bewusst neu gesetzt wird (siehe FrontendContextFactory,
 *    Review Phase 4, S7: identische Logik wird seither auch von
 *    RetrievalService fuer die Sichtbarkeitspruefung der Treffer benutzt).
 * 3. Geschrieben wird ausschliesslich per QueryBuilder in die EIGENE Tabelle -
 *    niemals ueber den DataHandler, der sonst unseren eigenen Hook erneut
 *    ausloesen und eine Endlosschleife erzeugen wuerde.
 */
final class IndexService
{
    public const TABLE = 'tx_accessiblechatbot_index';

    /**
     * Reihenfolge muss zur Reihenfolge in buildRows() passen.
     */
    private const COLUMNS = [
        'pid',
        'site_identifier',
        'page_uid',
        'language_uid',
        'title',
        'nav_title',
        'abstract',
        'keywords',
        'content',
        'fe_groups',
        'updated_at',
    ];

    /**
     * Seitentypen, die niemals besucher-sichtbaren Seiteninhalt tragen.
     * Achtung: das ist ein Rausch-, kein Schutzfilter. Backend-Benutzer-
     * bereiche (doktype 6) werden vom Core ohnehin schon ausgeschlossen.
     */
    private const EXCLUDED_DOKTYPES = [
        PageRepository::DOKTYPE_BE_USER_SECTION,
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
    ];

    private const MAX_TREE_DEPTH = 99;
    private const FALLBACK_MAX_CONTENT_LENGTH = 20000;
    private const CHUNK_SIZE = 500;
    private const INSERT_CHUNK_SIZE = 100;

    /**
     * "abstract" und "keywords" sind text-Spalten (65535 Byte). 16000 Zeichen
     * bleiben bei utf8mb4 (max. 4 Byte/Zeichen) sicher darunter.
     */
    private const MAX_TEXT_FIELD_LENGTH = 16000;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ConfigurationProvider $configurationProvider,
        private readonly RootLineAccessChecker $rootLineChecker,
        private readonly FrontendContextFactory $contextFactory,
        #[Channel('accessible_chatbot')]
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Voll-Reindex einer Website. Ersetzt den Bestand dieser Website atomar:
     * Loeschen und Neuschreiben passieren in EINER Transaktion, es gibt also
     * zu keinem Zeitpunkt einen leeren Zwischenzustand.
     *
     * Bewusst kein TRUNCATE: das ist auf MariaDB/MySQL nicht transaktional.
     *
     * @param \Closure(int, int): void|null $progress erhaelt (erledigt, gesamt)
     * @return int Anzahl geschriebener Datensaetze
     */
    public function indexSite(Site $site, ?\Closure $progress = null): int
    {
        // Karte nur fuer die Dauer EINES Indexierungsvorgangs halten - waehrend
        // eines DataHandler-Durchlaufs aendert sich "pages" zwischen zwei
        // Aufrufen (siehe RootLineAccessChecker::pageTreeMap()).
        $this->rootLineChecker->reset();

        $pageUids = $this->collectSitePageUids($site);
        $rows = $this->buildRows($site, $pageUids, $progress);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->beginTransaction();
        try {
            $connection->delete(
                self::TABLE,
                ['site_identifier' => $site->getIdentifier()],
                [Connection::PARAM_STR]
            );
            $this->insertRows($connection, $rows);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->logger->error('Index rebuild failed for site {site}: {message}', [
                'site' => $site->getIdentifier(),
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return count($rows);
    }

    /**
     * Fuegt Zeilen paketweise ein, damit inhaltsreiche Seiten nicht an
     * "max_allowed_packet" scheitern. Laeuft innerhalb der aufrufenden
     * Transaktion, die Atomaritaet bleibt also erhalten.
     *
     * @param list<list<mixed>> $rows
     */
    private function insertRows(Connection $connection, array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            $connection->bulkInsert(self::TABLE, $chunk, self::COLUMNS);
        }
    }

    /**
     * Entfernt Eintraege von Websites, die es nicht mehr gibt.
     *
     * @param list<string> $knownSiteIdentifiers
     */
    public function removeOrphanedSiteRows(array $knownSiteIdentifiers): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->delete(self::TABLE);

        if ($knownSiteIdentifiers !== []) {
            $queryBuilder->where(
                $queryBuilder->expr()->notIn(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($knownSiteIdentifiers, Connection::PARAM_STR_ARRAY)
                )
            );
        }

        return (int)$queryBuilder->executeStatement();
    }

    /**
     * Inkrementell: genau eine Seite neu bewerten (alle Sprachen ihrer Website).
     */
    public function refreshPage(int $pageUid): void
    {
        $this->refresh([$pageUid]);
    }

    /**
     * Inkrementell: eine Seite UND ihren gesamten Unterbaum neu bewerten.
     *
     * Noetig, weil "extendToSubpages" die Sichtbarkeit aller Unterseiten
     * mitbestimmt: wird eine Seite mit diesem Haken versteckt, verschwinden
     * auch alle Unterseiten aus dem Frontend - und muessen deshalb auch aus
     * dem Index verschwinden.
     */
    public function refreshPageTree(int $pageUid): void
    {
        $this->refresh($this->collectSubtreeUids($pageUid));
    }

    /**
     * Invalidiert die zwischengespeicherte Seitenbaum-Landkarte
     * (siehe RootLineAccessChecker::pageTreeMap()).
     *
     * Die eigentliche Sicherheitsgarantie liegt seit dem B-1-Fix in
     * indexSite() und refresh(): beide leeren die Karte selbst zu Beginn
     * jedes einzelnen Indexierungsvorgangs, weil sich "pages" auch INNERHALB
     * eines DataHandler-Durchlaufs aendern kann (mehrere Seiten in einer
     * Datamap, mehrere move-Befehle in einer Cmdmap). Diese Methode - von
     * IndexUpdateHook einmal pro DataHandler-Durchlauf aufgerufen - ist
     * dadurch nur noch ein zusaetzliches, redundantes Sicherheitsnetz.
     */
    public function invalidatePageTreeCache(): void
    {
        $this->rootLineChecker->reset();
    }

    /**
     * Bewertet eine Menge von Seiten-UIDs neu, jede fuer sich der Website
     * zugeordnet, unter der sie tatsaechlich liegt.
     *
     * Bewusst PRO SEITE per SiteFinder aufgeloest statt einen gemeinsamen
     * Anker anzunehmen: sonst wuerden Seiten einer verschachtelten Website
     * (Website B im Seitenbaum von Website A) unter der falschen
     * site_identifier landen.
     *
     * @param list<int> $pageUids
     */
    private function refresh(array $pageUids): void
    {
        $pageUids = array_values(array_unique(array_filter($pageUids)));
        if ($pageUids === []) {
            return;
        }

        // Karte nur fuer die Dauer EINES Indexierungsvorgangs halten - waehrend
        // eines DataHandler-Durchlaufs aendert sich "pages" zwischen zwei
        // Aufrufen (siehe RootLineAccessChecker::pageTreeMap()).
        $this->rootLineChecker->reset();

        /** @var array<string, array{site: Site, pageUids: list<int>}> $pageUidsBySite */
        $pageUidsBySite = [];
        $unresolvedPageUids = [];
        foreach ($pageUids as $pageUid) {
            try {
                $site = $this->siteFinder->getSiteByPageId($pageUid);
            } catch (SiteNotFoundException) {
                // Seite geloescht oder ausserhalb jeder Website: nur aufraeumen.
                $unresolvedPageUids[] = $pageUid;

                continue;
            }

            $identifier = $site->getIdentifier();
            $pageUidsBySite[$identifier]['site'] = $site;
            $pageUidsBySite[$identifier]['pageUids'][] = $pageUid;
        }

        // Review Phase 4, V11: Fruehabbruch VOR dem Buildschritt, nicht erst
        // danach - der Buildschritt (viele SELECTs, PSR-14-Event,
        // HTML-Konvertierung) darf nicht fuer nichts laufen.
        if ($pageUidsBySite === [] && $unresolvedPageUids === []) {
            return;
        }

        // Buildschritt (viele SELECTs, PSR-14-Event, HTML-Konvertierung) bewusst
        // VOR dem Oeffnen der Transaktion: sonst haelt er die Index-Tabelle
        // unnoetig lange gesperrt, und ein Fehler im Event-Listener rollt die
        // Transaktion zurueck, obwohl er nichts mit ihr zu tun hat.
        $builtRows = [];
        foreach ($pageUidsBySite as $identifier => $group) {
            $builtRows[$identifier] = $this->buildRows($group['site'], $group['pageUids']);
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->beginTransaction();
        try {
            if ($unresolvedPageUids !== []) {
                $this->deleteByPageUids($unresolvedPageUids);
            }
            foreach ($pageUidsBySite as $identifier => $group) {
                $this->deleteByPageUids($group['pageUids']);
                $this->insertRows($connection, $builtRows[$identifier]);
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->logger->error('Incremental index refresh failed: {message}', [
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param list<int> $pageUids
     */
    private function deleteByPageUids(array $pageUids): void
    {
        foreach (array_chunk($pageUids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder
                ->delete(self::TABLE)
                ->where(
                    $queryBuilder->expr()->in(
                        'page_uid',
                        $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)
                    )
                )
                ->executeStatement();
        }
    }

    /**
     * Alle Seiten-UIDs einer Website, die ein anonymer Besucher erreichen kann.
     *
     * Kernstueck: getDescendantPageIdsRecursive() erledigt laut Core-Docblock
     * genau das Schwierige - geloeschte Seiten, Backend-Benutzerbereiche und
     * die komplette "extendToSubpages"-Vererbung. Diese Logik wird bewusst
     * NICHT selbst nachgebaut.
     *
     * Die Startseite selbst liefert die Methode nicht mit, sie wird ergaenzt
     * und in buildRows() genauso streng geprueft wie jede andere Seite.
     *
     * Startseiten anderer Websites werden ausgeschlossen: liegt eine Website
     * im Seitenbaum einer anderen, wuerde ihr Unterbaum sonst doppelt und
     * unter der falschen site_identifier landen.
     *
     * @return list<int>
     */
    private function collectSitePageUids(Site $site): array
    {
        $context = $this->contextFactory->create($site->getDefaultLanguage());
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);

        $foreignRootPageIds = [];
        foreach ($this->siteFinder->getAllSites() as $otherSite) {
            if ($otherSite->getIdentifier() !== $site->getIdentifier()) {
                $foreignRootPageIds[] = $otherSite->getRootPageId();
            }
        }

        $descendants = $pageRepository->getDescendantPageIdsRecursive(
            $site->getRootPageId(),
            self::MAX_TREE_DEPTH,
            0,
            $foreignRootPageIds
        );

        return array_values(array_unique(array_merge([$site->getRootPageId()], $descendants)));
    }

    /**
     * Seite plus kompletter Unterbaum - inklusive versteckter und geloeschter
     * Seiten. Beide muessen mit, damit ihre Index-Eintraege verschwinden.
     *
     * @return list<int>
     */
    private function collectSubtreeUids(int $pageUid): array
    {
        $uids = [$pageUid];
        $level = [$pageUid];
        $depth = 0;

        while ($level !== [] && $depth < self::MAX_TREE_DEPTH) {
            $next = [];
            foreach (array_chunk($level, self::CHUNK_SIZE) as $chunk) {
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
                $queryBuilder->getRestrictions()->removeAll();
                $found = $queryBuilder
                    ->select('uid')
                    ->from('pages')
                    ->where(
                        $queryBuilder->expr()->in(
                            'pid',
                            $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)
                        ),
                        $queryBuilder->expr()->eq(
                            'sys_language_uid',
                            $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                        )
                    )
                    ->executeQuery()
                    ->fetchFirstColumn();

                foreach ($found as $foundUid) {
                    $next[] = (int)$foundUid;
                }
            }

            // Schleifenschutz gegen kaputte pid-Verkettungen.
            $next = array_values(array_diff(array_unique($next), $uids));
            $uids = array_merge($uids, $next);
            $level = $next;
            $depth++;
        }

        return $uids;
    }

    /**
     * Baut aus einer Liste von Seiten-UIDs die fertigen Index-Zeilen.
     *
     * @param list<int> $pageUids
     * @param \Closure(int, int): void|null $progress
     * @return list<list<mixed>>
     */
    private function buildRows(Site $site, array $pageUids, ?\Closure $progress = null): array
    {
        $languages = $site->getLanguages();
        $maxContentLength = $this->maxContentLength();
        $timestamp = (int)($GLOBALS['EXEC_TIME'] ?? time());
        $hasNoIndexField = isset($GLOBALS['TCA']['pages']['columns']['no_index']);

        $rows = [];
        $done = 0;
        $total = count($pageUids) * max(1, count($languages));

        foreach ($languages as $language) {
            $context = $this->contextFactory->create($language);
            /** @var LanguageAspect $languageAspect */
            $languageAspect = $context->getAspect('language');
            $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);

            // Sichtbare Seiten in einem Rutsch holen und danach sprachlich
            // ueberlagern. Deutlich weniger Abfragen als getPage() je Seite,
            // aber exakt dieselben Core-Pruefungen.
            $rowsByUid = [];
            $visibleRows = $pageRepository->getPagesOverlay(
                $this->fetchVisiblePageRows($pageUids, $context),
                $languageAspect
            );
            foreach ($visibleRows as $visibleRow) {
                $rowsByUid[(int)$visibleRow['uid']] = $visibleRow;
            }

            foreach ($pageUids as $pageUid) {
                if ($progress !== null) {
                    $progress($done, $total);
                }
                $done++;

                $pageRow = $rowsByUid[$pageUid] ?? null;
                if ($pageRow === null) {
                    // Von enable-fields, fe_group oder Workspace ausgeschlossen.
                    continue;
                }

                if (in_array((int)($pageRow['doktype'] ?? 0), self::EXCLUDED_DOKTYPES, true)) {
                    continue;
                }
                if ((int)($pageRow['no_search'] ?? 0) === 1) {
                    continue;
                }
                if ($hasNoIndexField && (int)($pageRow['no_index'] ?? 0) === 1) {
                    continue;
                }
                if (!$pageRepository->isPageSuitableForLanguage($pageRow, $languageAspect)) {
                    continue;
                }

                // Rootline-Pruefung: beim inkrementellen Update laeuft
                // getDescendantPageIdsRecursive() nicht, deshalb hier erneut
                // die eine Core-Regel anwenden, die "extendToSubpages" umsetzt.
                $ancestors = $this->rootLineChecker->ancestorRows((int)($pageRow['pid'] ?? 0));
                if ($ancestors === null) {
                    // Abgerissene Vorfahrenkette: Zugriff nicht sicher
                    // bewertbar, Seite deshalb NICHT indexieren.
                    continue;
                }
                if (!$this->rootLineChecker->rootLineAccessGranted($ancestors, $context)) {
                    continue;
                }

                $content = $this->cap(
                    $this->collectPageContent($pageUid, $context, $languageAspect, $pageRepository),
                    $maxContentLength
                );

                $abstract = trim((string)($pageRow['abstract'] ?? ''));
                if ($abstract === '') {
                    $abstract = trim((string)($pageRow['description'] ?? ''));
                }

                $record = [
                    'pid' => 0,
                    'site_identifier' => $site->getIdentifier(),
                    'page_uid' => $pageUid,
                    'language_uid' => $language->getLanguageId(),
                    'title' => (string)($pageRow['title'] ?? ''),
                    'nav_title' => (string)($pageRow['nav_title'] ?? ''),
                    'abstract' => $abstract,
                    'keywords' => trim((string)($pageRow['keywords'] ?? '')),
                    'content' => $content,
                    'fe_groups' => $this->collectFeGroups($pageRow, $ancestors),
                    'updated_at' => $timestamp,
                ];

                $event = $this->eventDispatcher->dispatch(
                    new ModifyPageIndexRecordEvent($record, $pageRow, $site, $language)
                );
                if ($event->isSkipped()) {
                    continue;
                }
                $record = $event->getRecord();

                // Nach dem Event erneut aufraeumen: ein Listener koennte
                // beliebig langen oder unsauberen Text angehaengt haben.
                $record['content'] = $this->cap(
                    $this->normaliseWhitespace((string)($record['content'] ?? '')),
                    $maxContentLength
                );
                $record['title'] = mb_substr((string)($record['title'] ?? ''), 0, 255);
                $record['nav_title'] = mb_substr((string)($record['nav_title'] ?? ''), 0, 255);
                $record['abstract'] = mb_substr((string)($record['abstract'] ?? ''), 0, self::MAX_TEXT_FIELD_LENGTH);
                $record['keywords'] = mb_substr((string)($record['keywords'] ?? ''), 0, self::MAX_TEXT_FIELD_LENGTH);

                // Zuordnung UND Zugriffsgruppen sind nicht verhandelbar - ein
                // Listener darf weder einen Datensatz einer anderen Website
                // oder Seite unterschieben, noch das Filterfeld fe_groups
                // aushebeln.
                $record['pid'] = 0;
                $record['site_identifier'] = $site->getIdentifier();
                $record['page_uid'] = $pageUid;
                $record['language_uid'] = $language->getLanguageId();
                $record['fe_groups'] = $this->collectFeGroups($pageRow, $ancestors);
                $record['updated_at'] = $timestamp;

                $rows[] = [
                    $record['pid'],
                    $record['site_identifier'],
                    $record['page_uid'],
                    $record['language_uid'],
                    $record['title'],
                    $record['nav_title'],
                    $record['abstract'],
                    $record['keywords'],
                    $record['content'],
                    $record['fe_groups'],
                    $record['updated_at'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Holt die Seitenzeilen der Standardsprache mit voller Frontend-Filterung.
     *
     * Der FrontendRestrictionContainer buendelt genau die Einschraenkungen,
     * die auch im Frontend gelten: deleted, Workspace, hidden, starttime,
     * endtime und fe_group.
     *
     * Der eigene Context MUSS uebergeben werden - ohne Argument faellt die
     * Klasse still auf den globalen Context zurueck, und der zeigt im CLI
     * versteckte Inhalte an.
     *
     * @param list<int> $pageUids
     * @return list<array<string, mixed>>
     */
    private function fetchVisiblePageRows(array $pageUids, Context $context): array
    {
        $rows = [];
        foreach (array_chunk($pageUids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->setRestrictions(
                GeneralUtility::makeInstance(FrontendRestrictionContainer::class, $context)
            );
            $result = $queryBuilder
                ->select('*')
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

        return $rows;
    }

    /**
     * Sammelt den Text der sichtbaren Inhaltselemente einer Seite.
     *
     * Die Sprachbehandlung ist bewusst 1:1 aus dem Core uebernommen
     * (ContentObjectRenderer::getLanguageRestriction()): mit Overlays werden
     * nur Sprache 0 und -1 geholt und danach ueberlagert; im Sprachmodus
     * "strict" (OVERLAYS_ON_WITH_FLOATING) zusaetzlich frei schwebende
     * Uebersetzungen ohne l18n_parent; ohne Overlays ("free mode") direkt die
     * Datensaetze der Zielsprache.
     */
    private function collectPageContent(
        int $pageUid,
        Context $context,
        LanguageAspect $languageAspect,
        PageRepository $pageRepository
    ): string {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->setRestrictions(
            GeneralUtility::makeInstance(FrontendRestrictionContainer::class, $context)
        );
        $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                ),
                // Negative colPos = Elemente ausserhalb jedes Backend-Layouts
                // ("Nicht verwendete Elemente"). Sie werden im Frontend nie
                // gerendert und duerfen deshalb auch nicht in den Index
                // (Kernprinzip 6: der Bot sieht nur, was der Besucher sieht).
                $queryBuilder->expr()->gte(
                    'colPos',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting');

        $expr = $queryBuilder->expr();
        if ($languageAspect->doOverlays()) {
            $languageConstraint = $expr->in(
                'sys_language_uid',
                $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)
            );
            if ($languageAspect->getOverlayType() === LanguageAspect::OVERLAYS_ON_WITH_FLOATING) {
                $languageConstraint = $expr->or(
                    $languageConstraint,
                    $expr->and(
                        $expr->eq('l18n_parent', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                        $expr->eq(
                            'sys_language_uid',
                            $queryBuilder->createNamedParameter($languageAspect->getContentId(), Connection::PARAM_INT)
                        )
                    )
                );
            }
        } else {
            $languageConstraint = $expr->in(
                'sys_language_uid',
                $queryBuilder->createNamedParameter(
                    [$languageAspect->getContentId(), -1],
                    Connection::PARAM_INT_ARRAY
                )
            );
        }
        $queryBuilder->andWhere($languageConstraint);

        $parts = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            if ($languageAspect->doOverlays()) {
                $row = $pageRepository->getLanguageOverlay('tt_content', $row, $languageAspect);
                if (!is_array($row) || $row === []) {
                    // Strikte Sprachvariante ohne Uebersetzung: im Frontend
                    // ebenfalls nicht sichtbar.
                    continue;
                }
            }

            // header_layout = 100 bedeutet "Ueberschrift verbergen" - der
            // Besucher sieht sie nicht, also gehoert sie nicht in den Index.
            if ((string)($row['header_layout'] ?? '0') !== '100') {
                $parts[] = (string)($row['header'] ?? '');
            }
            $parts[] = (string)($row['subheader'] ?? '');
            $parts[] = $this->htmlToText((string)($row['bodytext'] ?? ''));
        }

        $parts = array_filter(
            array_map(static fn(string $part): string => trim($part), $parts),
            static fn(string $part): bool => $part !== ''
        );

        return $this->normaliseWhitespace(implode("\n", $parts));
    }

    /**
     * Macht aus HTML lesbaren Text.
     *
     * Wichtig: Blockelemente muessen zu Zeilenumbruechen werden. Sonst wuerde
     * aus "<li>Montag</li><li>Dienstag</li>" das Wort "MontagDienstag".
     */
    private function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // 1. Skript- und Style-Bloecke samt Inhalt verwerfen.
        $text = (string)preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);

        // 2. Zeilenumbrueche und Blockelemente werden zu echten Umbruechen.
        $text = (string)preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string)preg_replace(
            '#</?(p|div|li|ul|ol|dl|dd|dt|h[1-6]|tr|table|thead|tbody|tfoot|section'
            . '|article|header|footer|aside|blockquote|pre|figure|figcaption|address|hr)\b[^>]*>#i',
            "\n",
            $text
        );

        // 3. Tabellenzellen nur trennen, nicht umbrechen.
        $text = (string)preg_replace('#</?(td|th)\b[^>]*>#i', ' ', $text);

        // 4. Restliche Tags entfernen, HTML-Entities aufloesen.
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->normaliseWhitespace($text);
    }

    private function normaliseWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = (string)preg_replace('/[ \t]+/', ' ', $text);
        $text = (string)preg_replace('/ *\n */', "\n", $text);
        $text = (string)preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Kappt auf die konfigurierte Laenge, moeglichst an einer Wortgrenze.
     */
    private function cap(string $text, int $maxLength): string
    {
        if ($maxLength <= 0 || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > (int)($maxLength * 0.9)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut);
    }

    private function maxContentLength(): int
    {
        $configured = $this->configurationProvider->get()->maxContentLength;

        return $configured > 0 ? $configured : self::FALLBACK_MAX_CONTENT_LENGTH;
    }

    /**
     * Alle Zugriffsgruppen, die fuer diese Seite gelten: die eigene plus die
     * der Vorfahren mit "extendToSubpages".
     *
     * In Phase 3 filtert dieses Feld noch nichts - es wird nur befuellt.
     * Die spaetere Regel lautet: ein Eintrag ist fuer eine Person sichtbar,
     * wenn JEDER Wert in fe_groups zu ihren Gruppen gehoert. Fuer anonyme
     * Besucher sind das [0, -1].
     *
     * @param array<string, mixed> $pageRow
     * @param list<array<string, mixed>> $ancestors
     */
    private function collectFeGroups(array $pageRow, array $ancestors): string
    {
        $groups = GeneralUtility::trimExplode(',', (string)($pageRow['fe_group'] ?? ''), true);

        foreach ($ancestors as $ancestor) {
            if (!($ancestor['extendToSubpages'] ?? false)) {
                continue;
            }
            foreach (GeneralUtility::trimExplode(',', (string)($ancestor['fe_group'] ?? ''), true) as $group) {
                $groups[] = $group;
            }
        }

        $groups = array_values(array_unique(array_filter(
            $groups,
            static fn(string $group): bool => $group !== '' && $group !== '0'
        )));
        sort($groups);

        return mb_substr(implode(',', $groups), 0, 255);
    }
}
