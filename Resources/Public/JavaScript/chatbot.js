/*
 * EXT:accessible_chatbot - Chat-Widget (Phase 1)
 *
 * Reines ES-Modul: keine Imports, keine externen Bibliotheken, kein Build-Schritt.
 * Eingebunden ueber page.includeJSFooter mit type = module.
 *
 * Grundregel dieser Datei: Nachrichtentext wird AUSSCHLIESSLICH ueber
 * textContent gesetzt, niemals ueber innerHTML. Damit kann auch eine spaeter
 * vom Server gelieferte Antwort kein HTML oder JavaScript einschleusen.
 */

const STORAGE_KEY = 'accessibleChatbot';

/* Adresse des Server-Endpunkts, relativ zur Basis der TYPO3-Installation. */
const ENDPOINT_PATH = '-/accessible-chatbot/message';

/* Etwas mehr als das Server-Zeitlimit (30 s), damit der Server zuerst antwortet. */
const REQUEST_TIMEOUT_MS = 35000;

/* Muss zu den Grenzen der Server-Pruefung passen (Konzept 4.2). */
const MAX_HISTORY_ENTRIES = 10;
const MAX_HISTORY_TEXT_LENGTH = 2000;

/*
 * Wahrheitswerte aus data-Attributen (z. B. data-acb-auto-navigate) koennen
 * je nach Einbindungsweg "", "0" oder "1" lauten. Ab Phase 5 daher immer
 * streng auf === '1' pruefen, niemals nur auf "wahrheitsartig".
 */

const widgetElement = document.getElementById('acb-widget');

if (widgetElement !== null) {
    initialiseWidget(widgetElement);
}

/* ------------------------------------------------------------------ *
 * Hilfsfunktionen ohne Zustand
 * ------------------------------------------------------------------ */

/**
 * Liest alle Oberflaechentexte aus dem <template id="acb-i18n">-Block.
 * So steht keine einzige uebersetzbare Zeichenkette im JavaScript.
 */
/**
 * Baut die Adresse des Endpunkts.
 *
 * Grundlage ist der Pfad-Anteil der TYPO3-Installation, den der Server als
 * data-Attribut mitgibt (z. B. "/" oder "/unterverzeichnis/"). Die Herkunft
 * (Schema und Host) kommt immer aus der aktuell aufgerufenen Adresse - so
 * bleibt die Anfrage garantiert bei derselben Herkunft.
 */
function buildEndpointUrl(basePath) {
    try {
        return new URL(ENDPOINT_PATH, new URL(basePath || '/', window.location.href)).href;
    } catch (error) {
        return new URL('/' + ENDPOINT_PATH, window.location.origin).href;
    }
}

/**
 * Erzeugt einen Fehler, der den Schluessel seines Anzeigetextes mitfuehrt.
 * So entscheidet nur eine Stelle im Code, welcher Text erscheint.
 */
function createRequestError(labelKey) {
    const error = new Error(labelKey);
    error.acbLabel = labelKey;

    return error;
}

