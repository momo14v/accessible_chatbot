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

        $sources = $this->sourceLinks($result, $retrieval, $site, $language);

        return new ChatReply(
            $result->reply,
            $result->action,
            // Navigation wird erst in Phase 5 ausgewertet - der Wert wird
            // hier nur durchgereicht, nicht benutzt.
            $result->targetPageUid,
            $sources,
            // "Weiss ich nicht" (Review Phase 4, S4): entscheidend ist
            // ausschliesslich "answer_found", NICHT die Quellenliste. In
            // Rueckfallstufe 3 und im Parse-Fallback ist $sources IMMER
            // leer - waere die Kontaktseite an $sources gekoppelt, bekaeme
            // dort jede Antwort faelschlich den "weiss ich nicht"-Hinweis.
            !$result->answerFound && $result->action !== ChatAction::Clarify,
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
