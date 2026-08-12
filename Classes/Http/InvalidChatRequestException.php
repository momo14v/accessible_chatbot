<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Http;

/**
 * Die Anfrage des Browsers ist formal falsch (Konzept 4.2) -> HTTP 400.
 *
 * Die Meldung beschreibt NUR den Regelverstoss, niemals den Inhalt der
 * Nachricht. Nur so darf sie ins Log.
 */
final class InvalidChatRequestException extends \RuntimeException {}
