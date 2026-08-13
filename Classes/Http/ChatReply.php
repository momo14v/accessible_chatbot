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
     * @param ChatLink|null $navigation Geprueftes Navigationsangebot
     *        (Konzept 4.5). null = kein Angebot. Die Adresse stammt IMMER vom
     *        TYPO3-Site-Router, der Titel aus dem Inhaltsindex - beides nie
     *        aus dem Text der KI.
     * @param list<string> $choices Auswahltitel einer Rueckfrage
     *        (action = clarify). Reine Seitentitel aus dem Index. Das Widget
     *        macht daraus Knoepfe, die eine Praezisierung SENDEN - sie
     *        navigieren nicht (WCAG 3.2.2 On Input).
     * @param bool $suggestContact true = das Widget darf zusaetzlich die
     *             Kontaktseite anbieten ("weiss ich nicht", Konzept 4.5)
     */
    public function __construct(
        public string $reply,
        public ChatAction $action,
        public ?ChatLink $navigation,
        public array $sources,
        public array $choices,
        public bool $suggestContact,
    ) {}
}
