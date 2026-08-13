<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Middleware;

use Extension14v\AccessibleChatbot\Ai\AiProviderException;
use Extension14v\AccessibleChatbot\Http\ChatLink;
use Extension14v\AccessibleChatbot\Http\ChatRequestPayload;
use Extension14v\AccessibleChatbot\Http\InvalidChatRequestException;
use Extension14v\AccessibleChatbot\Service\ChatService;
use Extension14v\AccessibleChatbot\Service\RateLimiter;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\Channel;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Der Chat-Endpunkt: POST /-/accessible-chatbot/message
 *
 * Eine PSR-15-Middleware ist eine Station in der Kette, die jede Anfrage
 * durchlaeuft. Passt die Anfrage nicht zu uns, reichen wir sie unveraendert
 * weiter - die Website verhaelt sich dann exakt wie ohne diese Extension.
 *
 * Protokolliert werden ausschliesslich Fehlertyp und HTTP-Status, NIEMALS
 * Nachrichteninhalte (Konzept 3.6). Deshalb wird auch nie das Ausnahme-Objekt
 * selbst geloggt: dessen Stacktrace enthaelt Funktionsargumente.
 *
 * Die Antworten werden ueber die PSR-17-Factories gebaut. TYPO3s
 * JsonResponse waere kuerzer, ist im Core aber als @internal markiert.
 */
final class ChatEndpointMiddleware implements MiddlewareInterface
{
    private const ENDPOINT_PATH = '/-/accessible-chatbot/message';
    private const LLL = 'LLL:EXT:accessible_chatbot/Resources/Private/Language/locallang.xlf:';

    /**
     * Die Flags entsprechen denen von TYPO3s JsonResponse, ergaenzt um
     * INVALID_UTF8_SUBSTITUTE: eine kaputte Zeichenkette aus der KI soll die
     * Antwort nicht komplett scheitern lassen.
     */
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private readonly ChatService $chatService,
        private readonly RateLimiter $rateLimiter,
        private readonly SiteFinder $siteFinder,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        #[Channel('accessible_chatbot')]
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // str_ends_with statt exaktem Vergleich: so funktioniert der Endpunkt
        // auch, wenn TYPO3 in einem Unterverzeichnis liegt oder die Site-Basis
        // einen Pfad enthaelt (z. B. https://example.org/en/).
        if ($request->getMethod() !== 'POST'
            || !str_ends_with(rtrim($request->getUri()->getPath(), '/'), self::ENDPOINT_PATH)
        ) {
            return $handler->handle($request);
        }

        $requestSite = $request->getAttribute('site');
        $language = $request->getAttribute('language');
        if (!$language instanceof SiteLanguage && $requestSite instanceof Site) {
            $language = $requestSite->getDefaultLanguage();
        }
        $language = $language instanceof SiteLanguage ? $language : null;

