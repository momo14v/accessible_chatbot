<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Http;

use Extension14v\AccessibleChatbot\Ai\ChatMessage;
use Extension14v\AccessibleChatbot\Ai\ChatRole;

/**
 * Geprueftes Abbild dessen, was das Browser-Widget schickt (Konzept 4.2).
 *
 * Der Konstruktor ist privat: ein Objekt dieser Klasse kann nur durch
 * fromJson() entstehen, also nur nach vollstaendiger Pruefung.
 */
final readonly class ChatRequestPayload
{
    public const MAX_BODY_BYTES = 200000;
    public const MAX_MESSAGE_LENGTH = 1000;
    public const MAX_HISTORY_ENTRIES = 10;
    public const MAX_HISTORY_ENTRY_LENGTH = 2000;

    /**
     * @param ChatMessage[] $history
     */
    private function __construct(
        public string $message,
        public array $history,
        public int $pageUid,
        public int $languageUid,
        public GenderStyle $genderStyle,
    ) {}

    /**
     * @throws InvalidChatRequestException
     */
    public static function fromJson(string $body): self
    {
        // Erste, billigste Huerde: gar kein oder ein absurd grosser Rumpf.
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidChatRequestException('Request body is empty or too large', 1755000001);
        }

        // Tiefe 8 begrenzt tief verschachtelte Strukturen.
        $data = json_decode($body, true, 8);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new InvalidChatRequestException('Request body is not valid JSON', 1755000002);
        }

        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
        // mb_strlen zaehlt Zeichen, nicht Bytes - sonst waeren Umlaute
        // unterschiedlich "teuer".
        if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new InvalidChatRequestException('Message is empty or too long', 1755000003);
        }

        $rawHistory = $data['history'] ?? [];
        if (!is_array($rawHistory) || count($rawHistory) > self::MAX_HISTORY_ENTRIES) {
            throw new InvalidChatRequestException('History is missing, malformed or too long', 1755000004);
        }

        $history = [];
        foreach ($rawHistory as $entry) {
            if (!is_array($entry)) {
                throw new InvalidChatRequestException('History entry is malformed', 1755000005);
            }

            $role = ChatRole::tryFrom(is_string($entry['role'] ?? null) ? $entry['role'] : '');
            $text = is_string($entry['text'] ?? null) ? trim($entry['text']) : '';

            if ($role === null || $text === '' || mb_strlen($text) > self::MAX_HISTORY_ENTRY_LENGTH) {
                throw new InvalidChatRequestException('History entry is malformed or too long', 1755000006);
            }

            $history[] = new ChatMessage($role, $text);
        }

        return new self(
            $message,
            $history,
            self::positiveInt($data['pageUid'] ?? null),
            self::positiveInt($data['languageUid'] ?? null),
            GenderStyle::fromInput($data['genderStyle'] ?? null),
        );
    }

    private static function positiveInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }
}
