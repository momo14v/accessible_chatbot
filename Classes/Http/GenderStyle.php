<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Http;

/**
 * Die vom Besucher gewaehlte Sprachform (Konzept 8.8).
 *
 * Die Reihenfolge der Faelle folgt der Empfehlung des DBSV (Deutscher
 * Blinden- und Sehbehindertenverband, Stand Maerz 2024):
 *
 * Neutral  = ohne Geschlechtsbezug formulieren ("das Team") - Erstempfehlung
 *            des DBSV und Standard dieser Extension.
 * Pair     = beide Formen ausschreiben ("Autorinnen und Autoren") -
 *            Zweitempfehlung des DBSV.
 * Asterisk = Kurzform mit Sternchen ("Autor*innen") - nur falls eine
 *            Kurzform gewuenscht ist.
 *
 * Der Doppelpunkt (vormals "Colon") ist bewusst entfallen: der DBSV raet
 * davon ab, weil er im Wortinneren eine Satzzeichenpause wie ein Punkt oder
 * Komma erzeugt, wodurch der Satz vom Screenreader vorzeitig als beendet
 * vorgelesen wird. Der Unterstrich entfaellt aus demselben Grund und war nie
 * eine eigene Option dieser Extension.
 */
enum GenderStyle: string
{
    case Neutral = 'neutral';
    case Pair = 'pair';
    case Asterisk = 'asterisk';

    /**
     * Nimmt beliebige Eingaben aus dem Browser entgegen und faellt im
     * Zweifel auf den Standard zurueck - eine falsche Sprachform ist
     * kein Grund, die Anfrage abzulehnen.
     */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Neutral) : self::Neutral;
    }
}
