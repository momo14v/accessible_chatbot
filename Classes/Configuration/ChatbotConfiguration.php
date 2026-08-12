<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Configuration;

/**
 * Die fertig aufgeloesten Betriebs-Einstellungen (Konzept Abschnitt 5).
 *
 * Ein unveraenderliches Objekt statt eines rohen Arrays: dadurch sind alle
 * Werte typisiert, und ein Tippfehler im Schluesselnamen faellt sofort auf.
 */
final readonly class ChatbotConfiguration
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $provider,
        public string $model,
        public string $apiBaseUrl,
        public int $rateLimitPerMinute,
        public int $rateLimitPerDay,
        public int $rateLimitGlobalPerDay,
    ) {}

    /**
     * Kann damit ueberhaupt eine KI angesprochen werden?
     */
    public function isUsable(): bool
    {
        return $this->apiKey !== '' && $this->model !== '' && $this->provider === 'gemini';
    }
}
