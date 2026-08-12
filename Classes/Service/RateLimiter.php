<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Service;

use Extension14v\AccessibleChatbot\Configuration\ConfigurationProvider;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * Einfacher Zaehler ueber das Caching Framework (Konzept 5 / 7).
 *
 * Warum nicht TYPO3s RateLimiterFactory? Die ist als @internal markiert und
 * ausschliesslich fuer den Login-Schutz gedacht - fuer sie gilt keine
 * Bestandsgarantie in kuenftigen Versionen.
 *
 * Bewusst ein "festes Fenster" (fixed window) und keine gleitende Messung:
 * An der Fenstergrenze sind kurzzeitig bis zu doppelt so viele Anfragen
 * moeglich. Fuer Missbrauchsschutz genuegt das voellig, und es kostet nur
 * einen Zaehler statt einer Liste von Zeitstempeln.
 *
 * Bekannte Einschraenkung: Lesen und Schreiben des Zaehlers sind nicht atomar
 * (Typo3DatabaseBackend kennt kein "hochzaehlen"-Kommando). Bei gleichzeitigen
 * Anfragen kann die Grenze deshalb kurzzeitig leicht ueberschritten werden.
 * Fuer Missbrauchsschutz ist das ohne Bedeutung - es ist kein Fehler.
 */
final class RateLimiter
{
    private const CACHE_IDENTIFIER = 'accessible_chatbot_ratelimit';
    private const HMAC_SECRET = 'accessible_chatbot/rate-limit';

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly HashService $hashService,
        private readonly ConfigurationProvider $configurationProvider,
    ) {}

    /**
     * Prueft alle Grenzen und zaehlt bei Erfolg hoch.
     *
     * @return int|null null = Anfrage erlaubt. Sonst die Anzahl Sekunden bis
     *                  zum Ablauf des ueberschrittenen Zeitfensters.
     */
    public function consume(ServerRequestInterface $request): ?int
    {
        $configuration = $this->configurationProvider->get();
        $cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);

        $now = time();
        $client = $this->clientIdentifier($request);
        $day = gmdate('Ymd', $now);

        // Sekunden bis Mitternacht UTC - passend zum Tagesschluessel oben.
        $untilMidnight = 86400 - ($now % 86400);

        /** @var list<array{id: string, limit: int, lifetime: int, retryAfter: int}> $windows */
        $windows = [
            [
                'id' => 'min_' . $client . '_' . intdiv($now, 60),
                'limit' => $configuration->rateLimitPerMinute,
                'lifetime' => 70,
                'retryAfter' => 60 - ($now % 60),
            ],
            [
                'id' => 'day_' . $client . '_' . $day,
                'limit' => $configuration->rateLimitPerDay,
                'lifetime' => 86400,
                'retryAfter' => $untilMidnight,
            ],
            [
                'id' => 'all_' . $day,
                'limit' => $configuration->rateLimitGlobalPerDay,
                'lifetime' => 86400,
                'retryAfter' => $untilMidnight,
            ],
        ];

        // Erst pruefen ...
        foreach ($windows as $window) {
            if ($window['limit'] > 0 && $this->counter($cache, $window['id']) >= $window['limit']) {
                return max(1, $window['retryAfter']);
            }
        }

        // ... und erst danach hochzaehlen. Sonst wuerde eine abgelehnte
        // Anfrage die anderen Zaehler unnoetig belasten.
        foreach ($windows as $window) {
            if ($window['limit'] > 0) {
                $cache->set(
                    $window['id'],
                    $this->counter($cache, $window['id']) + 1,
                    [],
                    $window['lifetime']
                );
            }
        }

        return null;
    }

    private function counter(FrontendInterface $cache, string $identifier): int
    {
        $value = $cache->get($identifier);

        // get() liefert false, wenn nichts (mehr) da ist.
        return is_int($value) ? $value : 0;
    }

    /**
     * Erzeugt einen nicht rueckrechenbaren Schluessel aus der Client-IP.
     *
     * NormalizedParams beruecksichtigt bereits eine konfigurierte
     * Reverse-Proxy-Einrichtung ([SYS][reverseProxyIP]).
     */
    private function clientIdentifier(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        $address = $normalizedParams instanceof NormalizedParams
            ? $normalizedParams->getRemoteAddress()
            : '';

        return $this->hashService->hmac($address, self::HMAC_SECRET);
    }
}
