# Accessible Chatbot

Ein barrierefreier Chatbot für TYPO3, der Fragen ausschließlich aus den sichtbaren Inhalten der jeweiligen Website beantwortet und Nutzer:innen auf Wunsch zu passenden Seiten navigiert. Die Entwicklung orientiert sich an WCAG 2.1 AA (Maßstab BITV 2.0); eine formale Konformitätsaussage setzt eine externe Prüfung voraus.

## Status

Version 0.1.0 - in Entwicklung (Phase 0 von 7). Die Extension hat aktuell noch keine Funktion im Frontend.

## Voraussetzungen

- TYPO3 13.4 LTS oder 14.x
- PHP >= 8.2
- Keine externen Abhängigkeiten

## Installation

**Composer-basierte Installation:**

Die Extension ist proprietär und nicht auf Packagist veröffentlicht. Sie muss dem
Zielprojekt daher zuerst als Repository bekannt gemacht werden:

```
composer config repositories.accessible-chatbot path ../pfad/zu/accessible_chatbot
composer require extension14v/accessible-chatbot:@dev
```

**Classic/Legacy-Installation:**

Extension-Ordner nach `typo3conf/ext/accessible_chatbot/` kopieren und anschließend im Extension Manager aktivieren.

## Einbindung

Je nach Projekt entweder das Site Set "Barrierefreier Chatbot" (`extension14v/accessible-chatbot`) der Site zuweisen, oder - in Projekten ohne Site Sets - das statische TypoScript-Template im `sys_template`-Datensatz auswählen.

Die eigentliche Chatbot-Funktion (Widget, Fragenbeantwortung, Navigation) ist noch geplant und in dieser Phase nicht enthalten.

## Lizenz

Proprietär. one4vision GmbH
