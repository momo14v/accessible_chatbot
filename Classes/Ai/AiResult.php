<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

/**
 * Das Ergebnis eines KI-Aufrufs (Konzept 6.1).
 *
 * ACHTUNG: $targetPageUid ist UNVALIDIERT. Die Pruefung gegen die Whitelist
 * erlaubter Seiten passiert ab Phase 5 im ChatService - niemals hier.
 */
final readonly class AiResult
{
    public function __construct(
        public string $reply,
        public ChatAction $action = ChatAction::Answer,
        public ?int $targetPageUid = null,
    ) {}
}
