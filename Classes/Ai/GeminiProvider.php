<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\Channel;

/**
 * Anbindung an die Google-Gemini-API (Konzept 6).
 *
 * Die einzige Klasse, die das Datenformat von Gemini kennt.
 * Aufruf ueber TYPO3s RequestFactory - kein zusaetzliches PHP-Paket.
 */
final class GeminiProvider implements AiProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Gestufte Rueckfallkette: jede Stufe verzichtet auf einen weiteren,
     * nicht zwingend benoetigten Bestandteil der Anfrage. So bleibt der Chat
     * auch dann nutzbar, wenn ein Modellwechsel einen dieser Bestandteile
     * nicht mehr erlaubt - notfalls eben ohne Quellenlinks.
     *
     * @var list<array{disableThinking: bool, includeSourceUids: bool}>
     */
    private const FALLBACK_ATTEMPTS = [
        ['disableThinking' => true, 'includeSourceUids' => true],
        ['disableThinking' => false, 'includeSourceUids' => true],
        ['disableThinking' => false, 'includeSourceUids' => false],
    ];

    public function __construct(
        private readonly RequestFactory $requestFactory,
        #[Channel('accessible_chatbot')]
        private readonly LoggerInterface $logger,
    ) {}

    public function chat(string $systemPrompt, array $messages, ProviderOptions $options): AiResult
    {
        $response = null;
        $status = null;

        // Review Phase 4, F4: EIN gemeinsames Zeitbudget fuer die gesamte
        // Rueckfallkette. Sonst koennten drei Versuche zusammen laenger
        // dauern als der Browser wartet (35 s), waehrend der PHP-Prozess
        // trotzdem weiterlaeuft und Kontingent verbraucht.
        $deadline = time() + $options->timeoutSeconds;
        $lastAttemptIndex = count(self::FALLBACK_ATTEMPTS) - 1;

        // Nicht jedes Modell erlaubt es, das interne "Nachdenken" abzuschalten,
        // und nicht jedes Modell erlaubt "source_page_uids" im Schema. Lehnt
        // die API eine Stufe deshalb mit HTTP 400 ab, wird die naechste,
        // abgespeckte Stufe versucht. Sonst wuerde ein im Backend geaenderter
        // Modellname den Chat komplett lahmlegen.
        foreach (self::FALLBACK_ATTEMPTS as $index => $attempt) {
            $remaining = $deadline - time();
            if ($remaining < 5) {
                break;
            }

            $response = $this->call(
                $systemPrompt,
                $messages,
                $options,
                $attempt['disableThinking'],
                $attempt['includeSourceUids'],
                $remaining
            );
            $status = $response->getStatusCode();

            if ($status !== 400) {
                break;
            }

            // Grund der Ablehnung ansehen, statt blind weiterzuprobieren.
            // Ausgelesen wird NUR das feste Statuswort der API - niemals
            // Freitext und niemals Nachrichteninhalte.
            $reason = $this->errorReason((string)$response->getBody());

            if ($reason === 'API_KEY_INVALID') {
                throw new AiProviderException(
                    'AI service rejected the API key (HTTP 400, API_KEY_INVALID)',
                    1755000207,
                    'error.notconfigured'
                );
            }

            // Review Phase 4, V5: bei der letzten Stufe gibt es keine weitere
            // Stufe mehr, auf die "retrying" sich sinnvoll beziehen koennte.
            if ($index < $lastAttemptIndex) {
                // Review Phase 4, S5: eine dauerhaft abgelehnte Stufe kostet ab
                // sofort JEDE Chat-Nachricht zwei bis drei API-Aufrufe statt
                // einem - ohne dieses Protokoll wuerde das nie auffallen.
                $this->logger->warning(
                    'AI request rejected with HTTP 400 (reason: {reason}); retrying without {dropped}.',
                    [
                        'reason' => $reason !== '' ? $reason : 'unknown',
                        'dropped' => $attempt['disableThinking'] ? 'thinkingConfig' : 'source_page_uids',
                    ]
                );
            }

            // Sonst: naechste, abgespeckte Stufe versuchen.
        }

        // $response/$status bleiben null, wenn die foreach-Schleife oben
        // schon bei der ERSTEN Stufe wegen "$remaining < 5" abbricht (bei
        // einem sehr knapp konfigurierten Zeitbudget). Heute unerreichbar,
        // weil ChatService::TIMEOUT_SECONDS bei 30 liegt - aber ein Wechsel
        // dieser Konstante darf hier nicht in einem Null-Dereferenzierungs-
        // fehler enden.
        if ($response === null || $status !== 200) {
            throw new AiProviderException(
                'AI service answered with HTTP status ' . ($status ?? 'none'),
                1755000203,
                match (true) {
                    // 429 = Kontingent erschoepft, spaeter erneut versuchen
                    $status === 429 => 'error.busy',
                    // 401/403 = Schluessel fehlt, falsch oder ohne Rechte
                    $status === 401 || $status === 403 => 'error.notconfigured',
                    // 400 = Anfrage kaputt, 5xx = Stoerung beim Anbieter,
                    // null = keine Anfrage lief ueberhaupt
                    default => 'error.unavailable',
                }
            );
        }

        return $this->parse((string)$response->getBody());
    }

    /**
     * Schickt genau eine Anfrage an die API.
     *
     * @param ChatMessage[] $messages
     * @throws AiProviderException bei Netzwerkfehlern und Zeitueberschreitung
     */
    private function call(
        string $systemPrompt,
        array $messages,
        ProviderOptions $options,
        bool $disableThinking,
        bool $includeSourceUids,
        int $timeoutSeconds
    ): ResponseInterface {
        $properties = [
            'reply' => ['type' => 'STRING'],
            'action' => [
                'type' => 'STRING',
                'enum' => ['answer', 'navigate', 'clarify'],
            ],
            // Review Phase 4, S4: unabhaengig von "source_page_uids" und
            // deshalb IMMER Teil des Schemas, auch im abgespeckten
            // Rueckfallmodus ohne Quellenangaben - sonst waere "weiss ich
            // nicht" in diesem Modus faelschlich an eine leere Quellenliste
            // gekoppelt.
            'answer_found' => ['type' => 'BOOLEAN'],
            'target_page_uid' => ['type' => 'INTEGER'],
        ];
        $required = ['reply', 'action', 'answer_found'];

        if ($includeSourceUids) {
            // Quellseiten der Antwort (Konzept 4.5: Link zur Quellseite statt
            // automatischer Navigation).
            $properties['source_page_uids'] = [
                'type' => 'ARRAY',
                'items' => ['type' => 'INTEGER'],
            ];
            // Pflicht, damit das Modell den Schluessel immer liefert -
            // notfalls als leere Liste.
            $required[] = 'source_page_uids';
        }

        $generationConfig = [
            // Structured Output: Gemini liefert damit garantiert JSON
            // in genau dieser Form - kein Herumraten beim Parsen.
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'OBJECT',
                'properties' => $properties,
                'required' => $required,
            ],
            // Niedrige Temperatur = weniger Fantasie, mehr Regeltreue.
            'temperature' => 0.3,
            'maxOutputTokens' => 4096,
        ];

        if ($disableThinking) {
            // Gemini-Modelle "denken" vor der Antwort, und diese Denk-Tokens
            // zaehlen gegen maxOutputTokens. Ohne diese Angabe kann das Budget
            // beim Denken aufgebraucht sein und die eigentliche Antwort leer
            // bleiben (finishReason MAX_TOKENS). Fuer kurze, regelgebundene
            // Antworten wird kein Denken gebraucht - das halbiert ausserdem
            // die Antwortzeit.
            //
            // Achtung, der Parametername hat sich geaendert: der frueher
            // uebliche "thinkingBudget" wird von der aktuellen
            // Modellgeneration mit HTTP 400 abgelehnt. Sollte auch
            // "thinkingLevel" einmal wegfallen, faengt das die
            // Rueckfallkette in chat() ab.
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => 'minimal'];
        }

        $payload = [
            // Der Systemprompt gehoert in ein eigenes Feld, nicht in "contents":
            // dadurch behandelt das Modell ihn als Regelwerk, nicht als Gespraech.
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => array_map(
                static fn(ChatMessage $message): array => [
                    // Gemini nennt die Bot-Rolle "model", nicht "assistant".
                    'role' => $message->role === ChatRole::User ? 'user' : 'model',
                    'parts' => [['text' => $message->text]],
                ],
                $messages
            ),
            'generationConfig' => $generationConfig,
        ];

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            throw new AiProviderException('Request payload could not be encoded as JSON', 1755000201, 'error.unavailable');
        }

        try {
            return $this->requestFactory->request(
                $this->endpoint($options),
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        // Der Schluessel gehoert in den Header, NICHT in die URL:
                        // URLs landen in Proxy- und Serverlogs.
                        'x-goog-api-key' => $options->apiKey,
                    ],
                    'body' => $body,
                    // Gemeinsames Zeitbudget der Rueckfallkette (siehe chat()) -
                    // damit bleiben alle Versuche zusammen innerhalb des
                    // Zeitlimits des Browsers (35 s).
                    'timeout' => $timeoutSeconds,
                    'connect_timeout' => 10,
                    // Statuscodes selbst auswerten statt Ausnahmen zu fangen.
                    'http_errors' => false,
                ],
                // Eigener HTTP-Kontext: Betreiber koennen ueber
                // $GLOBALS['TYPO3_CONF_VARS']['HTTP']['allowed_hosts']['accessible_chatbot']
                // festlegen, welche Zielhosts dieser Aufruf ansprechen darf
                // (Schutz gegen serverseitige Anfragefaelschung). Proxy- und
                // Zertifikatsoptionen sind davon unberuehrt und gelten global.
                'accessible_chatbot'
            );
        } catch (ClientExceptionInterface) {
            // Netzwerkfehler oder Zeitueberschreitung. Die urspruengliche
            // Ausnahme wird BEWUSST nicht weitergereicht: ihr Stacktrace
            // koennte Nachrichtentexte enthalten.
            throw new AiProviderException('HTTP request to the AI service failed', 1755000202, 'error.unavailable');
        }
    }

    /**
     * Liest nur das feste Statuswort aus einer Fehlerantwort
     * ("INVALID_ARGUMENT", "API_KEY_INVALID", ...). Freitexte werden
     * bewusst NICHT ausgewertet - sie koennten Inhalte enthalten und
     * duerfen deshalb nicht ins Log.
     */
    private function errorReason(string $rawBody): string
    {
        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return '';
        }

        $candidates = [
            $data['error']['details'][0]['reason'] ?? null,
            $data['error']['status'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $word = self::statusWord($candidate);
            if ($word !== '') {
                return $word;
            }
        }

        return '';
    }

    /**
     * Laesst nur erwartbare Statuswoerter (GROSSBUCHSTABEN und Unterstrich)
     * durch. Alles andere wird verworfen, damit kein fremder Freitext ins
     * Log geraet.
     */
    private static function statusWord(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Z_]{1,40}$/', $value) === 1 ? $value : '';
    }

    private function parse(string $rawBody): AiResult
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new AiProviderException('AI service answered with a body that is not JSON', 1755000204, 'error.unavailable');
        }

        // Auch dieses Wort stammt von einem fremden Dienst und landet im Log -
        // deshalb nur ein erwartbares Statuswort durchlassen.
        $blockReason = self::statusWord($data['promptFeedback']['blockReason'] ?? null);
        $candidate = $data['candidates'][0] ?? null;

        if (!is_array($candidate)) {
            // Wichtiger Sonderfall: ein vom Sicherheitsfilter blockierter Prompt
            // liefert HTTP 200 MIT LEEREM candidates-Array.
            throw new AiProviderException(
                'AI service returned no candidate (blockReason: ' . ($blockReason !== '' ? $blockReason : 'unknown') . ')',
                1755000205,
                'error.blocked'
            );
        }

        $finishReason = self::statusWord($candidate['finishReason'] ?? null);

        $text = '';
        $parts = $candidate['content']['parts'] ?? [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }
        $text = trim($text);

        if ($text === '') {
            throw new AiProviderException(
                'AI service returned an empty answer (finishReason: ' . ($finishReason !== '' ? $finishReason : 'unknown') . ')',
                1755000206,
                $finishReason === 'SAFETY' ? 'error.blocked' : 'error.unavailable'
            );
        }

        $decoded = json_decode($text, true);
        if (!is_array($decoded) || !is_string($decoded['reply'] ?? null) || trim($decoded['reply']) === '') {
            // Konzept 6.3: ein Formatproblem darf beim Nutzer NIE als Fehler
            // ankommen - die Rohantwort wird als reine Textantwort behandelt.
            return new AiResult($text);
        }

        $targetPageUid = is_numeric($decoded['target_page_uid'] ?? null) ? (int)$decoded['target_page_uid'] : 0;

        return new AiResult(
            trim($decoded['reply']),
            ChatAction::tryFrom(is_string($decoded['action'] ?? null) ? $decoded['action'] : '') ?? ChatAction::Answer,
            $targetPageUid > 0 ? $targetPageUid : null,
            self::pageUidList($decoded['source_page_uids'] ?? null),
            // Fehlt das Feld oder ist es kein Bool (Modell haelt sich nicht
            // ans Schema), lieber KEINEN falschen "weiss ich nicht"-Hinweis
            // zeigen als einen unbegruendeten (Review Phase 4, S4).
            !is_bool($decoded['answer_found'] ?? null) || $decoded['answer_found'],
        );
    }

    /**
     * Liest eine Liste von Seiten-UIDs aus der KI-Antwort.
     *
     * Hier wird NUR die Form geprueft (ganze Zahlen groesser 0), nicht die
     * Berechtigung - die Whitelist-Pruefung gehoert in den ChatService.
     *
     * Review Phase 4, V3: es wird VOR der Zaehlpruefung dedupliziert. Sonst
     * wuerde z. B. [7,7,7,7,7,7,7,7,7,7,12] bei der alten Reihenfolge
     * (erst zehn kappen, dann deduplizieren) auf [7] statt auf [7, 12]
     * schrumpfen.
     *
     * @return list<int>
     */
    private static function pageUidList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $uids = [];
        foreach ($value as $entry) {
            if (!is_numeric($entry) || (int)$entry <= 0) {
                continue;
            }

            $uid = (int)$entry;
            if (in_array($uid, $uids, true)) {
                continue;
            }

            $uids[] = $uid;
            if (count($uids) >= 10) {
                break;
            }
        }

        return $uids;
    }

    private function endpoint(ProviderOptions $options): string
    {
        $baseUrl = $options->baseUrl !== '' ? rtrim($options->baseUrl, '/') : self::DEFAULT_BASE_URL;

        $model = ltrim(trim($options->model), '/');
        if (!str_starts_with($model, 'models/')) {
            $model = 'models/' . $model;
        }

        return $baseUrl . '/' . $model . ':generateContent';
    }
}
