# Accessible Chatbot

Ein barrierefreier Chatbot für TYPO3, der Fragen ausschließlich aus den sichtbaren Inhalten der jeweiligen Website beantwortet und Nutzerinnen und Nutzer auf Wunsch zu passenden Seiten navigiert. Die Entwicklung orientiert sich an Zielnorm WCAG 2.2 AA (EN 301 549); es wird keine Konformitätsaussage getroffen.

## Status

Version 0.7.0 - in Entwicklung (Phase 7 von 9). Das barrierefreie Chat-Widget ist vorhanden und beantwortet Fragen über die Google-Gemini-API. Seit Phase 4 greift der Chat auf den Inhaltsindex zu: Der Bot beantwortet Fragen aus den tatsächlichen, sichtbaren Inhalten dieser Website und nennt zu jeder inhaltlich gestützten Antwort einen oder mehrere Links auf die verwendeten Seiten. Findet er keine passende Seite, sagt er das ehrlich und bietet - falls eingerichtet - die Kontaktseite an. Seit Phase 5 ist der Gesprächsverlauf mit Pfeiltasten durchsuchbar und Ansagen laufen über eine eigene Statuszeile statt über den Verlauf selbst; fehlt die Spracherkennung des Browsers, erscheint ein sichtbarer Hinweis. Seit Phase 6 kann der Assistent zu einer Seite führen. Er bietet dafür einen Knopf innerhalb seiner Antwort an — die Seite wechselt ausschließlich, wenn dieser Knopf betätigt wird. Einen automatischen Seitenwechsel gibt es in keiner Einstellung. Passen mehrere Seiten, stellt der Assistent eine Rückfrage mit Auswahlknöpfen. Seit Phase 7 gibt es vorgeschlagene Einstiegsfragen, einen dauerhaft sichtbaren Weg zu einem Menschen, ein "Neues Gespräch beginnen" mit Rückfrage, eine abbrechbare laufende Anfrage, einen Hinweis, wenn ältere Nachrichten aus dem Kontext fallen, drei statt zwei Sprachformen, eine serverseitige Textsäuberung der KI-Antwort sowie eine optionale Vorlesefunktion.

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

Es gibt zwei Wege. **Pro Website darf immer nur einer davon aktiv sein.**

### Welchen Weg wählen?

Als Faustregel: **Weg A**, wenn die Website TYPO3 v13 oder neuer nutzt und Site Sets
im Einsatz sind. **Weg B** nur, wenn die Website keine Site Sets verwendet.

Beide Wege funktionieren unabhängig davon, ob das `page`-Objekt der Website aus
einem Site Set oder aus einem `sys_template`-Datensatz stammt: TYPO3 wertet
Site-Set-TypoScript zwar vor allen `sys_template`-Datensätzen aus, aber eine
spätere Zuweisung wie `page = PAGE` setzt nur den Wert und löscht die vorher
gesetzte Eigenschaft `page.9990` nicht.

Es gibt genau drei Konstellationen, in denen die Einbindung aus einem Site Set
nachträglich verloren geht:

- ein `sys_template`-Datensatz mit angehaktem **"Clear Setup"**,
- ein späteres `page >` (löscht das komplette `page`-Objekt),
- ein späteres `page < …` (kopiert ein anderes Objekt darüber).

Trifft eines davon zu, hilft Weg B - oder das Häkchen "Clear Setup" entfernen.

### Weg A - Site Set (empfohlen, TYPO3 v13+)

1. Site Management → Sites → die eigene Site bearbeiten → unter "Sets" das Set
   "Barrierefreier Chatbot" (`extension14v/accessible-chatbot`) hinzufügen.
2. Site Management → Settings → Kategorie "Barrierefreier Chatbot" → dort
   "Chatbot aktivieren" einschalten und die übrigen Werte setzen.

### Weg B - klassisches statisches Template (Projekte ohne Site Sets)

1. Web → Template → Datensatz der Root-Seite → unter "Include static (from
   extensions)" den Eintrag "Barrierefreier Chatbot" hinzufügen. Er sollte in der
   Liste möglichst weit unten stehen, und in keinem danach folgenden Template darf
   "Clear Setup" angehakt sein.
2. Im Constant Editor (Kategorie "accessible chatbot") `accessiblechatbot.enabled`
   auf 1 setzen und die übrigen Konstanten anpassen.
3. Zusätzlich das Site Set der Site zuweisen und dort „Chatbot aktivieren" einschalten.
   Der Chat-Endpunkt liest diesen Schalter **ausschließlich** aus den Site Settings —
   ohne ihn erscheint das Widget zwar, aber jede Nachricht wird mit einem Fehler
   abgewiesen.

`botName`, `salutation` und `maxIndexPagesFullSitemap` wirken serverseitig ebenfalls
nur, wenn sie aus den Site Settings stammen - über Weg B beeinflussen sie
ausschließlich die Anzeige des Widgets, weil die Chat-Middleware bereits läuft,
bevor TYPO3 das TypoScript auflöst, und Konstanten aus einem `sys_template` dort
deshalb nicht zur Verfügung stehen.

### Kontrolle, ob die Einbindung greift

Backend → Web → Template → Root-Seite → "TypoScript Object Browser" → Setup:
Der Zweig `page.9990` muss vorhanden sein. Fehlt er, ist die Reihenfolge falsch -
dann Weg B verwenden bzw. die Reihenfolge korrigieren.

### Warum nicht beides gleichzeitig

