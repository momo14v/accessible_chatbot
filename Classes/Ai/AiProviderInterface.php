<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

/**
 * Der Vertrag, den jeder KI-Anbieter erfuellen muss (Konzept 6.1).
 *
 * Absichtlich genau eine Methode: alles, was ein zweiter Anbieter
 * (z. B. ein spaeter selbst betriebener Server) anders macht, steckt in
 * seiner eigenen Klasse. ChatService, Middleware und Frontend bleiben gleich.
 */
interface AiProviderInterface
{
    /**
     * @param ChatMessage[] $messages Verlauf inklusive der aktuellen Frage, aelteste Nachricht zuerst
     * @throws AiProviderException bei jeder Stoerung - der Aufrufer macht daraus eine freundliche Meldung
     */
    public function chat(string $systemPrompt, array $messages, ProviderOptions $options): AiResult;
}
