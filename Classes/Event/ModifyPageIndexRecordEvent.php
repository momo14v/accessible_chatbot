<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Event;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Wird vor dem Speichern JEDES Index-Datensatzes ausgeloest (Konzept 4.4).
 *
 * Erweiterungspunkt: eine andere Extension kann hier eigene Inhalte an die
 * Seite anhaengen (z. B. News-Datensaetze oder Plugin-Ausgaben), ohne dass
 * diese Extension geaendert werden muss.
 *
 * WICHTIG fuer Listener: Alles, was hier angehaengt wird, landet spaeter im
 * Prompt an die KI. Es duerfen ausschliesslich Inhalte angehaengt werden, die
 * ein anonymer Besucher auf dieser Seite auch selbst sehen koennte.
 *
 * Die Felder site_identifier, page_uid, language_uid, pid und updated_at
 * werden nach dem Event bewusst wieder ueberschrieben - ein Listener kann die
 * Zuordnung eines Datensatzes also nicht faelschen.
 */
final class ModifyPageIndexRecordEvent
{
    private bool $skipped = false;

    /**
     * @param array<string, mixed> $record     Der Datensatz, so wie er gespeichert wuerde
     * @param array<string, mixed> $pageRecord Die (sprachueberlagerte) Zeile aus "pages"
     */
    public function __construct(
        private array $record,
        private readonly array $pageRecord,
        private readonly Site $site,
        private readonly SiteLanguage $siteLanguage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getRecord(): array
    {
        return $this->record;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function setRecord(array $record): void
    {
        $this->record = $record;
    }

    /**
     * Bequemer Weg fuer den Normalfall: Text hinten anhaengen.
     * Der IndexService normalisiert und kappt danach erneut.
     */
    public function appendContent(string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $current = trim((string)($this->record['content'] ?? ''));
        $this->record['content'] = $current === '' ? $text : $current . "\n" . $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageRecord(): array
    {
        return $this->pageRecord;
    }

    public function getSite(): Site
    {
        return $this->site;
    }

    public function getSiteLanguage(): SiteLanguage
    {
        return $this->siteLanguage;
    }

    /**
     * Diese Seite gar nicht in den Index aufnehmen.
     */
    public function skip(): void
    {
        $this->skipped = true;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }
}