TYPO3 wertet Konstanten in Stufen aus. Konstanten aus einem `sys_template`-Datensatz
(also auch aus dem statischen Template) stehen **über** den Site Settings. Sind beide
Wege gleichzeitig aktiv, überschreiben die Standardwerte des statischen Templates
stillschweigend die Werte, die im Site-Settings-Formular eingetragen wurden - der
Chatbot ließe sich dort dann scheinbar nicht mehr einschalten. Deshalb: pro Site
entweder Weg A oder Weg B.

### Wichtig bei Weg B: Botname, Anrede und Seitenliste in den KI-Antworten

Für das, was der Assistent tatsächlich **sagt** - seinen Namen, die Anrede ("sie"
oder "du") und wie lang die Seitenliste im Hintergrund werden darf, bevor sie
gekürzt wird - liest der Server ausschließlich die **Site Settings** aus (also
den Weg über das Site Set), unabhängig davon, welcher Einbindungsweg für das
sichtbare Widget aktiv ist.

Wer nur Weg B (klassisches statisches Template) benutzt, konfiguriert damit
ausschließlich das **Erscheinungsbild** des Widgets im Frontend (Kopfzeile,
Begrüßungstext). Die Werte aus dem Constant Editor wirken sich **nicht** auf
den Systemprompt aus, den die KI erhält - dort bleiben `botName`, `salutation`
und `maxIndexPagesFullSitemap` auf ihren Standardwerten ("Assistent", "sie",
150), selbst wenn im Constant Editor etwas anderes eingetragen ist.

**Pflicht, nicht nur Empfehlung:** der Chat-Endpunkt selbst liest die
Einstellung "Chatbot aktivieren" ausschließlich aus den Site Settings. Fehlt
der Site das Site Set "Barrierefreier Chatbot" komplett, kennt der Server
diese Einstellung gar nicht und lehnt jede Anfrage sicherheitshalber ab (HTTP
403) - unabhängig davon, was im Constant Editor von Weg B eingetragen ist. Der
Site muss deshalb **immer zusätzlich** das Site Set "Barrierefreier Chatbot"
zugewiesen werden, auch wenn sonst ausschließlich mit statischem TypoScript
(Weg B) gearbeitet wird.

## Einstellungen

