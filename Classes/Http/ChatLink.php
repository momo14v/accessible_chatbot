<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Http;

/**
 * Ein fertiger Link fuer die Antwort im Chat.
 *
 * $url wird AUSSCHLIESSLICH vom TYPO3-Site-Router erzeugt (ChatService),
 * niemals von der KI und niemals vom Browser. $title ist der Seitentitel
 * aus dem Inhaltsindex - er wird im Browser ueber textContent gesetzt und
 * kann deshalb kein Markup einschleusen.
 */
final readonly class ChatLink
{
    public function __construct(
        public string $url,
        public string $title,
    ) {}
}
