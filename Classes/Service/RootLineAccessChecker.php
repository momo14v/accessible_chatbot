<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Domain\Access\RecordAccessVoter;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Prueft, ob die VORFAHRENKETTE einer Seite den Zugriff erlaubt
 * ("extendToSubpages") - unabhaengig von der Sichtbarkeit der Seite selbst.
 *
 * SICHERHEITSKRITISCH. Ausgelagert aus IndexService (Review B1/F2), WORTGLEICH
 * uebernommen - das Verhalten des Indexers darf sich dadurch NICHT im
 * Geringsten aendern, dieser Code ist der sicherheitskritischste Teil der
 * Extension. Dieselbe Logik wird seither zusaetzlich von RetrievalService
 * benutzt, um Treffer UND Sitemap-Eintraege gegen dieselbe Regel zu pruefen
 * wie der Indexer selbst.
 *
 * Eine Elternseite mit "extendToSubpages" und z. B. abgelaufener "endtime"
 * versteckt im Frontend den kompletten Unterbaum, ohne dass sich an den
 * Unterseiten selbst etwas aendert - PageRepository::getPage() (und jede
 * andere Pruefung, die nur die Seite selbst betrachtet) sieht das nicht.
 */
final class RootLineAccessChecker
{
    private const MAX_TREE_DEPTH = 99;

    /**
     * Seitenbaum-Landkarte (uid => Zeile) fuer die Rootline-Pruefung.
     * Wird pro Lauf einmal geladen.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $pageTree = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RecordAccessVoter $accessVoter,
    ) {}

    /**
     * Leert die zwischengespeicherte Seitenbaum-Landkarte.
     *
     * MUSS zu Beginn jedes einzelnen Indexierungsvorgangs aufgerufen werden
     * (IndexService::indexSite(), ::refresh()) - "pages" kann sich auch
     * INNERHALB eines DataHandler-Durchlaufs aendern (mehrere Seiten in einer
     * Datamap, mehrere move-Befehle in einer Cmdmap).
     */
    public function reset(): void
    {
        $this->pageTree = null;
    }

    /**
     * true = die Vorfahrenkette dieser Seite erlaubt den Zugriff.
     *
     * $parentPageUid ist die PID der zu pruefenden Seite - genau wie bei
     * IndexService::buildRows(), das dieselbe Pruefung auf $pageRow['pid']
     * anwendet.
     */
    public function accessGranted(int $parentPageUid, Context $context): bool
    {
        $ancestors = $this->ancestorRows($parentPageUid);

        return $ancestors !== null && $this->rootLineAccessGranted($ancestors, $context);
    }

    /**
     * Die Vorfahren einer Seite, von der direkten Elternseite aufwaerts.
     *
     * Liefert NULL bei einer abgerissenen Kette (ein Vorfahre wurde
     * geloescht oder die Verkettung ist kaputt) oder wenn sie nicht bei
     * pid = 0 endet - der Zugriff ist dann nicht sicher bewertbar, und der
     * Aufrufer muss die Seite als NICHT zugreifbar behandeln.
     *
     * @return list<array<string, mixed>>|null
     */
    public function ancestorRows(int $parentPageUid): ?array
    {
        $map = $this->pageTreeMap();
        $rows = [];
        $seen = [];
        $current = $parentPageUid;
        $depth = 0;

        while ($current > 0) {
            if ($depth >= self::MAX_TREE_DEPTH || !isset($map[$current]) || isset($seen[$current])) {
                return null;
            }
            $seen[$current] = true;
            $rows[] = $map[$current];
            $current = (int)$map[$current]['pid'];
            $depth++;
        }

        return $rows;
    }

    /**
     * Setzt genau die Core-Regel um, die PageInformationFactory::
     * checkRootlineForIncludeSection() fuer jeden Rootline-Eintrag prueft:
     *
     * 1. Ein Backend-Benutzerbereich (doktype 6) in der Vorfahrenkette sperrt
     *    den kompletten Unterbaum fuer anonyme Besucher - unabhaengig von
     *    "extendToSubpages".
     * 2. Nur wenn "extendToSubpages" gesetzt ist, gelten hidden/starttime/
     *    endtime/fe_group der Elternseite auch fuer alle Unterseiten.
     *
     * Bewusst nachgebaut statt RecordAccessVoter::accessGrantedForPageInRootLine()
     * aufzurufen - die Methode ist @internal. accessGranted() ist es nicht.
     *
     * @param list<array<string, mixed>> $ancestors
     */
    public function rootLineAccessGranted(array $ancestors, Context $context): bool
    {
        foreach ($ancestors as $ancestor) {
            if ((int)($ancestor['doktype'] ?? 0) === PageRepository::DOKTYPE_BE_USER_SECTION) {
                return false;
            }
            if (!($ancestor['extendToSubpages'] ?? false)) {
                continue;
            }
            if (!$this->accessVoter->accessGranted('pages', $ancestor, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Eine kompakte Landkarte des gesamten Seitenbaums.
     *
     * Bewusst OHNE enable-fields: wir muessen versteckte Vorfahren SEHEN
     * koennen, um zu erkennen, dass sie ihren Unterbaum mitverstecken.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pageTreeMap(): array
    {
        if ($this->pageTree !== null) {
            return $this->pageTree;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $result = $queryBuilder
            ->select('uid', 'pid', 'doktype', 'fe_group', 'extendToSubpages', 'hidden', 'starttime', 'endtime')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->executeQuery();

        $map = [];
        while ($row = $result->fetchAssociative()) {
            $map[(int)$row['uid']] = $row;
        }

        return $this->pageTree = $map;
    }
}
