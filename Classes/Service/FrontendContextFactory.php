<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Domain\DateTimeFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Baut den anonymen Frontend-Context, den sowohl der Indexer als auch die
 * Sichtbarkeitspruefung der Retrieval-Treffer brauchen (Review Phase 4, S7).
 *
 * Ausgelagert aus IndexService::createContext(), WORTGLEICH uebernommen -
 * das Verhalten von IndexService darf sich dadurch nicht im Geringsten
 * aendern, dieser Code ist der sicherheitskritischste Teil der Extension.
 *
 * SICHERHEITSKRITISCH:
 *
 * - Im CLI stellt TYPO3 den globalen Context auf
 *   VisibilityAspect(true, true, false, true), also "zeige versteckte
 *   Seiten UND versteckte Inhalte UND ignoriere Start-/Endzeiten"
 *   (CommandApplication::initializeContext(), am Core verifiziert).
 *   Wuerden wir den globalen Context benutzen, landeten genau die
 *   versteckten Inhalte im Index bzw. in den Retrieval-Treffern, die der
 *   Bot niemals kennen darf.
 * - Im Backend (DataHandler-Hook) haengt am globalen Context ausserdem der
 *   Arbeitsbereich der Redaktion.
 * - Der Default-UserAspect liefert eine LEERE Gruppenliste, nicht [0, -1].
 *   Seiten mit fe_group = -1 ("bei Login verbergen") sind fuer anonyme
 *   Besucher aber sichtbar.
 *
 * Deshalb: den globalen Context klonen und danach JEDEN relevanten Aspekt
 * bewusst neu setzen. Der Singleton selbst wird nie veraendert.
 *
 * Der erzeugte Context arbeitet bewusst IMMER als anonymer Besucher. Seiten
 * mit Zugriffsgruppe kommen dadurch gar nicht erst in den Index (Konzept 3.4
 * und 11: keine personalisierten Inhalte fuer eingeloggte Nutzer in v1).
 */
final class FrontendContextFactory
{
    public function create(SiteLanguage $language): Context
    {
        $context = clone GeneralUtility::makeInstance(Context::class);

        // Review Phase 4, V12: bewusst EXEC_TIME statt $GLOBALS['SIM_ACCESS_TIME']
        // (das FrontendRestrictionContainer und RecordAccessVoter verwenden).
        // EXEC_TIME ist die STRENGERE Wahl und immun gegen die Zeitsimulation
        // eines angemeldeten Backend-Users ("Datum/Uhrzeit simulieren") - ein
        // Redakteur, der im Backend eine spaetere Uhrzeit simuliert, darf sich
        // damit keinen Zugriff auf noch nicht sichtbare Inhalte fuer den Bot
        // erschleichen. Nicht angleichen.
        $context->setAspect(
            'date',
            new DateTimeAspect(DateTimeFactory::createFromTimestamp((int)($GLOBALS['EXEC_TIME'] ?? time())))
        );
        $context->setAspect('visibility', new VisibilityAspect());
        $context->setAspect('workspace', new WorkspaceAspect(0));
        $context->setAspect('backend.user', new UserAspect());
        $context->setAspect('frontend.user', new UserAspect(null, [0, -1]));
        $context->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));

        return $context;
    }
}