        // Der gesamte weitere Ablauf steht im try-Block. Auch die Pruefungen
        // koennen Ausnahmen ausloesen (z. B. ein absichtlich kaputter
        // Referer-Header); ohne das Auffangnetz wuerde daraus eine
        // HTML-Fehlerseite mit HTTP 500 statt einer JSON-Antwort.
        try {
            // 0. JSON-Pflicht. Ohne diesen Header waere die Anfrage fuer den
            //    Browser ein "simple request" und ginge ohne CORS-Vorabfrage
            //    raus. Mit der Pflicht erzwingt der Browser eine Vorabfrage,
            //    die er mangels Erlaubnis-Header selbst abbricht.
            if (!str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
                return $this->errorResponse(415, 'invalid_request', 'error.invalidrequest', $language);
            }

            // 1. Gleiche Herkunft. Ein POST mit Content-Type application/json
            //    schickt zuverlaessig einen Origin-Header - fehlt er, ist das
            //    verdaechtig und wird abgelehnt.
            if (!$this->isSameOrigin($request, $requestSite)) {
                $this->logger->warning('Chat request rejected: request origin does not belong to this site.');

                return $this->errorResponse(403, 'forbidden', 'error.forbidden', $language);
            }

            // 2. Nutzdaten pruefen (Konzept 4.2).
            try {
                $payload = ChatRequestPayload::fromJson((string)$request->getBody());
            } catch (InvalidChatRequestException $exception) {
                // Die Meldung beschreibt nur den Regelverstoss, nie den Inhalt.
                $this->logger->info('Chat request rejected: {reason}', ['reason' => $exception->getMessage()]);

                return $this->errorResponse(400, 'invalid_request', 'error.invalidrequest', $language);
            }

            // 3. Website und Sprache bestimmen.
            $site = $this->resolveSite($payload->pageUid, $requestSite);
            if ($site === null) {
                $this->logger->error('Chat request failed: no TYPO3 site could be resolved for this request.');

                return $this->errorResponse(503, 'unavailable', 'error.notconfigured', $language);
            }
            $language = $this->resolveLanguage($site, $payload->languageUid);

            // 4. Ist der Chatbot fuer diese Website eingeschaltet?
            //    null = die Site kennt die Einstellung nicht, weil ihr das Site Set nicht
            //    zugewiesen ist. Dann wird bewusst ABGELEHNT: der Endpunkt kostet
            //    KI-Kontingent und darf nicht auf Websites antworten, auf denen niemand
            //    den Chatbot eingeschaltet hat.
            $enabled = $site->getSettings()->get('accessiblechatbot.enabled', null);
            if ($enabled === null || !$enabled) {
                $this->logger->info(
                    'Chat request rejected: chatbot is switched off (or the site set is missing) for site {site}.',
                    ['site' => $site->getIdentifier()]
                );

                return $this->errorResponse(403, 'forbidden', 'error.forbidden', $language);
            }

            // 5. Rate-Limit. Bewusst NACH der Pruefung der Nutzdaten: ein
            //    Client-Fehler soll dem Besucher nicht sein Kontingent kosten.
            $retryAfter = $this->rateLimiter->consume($request);
            if ($retryAfter !== null) {
                // Beim Minutenlimit hilft kurzes Warten, beim Tageslimit nicht -
                // der Text muss also unterschiedlich sein, sonst waere er eine
                // falsche Zusage.
                return $this->errorResponse(
                    429,
                    'rate_limit',
                    $retryAfter > 300 ? 'error.ratelimit.day' : 'error.ratelimit',
                    $language
                )->withHeader('Retry-After', (string)$retryAfter);
            }

            // 6. KI fragen.
            $reply = $this->chatService->reply($payload, $site, $language);
        } catch (AiProviderException $exception) {
            $this->logger->error('Chat request failed: {reason}', [
                'reason' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ]);

            return $this->errorResponse(503, 'ai_unavailable', $exception->userMessageKey, $language);
        } catch (\Throwable $exception) {
            // Auffangnetz: eine unerwartete Ausnahme wuerde sonst eine
            // HTML-Fehlerseite erzeugen, mit der das Widget nichts anfangen
            // kann. Nur die Klasse wird protokolliert - eine fremde
            // Fehlermeldung koennte Inhalte enthalten.
            $this->logger->error('Chat request failed unexpectedly: {class}', [
                'class' => $exception::class,
                'code' => $exception->getCode(),
            ]);

            return $this->errorResponse(503, 'unavailable', 'error.unavailable', $language);
        }

