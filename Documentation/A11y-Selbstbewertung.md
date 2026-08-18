# Barrierefreiheits-Selbstbewertung — EXT:accessible_chatbot

**Datum: 2026-08-18**

Dies ist die datierte Selbstbewertung aus Phase 8, Aufgabe 9 von `CONCEPT.md`, Abschnitt 9.4. Sie fasst zusammen, was die ausführliche Prüfung in [`A11y-Checklist.md`](A11y-Checklist.md) im Detail zeigt: welche technische Grundlage gilt, welche der drei zulässigen Konformitätsstufen zutrifft, wie und wann geprüft wurde — und, ausdrücklich, was **nicht** behauptet wird.

## (a) Technische Grundlage

**WCAG 2.2, Stufe AA**, ergänzt um Muster aus dem W3C-Dokument COGA („Cognitive and Learning Disabilities Accessibility Task Force"). Rechtlicher Hintergrund: **EN 301 549**, die europäische Norm, auf die sowohl das deutsche BFSG (Barrierefreiheitsstärkungsgesetz) als auch die BITV 2.0 (Barrierefreie-Informationstechnik-Verordnung, für öffentliche Stellen) verweisen. Die aktuell referenzierte Fassung EN 301 549 V3.2.1 verlangt WCAG 2.1 A/AA; diese Extension zielt bewusst über diese Basis hinaus auf WCAG 2.2 AA, weil die vier dort neuen relevanten Kriterien (2.4.11, 2.5.8, 3.2.6, 3.3.7) genau ein schwebendes Chat-Widget wie dieses betreffen (CONCEPT 9.1).

## (b) Konformitätsstufe

> ## **Teilweise konform**

Von den drei nach CONCEPT 9.4 zulässigen Stufen — „vollständig konform", „teilweise konform", „nicht konform" — trifft **„teilweise konform"** zu.

**Begründung:** Der überwiegende Teil der Anforderungen aus CONCEPT Abschnitt 8 ist im Quelltext nachgewiesen oder durch automatisierte Tests abgesichert (Details in der Checkliste). Für die Stufe „vollständig konform" fehlt aber Wesentliches, das sich grundsätzlich nicht am Quelltext oder per Skript entscheiden lässt:

- Die **manuelle Testphase** aus Phase 8 wurde für den aktuellen Stand (Dunkelmodus, Fokusring am Auslöser, beide erst heute fertiggestellt) noch nicht durchlaufen — insbesondere WCAG 2.4.11 „Focus Not Obscured" in seiner vollständigen Form und WCAG 3.2.6 „Consistent Help".
- Die geforderten **zwei Screenreader-/Browser-Kombinationen** (mindestens Orca + Firefox und NVDA + Firefox/Chrome, CONCEPT 8.11) sind für den aktuellen Stand nicht durchgeführt.
- **Safari (WebKit) ist von der automatisierten Prüfung nicht erfasst**, weil WebKit in der verwendeten Container-Umgebung nicht startet.

Eine Anforderung, die nicht geprüft wurde, ist keine erfüllte Anforderung — auch dann nicht, wenn der Quelltext dafür spricht. Deshalb steht hier „teilweise", nicht „vollständig", konform.

**Was müsste passieren, damit die Stufe auf „vollständig konform" wechselt:** Alle drei oben genannten Punkte müssten nachgeholt werden — vollständige Handprüfung von 2.4.11 und 3.2.6, mindestens zwei reale Screenreader-/Browser-Durchläufe, und eine begründete Aussage zu Safari (entweder ein funktionierender WebKit-Testlauf oder ein dokumentierter manueller Test in echtem Safari). Zusätzlich, aus den neu dokumentierten Punkten dieser Aktualisierung: eine Entscheidung und gegebenenfalls Umsetzung zum fehlenden zusammenklappbaren Auslöse-Knopf (CONCEPT 8.4, Lückenliste Punkt 10) sowie eine Handprüfung von WCAG 1.4.12 Text Spacing bei 320 px und Normalbreite (Lückenliste Punkt 13). Erst wenn diese Lücken geschlossen und in der Checkliste als „erfüllt" statt „offen"/„Lücke" eingetragen sind, wäre eine höhere Stufe zutreffend — und selbst dann bliebe es eine Selbstbewertung, siehe unten.

## (c) Prüfmethode und Datum

**Datum:** 2026-08-18

**Methode:**

1. **Code-Review gegen CONCEPT Abschnitt 8**, Punkt für Punkt, mit Fundstelle in Datei und Zeile — dokumentiert in [`A11y-Checklist.md`](A11y-Checklist.md).
2. **Automatisierte Prüfung mit axe-core über Playwright** gegen die Tags `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa` — in den Browsern Chromium, Firefox und Mobile Chrome, jeweils in hellem und dunklem Farbschema, für das geschlossene Widget, das geöffnete Widget und die offene Rückfrage „Neues Gespräch", zusätzlich Reflow-Prüfung bei 320 px Breite und die Vorbedingung von WCAG 2.4.11. Ergebnis: 30 von 30 Tests grün.

**Nicht Teil dieser Methode:** ein manueller Durchgang mit echten Screenreadern, ein echter Zoom-/Reflow-Test bei 400 % im Browser, und jede Prüfung in Safari/WebKit.

## (d) Offene Lückenliste

Diese Liste ist ehrlich und vollständig — jeder Punkt entspricht einer Zeile in [`A11y-Checklist.md`](A11y-Checklist.md), die dort als „Lücke" oder „offen (Handprüfung nötig)" geführt wird:

| # | Lücke | Betrifft |
|---|---|---|
| 1 | Manuelle Testphase (Handprüfung) nicht durchgeführt | WCAG 2.4.11 (vollständig), WCAG 3.2.6, CONCEPT 8.4 und 8.6 |
| 2 | Zwei geforderte Screenreader-/Browser-Kombinationen nicht durchgeführt | CONCEPT 8.11 |
| 3 | Safari/WebKit nicht automatisiert geprüft (startet im verwendeten Container nicht) | CONCEPT 8.11 |
| 4 | Kein Sprunglink zum Chat (Landmark ist vorhanden und erfüllt die Anforderung formal, ersetzt für rein tastaturgestützte, sehende Nutzende ohne Screenreader aber keine Landmark-Liste) | CONCEPT 8.10 |
| 5 | Restrisiko bei der Verfügbarkeitsabfrage der geräteinternen Spracherkennung: in betroffenen Browsern weiterhin möglicher Absturz des Renderer-Prozesses, ohne dass sich der kaputte Fall vorher erkennen lässt | CONCEPT 8.9 / 0.3.2 |
| 6 | Bei `headingLevel = 6` fallen Dialogtitel und versteckte Verlaufs-Überschrift beide auf `<h6>`, die vorgesehene Verschachtelung flacht ab (keine übersprungene Ebene, also keine WCAG-1.3.1-Verletzung) | CONCEPT 8.2 |
| ~~7~~ | ~~`overscroll-behavior: contain` fehlt am inneren Bildlaufbereich `.acb-log`~~ — **entfernt am 18.08.2026, war ein Fehleintrag.** Das Fehlen ist beabsichtigt: `contain` sitzt korrekt auf `.acb-dialog` und würde auf `.acb-log` Mausnutzenden den Weg zum Formular versperren. Im Quelltext geprüft, gilt jetzt als erfüllt (siehe `A11y-Checklist.md`, Abschnitt 8.5). | — |
| 8 | Register „Einfache Sprache" (kurze Sätze, keine Fachbegriffe, < 100 Wörter) ist eine Regel im KI-Systemprompt, nicht strukturell im Code erzwingbar — ob eine konkrete KI-Antwort sie einhält, lässt sich nur im tatsächlichen Gespräch beurteilen, nicht am Quelltext | CONCEPT 8.7 |
| 9 | Sprachformen und Gender-Vorgaben in anderen Sprachen als Deutsch sind ebenfalls Systemprompt-Regeln und nur im echten Gespräch überprüfbar | CONCEPT 8.8 |
| 10 | Kein zusammenklappbarer Auslöse-Knopf: CONCEPT 8.4 verlangt ihn ausdrücklich als dritte Abhilfe gegen WCAG 2.4.11, er existiert im Code aber nicht. Das geschlossene Widget kann dadurch dauerhaft ein fokussiertes Element der Gastseite in der unteren rechten Ecke vollständig verdecken (z. B. „Nach oben"-Knopf, Cookie-Banner-Bedienelement, Fußzeilen-Link). Betrifft sehende Tastaturnutzende und Personen mit Sehbeeinträchtigung bei hoher Vergrößerung | CONCEPT 8.4 |
| 11 | Drei Inline-Links unterschreiten 24×24 px (Datenschutz-Link, Kontaktlink in der System-Hinweiszeile, Link neben dem Kontakt-Hinweistext). WCAG 2.5.8 erlaubt das für Links innerhalb eines Satzes ausdrücklich - keine WCAG-Verletzung, aber CONCEPT 8.5 formuliert strenger als die Norm | CONCEPT 8.5 |
| 12 | Die Live-Region `#acb-status` liegt im initialen HTML, aber innerhalb des bei geschlossenem Widget `hidden` gesetzten `#acb-dialog` und ist damit während dieser Zeit nicht Teil des Accessibility-Baums. Eine bei geschlossenem Widget gepufferte Ansage wird beim Öffnen mit Verzögerung nachgeholt (inzwischen 700 statt 120 ms) - ob das für alle relevanten Screenreader ausreicht, ist nur mit einem echten Screenreader zu klären | CONCEPT 8.3 |
| 13 | WCAG 1.4.12 Text Spacing (AA) fehlt in CONCEPT §8 vollständig und ist entsprechend in der Checkliste nicht geprüft. Die Extension setzt `line-height`, `letter-spacing` und `text-transform` defensiv gegen geerbtes Site-CSS zurück - genau das Muster, das bei 1.4.12 klassisch scheitert. Die Anzeichen (rem-basiertes, flexibles Layout ohne feste Höhen) sprechen für ein Bestehen, sind aber kein Nachweis; das ist eine Lücke im Konzept selbst, nicht nur in der Umsetzung | fehlt in CONCEPT §8 |
| 14 | Offener Widerspruch zwischen CONCEPT 8.2 und CONCEPT 8.9: der Auslöser trägt sowohl `aria-expanded` als auch eine wechselnde Beschriftung - genau die Kombination, die CONCEPT 8.9 für das Mikrofon ausdrücklich verbietet, weil sie den Zustand doppelt ansagt. Kein Code-Fehler, sondern ein Widerspruch im Konzept selbst; eine Entscheidung von Momo steht noch aus | CONCEPT 8.2 vs. 8.9 |

Für Details, Fundstellen und die jeweilige Begründung siehe die passende Zeile in [`A11y-Checklist.md`](A11y-Checklist.md).

---

## Was hier ausdrücklich NICHT behauptet wird

Dieser Abschnitt ist nach CONCEPT 9.4 verbindlicher Bestandteil dieses Dokuments, nicht optionale Höflichkeit.

- **Es wird keine Konformität behauptet.** Nicht „WCAG 2.2 AA konform", nicht „BITV-konform", nicht „barrierefrei" als Gütesiegel — an keiner Stelle dieser Extension, dieser Dokumentation oder ihrer Beschreibungstexte.
- **Der offizielle deutsche Test heißt BITV-Test** (98 Prüfschritte gegen EN 301 549, fünfstufige Bewertung). Er kann **ausschließlich** von qualifizierten menschlichen Prüfenden an akkreditierten BIK-Prüfstellen (Barrierefreies Informieren und Kommunizieren) durchgeführt und zertifiziert werden. Eine Selbstzertifizierung für das offizielle Prüfzeichen **gibt es nicht** — auch nicht mit der sorgfältigsten Selbstbewertung.
- **Dieses Dokument ist eine Selbstbewertung**, kein Testat einer akkreditierten Stelle. Ihr Wert liegt darin, ehrlich, datiert, nachvollziehbar und mit einer offenen Lückenliste zu sein — nicht darin, ein Siegel zu ersetzen. Eine unbelegte Lücke wird in [`A11y-Checklist.md`](A11y-Checklist.md) konsequent als Lücke ausgewiesen, nicht stillschweigend als erfüllt gewertet.

## Was Betreiberinnen und Betreiber daraus machen sollten

Dieses Dokument bewertet **ausschließlich die Extension** — das Chat-Widget selbst, seine Bedienung, seine Struktur, seinen Code. Es bewertet **nicht** die Website, in die es eingebunden wird.

Eine Website, die dieses Widget einsetzt, braucht deshalb ihre **eigene** Barrierefreiheitserklärung, die die **gesamte** Website abdeckt — Navigation, Inhalte, andere eingebundene Erweiterungen, das verwendete Theme und alles andere, was diese Extension nicht kennt und nicht beeinflusst. Diese Selbstbewertung kann als **ein Baustein** in eine solche Erklärung einfließen, ersetzt sie aber nicht.

Wer für eine Website mit diesem Chatbot eine rechtsverbindliche Konformitätsaussage benötigt (etwa als öffentliche Stelle nach BITV 2.0 oder als BFSG-pflichtiger Wirtschaftsakteur), muss dafür eine akkreditierte BIK-Prüfstelle beauftragen. Diese Selbstbewertung ist dafür Ausgangsmaterial, kein Ersatz.

---

Verwandtes Dokument: [`A11y-Checklist.md`](A11y-Checklist.md) — die vollständige Punkt-für-Punkt-Prüfung, aus der diese Selbstbewertung ihre Einordnung ableitet.
