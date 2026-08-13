<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Service;

use Extension14v\AccessibleChatbot\Ai\AiProviderException;
use Extension14v\AccessibleChatbot\Ai\AiProviderInterface;
use Extension14v\AccessibleChatbot\Ai\AiResult;
use Extension14v\AccessibleChatbot\Ai\ChatAction;
use Extension14v\AccessibleChatbot\Ai\ChatMessage;
use Extension14v\AccessibleChatbot\Ai\ChatRole;
use Extension14v\AccessibleChatbot\Ai\ProviderOptions;
use Extension14v\AccessibleChatbot\Configuration\ConfigurationProvider;
use Extension14v\AccessibleChatbot\Http\ChatLink;
use Extension14v\AccessibleChatbot\Http\ChatReply;
use Extension14v\AccessibleChatbot\Http\ChatRequestPayload;
use Extension14v\AccessibleChatbot\Retrieval\RetrievalResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\Channel;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Orchestrator: verbindet Konfiguration, Prompt und KI-Anbieter.
 *
 * Diese Klasse kennt weder HTTP noch Gemini. Genau deshalb muss sie beim
 * Wechsel des Anbieters (Phase spaeter) nicht angefasst werden.
 */
final class ChatService
{
    private const TIMEOUT_SECONDS = 30;

    /** Konzept 4.5: hoechstens drei Quellseiten, sonst wird die Antwort unuebersichtlich. */
    private const MAX_SOURCE_LINKS = 3;

    /** Rueckfrage: wenige, klare Optionen (COGA 4.5). */
    private const MAX_CLARIFY_CHOICES = 3;

