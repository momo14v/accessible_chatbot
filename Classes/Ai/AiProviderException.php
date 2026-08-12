<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

/**
 * Stoerung beim KI-Aufruf.
 *
 * Zwei getrennte Texte, absichtlich:
 * - getMessage() ist technisch und darf protokolliert werden. Es duerfen
 *   deshalb NUR feste Zeichenketten und HTTP-Statuscodes hineingeschrieben
 *   werden, niemals Nachrichteninhalte (Konzept 3.6 / 6.1).
 * - $userMessageKey ist der Schluessel des freundlichen Textes aus
 *   locallang.xlf, den der Besucher zu sehen bekommt.
 */
final class AiProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code,
        public readonly string $userMessageKey = 'error.unavailable',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
