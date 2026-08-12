<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Anbindung an die Google-Gemini-API (Konzept 6).
 *
 * Die einzige Klasse, die das Datenformat von Gemini kennt.
 * Aufruf ueber TYPO3s RequestFactory - kein zusaetzliches PHP-Paket.
 */
final class GeminiProvider implements AiProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {}

    public function chat(string $systemPrompt, array $messages, ProviderOptions $options): AiResult
    {
        $response = $this->call($systemPrompt, $messages, $options, true);
        $status = $response->getStatusCode();

        // Nicht jedes Modell erlaubt es, das interne "Nachdenken" abzuschalten.
        // Lehnt die API die Anfrage genau deshalb ab, wird sie einmal ohne
        // diese Angabe wiederholt. Sonst wuerde ein im Backend geaenderter
        // Modellname den Chat komplett lahmlegen.
        if ($status === 400) {
            // Grund der Ablehnung ansehen, statt blind zu wiederholen.
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

            $response = $this->call($systemPrompt, $messages, $options, false);
            $status = $response->getStatusCode();
        }

        if ($status !== 200) {
            throw new AiProviderException(
                'AI service answered with HTTP status ' . $status,
                1755000203,
                match (true) {
                    // 429 = Kontingent erschoepft, spaeter erneut versuchen
                    $status === 429 => 'error.busy',
                    // 401/403 = Schluessel fehlt, falsch oder ohne Rechte
                    $status === 401 || $status === 403 => 'error.notconfigured',
                    // 400 = Anfrage kaputt, 5xx = Stoerung beim Anbieter
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
    private function call(string $systemPrompt, array $messages, ProviderOptions $options, bool $disableThinking): ResponseInterface
    {
        $generationConfig = [
            // Structured Output: Gemini liefert damit garantiert JSON
            // in genau dieser Form - kein Herumraten beim Parsen.
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'OBJECT',
                'properties' => [
                    'reply' => ['type' => 'STRING'],
                    'action' => [
                        'type' => 'STRING',
                        'enum' => ['answer', 'navigate', 'clarify'],
                    ],
                    'target_page_uid' => ['type' => 'INTEGER'],
                ],
                'required' => ['reply', 'action'],
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
            // "thinkingLevel" einmal wegfallen, faengt das der einmalige
            // Wiederholungsversuch in chat() ab.
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
                    // Der Wiederholungsversuch bekommt weniger Zeit, damit
                    // beide Aufrufe zusammen unter dem Zeitlimit des Browsers
                    // (35 s) bleiben.
                    'timeout' => $disableThinking ? $options->timeoutSeconds : max(10, $options->timeoutSeconds - 15),
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
            if (is_string($candidate) && preg_match('/^[A-Z_]{1,40}$/', $candidate) === 1) {
                return $candidate;
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
        );
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