| Einstellung | Standard | Zweck |
| --- | --- | --- |
| `enabled` | aus | Widget im Frontend einblenden |
| `botName` | Assistent | Anzeigename in Kopfzeile und vor jeder Antwort |
| `headingLevel` | 2 | Überschriften-Ebene im Chatfenster (2 bis 6). Sollte zur Überschriften-Gliederung der Website passen, damit der Chat die Gliederung der Seite nicht stört. |
| `salutation` | sie | Anrede der Antworten (`sie` oder `du`) |
| `genderStyleDefault` | neutral | Voreingestellte Sprachform (`neutral`, `pair` oder `asterisk`, siehe Abschnitt „Sprachform der Antworten"). Besucherinnen und Besucher können sie im Chatfenster selbst umstellen. |
| `privacyPageUid` | 0 | Seite mit der Datenschutzerklärung (0 = kein Link) |
| `contactPageUid` | 0 | **Faktische Pflichtangabe.** Weg zu einem Menschen, dauerhaft im Chatfenster sichtbar (nicht nur im Fehlerfall). Ohne Kontaktseite hat der Chatbot keinen Notausgang - siehe Abschnitt „Weg zu einem Menschen". 0 = kein Link. |
| `starterQuestions` | leer | Bis zu vier vorgeschlagene Einstiegsfragen, siehe Abschnitt „Einstiegsfragen". |
| `readAloudEnabled` | an | Vorlesefunktion je Antwort anbieten, siehe Abschnitt „Antworten vorlesen lassen". |
| `maxIndexPagesFullSitemap` | 150 | Ab dieser Anzahl indexierter Seiten bekommt der Assistent nur noch die oberen Ebenen des Seitenbaums (bis Ebene 2) statt der vollständigen Seitenliste |

Alle sichtbaren Texte liegen in `Resources/Private/Language/locallang.xlf`
(englische Quelldatei) und `de.locallang.xlf` (deutsche Übersetzung) und lassen
sich projektspezifisch über `locallangXMLOverride` überschreiben.

## Barrierefreiheit im Gesprächsverlauf

Seit Phase 5 ist der Gesprächsverlauf keine Live-Region mehr (kein `role="log"`,
kein `aria-live` auf der Nachrichtenliste). Stattdessen gibt es eine
Zwei-Ebenen-Struktur:

- Der Verlauf selbst ist eine benannte Region (`role="region"`) mit einer
  visuell versteckten Überschrift und einem versteckten Bedienhinweis.
- Neue Antworten werden über die bereits vorhandene Statuszeile angesagt:
  kurze Antworten im Volltext, lange Antworten nur als kurze Meldung. Der
  vollständige Text steht in jedem Fall im Verlauf.

Innerhalb des Verlaufs lässt sich mit den Pfeiltasten ↓ und ↑ von Nachricht zu
Nachricht springen, `Pos 1` springt zur ersten Nachricht und `Ende` zur
letzten. Die Tab-Reihenfolge der übrigen Seite bleibt davon unberührt.

**Spracheingabe ohne Unterstützung:** Fehlt die Web Speech API im Browser
(zum Beispiel Firefox in der Standardeinstellung) oder läuft die Seite nicht
über eine sichere Verbindung (kein HTTPS und nicht `localhost`), erscheint
statt des Mikrofon-Knopfs ein sichtbarer Hinweistext unter dem Eingabefeld.
Das Widget selbst bleibt in jedem Fall vollständig bedienbar - die
Spracheingabe ist immer nur eine Ergänzung zur Texteingabe.

## Navigation zu einer Seite

Seit Phase 6 kann der Assistent nicht nur Fragen beantworten, sondern auch
zu einer passenden Seite führen - zum Beispiel auf "Bring mich zur
Kontaktseite".

- **Der Assistent schlägt nur vor, ausgelöst wird nichts von selbst.** Die
  Antwort enthält dafür einen deutlich gestalteten Knopf. Erst wenn dieser
  Knopf betätigt wird, wechselt die Seite. Es gibt dafür weder einen Timer
  noch eine Einstellung, die das automatisch machen würde.
- **Das Ziel wird serverseitig geprüft**, und zwar gegen die Seiten, die für
  diese Anfrage freigegeben waren - also gegen Seiten, die der Besucher
  ohnehin selbst erreichen könnte. Versteckte, abgelaufene oder
  zugriffsgeschützte Seiten können deshalb niemals ein Ziel sein.
- **Die Adresse erzeugt immer TYPO3 selbst** über seine eigene
  Seiten-Verlinkung - niemals der KI-Dienst.
- **Nach dem Seitenwechsel** wird der bisherige Gesprächsverlauf vollständig
  wiederhergestellt, das Chatfenster behält seinen offenen oder geschlossenen
  Zustand, und im Verlauf erscheint eine Bestätigungszeile. Der Tastaturfokus
  wird dabei absichtlich nicht verschoben.
- **Bei Mehrdeutigkeit** (mehrere Seiten könnten gemeint sein) stellt der
  Assistent stattdessen eine Rückfrage mit Auswahlknöpfen. Diese Knöpfe
  beantworten nur die Rückfrage und navigieren nicht.

## Einstiegsfragen

Unter der Begrüßung können bis zu vier vorgeschlagene Fragen als echte
Knöpfe erscheinen - für alle, die vor einem leeren Eingabefeld nicht wissen,
was sie fragen sollen.

Gepflegt werden sie über das Setting `starterQuestions` als **eine einzige
Zeichenkette**, die Fragen werden durch einen senkrechten Strich `|`
getrennt:

```
Wann habt ihr geöffnet?|Wo finde ich den Kontakt?|Was kostet der Eintritt?
```

Mehr als vier Fragen werden abgeschnitten (die ersten vier zählen), leere
Einträge (zum Beispiel durch `||`) werden übersprungen. Leer gelassen =
keine Einstiegsfragen.

Ein Klick auf eine Einstiegsfrage **sendet sie sofort** - der Knopftext IST
die Nachricht, die abgeschickt wird, es gibt keinen Zwischenschritt zum
Nachbearbeiten. Der Klick navigiert nicht, es öffnet sich also keine andere
Seite.

**Wichtig für mehrsprachige Websites:** Site Settings gelten pro **Site**,
nicht pro **Sprache**. Wer auf einer mehrsprachigen Website in jeder Sprache
andere Einstiegsfragen zeigen möchte, kann das nicht über `starterQuestions`
allein lösen. Eine Möglichkeit ist eine TypoScript-Bedingung im
statischen Weg (Weg B), die je Sprach-ID eine eigene Konstante setzt, zum
Beispiel:

```
[siteLanguage("languageId") == 1]
accessiblechatbot.starterQuestions = Wann habt ihr geöffnet?|Wo finde ich den Kontakt?
[END]
```

Das wirkt allerdings - wie in Abschnitt „Wichtig bei Weg B" beschrieben -
ausschließlich auf die **Anzeige** des Widgets, nicht auf das, was die KI
tatsächlich als Systemprompt bekommt.

## Weg zu einem Menschen

`contactPageUid` ist eine **faktische Pflichtangabe**: Ohne eine gesetzte
Kontaktseite hat der Chatbot keinen Notausgang. Ist sie gesetzt, steht der
Link zur Kontaktseite **dauerhaft** im Chatfenster - nicht erst, wenn etwas
schiefgeht. Er steht bei jedem Seitentyp an derselben Stelle im Widget, damit
er wiederauffindbar bleibt.

## Neues Gespräch beginnen

Ein Knopf im Chatfenster löscht den bisherigen Verlauf und beginnt neu. Vor
dem Löschen erscheint eine Rückfrage mit zwei Knöpfen ("Ja, Gespräch
löschen" / "Nein, Gespräch behalten") - ein versehentlicher Klick soll nicht
sofort das ganze Gespräch kosten.

Gelöscht werden dabei **ausschließlich** der sichtbare Verlauf und ein
eventuell gemerktes Navigationsziel. Ob das Chatfenster offen oder
geschlossen ist und welche Sprachform eingestellt ist, bleiben unverändert -
das sind Einstellungen der besuchenden Person, kein Teil des Gesprächs.

## Antwort abbrechen

Solange eine Antwort noch nicht da ist, wird aus dem Senden-Knopf ein
Abbrechen-Knopf. Ein Klick beendet die laufende Anfrage sofort; im Verlauf
erscheint ein kurzer, neutraler Hinweis statt einer Fehlermeldung - ein
Abbruch auf eigenen Wunsch ist kein Fehler. Der Knopf wird dabei **nie**
mit `disabled` gesperrt, damit er tastaturerreichbar und ansagbar bleibt.

## Sprachform der Antworten

Im Chatfenster lässt sich zwischen drei Sprachformen wählen. Die Reihenfolge
folgt der Empfehlung des DBSV (Deutscher Blinden- und
Sehbehindertenverband, Stand März 2024):

| Option | Beispiel | Einordnung |
| --- | --- | --- |
| **Ohne Geschlechtsbezug formulieren** (Standard) | „das Team", „die Studierenden" | Erstempfehlung des DBSV |
| **Beide Formen ausschreiben** | „die Autorinnen und Autoren" | Zweitempfehlung des DBSV |
| **Kurzform mit Sternchen** | „die Autor*innen" | nur bei ausdrücklichem Wunsch nach einer Kurzform |

**Warum der Doppelpunkt entfallen ist:** In einer früheren Version gab es
eine Kurzform mit Doppelpunkt ("Autor:innen"). Der DBSV rät davon
ausdrücklich ab: der Doppelpunkt erzeugt im Wortinneren eine Satzzeichenpause
wie ein Punkt oder Komma, wodurch Screenreader den Satz vorzeitig als beendet
vorlesen. Aus demselben Grund war der Unterstrich nie eine Option dieser
Extension.

Voreingestellt ist die Sprachform, die im Setting `genderStyleDefault`
steht; jede besuchende Person kann sie im Chatfenster selbst umstellen. Die
gewählte Form wird im `sessionStorage` gemerkt.

## Antworten vorlesen lassen

Ist `readAloudEnabled` eingeschaltet (Standard) und beherrscht der Browser
Sprachausgabe, erscheint an jeder Antwort ein Knopf "Antwort vorlesen".
Derselbe Knopf startet und stoppt die Ausgabe. Vorgelesen wird
ausschließlich der Antworttext, nicht die Bedienelemente drumherum.

**Ehrliche Einordnung:** Das ist ein **Komfort-Feature** für Menschen mit
Lese- und Lernschwierigkeiten (das W3C-COGA-Regelwerk empfiehlt
„gleichzeitig hören und lesen"). Es ist **kein Ersatz für einen
Screenreader** und **keine WCAG-Anforderung** - WCAG enthält kein
Kriterium, das Vorlesen verlangt. Es wird nirgends als
Barrierefreiheits-Nachweis dargestellt.

**Bekannter Nebeneffekt:** Jede Bot-Antwort mit eingeschalteter
Vorlesefunktion bekommt einen zusätzlichen Tastatur-Stopp (den
Vorlese-Knopf). Wer die Funktion nicht möchte - weder als Betreiberin/
Betreiber noch als besuchende Person - kann sie über `readAloudEnabled`
komplett abschalten; dann entsteht auch kein zusätzlicher Tabstopp.

## Textsäuberung der KI-Antwort

Der Systemprompt verbietet der KI bereits Markdown, Emoji und rohe
Web-Adressen im Antworttext. Weil sich Sprachmodelle nicht immer
zuverlässig daran halten, filtert der Server die Antwort zusätzlich, bevor
sie das Chatfenster erreicht.

**Entfernt wird:**

- Markdown-Auszeichnung (`**fett**`, `__fett__`, `*kursiv*`, `` `Code` ``,
  `#` als Überschrift, `>` als Zitat, `-`/`*`/`1.` als Aufzählungszeichen,
  `---` als Trennlinie) - der sichtbare Text bleibt jeweils erhalten.
- Markdown-Links und -Bilder (`[Text](Adresse)`) - der sichtbare Linktext
  bleibt, die Adresse fällt weg. Echte Links entstehen ausschließlich
  server-generiert aus den geprüften Quellseiten, nie aus KI-Text.
- rohe Web-Adressen (`https://…`, `www.…`).
- Emoji und Piktogramme.

**Bleibt ausdrücklich stehen:**

- E-Mail-Adressen - sie sind kein Link zu einer fremden Seite, sondern ein
  Kontaktweg.
- Gradzeichen und ähnliche Sonderzeichen (zum Beispiel „20 °C").
- Pfeile - sie sind keine Emoji.
- ein Sternchen **innerhalb** eines Wortes, zum Beispiel in „Autor*innen"
  (siehe „Sprachform der Antworten") - nur ein Sternchen, das ein Wort oder
  einen Satzteil einrahmt, gilt als Markdown-Betonung.

Der Filter darf den Sinn nicht verändern - er entfernt Auszeichnung, nicht
Inhalt.

## Spracheingabe: geräteintern oder in der Cloud

Bietet der Browser die neue, geräteinterne Spracherkennung an (Stand
2026-08-13: Chrome und Edge ab Version 139, Opera ab Version 123, jeweils nur
auf dem Desktop), wird sie bevorzugt: Aufnahme und erkannter Text verlassen
das Gerät dann gar nicht erst. Der Hinweistext unter dem Eingabefeld nennt in
diesem Fall genau das.

In allen anderen Fällen - Firefox, Safari, mobile Browser, ältere
Chrome/Edge-Versionen - bleibt es beim bisherigen Cloud-Weg: Chrome, Edge und
Safari übertragen die Aufnahme an die Spracherkennungs-Dienste ihres
jeweiligen Herstellers (siehe Abschnitt „Datenschutz").

Die Prüfung, ob die geräteinterne Erkennung verfügbar ist, läuft asynchron im
Hintergrund und **lädt nie von selbst ein Sprachpaket nach**: Ist die
Erkennung grundsätzlich verfügbar, aber ein Sprachpaket müsste dafür erst
heruntergeladen werden, bleibt es bewusst beim Cloud-Weg - ein
unangekündigter, möglicherweise großer Download ohne Zutun der besuchenden
Person wäre nicht angemessen.

## Migrationshinweis für bestehende Installationen

Die Sprachform „Kurzform mit Doppelpunkt" (`colon`) ist mit Phase 7
entfallen (siehe Abschnitt „Sprachform der Antworten"). Ein im Browser
bereits gespeicherter Wert `colon` wird beim nächsten Laden des Widgets
still auf den Betreiber-Standard (`genderStyleDefault`, voreingestellt
`neutral`) zurückgesetzt - das ist kein Fehler und erfordert keine Aktion.

## API-Key einrichten

Der Chatbot braucht einen Zugangsschlüssel für die Google-Gemini-API. Es gibt
zwei Wege - **Weg 1 ist der empfohlene**.

### Schlüssel besorgen

1. <https://aistudio.google.com/> öffnen und mit einem Google-Konto anmelden.
2. Links auf "Get API key" klicken, dann "Create API key".
3. Den angezeigten Schlüssel kopieren. Er wird nur einmal vollständig gezeigt.

### Weg 1 (empfohlen) - Umgebungsvariable

Die Umgebungsvariable `ACCESSIBLE_CHATBOT_API_KEY` hat **immer Vorrang** vor dem
Backend-Feld. Vorteil: Der Schlüssel steht nirgends im Projektverzeichnis und ist
im TYPO3-Backend nicht sichtbar.

Bei DDEV wird sie in der Datei `.ddev/config.yaml` des **Projekts** gesetzt
(nicht in der Extension!):

```yaml
web_environment:
  - ACCESSIBLE_CHATBOT_API_KEY=hier-den-schluessel-einsetzen
```

Danach im Projektverzeichnis `ddev restart` ausführen.

Auf einem normalen Server wird die Variable in der Webserver- oder
PHP-FPM-Konfiguration gesetzt (`SetEnv`, `env[...]`, systemd `Environment=`).

### Weg 2 - Extension-Konfiguration im Backend

TYPO3-Backend, dann Admin Tools, Settings, Extension Configuration,
`accessible_chatbot`, Feld `apiKey`.

**Achtung:** TYPO3 kennt für dieses Formular keinen Passwort-Typ. Der Schlüssel
steht dort im Klartext und landet in `LocalConfiguration.php`. Wer mehrere
Backend-Redakteure mit Admin-Rechten hat, sollte Weg 1 wählen.

### Betriebs-Einstellungen (Extension Configuration)

| Einstellung | Standard | Zweck |
| --- | --- | --- |
| `apiKey` | leer | Zugangsschlüssel. Umgebungsvariable hat Vorrang. |
| `provider` | `gemini` | Zurzeit wird nur Gemini unterstützt. |
| `model` | `gemini-flash-latest` | Modellname beim Anbieter. Siehe Hinweis unten. |
| `apiBaseUrl` | leer | Nur für einen später selbst betriebenen KI-Server. |
| `rateLimitPerMinute` | 8 | Anfragen pro Minute je Besucher. 0 = aus. |
| `rateLimitPerDay` | 100 | Anfragen pro Tag je Besucher. 0 = aus. |
| `rateLimitGlobalPerDay` | 1000 | Anfragen pro Tag insgesamt. 0 = aus. |
| `maxContentLength` | 20000 | Maximale Zeichenzahl des indexierten Textes je Seite. Laengerer Text wird moeglichst an einer Wortgrenze abgeschnitten. |

### Hinweis zum Modellnamen

Google benennt Modelle regelmäßig um und sperrt ältere Versionen irgendwann für
neu erstellte Zugänge. Eine fest angegebene Version wie `gemini-2.5-flash`
funktioniert dann für bestehende Zugänge weiter, liefert für neue Schlüssel aber
`HTTP 404` - der Chat meldet in dem Fall "Der Assistent ist gerade nicht
erreichbar", und im TYPO3-Log steht `AI service answered with HTTP status 404`.

Deshalb ist `gemini-flash-latest` voreingestellt: dieser Name wächst mit und
zeigt immer auf das aktuelle Flash-Modell. Wer stattdessen eine feste Version
möchte (etwa um das Antwortverhalten stabil zu halten), muss den Namen bei
Modellwechseln selbst pflegen.

Welche Modelle der eigene Schlüssel benutzen darf, verrät diese Adresse - der
Schlüssel gehört dabei in den Header `x-goog-api-key`, nicht in die URL:

```
https://generativelanguage.googleapis.com/v1beta/models
```

Ein sparsameres Modell für sehr einfache Anwendungsfälle ist
`gemini-flash-lite-latest`.

## Schutz vor Missbrauch (Rate-Limits)

Der Server zählt Anfragen in drei Fenstern: pro Minute je Besucher, pro Tag je
Besucher und pro Tag für die gesamte Website. Wird eine Grenze erreicht,
antwortet der Chat mit einer freundlichen Meldung und der Besucher kann es
später erneut versuchen.

Gezählt wird über TYPO3s Caching Framework. Die IP-Adresse wird dabei **nicht**
gespeichert, sondern zu einem nicht rückrechenbaren Schlüssel verrechnet
(HMAC mit dem Encryption Key der Installation). Die Zähler laufen automatisch
ab - spätestens nach 24 Stunden.

**Einmalig nach der Installation nötig:** TYPO3-Backend, Admin Tools,
Maintenance, **Analyze Database Structure** ausführen und die vorgeschlagenen
Änderungen übernehmen. Dabei entstehen die beiden Tabellen
`cache_accessible_chatbot_ratelimit` und `cache_accessible_chatbot_ratelimit_tags`.

**Hinter einem Reverse Proxy oder Loadbalancer:** Ohne die Einstellung
`[SYS][reverseProxyIP]` sieht TYPO3 nur die IP des Proxys - dann teilen sich
alle Besucher ein einziges Kontingent. Diese Einstellung gehört in die
Installations-Konfiguration und muss dort passend gesetzt werden.

## Inhaltsindex

Damit der Chatbot später Fragen aus den Inhalten dieser Website beantworten
kann, wird der sichtbare Text aller Seiten in eine eigene Tabelle geschrieben:
`tx_accessiblechatbot_index`.

**Warum ein eigener Index und keine direkte Datenbankabfrage?** Eine rohe
Abfrage würde TYPO3s Sichtbarkeitsregeln umgehen. Der Bot könnte dann
versteckte, zeitgesteuerte oder zugriffsgeschützte Inhalte ausplaudern. Der
Index wird deshalb ausschließlich über TYPO3-Kernfunktionen aufgebaut, und
zwar genau so, wie ein **anonymer Besucher** die Website sieht.

**Nicht aufgenommen werden:**

- versteckte Seiten und versteckte Inhaltselemente,
- Seiten und Elemente, deren Start- oder Enddatum gerade nicht passt,
- Seiten mit gesetzter Zugriffsgruppe (Login-Bereiche),
- alles unterhalb einer Seite, die ihre Einschränkungen per
  "Für Unterseiten übernehmen" (`extendToSubpages`) vererbt,
- Systemordner, Trenner und Backend-Benutzerbereiche,
- Seiten mit dem Haken "In Suche ausschließen" (`no_search`),
- Seiten mit "noindex" (nur wenn EXT:seo installiert ist),
- Inhalte im Arbeitsbereich (Workspace) - der Index kennt nur "Live".

### Einmalig nach der Aktualisierung nötig

TYPO3-Backend, dann Admin Tools, Maintenance, **Analyze Database Structure**
ausführen und die vorgeschlagenen Änderungen übernehmen. Dabei entsteht die
Tabelle `tx_accessiblechatbot_index`.

### Index aufbauen

Im Hauptverzeichnis der TYPO3-Installation:

```
ddev exec typo3/sysext/core/bin/typo3 accessible-chatbot:index
```

Nur eine bestimmte Website:

```
ddev exec typo3/sysext/core/bin/typo3 accessible-chatbot:index --site=osm
```

Ohne DDEV entfällt jeweils das vorangestellte `ddev exec`.

Der Befehl ist beliebig oft wiederholbar. Je Website wird der alte Bestand in
einer einzigen Datenbank-Transaktion durch den neuen ersetzt - es gibt also
nie einen Moment, in dem der Index leer wäre.

### Automatische Aktualisierung beim Bearbeiten

Wird im Backend eine Seite oder ein Inhaltselement angelegt, geändert,
versteckt, verschoben oder gelöscht, wird die betroffene Seite sofort neu
indexiert. Bei Änderungen an einer Seite, die auch Unterseiten betreffen
können (verstecken, Datum, Zugriffsgruppe, verschieben, löschen), wird der
gesamte Unterbaum neu bewertet.

**Ausnahme:** Beim **Kopieren** von Seiten oder Inhaltselementen wird der
Index nicht sofort aktualisiert. Die Kopie erscheint, sobald sie einmal
gespeichert wird - spätestens beim nächtlichen Voll-Reindex.

**Weitere Ausnahme:** Beim **Veröffentlichen eines Arbeitsbereichs
(Workspace)** greift der Hook bewusst nicht - er reagiert nur auf
Änderungen im Live-Arbeitsbereich. Veröffentlichte Inhalte erscheinen im
Index deshalb erst beim nächsten Voll-Reindex.

### Nächtlicher Reindex einrichten (Pflicht)

Es gibt einen Fall, den kein Automatismus abfangen kann: **zeitgesteuerte
Sichtbarkeit**. Läuft das Enddatum einer Seite um 14:00 Uhr ab, speichert
niemand etwas im Backend - es passiert schlicht nichts, was ein Programm
bemerken könnte. Nur ein regelmäßiger kompletter Neuaufbau hält den Index
dann korrekt.

Der Chat selbst prüft vor jeder Antwort zusätzlich gegen die aktuelle
Sichtbarkeit - sowohl die höchstens fünf Treffer als auch die Seitenliste
(Sitemap-Kompakt) im Hintergrund. Eine bereits abgelaufene Seite wird also
weder in einer Antwort erwähnt noch taucht ihr Titel in der Seitenliste auf,
die der KI mitgegeben wird. Ihr **Text** bleibt aber bis zum nächsten Reindex
in der Index-Tabelle stehen und zählt dadurch weiterhin bei der Trefferauswahl
mit, ohne selbst ausgegeben zu werden. Der nächtliche Voll-Reindex ist deshalb
keine Empfehlung, sondern **zwingend einzurichten**.

TYPO3 kann CLI-Befehle direkt als Scheduler-Aufgabe ausführen; ein eigener
Aufgabentyp ist nicht nötig.

1. Systemerweiterung `scheduler` aktivieren, falls noch nicht geschehen
   (Admin Tools, Extensions).
2. TYPO3-Backend, dann **System**, dann **Scheduler**.
3. Auf **+** (neue Aufgabe) klicken.
4. Bei **Class** den Eintrag **Execute console commands** wählen.
5. Bei **Type** **Recurring** wählen.
6. Bei **Frequency** eine nächtliche Zeit eintragen, zum Beispiel
   `0 3 * * *` (täglich um 3 Uhr morgens).
7. Bei **Schedulable Command** den Eintrag **accessible-chatbot:index**
   auswählen.
8. Speichern.

Zusätzlich muss der Scheduler selbst regelmäßig laufen. Auf einem normalen
Server geschieht das über einen Cronjob, der
`typo3/sysext/core/bin/typo3 scheduler:run` aufruft. Bei DDEV lässt sich die
Aufgabe zum Testen im Scheduler-Modul über das Play-Symbol von Hand starten.

### Geschützte Bereiche und eingeloggte Nutzer

Seiten mit einer Zugriffsgruppe (Login-Bereiche) werden **gar nicht erst
indexiert**. Der Chatbot kann sie deshalb weder erwähnen noch ansteuern -
auch dann nicht, wenn jemand angemeldet ist.

Das ist eine bewusste Entscheidung, und der Grund ist kein technischer:
Um eine Frage zu beantworten, muss der Server den betreffenden Seitentext in
die Anfrage an den KI-Dienst schreiben. Bei einem externen Anbieter verlassen
diese Inhalte damit die eigene Infrastruktur. Für personenbezogene Daten aus
geschützten Bereichen (Vertrags-, Konto-, Gesundheitsdaten) fehlt dafür die
Grundlage: Für das kostenlose Gemini-Kontingent bietet Google keinen
Auftragsverarbeitungsvertrag nach Art. 28 DSGVO an, und es liegt eine
Drittlandübermittlung vor.

Die Architektur bleibt dafür trotzdem offen: Die Spalte `fe_groups` und die
Filterlogik sind von Anfang an vorhanden. Sie enthält heute allerdings nie
eine echte Zugriffsgruppe: geschützte Seiten werden gar nicht erst indexiert
(siehe oben). Die Spalte ist damit eine vorbereitete, noch leere Schiene für
den späteren eigenen KI-Server. Sobald ein **selbst betriebener KI-Server**
eingesetzt wird - die Extension ist dafür über `AiProviderInterface`
vorbereitet - verlassen die Inhalte die eigene Infrastruktur nicht mehr, und
geschützte Bereiche für angemeldete Nutzende werden zu einer verantwortbaren
Erweiterung. Nötig wären dann: der Indexer läuft mit den Gruppen des
jeweiligen Zugriffs statt anonym, und die Abfrage filtert anhand der
angemeldeten Sitzung (nicht anhand von Angaben aus dem Browser).

### Erweiterungspunkt für Entwicklerinnen und Entwickler

Vor dem Speichern jedes Datensatzes wird das PSR-14-Event
`Extension14v\AccessibleChatbot\Event\ModifyPageIndexRecordEvent`
ausgelöst. Damit lassen sich zusätzliche Inhalte (etwa News-Datensätze oder
Plugin-Ausgaben) anhängen, ohne diese Extension zu ändern.

**Achtung:** Alles, was dort angehängt wird, landet später im Prompt an den
KI-Dienst. Es dürfen ausschließlich Inhalte angehängt werden, die ein
anonymer Besucher auf dieser Seite auch selbst sehen könnte.

### Offener Punkt für TYPO3 v14

Die automatische Aktualisierung nutzt die klassischen DataHandler-Hooks
`processDatamapClass` und `processCmdmapClass`. In TYPO3 13.4 gibt es für
diese beiden Zeitpunkte nachweislich kein PSR-14-Event; die Hooks sind der
einzige Weg. Nach Stand des v14-Changelogs sind beide Hooks dort unverändert
vorhanden - es gibt keinen Breaking- oder Deprecation-Eintrag dazu. Das ist
noch keine endgültige Bestätigung: in Phase 9 (Kompatibilität) wird das an
einer echten v14-Installation überprüft. Die Registrierung steht deshalb
bewusst an genau einer Stelle (`ext_localconf.php`) und ist dort leicht
austauschbar.

## Datenschutz - Textbaustein für die Datenschutzerklärung

> **KI-Assistent auf dieser Website**
>
> Diese Website bietet einen Chat-Assistenten an. Wenn Sie ihn benutzen, werden
> Ihre eingegebene Nachricht und die letzten Nachrichten des laufenden Gesprächs
> an den Dienst "Gemini API" der Google LLC übermittelt, um eine Antwort zu
> erzeugen. Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (berechtigtes
> Interesse an einem barrierearmen Zugang zu den Informationen dieser Website).
>
> **Drittlandübermittlung:** Die Verarbeitung findet auch in den USA statt. Die
> Google LLC ist unter dem EU-US Data Privacy Framework zertifiziert; die
> Übermittlung stützt sich hierauf sowie ergänzend auf die
> Standardvertragsklauseln der EU-Kommission.
>
> **Verwendung zu Trainingszwecken:** Google verwendet Inhalte aus dem
> kostenlosen Kontingent ("Free Tier") der Gemini API zur Verbesserung der
> eigenen Modelle. Für Konten mit Rechnungsadresse im EWR, in der Schweiz und
> im Vereinigten Königreich gelten automatisch die Bedingungen des
> kostenpflichtigen Kontingents - dort findet **keine** Verwendung zu
> Trainingszwecken statt. [Betreiber-Hinweis: Diesen Absatz an das tatsächlich
> genutzte Konto anpassen und den nicht zutreffenden Teil streichen.]
>
> **Speicherung:** Es findet keine Speicherung der Gesprächsinhalte auf dem
> Server dieser Website statt. Der Gesprächsverlauf wird ausschließlich im
> Speicher Ihres Browsers (`sessionStorage`) gehalten und automatisch gelöscht,
> sobald Sie den Browser-Tab schließen. Es werden keine Gesprächsinhalte
> protokolliert; in den Fehlerprotokollen des Servers stehen ausschließlich
> Fehlerart und HTTP-Statuscode.
>
> **Missbrauchsschutz:** Zur Begrenzung der Anfragen wird ein aus Ihrer
> IP-Adresse berechneter, nicht rückrechenbarer Prüfwert für maximal 24 Stunden
> gespeichert. Die IP-Adresse selbst wird dafür nicht gespeichert.

Dieser Baustein ist ein Entwurf und ersetzt keine Rechtsberatung. Betreiberinnen
und Betreiber sind für die Prüfung selbst verantwortlich.

## Wenn etwas nicht funktioniert

| Beobachtung | Ursache und Abhilfe |
| --- | --- |
| "Der Assistent ist noch nicht eingerichtet." | Kein API-Key gesetzt, Key ungültig (HTTP 401/403) oder ein anderer `provider` als `gemini` eingetragen. |
| Meldung "nicht erreichbar", im Log steht `HTTP status 404` | Der eingestellte Modellname existiert nicht (mehr) oder ist für neue Zugänge gesperrt. Siehe "Hinweis zum Modellnamen". |
| Jede Nachricht endet mit "nicht erreichbar" | Admin Tools, Maintenance, Analyze Database Structure ausführen (Cache-Tabellen fehlen). Danach Admin Tools, Log prüfen. |
| Im Browser erscheint ein CSP-Fehler | Ist eine Content Security Policy aktiv, muss `connect-src` mindestens `'self'` erlauben. |
| Antwort dauert und bricht dann ab | Der Server hält insgesamt höchstens 30 Sekunden durch, auch wenn er die Anfrage zwischendurch wiederholen muss. Der Browser bricht nach 35 Sekunden ab. |
| Chat antwortet immer mit "Diese Anfrage war nicht erlaubt. Bitte die Seite neu laden und es noch einmal versuchen." | Site Set "Barrierefreier Chatbot" ist der Site nicht zugewiesen (siehe "Wichtig bei Weg B") - oder der Schalter "Chatbot aktivieren" in den Site Settings ist ausgeschaltet. |
| `accessible-chatbot:index` meldet "Table 'tx_accessiblechatbot_index' doesn't exist" | Admin Tools, Maintenance, **Analyze Database Structure** ausführen. |
| Der Befehl meldet "Es ist keine Website konfiguriert" | Unter Site Management, Sites muss mindestens eine Website mit Startseite angelegt sein. |
| Eine versteckte Seite steht trotzdem im Index | Erst prüfen, ob es wirklich dieselbe Seite ist (Übersetzungen sind eigene Datensätze). Dann `accessible-chatbot:index` erneut ausführen und Admin Tools, Log prüfen. |
| Eine sichtbare Seite fehlt im Index | Häufigste Ursachen: Haken "In Suche ausschließen", Seitentyp Systemordner/Trenner, oder eine übergeordnete Seite mit "Für Unterseiten übernehmen" und Einschränkung. |
| Der Assistent bietet keinen Knopf an, obwohl ausdrücklich nach dem Weg gefragt wurde | Die Zielseite steht nicht im Index oder ist gerade nicht sichtbar, oder der KI-Dienst hat als normale Antwort geantwortet. Admin Tools, Log prüfen: `Navigation target rejected` bedeutet, dass ein Ziel vorgeschlagen wurde, das nicht erlaubt war - die Antwort wird dann ganz normal als Text ausgegeben. |

**Hinweis für bestehende Installationen:** Die Einstellung `accessiblechatbot.autoNavigate`
wurde mit Phase 6 entfernt, weil sie durch das serverseitig geprüfte
Navigationsangebot ersetzt wurde. Steht sie noch in der `settings.yaml` einer
Site, richtet das keinen Schaden an, ist aber überflüssig geworden und sollte
von Hand entfernt werden.

## Verhalten ohne JavaScript

Das Widget ist im HTML von Anfang an mit dem `hidden`-Attribut ausgeliefert und
wird erst durch JavaScript sichtbar gemacht. Ist JavaScript deaktiviert oder
blockiert, erscheint gar kein Chat-Button, und es entstehen keine Fehler auf der
Seite.

Der Chatbot ist ausdrücklich eine Ergänzung und niemals der einzige Zugangsweg zu
Informationen. Betreiberinnen und Betreibern wird dringend empfohlen, eine normale,
gut auffindbare Kontaktseite als Alternative anzubieten.

## Datenschutz

Der Gesprächsverlauf wird ausschließlich im Browser im `sessionStorage` unter dem
Schlüssel `accessibleChatbot` gehalten. Er übersteht Reload und Seitenwechsel im
selben Tab und wird vom Browser automatisch gelöscht, sobald der Tab geschlossen
wird. Es findet keinerlei serverseitige Speicherung statt.

**Spracheingabe:** Wird der Mikrofon-Knopf benutzt, verwendet das Widget die
Spracherkennung des Browsers (Web Speech API). Chrome, Edge und Safari übertragen
die Aufnahme dabei an die Spracherkennungs-Dienste ihres jeweiligen Herstellers -
nicht an diese Website und nicht an den KI-Dienst. Firefox unterstützt die Funktion
standardmäßig nicht; dort erscheint der Knopf gar nicht erst, sondern an
seiner Stelle ein sichtbarer Hinweistext (siehe Abschnitt „Barrierefreiheit
im Gesprächsverlauf"). Ist die Spracheingabe verfügbar, steht unter dem
Eingabefeld stattdessen der Hinweis auf diesen Datenfluss. Betreiberinnen und
Betreiber sollten diesen Datenfluss in ihrer Datenschutzerklärung erwähnen.

## Lizenz

Proprietär. one4vision GmbH
