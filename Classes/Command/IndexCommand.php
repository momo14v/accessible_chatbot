<?php

declare(strict_types=1);

namespace Extension14v\AccessibleChatbot\Command;

use Extension14v\AccessibleChatbot\Service\IndexService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Baut den Inhaltsindex neu auf: accessible-chatbot:index
 *
 * Der Befehl ist idempotent - er kann beliebig oft laufen und fuehrt immer
 * zum selben Ergebnis. Je Website wird der Bestand in einer Transaktion
 * ersetzt, es gibt also nie einen Moment, in dem der Index leer waere.
 *
 * Registriert wird der Befehl in Configuration/Services.yaml ueber den Tag
 * "console.command" (Configuration/Commands.php ist seit TYPO3 v10.3
 * veraltet). Nur ueber den Tag laesst sich "schedulable" steuern, das den
 * Befehl im Scheduler auswaehlbar macht.
 */
final class IndexCommand extends Command
{
    public function __construct(
        private readonly IndexService $indexService,
        private readonly SiteFinder $siteFinder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'site',
                null,
                InputOption::VALUE_REQUIRED,
                'Nur diese Website indexieren (Bezeichner aus der Site-Konfiguration).'
            )
            ->setHelp(
                <<<'HELP'
Liest alle Seiten aller Websites, die ein anonymer Besucher sehen kann, und
schreibt Titel und Textinhalt in die Tabelle tx_accessiblechatbot_index.

Versteckte Seiten, Seiten mit Start-/Enddatum ausserhalb des jetzigen
Zeitpunkts, zugriffsgeschuetzte Seiten, Systemordner sowie Seiten mit
"In Suche ausschliessen" werden dabei uebersprungen.

Empfehlung: diesen Befehl zusaetzlich naechtlich per Scheduler ausfuehren.
Nur so werden zeitgesteuerte Sichtbarkeitswechsel erkannt, bei denen niemand
etwas im Backend speichert.
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Accessible Chatbot: Inhaltsindex aufbauen');

        $siteIdentifier = $input->getOption('site');
        $siteIdentifier = is_string($siteIdentifier) ? trim($siteIdentifier) : '';

        try {
            $sites = $siteIdentifier !== ''
                ? [$this->siteFinder->getSiteByIdentifier($siteIdentifier)]
                : array_values($this->siteFinder->getAllSites());
        } catch (SiteNotFoundException) {
            $io->error(sprintf('Es gibt keine Website mit dem Bezeichner "%s".', $siteIdentifier));

            return Command::FAILURE;
        }

        if ($sites === []) {
            $io->warning('Es ist keine Website konfiguriert. Es wurde nichts indexiert.');

            return Command::SUCCESS;
        }

        $total = 0;
        foreach ($sites as $site) {
            $io->section(sprintf(
                'Website "%s" (Startseite %d)',
                $site->getIdentifier(),
                $site->getRootPageId()
            ));

            $started = false;
            try {
                $written = $this->indexService->indexSite(
                    $site,
                    function (int $done, int $count) use ($io, &$started): void {
                        if (!$started) {
                            $io->progressStart($count);
                            $started = true;
                        }
                        $io->progressAdvance();
                    }
                );
            } catch (\Throwable $exception) {
                if ($started) {
                    $io->progressFinish();
                }
                $io->error(sprintf(
                    'Website "%s" konnte nicht indexiert werden: %s',
                    $site->getIdentifier(),
                    $exception->getMessage()
                ));

                return Command::FAILURE;
            }

            if ($started) {
                $io->progressFinish();
            }

            $io->writeln(sprintf('%d Eintraege geschrieben.', $written));
            $total += $written;
        }

        if ($siteIdentifier === '') {
            $removed = $this->indexService->removeOrphanedSiteRows(
                array_map(static fn(Site $site): string => $site->getIdentifier(), $sites)
            );
            if ($removed > 0) {
                $io->writeln(sprintf('%d verwaiste Eintraege entfernt.', $removed));
            }
        }

        $io->success(sprintf('Fertig. %d Eintraege geschrieben.', $total));

        return Command::SUCCESS;
    }
}
