<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

/**
 * Das Ergebnis eines KI-Aufrufs (Konzept 6.1).
 *
 * ACHTUNG: $targetPageUid ist UNVALIDIERT. Die Pruefung gegen die Whitelist
 * erlaubter Seiten passiert ab Phase 5 im ChatService - niemals hier.
 * $sourcePageUids ist ebenso ungeprueft.
 */
final readonly class AiResult
{
    /**
     * @param list<int> $sourcePageUids Von der KI genannte Quellseiten -
     *        ebenfalls UNVALIDIERT. Der ChatService laesst nur UIDs durch,
     *        die in genau dieser Anfrage an die KI geliefert wurden.
     * @param bool $answerFound Von "source_page_uids" unabhaengiges Feld
     *        (Review Phase 4, S4): true, wenn die KI angibt, dass das
     *        gelieferte Website-Material die Antwort wirklich enthaelt.
     *        Der Standardwert true gilt fuer den Parse-Fallback (Konzept
     *        6.3: eine unstrukturierte Rohantwort) - lieber KEINEN
     *        Kontakt-Hinweis zeigen als einen falschen.
     */
    public function __construct(
        public string $reply,
        public ChatAction $action = ChatAction::Answer,
        public ?int $targetPageUid = null,
        public array $sourcePageUids = [],
        public bool $answerFound = true,
    ) {}
}
