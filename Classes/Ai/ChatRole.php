<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

/**
 * Wer hat eine Nachricht geschrieben.
 *
 * Die Werte sind bewusst identisch mit denen im Browser-Verlauf
 * (sessionStorage), damit die Umwandlung ohne Sonderfaelle auskommt.
 * Die Uebersetzung in die Rollennamen des jeweiligen KI-Anbieters
 * (Gemini: "user" / "model") passiert im Provider - nur dort.
 */
enum ChatRole: string
{
    case User = 'user';
    case Bot = 'bot';
}
