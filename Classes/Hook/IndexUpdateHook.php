<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Hook;

use Extension14v\AccessibleChatbot\Service\IndexService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Log\Channel;

/**
 * Haelt den Inhaltsindex beim Bearbeiten im Backend aktuell (Konzept 4.4).
 *
 * TYPO3 13.4 bietet fuer diese beiden Zeitpunkte KEIN PSR-14-Event an; die
 * klassischen DataHandler-Hooks sind der einzige Weg. Die Registrierung steht
 * deshalb an genau EINER Stelle (ext_localconf.php) und ist leicht ersetzbar,
 * falls TYPO3 v14 hier Events einfuehrt.
 *
 * Warum vier Hook-Methoden:
 * - processDatamap_afterDatabaseOperations: Anlegen, Aendern, Verstecken
 *   (Verstecken ist ein normaler Feldwert).
 * - processCmdmap_pre-/postProcess: Loeschen, Verschieben, Uebersetzen
 *   (das sind Befehle, keine Feldwerte).
 * - processDatamap_beforeStart / processCmdmap_beforeStart: invalidieren
 *   zusaetzlich, als redundantes Sicherheitsnetz, einmalig je DataHandler-
 *   Durchlauf die Seitenbaum-Landkarte des IndexService. Die eigentliche
 *   Garantie gegen veraltete Landkarten-Daten liegt seit dem B-1-Fix IN
 *   IndexService::indexSite()/refresh() selbst: beide leeren die Karte zu
 *   Beginn JEDES einzelnen Indexierungsvorgangs, weil sich "pages" auch
 *   innerhalb eines einzigen DataHandler-Durchlaufs mehrfach aendern kann
 *   (mehrere Seiten in einer Datamap, mehrere move-Befehle in einer
 *   Cmdmap).
 *
 * Wichtig: Der IndexService schreibt ausschliesslich per QueryBuilder in seine
 * eigene Tabelle. Wuerde er den DataHandler benutzen, riefe TYPO3 diesen Hook
 * erneut auf - eine Endlosschleife.
 */
final class IndexUpdateHook
{
    private const HANDLED_TABLES = ['pages', 'tt_content'];

    /**
     * Aenderungen an diesen Feldern koennen ueber "extendToSubpages" auch die
     * Sichtbarkeit aller Unterseiten umkippen. Dann reicht es nicht, nur die
     * eine Seite neu zu bewerten.
     */
    private const SUBTREE_RELEVANT_FIELDS = [
        'hidden',
        'starttime',
        'endtime',
        'fe_group',
        'extendToSubpages',
        'deleted',
        'doktype',
        'l18n_cfg',
        'pid',
    ];

    /**
     * Vor einem Befehl gemerkte Seiten (z. B. der alte Ablageort beim
     * Verschieben - nach dem Befehl waere er nicht mehr ermittelbar).
     *
     * Schluessel ist "$table:$id" statt einer einzigen flachen Liste: laeuft
     * zwischen unserem preProcess und postProcess ein verschachtelter
     * DataHandler-Cmdmap (der Core tut das u. a. selbst), wuerde eine flache
     * Liste durch dessen postProcess faelschlich geleert, bevor unser
     * eigener postProcess sie liest.
     *
     * @var array<string, list<int>>
     */
    private array $pendingPageUids = [];

    public function __construct(
        private readonly IndexService $indexService,
        #[Channel('accessible_chatbot')]
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Redundantes Sicherheitsnetz: invalidiert einmal pro DataHandler-Durchlauf
     * die zwischengespeicherte Seitenbaum-Landkarte des IndexService. Die
     * eigentliche Garantie liegt seit dem B-1-Fix in IndexService selbst, das
     * die Karte zu Beginn jedes einzelnen Indexierungsvorgangs neu leert
     * (siehe RootLineAccessChecker::pageTreeMap() und IndexService::invalidatePageTreeCache()).
     */
    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        $this->indexService->invalidatePageTreeCache();
    }