        return $this->jsonResponse(
            [
                'reply' => $reply->reply,
                // Nur zur Fehlersuche im Netzwerk-Protokoll. Das Widget
                // wertet dieses Feld bewusst NICHT aus: alle Entscheidungen
                // fallen serverseitig (Konzept 6.1).
                'action' => $reply->action->value,
                // Navigationsangebot (Konzept 4.5). Der Server liefert eine
                // FERTIGE, vom TYPO3-Router erzeugte Adresse. Eine Seiten-UID
                // verlaesst den Server bewusst nicht mehr - das Widget soll gar
                // nicht erst in die Lage kommen, selbst eine Adresse zu bauen.
                'navigation' => $reply->navigation === null ? null : [
                    'url' => $reply->navigation->url,
                    'title' => $reply->navigation->title,
                ],
                // Auswahltitel einer Rueckfrage (nur bei action = clarify).
                'choices' => $reply->choices,
                // Quellseiten der Antwort. Die Adressen stammen
                // ausschliesslich vom TYPO3-Router (ChatService).
                'sources' => array_map(
                    static fn(ChatLink $link): array => [
                        'url' => $link->url,
                        'title' => $link->title,
                    ],
                    $reply->sources
                ),
                'suggestContact' => $reply->suggestContact,
            ],
            200
        );
    }

    private function resolveSite(int $pageUid, mixed $requestSite): ?Site
    {
        if ($pageUid > 0) {
            try {
                $siteFromPage = $this->siteFinder->getSiteByPageId($pageUid);

                // Nur uebernehmen, wenn die Seite zur angefragten Website
                // gehoert. Die Seiten-UID kommt aus dem Browser und ist frei
                // waehlbar - ohne diese Pruefung koennte ein manipulierter
                // Client die Konfiguration einer FREMDEN Website benutzen und
                // damit auch deren Schalter "Chatbot aus" umgehen.
                if (!$requestSite instanceof Site
                    || $siteFromPage->getIdentifier() === $requestSite->getIdentifier()
                ) {
                    return $siteFromPage;
                }
            } catch (SiteNotFoundException) {
                // faellt unten auf die Site des Requests zurueck
            }
        }

        // Achtung: bei einer nicht aufloesbaren URL liefert TYPO3 eine
        // NullSite - die ist keine echte Site und hat keine Settings.
        return $requestSite instanceof Site ? $requestSite : null;
    }

    private function resolveLanguage(Site $site, int $languageUid): SiteLanguage
    {
        try {
            return $site->getLanguageById($languageUid);
        } catch (\InvalidArgumentException) {
            return $site->getDefaultLanguage();
        }
    }

    /**
     * Same-Origin-Pruefung. Einen Core-Helfer dafuer gibt es nicht.
     *
     * Verglichen wird gegen den Host, den der Browser tatsaechlich
     * angesprochen hat (NormalizedParams - von TYPO3 bereits gegen
     * [SYS][trustedHostsPattern] geprueft) und zusaetzlich gegen die
     * Basis-Adresse der Site, falls diese einen Host enthaelt.
     *
     * Das ist Schutz gegen fremde Websites, die den Endpunkt im Browser
     * eines Besuchers benutzen - kein Ersatz fuer das Rate-Limit.
     */
    private function isSameOrigin(ServerRequestInterface $request, mixed $site): bool
    {
        $origin = strtolower(trim($request->getHeaderLine('Origin')));

        if ($origin === '') {
            $referer = trim($request->getHeaderLine('Referer'));
            if ($referer !== '') {
                try {
                    $origin = strtolower($this->originFromUri(new Uri($referer)));
                } catch (\InvalidArgumentException) {
                    // Kaputter Referer-Header: wie "keine Herkunft" behandeln.
                    $origin = '';
                }
            }
        }

        if ($origin === '') {
            return false;
        }

        $allowed = [];

        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            // Format: "https://host[:port]" - genau wie ein Origin-Header.
            $allowed[] = strtolower($normalizedParams->getRequestHost());
        }

        if ($site instanceof Site && $site->getBase()->getHost() !== '') {
            $allowed[] = strtolower($this->originFromUri($site->getBase()));
        }

        return in_array($origin, $allowed, true);
    }

    private function originFromUri(UriInterface $uri): string
    {
        $scheme = $uri->getScheme();
        $host = $uri->getHost();

        if ($scheme === '' || $host === '') {
            return '';
        }

        $port = $uri->getPort();
        $isDefaultPort = $port === null
            || ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);

        return $scheme . '://' . $host . ($isDefaultPort ? '' : ':' . $port);
    }

    private function errorResponse(int $status, string $code, string $messageKey, ?SiteLanguage $language): ResponseInterface
    {
        return $this->jsonResponse(
            [
                'error' => $code,
                'message' => $this->translate($messageKey, $language),
            ],
            $status
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $status): ResponseInterface
    {
        $body = json_encode($data, self::JSON_FLAGS);
        if ($body === false) {
            // Sollte durch INVALID_UTF8_SUBSTITUTE nicht vorkommen - aber
            // eine leere Antwort waere fuer das Widget schlimmer als ein
            // generischer Fehler.
            $body = '{"error":"unavailable","message":""}';
            $status = 503;
        }

        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streamFactory->createStream($body));
    }

    private function translate(string $key, ?SiteLanguage $language): string
    {
        $languageService = $language instanceof SiteLanguage
            ? $this->languageServiceFactory->createFromSiteLanguage($language)
            : $this->languageServiceFactory->create('en');

        return $languageService->sL(self::LLL . $key);
    }
}
