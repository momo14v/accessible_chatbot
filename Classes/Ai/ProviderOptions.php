<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Ai;

/**
 * Alle Angaben, die ein Provider fuer genau einen Aufruf braucht.
 *
 * Der Provider haelt dadurch selbst keinen Zustand - er ist reine Mechanik.
 * #[\SensitiveParameter] sorgt dafuer, dass PHP den Schluessel in einem
 * Stacktrace durch "Object(SensitiveParameterValue)" ersetzt.
 */
final readonly class ProviderOptions
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $model,
        public string $baseUrl,
        public int $timeoutSeconds = 30,
    ) {}
}
