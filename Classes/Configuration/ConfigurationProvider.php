<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Liest die Betriebs-Einstellungen und setzt den ENV-Vorrang um.
 *
 * Warum ENV zuerst? ext_conf_template.txt kennt keinen Passwort-Typ - der
 * Key stuende im Backend im Klartext und landete in LocalConfiguration.php.
 * Die Umgebungsvariable bleibt ausserhalb des Projektverzeichnisses.
 */
final class ConfigurationProvider
{
    public const ENV_API_KEY = 'ACCESSIBLE_CHATBOT_API_KEY';

    private const EXTENSION_KEY = 'accessible_chatbot';

    private ?ChatbotConfiguration $configuration = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function get(): ChatbotConfiguration
    {
        // Services sind pro Request nur einmal vorhanden - einmal lesen genuegt.
        return $this->configuration ??= $this->load();
    }

    private function load(): ChatbotConfiguration
    {
        try {
            $raw = $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (ExtensionConfigurationExtensionNotConfiguredException) {
            // Die Extension-Konfiguration wurde im Backend noch nie gespeichert.
            // Das ist kein Fehler, sondern schlicht "noch nichts eingetragen".
            $raw = [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $apiKey = self::fromEnvironment(self::ENV_API_KEY);
        if ($apiKey === '') {
            $apiKey = self::string($raw, 'apiKey', '');
        }

        return new ChatbotConfiguration(
            $apiKey,
            self::string($raw, 'provider', 'gemini'),
            self::string($raw, 'model', 'gemini-2.5-flash'),
            self::baseUrl(self::string($raw, 'apiBaseUrl', '')),
            self::int($raw, 'rateLimitPerMinute', 8),
            self::int($raw, 'rateLimitPerDay', 100),
            self::int($raw, 'rateLimitGlobalPerDay', 1000),
        );
    }

    private static function fromEnvironment(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function string(array $raw, string $key, string $default): string
    {
        $value = isset($raw[$key]) && is_scalar($raw[$key]) ? trim((string)$raw[$key]) : '';

        return $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function int(array $raw, string $key, int $default): int
    {
        if (!isset($raw[$key]) || !is_numeric($raw[$key])) {
            return $default;
        }

        return max(0, (int)$raw[$key]);
    }

    /**
     * Der API-Key geht als Header an diese Adresse. Eine unverschluesselte
     * Verbindung wuerde ihn im Klartext uebertragen und wird deshalb
     * verworfen - ausser bei localhost (eigener KI-Server auf demselben Host).
     */
    private static function baseUrl(string $value): string
    {
        $value = rtrim($value, '/');
        if ($value === '') {
            return '';
        }

        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($value, PHP_URL_HOST));

        if ($scheme === 'https') {
            return $value;
        }

        // Unverschluesselt nur fuer einen KI-Server auf demselben Rechner.
        // Das Schema muss trotzdem angegeben sein - ohne "http://" waere der
        // Wert keine gueltige Adresse.
        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return $value;
        }

        // Ungueltige Angabe: auf den Anbieter-Standard zurueckfallen.
        return '';
    }
}
