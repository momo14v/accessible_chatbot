<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Http;

/**
 * Die vom Besucher gewaehlte Sprachform (Konzept 3.8).
 *
 * Pair  = beide Formen ausschreiben ("Autorinnen und Autoren") - Standard.
 * Colon = Kurzform mit Doppelpunkt ("Autor:innen").
 *
 * Die Auswahl der Formen wird in Phase 7 nach CONCEPT 8.8 umgebaut
 * (neutral als Default, Doppelpunkt entfaellt, Sternchen als Kurzform).
 */
enum GenderStyle: string
{
    case Pair = 'pair';
    case Colon = 'colon';

    /**
     * Nimmt beliebige Eingaben aus dem Browser entgegen und faellt im
     * Zweifel auf den Standard zurueck - eine falsche Sprachform ist
     * kein Grund, die Anfrage abzulehnen.
     */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Pair) : self::Pair;
    }
}