    /**
     * Nach dem Speichern eines Datensatzes (neu, geaendert, versteckt).
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        if (!$this->isRelevant($table, $dataHandler)) {
            return;
        }

        // Bei neuen Datensaetzen ist $id noch die Platzhalter-ID "NEW...".
        // Die echte UID steht dann in substNEWwithIDs.
        $uid = is_int($id) || ctype_digit((string)$id)
            ? (int)$id
            : (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($uid <= 0) {
            return;
        }

        try {
            if ($table === 'pages') {
                $pageUid = $this->pageUidOfPageRecord($uid);
                if ($pageUid <= 0) {
                    return;
                }
                if (array_intersect(array_keys($fieldArray), self::SUBTREE_RELEVANT_FIELDS) !== []) {
                    $this->indexService->refreshPageTree($pageUid);
                } else {
                    $this->indexService->refreshPage($pageUid);
                }

                return;
            }

            $pageUid = $this->pageUidOfContentRecord($uid);
            if ($pageUid > 0) {
                $this->indexService->refreshPage($pageUid);
            }
        } catch (\Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * Redundantes Sicherheitsnetz: invalidiert einmal pro DataHandler-Durchlauf
     * die zwischengespeicherte Seitenbaum-Landkarte des IndexService. Die
     * eigentliche Garantie liegt seit dem B-1-Fix in IndexService selbst, das
     * die Karte zu Beginn jedes einzelnen Indexierungsvorgangs neu leert
     * (siehe RootLineAccessChecker::pageTreeMap() und IndexService::invalidatePageTreeCache()).
     */
    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        $this->indexService->invalidatePageTreeCache();
    }

    /**
     * Vor einem Befehl: den JETZIGEN Ablageort merken. Beim Verschieben ist er
     * danach verloren, muss aber ebenfalls neu bewertet werden.
     */
    public function processCmdmap_preProcess(
        string $command,
        string $table,
        string|int $id,
        mixed $value,
        DataHandler $dataHandler,
        mixed $pasteUpdate = false
    ): void {
        if (!$this->isRelevant($table, $dataHandler)) {
            return;
        }

        $uid = (int)$id;
        if ($uid <= 0) {
            return;
        }

        try {
            $pageUid = $table === 'pages'
                ? $this->pageUidOfPageRecord($uid)
                : $this->pageUidOfContentRecord($uid);

            if ($pageUid > 0) {
                $this->pendingPageUids[$table . ':' . $uid][] = $pageUid;
            }
        } catch (\Throwable $exception) {
            $this->logFailure($exception);
        }
    }

    /**
     * Nach einem Befehl: alten und neuen Ablageort neu bewerten.
     */
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        string|int $id,
        mixed $value,
        DataHandler $dataHandler,
        mixed $pasteUpdate = false,
        array $pasteDatamap = []
    ): void {
        $uid = (int)$id;
        $key = $table . ':' . $uid;
        $affected = $this->pendingPageUids[$key] ?? [];
        unset($this->pendingPageUids[$key]);

        if (!$this->isRelevant($table, $dataHandler)) {
            return;
        }

        $isPages = $table === 'pages';

        try {
            $pageUid = $isPages
                ? $this->pageUidOfPageRecord($uid)
                : $this->pageUidOfContentRecord($uid);
            if ($pageUid > 0) {
                $affected[] = $pageUid;
            }
        } catch (\Throwable $exception) {
            $this->logFailure($exception);
        }

        foreach (array_unique($affected) as $affectedPageUid) {
            try {
                if ($isPages) {
                    // Loeschen und Verschieben betreffen immer den ganzen
                    // Unterbaum, nicht nur die eine Seite.
                    $this->indexService->refreshPageTree($affectedPageUid);
                } else {
                    $this->indexService->refreshPage($affectedPageUid);
                }
            } catch (\Throwable $exception) {
                $this->logFailure($exception);
            }
        }
    }

    /**
     * Nur Live-Arbeitsbereich und nur unsere beiden Tabellen.
     * Workspace-Inhalte sind fuer normale Besucher unsichtbar (Konzept 11).
     */
    private function isRelevant(string $table, DataHandler $dataHandler): bool
    {
        if (!in_array($table, self::HANDLED_TABLES, true)) {
            return false;
        }

        return isset($dataHandler->BE_USER) && (int)$dataHandler->BE_USER->workspace === 0;
    }

    /**
     * Zu welcher Seite gehoert diese "pages"-Zeile?
     *
     * Uebersetzungen von Seiten sind eigene Zeilen in "pages". Indexiert wird
     * aber immer ueber die UID der Standardsprache - deshalb wird bei einer
     * Uebersetzung auf l10n_parent umgebogen.
     */
    private function pageUidOfPageRecord(int $uid): int
    {
        $row = BackendUtility::getRecord('pages', $uid, 'uid,sys_language_uid,l10n_parent', '', false);
        if (!is_array($row)) {
            return $uid;
        }

        return (int)($row['sys_language_uid'] ?? 0) > 0
            ? (int)($row['l10n_parent'] ?? 0)
            : (int)$row['uid'];
    }

    /**
     * Auf welcher Seite liegt dieses Inhaltselement?
     *
     * Bewusst die ganze Seite neu indexieren und nicht nur das Element -
     * der Index kennt nur Seiten, keine einzelnen Elemente.
     */
    private function pageUidOfContentRecord(int $uid): int
    {
        $row = BackendUtility::getRecord('tt_content', $uid, 'pid', '', false);

        return is_array($row) ? (int)($row['pid'] ?? 0) : 0;
    }

    /**
     * Ein Fehler beim Indexieren darf den Speichervorgang der Redaktion
     * niemals abbrechen. Er wird protokolliert; der naechste Voll-Reindex
     * raeumt auf.
     */
    private function logFailure(\Throwable $exception): void
    {
        $this->logger->error('Index update failed: {class}', [
            'class' => $exception::class,
            'code' => $exception->getCode(),
        ]);
    }
}
