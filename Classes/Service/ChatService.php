<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Service;

use Extension14v\AccessibleChatbot\Ai\AiProviderException;
use Extension14v\AccessibleChatbot\Ai\AiProviderInterface;
use Extension14v\AccessibleChatbot\Ai\AiResult;
use Extension14v\AccessibleChatbot\Ai\ChatMessage;
use Extension14v\AccessibleChatbot\Ai\ChatRole;
use Extension14v\AccessibleChatbot\Ai\ProviderOptions;
use Extension14v\AccessibleChatbot\Configuration\ConfigurationProvider;
use Extension14v\AccessibleChatbot\Http\ChatRequestPayload;
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

    public function __construct(
        private readonly ConfigurationProvider $configurationProvider,
        private readonly PromptBuilder $promptBuilder,
        private readonly AiProviderInterface $provider,
    ) {}

    /**
     * @throws AiProviderException
     */
    public function reply(ChatRequestPayload $payload, Site $site, SiteLanguage $language): AiResult
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

        return $this->provider->chat(
            $this->promptBuilder->build($site, $language, $payload->genderStyle),
            $messages,
            new ProviderOptions(
                $configuration->apiKey,
                $configuration->model,
                $configuration->apiBaseUrl,
                self::TIMEOUT_SECONDS,
            ),
        );
    }
}