/** Liest eine Zahl aus einem data-Attribut; alles Ungueltige wird zu 0. */
function readNumber(value) {
    const parsed = Number.parseInt(value ?? '', 10);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function readLabels(root) {
    const labels = {};
    const template = root.querySelector('#acb-i18n');

    if (template === null) {
        return labels;
    }

    template.content.querySelectorAll('[data-acb-key]').forEach((node) => {
        labels[node.dataset.acbKey] = node.textContent.trim();
    });

    return labels;
}

/** Der Startzustand, wenn im sessionStorage noch nichts liegt. */
function createDefaultState() {
    return {
        messages: [],
        pendingNavigation: null,
        isOpen: false,
        genderStyle: 'pair',
    };
}

function isValidMessage(entry) {
    return Boolean(entry)
        && typeof entry.text === 'string'
        && (entry.role === 'user' || entry.role === 'bot');
}

/**
 * Der sessionStorage ist vom Browser aus beschreibbar. Ein Navigationsziel
 * wird daher nur uebernommen, wenn es die erwartete Form hat UND auf die
 * eigene Domain zeigt - sonst wird es verworfen.
 */
function readPendingNavigation(value) {
    if (!value || typeof value.url !== 'string' || typeof value.title !== 'string') {
        return null;
    }

    try {
        const target = new URL(value.url, window.location.href);

        if (target.origin !== window.location.origin) {
            return null;
        }

        return { url: target.href, title: value.title };
    } catch (error) {
        return null;
    }
}

/**
 * Liest den Verlauf aus dem sessionStorage. sessionStorage ueberlebt Reload
 * und Seitenwechsel im selben Tab und wird vom Browser geloescht, sobald der
 * Tab geschlossen wird - genau das verlangt das Datenschutzkonzept.
 * Fremde oder beschaedigte Daten werden bewusst verworfen statt uebernommen.
 */
function readState() {
    try {
        const raw = window.sessionStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return createDefaultState();
        }

        const parsed = JSON.parse(raw);

        return {
            messages: Array.isArray(parsed.messages) ? parsed.messages.filter(isValidMessage) : [],
            pendingNavigation: readPendingNavigation(parsed.pendingNavigation),
            isOpen: parsed.isOpen === true,
            genderStyle: parsed.genderStyle === 'colon' ? 'colon' : 'pair',
        };
    } catch (error) {
        return createDefaultState();
    }
}

/**
 * sessionStorage kann blockiert (privater Modus) oder voll sein.
 * Dann laeuft das Widget einfach ohne gespeicherten Verlauf weiter.
 */
function writeState(state) {
    try {
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    } catch (error) {
        /* bewusst ignoriert */
    }
}

/** Baut ein Listenelement fuer eine Nachricht - Absender immer als sichtbarer Text. */
function createMessageElement(message, labels) {
    const item = document.createElement('li');
    item.className = message.role === 'user' ? 'acb-msg acb-msg--user' : 'acb-msg acb-msg--bot';

    const sender = document.createElement('span');
    sender.className = 'acb-msg__sender';
    sender.textContent = (message.role === 'user' ? labels['sender.user'] : labels['sender.bot']) ?? '';

    const text = document.createElement('span');
    text.className = 'acb-msg__text';
    text.textContent = message.text;

    item.append(sender, ' ', text);

    return item;
}

/* ------------------------------------------------------------------ *
 * Widget
 * ------------------------------------------------------------------ */