    public function __construct(
        private readonly ConfigurationProvider $configurationProvider,
        private readonly PromptBuilder $promptBuilder,
        private readonly AiProviderInterface $provider,
        private readonly RetrievalService $retrievalService,
        #[Channel('accessible_chatbot')]
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws AiProviderException
     */
    public function reply(ChatRequestPayload $payload, Site $site, SiteLanguage $language): ChatReply
    {
        $configuration = $this->configurationProvider->get();

        if (!$configuration->isUsable()) {
            // Bewusst ohne Details: die Meldung landet im Log.
            throw new AiProviderException(
                'Extension configuration is incomplete (API key, model or provider missing)',
                1755000101,
                'error.notconfigured'
            );
        }

        // Website-Wissen holen, BEVOR die KI gefragt wird. Was hier nicht
        // gefunden wird, kann die KI nicht kennen (Konzept 3.3).
        $retrieval = $this->retrievalService->retrieve($payload->message, $site, $language);

        $messages = $payload->history;

        // Gemini erwartet, dass der Verlauf mit einer Nutzernachricht beginnt.
        // Faengt er mit einer Bot-Nachricht an, wird sie verworfen.
        while ($messages !== [] && $messages[0]->role !== ChatRole::User) {
            array_shift($messages);
        }

        $messages[] = new ChatMessage(ChatRole::User, $payload->message);

        // Scheitert eine Antwort, steht im Browser-Verlauf danach zweimal
        // hintereinander eine Nutzernachricht. Aufeinanderfolgende gleiche
        // Rollen werden deshalb zu einem Zug zusammengefasst.
        $normalised = [];
        foreach ($messages as $message) {
            $last = $normalised === [] ? null : $normalised[array_key_last($normalised)];

            if ($last !== null && $last->role === $message->role) {
                $normalised[array_key_last($normalised)] = new ChatMessage(
                    $last->role,
                    $last->text . "\n\n" . $message->text
                );

                continue;
            }

            $normalised[] = $message;
        }
        $messages = $normalised;

        $result = $this->provider->chat(
            $this->promptBuilder->build($site, $language, $payload->genderStyle, $retrieval),
            $messages,
            new ProviderOptions(
                $configuration->apiKey,
                $configuration->model,
                $configuration->apiBaseUrl,
                self::TIMEOUT_SECONDS,
            ),
        );

        // Navigationsangebot (Konzept 4.5). Geprueft wird gegen GENAU DIE
        // Seiten, die in dieser Anfrage an die KI gegangen sind.
        $navigation = $this->navigationTarget($result, $retrieval, $site, $language);

        // Bei einer Rueckfrage ersetzen die Auswahlknoepfe die Quellenlinks.
        // Ein Link wuerde die Seite wechseln; der Knopf schickt nur eine
        // Praezisierung (WCAG 3.2.2). Beides gleichzeitig waere widerspruechlich.
        $isClarify = $result->action === ChatAction::Clarify;
        $sources = $isClarify ? [] : $this->sourceLinks($result, $retrieval, $site, $language);
        $choices = $isClarify ? $this->clarifyChoices($result, $retrieval) : [];

        // Ein Navigationsangebot ersetzt den Quellenlink auf dieselbe Seite -
        // zwei Links auf dasselbe Ziel waeren fuer Screenreader-Nutzende
        // doppelt und verwirrend (gleiche Ueberlegung wie bei clarify).
        if ($navigation !== null) {
            $sources = array_values(array_filter(
                $sources,
                static fn (ChatLink $link): bool => $link->url !== $navigation->url
            ));
        }

        // Downgrade (Konzept 4.5): ein ungueltiges Ziel ist KEIN Fehler.
        // Der Nutzer bekommt dann eine ganz normale Textantwort.
        $downgraded = $result->action === ChatAction::Navigate && $navigation === null;

        // Eine Rueckfrage ohne Auswahlmoeglichkeiten ist ebenfalls eine
        // Sackgasse: "Meinst du X oder Y?" ohne Knoepfe. Passiert in der
        // dritten Rueckfallstufe, in der die KI gar keine Seiten-UIDs
        // liefert. Wird deshalb genauso behandelt.
        $emptyClarify = $isClarify && $choices === [];

        $action = $downgraded || $emptyClarify ? ChatAction::Answer : $result->action;

        return new ChatReply(
            $result->reply,
            $action,
            $navigation,
            $sources,
            $choices,
            // "Weiss ich nicht" (Review Phase 4, S4): entscheidend ist
            // ausschliesslich "answer_found", NICHT die Quellenliste. In
            // Rueckfallstufe 3 und im Parse-Fallback ist $sources IMMER leer -
            // waere die Kontaktseite an $sources gekoppelt, bekaeme dort jede
            // Antwort faelschlich den "weiss ich nicht"-Hinweis.
            //
            // Zusaetzlich seit dem Phase-6-Review: bei einem abgewiesenen
            // Navigationsziel und bei einer Rueckfrage ohne Auswahl kuendigt
            // der Antworttext einen Weg an, den es nicht gibt. Dann MUSS
            // wenigstens die Kontaktseite angeboten werden - sonst steht der
            // Nutzer vor einer Sackgasse (Konzept 3.5, 8.6).
            $downgraded
                || $emptyClarify
                || (!$result->answerFound && $result->action === ChatAction::Answer),
        );
    }

    /**
     * Macht aus den von der KI genannten Seiten-UIDs echte Links.
     *
     * DAS IST DIE SICHERHEITSSTELLE dieser Phase: eine UID wird nur dann
     * verlinkt, wenn sie in GENAU DIESER Anfrage als Treffer an die KI
     * geliefert wurde. Alles andere - erfundene UIDs, UIDs aus einer
     * Prompt-Injection, UIDs versteckter Seiten - faellt hier lautlos raus.
     *
     * @return list<ChatLink>
     */
    private function sourceLinks(
        AiResult $result,
        RetrievalResult $retrieval,
        Site $site,
        SiteLanguage $language
    ): array {
        $allowed = [];
        foreach ($retrieval->hits as $hit) {
            $allowed[$hit->pageUid] = $hit->title;
        }

        $links = [];
        foreach ($result->sourcePageUids as $pageUid) {
            if (!isset($allowed[$pageUid])) {
                continue;
            }

            $url = $this->pageUrl($site, $language, $pageUid);
            if ($url === null) {
                continue;
            }

            $links[] = new ChatLink($url, $allowed[$pageUid]);

            if (count($links) >= self::MAX_SOURCE_LINKS) {
                break;
            }
        }

        return $links;
    }

    /**
     * Prueft ein von der KI vorgeschlagenes Navigationsziel (Konzept 4.5, 7).
     *
     * DAS IST DIE SICHERHEITSSTELLE dieser Phase. Erlaubt ist eine UID nur,
     * wenn sie in GENAU DIESER Anfrage an die KI gegangen ist - als Treffer
     * oder als Zeile der Seitenliste. Beide Mengen hat der RetrievalService
     * vorher live gegen die aktuelle Sichtbarkeit geprueft; sie enthalten
     * damit ausschliesslich Seiten, die der Besucher auch selbst erreichen
     * koennte. Erfundene UIDs, UIDs aus einer Prompt-Injection und UIDs
     * versteckter, abgelaufener oder geschuetzter Seiten fallen hier raus.
     *
     * Die Adresse baut ausschliesslich der TYPO3-Site-Router - eine von der
     * KI gelieferte Zeichenkette wird nie als Adresse verwendet.
     */
    private function navigationTarget(
        AiResult $result,
        RetrievalResult $retrieval,
        Site $site,
        SiteLanguage $language
    ): ?ChatLink {
        if ($result->action !== ChatAction::Navigate || $result->targetPageUid === null) {
            return null;
        }

        $allowed = self::allowedPages($retrieval);

        if (!isset($allowed[$result->targetPageUid])) {
            // Nur die Seiten-UID, niemals Nachrichteninhalte (Konzept 3.6).
            // Ohne dieses Protokoll wuerde ein dauerhaft falsch vorschlagendes
            // Modell nie auffallen.
            $this->logger->warning(
                'Navigation target rejected: page {page} was not delivered to the AI in this request.',
                ['page' => $result->targetPageUid]
            );

            return null;
        }

        $url = $this->pageUrl($site, $language, $result->targetPageUid);

        return $url === null ? null : new ChatLink($url, $allowed[$result->targetPageUid]);
    }

    /**
     * Auswahlmoeglichkeiten einer Rueckfrage (Konzept 4.5, "Mehrdeutigkeit").
     *
     * Bewusst nur TITEL und keine Adressen: die Knoepfe senden eine
     * Praezisierung, sie navigieren nicht. Der Titel kommt aus dem
     * Inhaltsindex - der von der KI gelieferte Text landet NIE auf einem
     * Knopf. Gleiche Titel werden ausgelassen, sonst staenden zwei nicht
     * unterscheidbare Knoepfe nebeneinander.
     *
     * @return list<string>
     */
    private function clarifyChoices(AiResult $result, RetrievalResult $retrieval): array
    {
        $allowed = self::allowedPages($retrieval);

        $choices = [];
        foreach ($result->sourcePageUids as $pageUid) {
            $title = $allowed[$pageUid] ?? '';

            if ($title === '' || in_array($title, $choices, true)) {
                continue;
            }

            $choices[] = $title;

            if (count($choices) >= self::MAX_CLARIFY_CHOICES) {
                break;
            }
        }

        return $choices;
    }

    /**
     * Die Whitelist dieser einen Anfrage: Trefferliste UND Seitenliste
     * (Konzept 4.5). Treffer stehen bewusst zuletzt - bei gleicher UID
     * gewinnt ihr Titel.
     *
     * BEWUSSTE, DOKUMENTIERTE UNSCHAERFE: der PromptBuilder kann die
     * Seitenliste am Zeichenbudget kappen; einzelne Eintraege von hier sind
     * dann gar nicht bei der KI angekommen. Sicherheitsrelevant ist das
     * nicht - jeder Eintrag hat die Live-Sichtbarkeitspruefung bestanden und
     * ist damit eine Seite, die der Besucher selbst erreichen koennte. Die
     * exakt gelieferte Menge zurueckzureichen waere Mehraufwand ohne
     * Sicherheitsgewinn.
     *
     * @return array<int, string> Seiten-UID => Titel
     */
    private static function allowedPages(RetrievalResult $retrieval): array
    {
        $allowed = [];

        foreach ($retrieval->sitemap as $entry) {
            $allowed[$entry->pageUid] = $entry->title;
        }

        foreach ($retrieval->hits as $hit) {
            $allowed[$hit->pageUid] = $hit->title;
        }

        return $allowed;
    }

    /**
     * Erzeugt die Adresse einer Seite ueber TYPO3s Site-Router.
     *
     * Niemals selbst zusammenbauen: der Router kennt Slugs, Sprach-Praefixe
     * und Route-Enhancer.
     */
    private function pageUrl(Site $site, SiteLanguage $language, int $pageUid): ?string
    {
        try {
            return (string)$site->getRouter()->generateUri($pageUid, ['_language' => $language]);
        } catch (\Throwable $exception) {
            // Nur Seiten-UID und Ausnahmeklasse - niemals Nachrichteninhalte.
            $this->logger->warning('Could not build a URL for page {page}: {class}', [
                'page' => $pageUid,
                'class' => $exception::class,
            ]);

            return null;
        }
    }
}
