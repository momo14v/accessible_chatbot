<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Http;

use Extension14v\AccessibleChatbot\Ai\ChatAction;

/**
 * Die fertige, serverseitig gepruefte Antwort an das Widget.
 *
 * Bewusst NICHT AiResult: AiResult ist das ungepruefte Rohergebnis der KI.
 * Alles in dieser Klasse hat die serverseitige Pruefung durchlaufen -
 * insbesondere sind die Links echte, vom TYPO3-Router erzeugte Adressen zu
 * Seiten, die in genau dieser Anfrage an die KI geliefert wurden.
 */
final readonly class ChatReply
{
    /**
     * @param list<ChatLink> $sources Quellseiten der Antwort
     * @param bool $suggestContact true = das Widget darf zusaetzlich die
     *             Kontaktseite anbieten ("weiss ich nicht", Konzept 4.5)
     */
    public function __construct(
        public string $reply,
        public ChatAction $action,
        public ?int $targetPageUid,
        public array $sources,
        public bool $suggestContact,
    ) {}
}