function initialiseWidget(root) {
    const labels = readLabels(root);

    // Vom Server mitgegebene Werte (siehe Widget.typoscript, Block "variables").
    const endpointUrl = buildEndpointUrl(root.dataset.acbBasePath);
    const pageUid = readNumber(root.dataset.acbPageUid);
    const languageUid = readNumber(root.dataset.acbLanguageUid);
    // Fertige Adresse vom Server. Wird nur als Fallback in Stoermeldungen
    // angeboten - das JavaScript baut selbst nie eine Adresse.
    const contactUrl = root.dataset.acbContactUrl || '';

    const refs = {
        toggle: root.querySelector('#acb-toggle'),
        toggleLabel: root.querySelector('#acb-toggle-label'),
        dialog: root.querySelector('#acb-dialog'),
        closeButton: root.querySelector('#acb-close'),
        log: root.querySelector('#acb-log'),
        list: root.querySelector('#acb-log-list'),
        status: root.querySelector('#acb-status'),
        form: root.querySelector('#acb-form'),
        send: root.querySelector('#acb-send'),
        input: root.querySelector('#acb-input'),
        micButton: root.querySelector('#acb-mic'),
        genderInputs: Array.from(root.querySelectorAll('input[name="acb-gender-style"]')),
    };

    // Fehlt ein Pflichtteil, bleibt das Widget lieber unsichtbar als halb bedienbar.
    if (!refs.toggle || !refs.toggleLabel || !refs.dialog || !refs.closeButton
        || !refs.log || !refs.list || !refs.status || !refs.form || !refs.send || !refs.input) {
        return;
    }

    const state = readState();
    let typingElement = null;
    let awaitingReply = false;

    /* ---------- kleine Helfer mit Zugriff auf den Zustand ---------- */

    function scrollLogToEnd() {
        refs.log.scrollTop = refs.log.scrollHeight;
    }

    /**
     * Schreibt eine Meldung in die Statuszeile (role="status" kuendigt sie an).
     *
     * Der Umweg ueber das Leeren und den naechsten Frame ist noetig, damit
     * auch zweimal dieselbe Meldung vorgelesen wird: ohne DOM-Aenderung
     * bleibt der Screenreader sonst stumm.
     */
    function announce(message) {
        refs.status.textContent = '';

        if (message) {
            window.requestAnimationFrame(() => {
                refs.status.textContent = message;
            });
        }
    }

    function addMessage(message) {
        state.messages.push(message);
        writeState(state);
        refs.list.append(createMessageElement(message, labels));
        scrollLogToEnd();
    }

    /**
     * Schreibt eine Hinweiszeile ins Gespraechsprotokoll.
     *
     * Diese Zeilen werden bewusst NICHT im sessionStorage gespeichert und
     * nicht an den Server mitgeschickt: sie gehoeren nicht zum Gespraech,
     * sondern erklaeren eine Stoerung.
     *
     * Barrierefreiheit: das Protokoll ist role="log" mit aria-live="polite" -
     * eine neue Zeile wird dadurch automatisch vorgelesen. Der sichtbare
     * Vorspann "Hinweis:" sorgt dafuer, dass die Art der Nachricht nicht
     * allein an der Farbe haengt.
     */
    function addSystemMessage(text) {
        if (!text) {
            return;
        }

        const item = document.createElement('li');
        item.className = 'acb-msg acb-msg--system';

        const sender = document.createElement('span');
        sender.className = 'acb-msg__sender';
        sender.textContent = labels['sender.system'] ?? '';

        const body = document.createElement('span');
        body.className = 'acb-msg__text';
        body.textContent = text;

        item.append(sender, ' ', body);

        // Konzept 6.1: bei Stoerungen soll - falls konfiguriert - ein Weg zur
        // Kontaktseite angeboten werden. Der Link ist ein normales
        // <a>-Element und damit tastaturerreichbar.
        if (contactUrl !== '') {
            const link = document.createElement('a');
            link.className = 'acb-msg__link';
            link.href = contactUrl;
            link.textContent = labels['error.contactlink'] ?? '';
            item.append(' ', link);
        }

        refs.list.append(item);
        scrollLogToEnd();
    }

    function showTyping() {
        if (typingElement !== null) {
            return;
        }

        typingElement = document.createElement('li');
        typingElement.className = 'acb-msg acb-msg--status';
        typingElement.textContent = labels['status.typing'] ?? '';
        refs.list.append(typingElement);
        scrollLogToEnd();
    }

    function hideTyping() {
        if (typingElement !== null) {
            typingElement.remove();
            typingElement = null;
        }
    }

    /**
     * Oeffnet oder schliesst den Dialog und haelt aria-expanded,
     * die Button-Beschriftung und den gespeicherten Zustand synchron.
     *
     * moveFocus = false wird beim Wiederherstellen nach einem Seitenwechsel
     * benutzt: der Fokus darf sich niemals unangekuendigt verschieben.
     */
    function setOpen(open, moveFocus) {
        refs.dialog.hidden = !open;
        refs.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        refs.toggleLabel.textContent = (open ? labels['toggle.close'] : labels['toggle.open']) ?? '';

        state.isOpen = open;
        writeState(state);

        if (open) {
            scrollLogToEnd();

            if (moveFocus) {
                refs.input.focus();
            }
        } else if (moveFocus) {
            refs.toggle.focus();
        }
    }

    /* ---------- Antwortquelle ---------- */

    /**
     * PHASE 2: fragt den eigenen TYPO3-Endpunkt.
     *
     * Dies ist weiterhin die EINZIGE Stelle, an der die Antwortquelle steckt.
     * Signatur (string) und Rueckgabetyp (Promise<string>) sind unveraendert -
     * am uebrigen Code aendert sich dadurch nichts.
     *
     * Fehler werden als Error mit der Eigenschaft acbLabel (Schluessel eines
     * uebersetzten Textes) bzw. acbText (fertiger Text vom Server) geworfen.
     */
    function requestReply(userMessage) {
        if (window.navigator.onLine === false) {
            return Promise.reject(createRequestError('error.offline'));
        }

        // AbortController statt AbortSignal.timeout: gleiche Wirkung,
        // aber in allen Browsern verfuegbar.
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

        // Der Verlauf steht bereits im Zustand. Der LETZTE Eintrag ist die
        // gerade abgeschickte Nachricht - sie wird separat als "message"
        // uebertragen und deshalb hier abgeschnitten.
        const history = state.messages
            .slice(0, -1)
            .slice(-MAX_HISTORY_ENTRIES)
            .map((entry) => ({
                role: entry.role,
                text: entry.text.slice(0, MAX_HISTORY_TEXT_LENGTH),
            }));

        return window.fetch(endpointUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                message: userMessage,
                history: history,
                pageUid: pageUid,
                languageUid: languageUid,
                genderStyle: state.genderStyle,
            }),
            signal: controller.signal,
        })
            .catch(() => {
                // fetch scheitert nur bei Netzwerkfehlern oder nach Abbruch -
                // ein HTTP-Fehlerstatus landet NICHT hier.
                throw createRequestError(controller.signal.aborted ? 'error.timeout' : 'error.offline');
            })
            .then((response) => response.json()
                .catch(() => null)
                .then((data) => ({ response: response, data: data })))
            .then((result) => {
                if (!result.response.ok) {
                    const error = createRequestError('error.request');

                    // Der Server liefert den passenden, uebersetzten Text mit.
                    if (result.data && typeof result.data.message === 'string' && result.data.message !== '') {
                        error.acbText = result.data.message;
                    }

                    throw error;
                }

                if (!result.data || typeof result.data.reply !== 'string' || result.data.reply.trim() === '') {
                    throw createRequestError('error.request');
                }

                return result.data.reply;
            })
            .finally(() => {
                window.clearTimeout(timeout);
            });
    }

    function sendMessage() {
        const text = refs.input.value.trim();

        if (text === '') {
            announce(labels['error.empty'] ?? '');
            refs.input.focus();
            return;
        }

        // Solange eine Antwort laeuft, wird nichts Zweites abgeschickt -
        // aber der Grund wird angesagt, nicht stillschweigend geschluckt.
        // Sonst bekaeme jemand, der den Tipp-Hinweis nicht sehen kann,
        // ueberhaupt keine Rueckmeldung.
        if (awaitingReply) {
            announce(labels['status.typing'] ?? '');
            return;
        }

        announce('');
        addMessage({ role: 'user', text });
        refs.input.value = '';
        showTyping();

        awaitingReply = true;
        // Bewusst aria-disabled statt disabled:
        // - disabled wuerde den Fokus verlieren, wenn der Knopf ihn gerade hat
        //   (Browser setzen ihn dann auf <body> - der Nutzer landet am
        //   Seitenanfang).
        // - disabled unterdrueckt ausserdem das submit-Ereignis. Damit waere
        //   die Ansage oben ("wird gerade geschrieben") nie erreichbar.
        // aria-disabled meldet den Zustand an Screenreader, laesst den Knopf
        // aber fokussierbar und ausloesbar - die Sperre uebernimmt
        // awaitingReply.
        refs.send.setAttribute('aria-disabled', 'true');

        requestReply(text)
            .then((reply) => {
                // Zuerst den Tipp-Hinweis entfernen, dann die Antwort
                // anhaengen - sonst stuende die Antwort kurz ueber dem
                // Hinweis "schreibt eine Antwort ...".
                hideTyping();

                if (reply !== '') {
                    addMessage({ role: 'bot', text: reply });
                }
            })
            .catch((error) => {
                hideTyping();

                // Es darf niemals stilles Schweigen geben: jede Stoerung wird
                // als sichtbare Zeile ins Protokoll geschrieben und dadurch
                // ueber aria-live vorgelesen.
                const errorText = (error && error.acbText)
                    || labels[(error && error.acbLabel) || 'error.request']
                    || labels['error.request']
                    || '';

                addSystemMessage(errorText);
            })
            .finally(() => {
                awaitingReply = false;
                refs.send.removeAttribute('aria-disabled');
            });
    }

    /* ---------- Spracheingabe ---------- */

    function setUpSpeechInput() {
        const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

        // Browser ohne Unterstuetzung (z. B. Firefox in der Standardeinstellung):
        // Der Button bleibt hidden - kein toter Knopf, kein Tabstopp.
        if (!Recognition || !refs.micButton) {
            return;
        }

        const recognition = new Recognition();
        const pageLanguage = document.documentElement.lang;

        if (pageLanguage) {
            recognition.lang = pageLanguage;
        }

        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;

        let listening = false;
        let handled = false;

        refs.micButton.hidden = false;

        const micHint = root.querySelector('#acb-mic-hint');

        if (micHint !== null) {
            micHint.hidden = false;
        }

        refs.micButton.addEventListener('click', () => {
            if (listening) {
                recognition.stop();
                return;
            }

            handled = false;

            try {
                recognition.start();
            } catch (error) {
                announce(labels['mic.error']);
            }
        });

        recognition.addEventListener('start', () => {
            listening = true;
            refs.micButton.setAttribute('aria-pressed', 'true');
            announce(labels['mic.listening']);
        });

        recognition.addEventListener('result', (event) => {
            handled = true;
            refs.input.value = event.results?.[0]?.[0]?.transcript ?? '';
            announce(labels['mic.recognized']);
            refs.input.focus();
        });

        recognition.addEventListener('error', (event) => {
            handled = true;

            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                announce(labels['mic.denied']);
            } else if (event.error === 'no-speech') {
                announce(labels['mic.nospeech']);
            } else {
                announce(labels['mic.error']);
            }
        });

        recognition.addEventListener('end', () => {
            listening = false;
            refs.micButton.setAttribute('aria-pressed', 'false');

            if (!handled) {
                announce(labels['mic.stopped']);
            }
        });
    }

    /* ---------- Start ---------- */

    // 1. Gespeicherten Verlauf wieder aufbauen.
    state.messages.forEach((message) => {
        refs.list.append(createMessageElement(message, labels));
    });

    // 2. Gewaehlte Sprachform wiederherstellen.
    refs.genderInputs.forEach((option) => {
        option.checked = option.value === state.genderStyle;
    });

    // 3. Spracheingabe nur bei Browser-Unterstuetzung freischalten.
    setUpSpeechInput();

    // 4. Erst jetzt sichtbar machen: ohne JavaScript erscheint gar nichts.
    root.hidden = false;

    // 5. Offen/zu wiederherstellen - ohne den Fokus zu bewegen.
    setOpen(state.isOpen, false);

    /* ---------- Ereignisse ---------- */

    refs.toggle.addEventListener('click', () => {
        setOpen(refs.dialog.hidden, true);
    });

    refs.closeButton.addEventListener('click', () => {
        setOpen(false, true);
    });

    // Bewusst am Dokument: Wer nach dem Oeffnen in die Seite geklickt hat,
    // muss den Chat trotzdem mit Escape schliessen koennen.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || refs.dialog.hidden) {
            return;
        }

        // Der Fokus wird nur dann auf den Chat-Knopf zurueckgegeben, wenn er
        // vorher ueberhaupt im Widget stand. Sonst wuerde Escape den Nutzer
        // unangekuendigt aus der Seite ins Widget reissen (Konzept 8.3).
        // Aus demselben Grund wird das Standardverhalten von Escape nur dann
        // unterdrueckt - fremde Komponenten der Seite duerfen nicht blockiert
        // werden.
        const focusWasInsideWidget = root.contains(document.activeElement);

        if (focusWasInsideWidget) {
            event.preventDefault();
        }

        setOpen(false, focusWasInsideWidget);
    });

    refs.form.addEventListener('submit', (event) => {
        event.preventDefault();
        sendMessage();
    });

    refs.genderInputs.forEach((option) => {
        option.addEventListener('change', () => {
            if (option.checked) {
                state.genderStyle = option.value;
                writeState(state);
            }
        });
    });
}
