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

/* Muss zu MAX_SOURCE_LINKS im ChatService passen. */
const MAX_SOURCE_LINKS = 3;

/* Muss zu MAX_CLARIFY_CHOICES im ChatService passen. */
const MAX_CLARIFY_CHOICES = 3;

/*
 * Obergrenze fuer Titel, die aus dem sessionStorage kommen. Der Speicher ist
 * vom Browser aus beschreibbar - ein absurd langer Titel soll die Anzeige
 * nicht sprengen koennen.
 */
const MAX_TITLE_LENGTH = 120;

/*
 * Ab dieser Laenge wird eine Antwort nicht mehr im Volltext angesagt, sondern
 * nur kurz gemeldet; nachlesbar ist sie ohnehin im Verlauf (Konzept 8.3).
 */
const ANNOUNCE_FULL_MAX_CHARS = 160;

/* Muss zu Extension14v\AccessibleChatbot\Http\GenderStyle passen (Konzept 8.8). */
const GENDER_STYLES = ['neutral', 'pair', 'asterisk'];

/* ------------------------------------------------------------------ *
 * Hilfsfunktionen ohne Zustand
 * ------------------------------------------------------------------ */

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

/**
 * Setzt einen Wert in einen uebersetzten Text mit %s ein.
 *
 * Fluid kann das hier nicht: der Seitentitel steht erst zur Laufzeit fest.
 * Fehlt das %s in einer Uebersetzung, wird der Wert angehaengt statt
 * verschluckt - lieber unschoen als unvollstaendig.
 */
function formatLabel(template, value) {
    const text = typeof template === 'string' ? template : '';
    const safeValue = typeof value === 'string' ? value : '';

    return text.includes('%s')
        // Ersatz als Funktion: sonst wuerde String.replace Muster wie "$&"
        // im Seitentitel als Rueckverweis deuten und den Text verstuemmeln.
        ? text.replace('%s', () => safeValue)
        : (text + ' ' + safeValue).trim();
}

/**
 * Steht der Browser gerade auf genau dieser Adresse?
 *
 * Der gemerkte Navigationswunsch liegt im sessionStorage und ist damit
 * beschreibbar. Ohne diesen Abgleich koennte eine veraltete oder gefaelschte
 * Angabe behaupten, man sei auf einer Seite, auf der man gar nicht ist. Das
 * betrifft auch den harmlosen Alltagsfall "Link in neuem Tab geoeffnet": der
 * alte Tab hat den Merker dann ebenfalls, wechselt aber nicht die Seite.
 *
 * Verglichen werden bewusst nur Herkunft und Pfad, nicht Query oder Anker:
 * eine vom Server angehaengte Query soll die Bestaetigung nicht unterdruecken.
 */
function isCurrentPage(url) {
    try {
        const target = new URL(url, window.location.href);

        return target.origin === window.location.origin
            && target.pathname === window.location.pathname;
    } catch (error) {
        return false;
    }
}

/** Liest eine Zahl aus einem data-Attribut; alles Ungueltige wird zu 0. */
function readNumber(value) {
    const parsed = Number.parseInt(value ?? '', 10);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

/**
 * Prueft einen Sprachform-Wert, bevor er uebernommen wird.
 *
 * Der sessionStorage ist vom Browser aus beschreibbar - ein unbekannter Wert
 * (z. B. ein noch gespeichertes "colon" aus einer aelteren Version) faellt
 * deshalb auf den Standard des Betreibers zurueck, statt uebernommen zu
 * werden.
 */
function sanitiseGenderStyle(value, fallback) {
    return typeof value === 'string' && GENDER_STYLES.includes(value) ? value : fallback;
}

/**
 * Liest alle Oberflaechentexte aus dem <template id="acb-i18n">-Block.
 * So steht keine einzige uebersetzbare Zeichenkette im JavaScript.
 */
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
function createDefaultState(defaultGenderStyle) {
    return {
        messages: [],
        pendingNavigation: null,
        isOpen: false,
        genderStyle: defaultGenderStyle,
    };
}

/**
 * Prueft eine Liste von Links, bevor sie ins DOM darf.
 *
 * Zwei Gruende, warum das auch bei Server-Daten passiert:
 * 1. Dieselbe Liste kommt beim naechsten Seitenaufruf aus dem
 *    sessionStorage zurueck - und der ist vom Browser aus beschreibbar.
 * 2. Doppelter Boden: eine Adresse, die nicht auf die eigene Domain zeigt,
 *    wird verworfen (gleiche Regel wie bei sanitiseNavigation).
 *
 * javascript:-Adressen scheitern an dieser Pruefung, weil ihr origin
 * niemals dem der Seite entspricht.
 */
function sanitiseLinks(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    const links = [];

    value.forEach((entry) => {
        if (links.length >= MAX_SOURCE_LINKS) {
            return;
        }
        if (!entry || typeof entry.url !== 'string' || typeof entry.title !== 'string' || entry.title === '') {
            return;
        }

        try {
            const target = new URL(entry.url, window.location.href);

            if (target.origin === window.location.origin) {
                links.push({ url: target.href, title: entry.title });
            }
        } catch (error) {
            /* ungueltige Adresse: verwerfen */
        }
    });

    return links;
}

/** Baut aus einem gespeicherten Eintrag ein sauberes Nachrichtenobjekt. */
function sanitiseMessage(entry) {
    return {
        role: entry.role,
        // Der Speicher ist vom Browser aus beschreibbar - ein absurd langer
        // Text soll die Anzeige nicht sprengen koennen.
        text: entry.text.slice(0, MAX_HISTORY_TEXT_LENGTH),
        links: entry.role === 'bot' ? sanitiseLinks(entry.links) : [],
        // Auch das Navigationsangebot kommt beim naechsten Seitenaufruf aus
        // dem sessionStorage zurueck und wird deshalb erneut geprueft.
        navigation: entry.role === 'bot' ? sanitiseNavigation(entry.navigation) : null,
        choices: entry.role === 'bot' ? sanitiseChoices(entry.choices) : [],
        suggestContact: entry.role === 'bot' && entry.suggestContact === true,
    };
}

function isValidMessage(entry) {
    return Boolean(entry)
        && typeof entry.text === 'string'
        && (entry.role === 'user' || entry.role === 'bot');
}

/**
 * Prueft ein Navigationsangebot, bevor es ins DOM oder in den Speicher darf.
 *
 * Uebernommen wird es nur, wenn es die erwartete Form hat UND auf die eigene
 * Herkunft zeigt. Dadurch scheitern auch "javascript:"-Adressen, deren origin
 * nie dem der Seite entspricht. Der Titel wird gekappt - er landet als
 * textContent im Knopf und kann kein Markup einschleusen, wohl aber die
 * Anzeige sprengen.
 */
function sanitiseNavigation(value) {
    if (!value || typeof value.url !== 'string' || typeof value.title !== 'string') {
        return null;
    }

    const title = value.title.trim().slice(0, MAX_TITLE_LENGTH);

    if (title === '') {
        return null;
    }

    try {
        const target = new URL(value.url, window.location.href);

        if (target.origin !== window.location.origin) {
            return null;
        }

        return { url: target.href, title: title };
    } catch (error) {
        return null;
    }
}

/**
 * Prueft die Auswahltitel einer Rueckfrage. Es sind reine Texte - sie werden
 * ausschliesslich ueber textContent gesetzt und loesen keine Navigation aus.
 */
function sanitiseChoices(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    const choices = [];

    value.forEach((entry) => {
        if (choices.length >= MAX_CLARIFY_CHOICES || typeof entry !== 'string') {
            return;
        }

        const title = entry.trim().slice(0, MAX_TITLE_LENGTH);

        if (title !== '') {
            choices.push(title);
        }
    });

    return choices;
}

/**
 * Liest den Verlauf aus dem sessionStorage. sessionStorage ueberlebt Reload
 * und Seitenwechsel im selben Tab und wird vom Browser geloescht, sobald der
 * Tab geschlossen wird - genau das verlangt das Datenschutzkonzept.
 * Fremde oder beschaedigte Daten werden bewusst verworfen statt uebernommen.
 */
function readState(defaultGenderStyle) {
    try {
        const raw = window.sessionStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return createDefaultState(defaultGenderStyle);
        }

        const parsed = JSON.parse(raw);

        return {
            messages: Array.isArray(parsed.messages) ? parsed.messages.filter(isValidMessage).map(sanitiseMessage) : [],
            pendingNavigation: sanitiseNavigation(parsed.pendingNavigation),
            isOpen: parsed.isOpen === true,
            // Ein gespeichertes "colon" aus einer aelteren Version faellt hier
            // still auf den Betreiber-Standard zurueck (Konzept 8.8).
            genderStyle: sanitiseGenderStyle(parsed.genderStyle, defaultGenderStyle),
        };
    } catch (error) {
        return createDefaultState(defaultGenderStyle);
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

/**
 * Haengt den Vorlese-Knopf an eine Bot-Nachricht an (Konzept 8.9).
 *
 * Bewusst als LETZTES Element der Nachricht: Navigationsknopf, Rueckfrage-
 * Knoepfe und Quellenlinks fuehren zu einer anderen Seite oder praezisieren
 * das Gespraech - sie sind wichtiger und stehen deshalb frueher in der
 * Tab-Reihenfolge als das Komfort-Feature "vorlesen".
 */
function appendReadAloudButton(item, labels) {
    const tools = document.createElement('div');
    tools.className = 'acb-msg__tools';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'acb-speak';
    button.textContent = labels['readaloud.start'] ?? '';

    tools.append(button);
    item.append(tools);
}

/**
 * Baut ein Listenelement fuer eine Nachricht - Absender immer als sichtbarer Text.
 *
 * SICHERHEIT (Phase-3-Merkposten): Im Inhaltsindex kann Text stehen, der
 * wie Markup AUSSIEHT - der Indexer loest HTML-Entities auf, aus "&lt;b&gt;"
 * wird also "<b>". Deshalb gilt hier ohne Ausnahme: jeder Text vom Server
 * wird ueber textContent gesetzt, niemals ueber innerHTML. Links entstehen
 * als echte DOM-Knoten, deren href ausschliesslich aus einer bereits
 * geprueften, server-erzeugten Adresse stammt.
 */
function createMessageElement(message, labels, contactUrl, readAloudEnabled) {
    const item = document.createElement('li');
    item.className = message.role === 'user' ? 'acb-msg acb-msg--user' : 'acb-msg acb-msg--bot';
    item.tabIndex = -1;

    const sender = document.createElement('span');
    sender.className = 'acb-msg__sender';
    sender.textContent = (message.role === 'user' ? labels['sender.user'] : labels['sender.bot']) ?? '';

    const text = document.createElement('span');
    text.className = 'acb-msg__text';
    text.textContent = message.text;

    item.append(sender, ' ', text);

    if (message.role !== 'bot') {
        return item;
    }

    // Navigationsangebot (Konzept 4.5): ein deutlich gestalteter Knopf
    // INNERHALB der Bot-Nachricht.
    //
    // Bewusst ein <a> mit echter Adresse und kein <button>: ein Seitenwechsel
    // ist ein Link. Dadurch sagt der Screenreader "Link" an (also "hier
    // wechselt die Seite"), "in neuem Tab oeffnen" und das Kontextmenue
    // funktionieren, und ohne JavaScript passiert schlicht gar nichts.
    // Die Adresse hat sanitiseNavigation() durchlaufen.
    if (message.navigation) {
        const block = document.createElement('div');
        block.className = 'acb-msg__actions';

        const anchor = document.createElement('a');
        anchor.className = 'acb-navigate';
        anchor.href = message.navigation.url;
        anchor.textContent = formatLabel(labels['navigate.button'], message.navigation.title);
        // Der reine Seitentitel fuer die Bestaetigung auf der Zielseite -
        // der Knopftext enthaelt ja zusaetzlich die Aufforderung.
        anchor.dataset.acbTitle = message.navigation.title;

        block.append(anchor);
        item.append(block);
    } else if (Array.isArray(message.choices) && message.choices.length > 0) {
        // Rueckfrage (Konzept 4.5): echte Knoepfe, die die Praezisierung
        // SENDEN. Sie navigieren nicht - eine Auswahl darf niemals von selbst
        // den Seitenkontext wechseln (WCAG 3.2.2 On Input).
        const block = document.createElement('div');
        block.className = 'acb-msg__actions';

        const intro = document.createElement('span');
        intro.className = 'acb-msg__actions-intro';
        intro.textContent = labels['clarify.intro'] ?? '';
        const introId = 'acb-choices-' + Math.random().toString(36).slice(2, 10);
        intro.id = introId;

        const list = document.createElement('ul');
        list.className = 'acb-msg__choices';
        list.setAttribute('role', 'list');
        list.setAttribute('aria-labelledby', introId);

        message.choices.forEach((title) => {
            const listItem = document.createElement('li');

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'acb-choice';
            // Der Knopftext IST die Nachricht, die abgeschickt wird. Der
            // Nutzer sieht damit vorher genau, was passieren wird.
            button.textContent = formatLabel(labels['clarify.option'], title);
            button.dataset.acbChoice = button.textContent;

            listItem.append(button);
            list.append(listItem);
        });

        block.append(intro, list);
        item.append(block);
    }

    const links = Array.isArray(message.links) ? message.links : [];

    if (links.length > 0) {
        // Ein <div> und kein <p>: eine Liste darf nicht in einem Absatz
        // stehen, das waere ungueltiges HTML.
        const block = document.createElement('div');
        block.className = 'acb-msg__sources';

        const intro = document.createElement('span');
        intro.className = 'acb-msg__sources-intro';
        intro.textContent = (links.length === 1 ? labels['sources.one'] : labels['sources.many']) ?? '';

        const list = document.createElement('ul');
        list.className = 'acb-msg__source-list';
        list.setAttribute('role', 'list');

        links.forEach((link) => {
            const listItem = document.createElement('li');
            const anchor = document.createElement('a');
            anchor.className = 'acb-msg__link';
            anchor.href = link.url;
            anchor.textContent = link.title;
            listItem.append(anchor);
            list.append(listItem);
        });

        block.append(intro, list);
        item.append(block);
    } else if (message.suggestContact === true && contactUrl !== '') {
        const block = document.createElement('div');
        block.className = 'acb-msg__sources';

        const intro = document.createElement('span');
        intro.className = 'acb-msg__sources-intro';
        intro.textContent = labels['contact.hint'] ?? '';

        const anchor = document.createElement('a');
        anchor.className = 'acb-msg__link';
        anchor.href = contactUrl;
        anchor.textContent = labels['error.contactlink'] ?? '';

        block.append(intro, ' ', anchor);
        item.append(block);
    }

    if (readAloudEnabled) {
        appendReadAloudButton(item, labels);
    }

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
    //
    // Der Wert kommt zwar vom Server und nicht von der KI, durchlaeuft aber
    // trotzdem dieselbe Pruefung wie jede andere Adresse in diesem Modul
    // (Review Phase 4, V1) - so gilt ausnahmslos: jede href in diesem Widget
    // hat sanitiseLinks() durchlaufen. Ist keine Kontaktseite gesetzt, bleibt
    // der Wert bewusst eine leere Zeichenkette statt ueber new URL('', ...)
    // zur aktuellen Seite aufzuloesen.
    const rawContactUrl = root.dataset.acbContactUrl || '';
    const contactUrl = rawContactUrl === ''
        ? ''
        : (sanitiseLinks([{ url: rawContactUrl, title: 'x' }])[0]?.url ?? '');

    // Betreiber-Voreinstellungen (Site Settings bzw. Constant Editor, siehe
    // Widget.typoscript). "readAloudEnabled" prueft zusaetzlich, ob der
    // Browser ueberhaupt Sprachausgabe kann (Konzept 8.9) - fehlt sie, wird
    // kein toter Knopf angeboten.
    const defaultGenderStyle = sanitiseGenderStyle(root.dataset.acbGenderDefault, 'neutral');
    const readAloudEnabled = root.dataset.acbReadAloud === '1' && 'speechSynthesis' in window;

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
        // Ab hier optionale Elemente - siehe Pflichtteil-Pruefung unten:
        // fehlen sie, bleibt das jeweilige Feature einfach aus, statt das
        // ganze Widget zu verstecken.
        greeting: root.querySelector('#acb-greeting'),
        starters: root.querySelector('#acb-starters'),
        resetButton: root.querySelector('#acb-reset'),
        resetConfirm: root.querySelector('#acb-reset-confirm'),
        resetYes: root.querySelector('#acb-reset-yes'),
        resetNo: root.querySelector('#acb-reset-no'),
    };

    // Fehlt ein Pflichtteil, bleibt das Widget lieber unsichtbar als halb bedienbar.
    if (!refs.toggle || !refs.toggleLabel || !refs.dialog || !refs.closeButton
        || !refs.log || !refs.list || !refs.status || !refs.form || !refs.send || !refs.input) {
        return;
    }

    const state = readState(defaultGenderStyle);
    let typingElement = null;
    let awaitingReply = false;
    let announceTimeout = null;
    let pendingAnnouncement = '';
    // Ein laufender fetch(), damit der Sende-Knopf ihn als Abbruch-Knopf
    // beenden kann (Konzept 6.1).
    let pendingController = null;
    let cancelRequested = false;
    // Die Kontextgrenzen-Hinweiszeile (Konzept 4.6) erscheint nur einmal je
    // Seitenaufruf - sonst stuende sie irgendwann in jeder zweiten Antwort.
    let contextNoticeShown = false;
    // Der Knopf, der gerade vorliest (Konzept 8.9) - es spricht immer nur
    // eine Nachricht gleichzeitig.
    let speakingButton = null;
    // Steigt bei jedem "Neues Gespraech beginnen". Eine noch laufende
    // Antwort auf das ALTE Gespraech darf danach nicht mehr im Verlauf
    // erscheinen (siehe requestReply()/sendMessage()).
    let conversationEpoch = 0;

    // Der aktive Escape-Waechter (CloseWatcher). Er lebt GENAU so lange wie
    // das offene Chatfenster - siehe armCloseWatcher(). Ist er null, gibt es
    // keinen: dann uebernimmt der keydown-Rueckfallweg. Diese eine Variable
    // entscheidet damit auch, welcher der beiden Wege laeuft - beide
    // gleichzeitig waere Doppelausfuehrung bei einem Tastendruck.
    let closeWatcher = null;

    /* ---------- kleine Helfer mit Zugriff auf den Zustand ---------- */

    function scrollLogToEnd(force) {
        // 1. Der Fokus steht auf einer bestimmten Nachricht -> nicht scrollen.
        if (force !== true
            && refs.log.contains(document.activeElement)
            && document.activeElement !== refs.log) {
            return;
        }

        // 2. Der Nutzer hat selbst nach oben gescrollt (auch ohne Fokus im
        //    Verlauf, z. B. mit der Maus) -> ebenfalls nicht scrollen.
        //    Toleranz von 2 rem, damit "fast unten" noch als unten gilt.
        const tolerance = 32;
        const atBottom = refs.log.scrollHeight - refs.log.scrollTop - refs.log.clientHeight <= tolerance;

        if (force !== true && !atBottom) {
            return;
        }

        refs.log.scrollTop = refs.log.scrollHeight;
    }

    /**
     * Schreibt eine Meldung in die Statuszeile (role="status" kuendigt sie an).
     *
     * Der Umweg ueber das Leeren und eine kurze Verzoegerung ist noetig,
     * damit auch zweimal dieselbe Meldung vorgelesen wird: ohne DOM-Aenderung
     * bleibt der Screenreader sonst stumm. Review Phase 4, V1: ein einzelnes
     * Animationsframe (~16 ms) reicht dafuer nicht immer - manche Screenreader
     * (u. a. NVDA in Firefox) registrieren die Aenderung dann nicht als neue
     * Ansage. 120 ms sind zuverlaessiger.
     */
    function announce(message) {
        // Ist das Chatfenster zu, liegt die Ansage-Region in einem
        // display:none-Bereich und wird von Screenreadern nicht vorgelesen.
        // Die Ansage wird dann gemerkt und beim naechsten Oeffnen nachgeholt
        // (Konzept 8.3: nichts geht stillschweigend verloren).
        if (refs.dialog.hidden) {
            pendingAnnouncement = message ?? '';
            return;
        }

        // Eine noch wartende Ansage wird verworfen. Ohne dieses Loeschen
        // ueberholt eine zweite Ansage die erste: der noch laufende Timer der
        // ersten schreibt seinen Text NACH dem der zweiten in die Region -
        // angesagt wird dann die veraltete Meldung.
        if (announceTimeout !== null) {
            window.clearTimeout(announceTimeout);
            announceTimeout = null;
        }

        refs.status.textContent = '';

        if (message) {
            announceTimeout = window.setTimeout(() => {
                announceTimeout = null;
                refs.status.textContent = message;
            }, 120);
        }
    }

    function addMessage(message) {
        state.messages.push(message);
        writeState(state);
        refs.list.append(createMessageElement(message, labels, contactUrl, readAloudEnabled));
        scrollLogToEnd();
    }

    /**
     * Schreibt eine Hinweiszeile ins Gespraechsprotokoll.
     *
     * Diese Zeilen werden bewusst NICHT im sessionStorage gespeichert und
     * nicht an den Server mitgeschickt: sie gehoeren nicht zum Gespraech.
     *
     * Barrierefreiheit: seit Phase 5 hat das Protokoll KEIN aria-live mehr.
     * Die Meldung wird deshalb zusaetzlich ueber announce() in die
     * Ansage-Region geschrieben - sichtbar im Verlauf, einmalig angesagt
     * (Konzept 8.3). Der sichtbare Vorspann "Hinweis:" sorgt dafuer, dass die
     * Art der Nachricht nicht allein an der Farbe haengt.
     *
     * withContactLink = false und ein eigener Modifier werden fuer die
     * Ankunfts-Bestaetigung nach einer Navigation benutzt: sie ist keine
     * Stoerung und braucht deshalb weder Warnfarbe noch Notausgang.
     */
    function addSystemMessage(text, withContactLink = true, modifier = 'acb-msg--system') {
        if (!text) {
            return;
        }

        const item = document.createElement('li');
        item.className = 'acb-msg ' + modifier;
        item.tabIndex = -1;

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
        if (withContactLink && contactUrl !== '') {
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
        if (typingElement === null) {
            return;
        }

        // Steht der Fokus auf der Tipp-Zeile, wandert er zuerst auf den
        // Verlauf. Ohne das setzt der Browser ihn auf <body> und der Nutzer
        // landet unangekuendigt am Seitenanfang (Konzept 8.4).
        if (typingElement.contains(document.activeElement)) {
            refs.log.focus();
        }

        typingElement.remove();
        typingElement = null;
    }

    /**
     * Die EINE Stelle, die entscheidet, was ein Schliess-Wunsch ausloest -
     * egal ob er als Escape-Taste, als Android-Zurueck-Taste oder ueber den
     * keydown-Rueckfallweg ankommt. Beide Wege rufen ausschliesslich sie auf,
     * damit sich das Verhalten nie auseinanderentwickelt.
     *
     * Rueckgabe: true, wenn der Fokus beim Aufruf IM Widget stand. Genau dann
     * unterdrueckt der Rueckfallweg das Standardverhalten von Escape.
     */
    function handleCloseRequest() {
        if (refs.dialog.hidden) {
            return false;
        }

        // Der Fokus wird nur bewegt, wenn er vorher ueberhaupt im Widget
        // stand (Konzept 8.3). document.activeElement ist hier noch
        // aussagekraeftig: der Browser verarbeitet Close-Watcher erst NACH
        // dem keydown, und weder er noch dieses Widget bewegt bei Escape von
        // sich aus den Fokus. Vorher merken geht ohnehin nicht - die
        // Android-Zurueck-Taste liefert gar kein Tastaturereignis.
        const focusWasInsideWidget = root.contains(document.activeElement);

        // Steht die Rueckfrage vor dem Loeschen offen, geht es zuerst nur
        // einen Schritt zurueck, nicht gleich ganz zu (COGA 4.5.2) - und nur
        // bei Fokus IM Widget, sonst zoege setResetConfirmOpen() den Fokus
        // von aussen herein (Review Phase 7, S3).
        if (refs.resetConfirm && !refs.resetConfirm.hidden && focusWasInsideWidget) {
            setResetConfirmOpen(false);
            return true;
        }

        setOpen(false, focusWasInsideWidget);
        return focusWasInsideWidget;
    }

    /**
     * Stellt den standardisierten Schliess-Waechter scharf (CloseWatcher).
     * Vorteile gegenueber einem eigenen keydown-Handler: der Browser reiht
     * das Widget in den Stapel aller schliessbaren Dinge ein, respektiert
     * automatisch ein preventDefault() fremder Komponenten und deckt die
     * Android-Zurueck-Taste mit ab.
     *
     * Kann der Browser das nicht (Stand 08/2026 vor allem Safari), bleibt
     * closeWatcher null - und nur dann greift der keydown-Rueckfallweg.
     */
    function armCloseWatcher() {
        if (closeWatcher !== null || !('CloseWatcher' in window)) {
            return;
        }

        try {
            const watcher = new window.CloseWatcher();

            watcher.addEventListener('close', () => {
                // Der Browser hat den Waechter bereits verbraucht - er wird
                // vor dem Feuern dieses Ereignisses zerstoert.
                closeWatcher = null;

                handleCloseRequest();

                // Ging nur die Rueckfrage zu, ist das Fenster noch offen und
                // braucht sofort einen frischen Waechter fuer das naechste
                // Escape - ein verbrauchter waechst nicht nach.
                if (!refs.dialog.hidden) {
                    armCloseWatcher();
                }
            });

            closeWatcher = watcher;
        } catch (error) {
            // Liesse sich kein Waechter erzeugen, bliebe das Widget sonst
            // ganz ohne Escape. closeWatcher bleibt null, der Rueckfallweg
            // uebernimmt.
            closeWatcher = null;
        }
    }

    /** Nimmt den Waechter zurueck, sobald das Fenster zu ist. Ein dauerhaft
     *  lebender Waechter wuerde Escape auch bei geschlossenem Chat fuer sich
     *  beanspruchen - schlechter als gar keiner. */
    function disarmCloseWatcher() {
        if (closeWatcher === null) {
            return;
        }

        const watcher = closeWatcher;
        closeWatcher = null;
        watcher.destroy();
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
            // Der Escape-Waechter lebt genau so lange wie das offene Fenster.
            armCloseWatcher();

            scrollLogToEnd(true);

            // Nachgemerkte Ansage nachholen, siehe announce().
            if (pendingAnnouncement !== '') {
                const pending = pendingAnnouncement;
                pendingAnnouncement = '';
                announce(pending);
            }

            if (moveFocus) {
                refs.input.focus();
            }
        } else {
            disarmCloseWatcher();

            if (moveFocus) {
                refs.toggle.focus();
            }
        }
    }

    /**
     * Schaltet den Sende-Knopf zwischen "Senden" und "Abbrechen" um
     * (Konzept 6.1). Der Knopf bleibt in beiden Zustaenden ein ganz normaler,
     * fokussierbarer Knopf - nur seine Aufgabe wechselt.
     */
    function setSendMode(mode) {
        const cancel = mode === 'cancel';
        refs.send.dataset.acbMode = cancel ? 'cancel' : 'send';
        refs.send.textContent = (cancel ? labels['send.cancel'] : labels['send.label']) ?? refs.send.textContent;
    }

    /** Bricht die laufende Anfrage ab (Konzept 6.1, COGA 4.5.9). */
    function cancelRequest() {
        if (!awaitingReply || pendingController === null) {
            return;
        }

        cancelRequested = true;
        pendingController.abort();
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
        // aber in allen Browsern verfuegbar. Ausserdem wird derselbe
        // Controller vom Abbruch-Knopf benutzt (Konzept 6.1).
        const controller = new AbortController();
        pendingController = controller;
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
                // ein HTTP-Fehlerstatus landet NICHT hier. Ein Abbruch durch
                // den Nutzer (Abbruch-Knopf) ist kein Fehler, sondern eine
                // Information (Konzept 6.1, COGA 4.5.9).
                if (cancelRequested) {
                    const cancelled = createRequestError('status.cancelled');
                    cancelled.acbCancelled = true;
                    throw cancelled;
                }

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

                // Ab Phase 4 kommt mehr als nur Text zurueck: die Quellseiten
                // (fertige Adressen vom Server) und der Hinweis, ob die
                // Kontaktseite angeboten werden darf.
                return {
                    text: result.data.reply,
                    links: sanitiseLinks(result.data.sources),
                    navigation: sanitiseNavigation(result.data.navigation),
                    choices: sanitiseChoices(result.data.choices),
                    suggestContact: result.data.suggestContact === true,
                };
            })
            .finally(() => {
                window.clearTimeout(timeout);
                pendingController = null;
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
            announce(labels['status.busy'] ?? labels['status.typing'] ?? '');
            return;
        }

        addMessage({ role: 'user', text });
        refs.input.value = '';

        // Kontextgrenze (Konzept 4.6): faellt mit dieser Nachricht die
        // erste aeltere Nachricht aus dem mitgesendeten Verlauf heraus, wird
        // das EINMAL je Seitenaufruf im Verlauf vermerkt - lesbar, aber ohne
        // eigene Ansage. Eine eigene Ansage wuerde die Meldung "schreibt eine
        // Antwort" gleich darunter ueberholen (siehe announce()): eine
        // wartende Ansage wird von der naechsten verworfen, und "es wird
        // gerade geantwortet" ist in diesem Moment wichtiger. Nachlesbar
        // bleibt der Hinweis trotzdem im Verlauf.
        if (!contextNoticeShown && state.messages.length - 1 > MAX_HISTORY_ENTRIES) {
            contextNoticeShown = true;
            addSystemMessage(labels['status.contextlimit'] ?? '', false, 'acb-msg--note');
        }

        // Erst danach die Tipp-Zeile: sie gehoert ans Ende des Verlaufs, der
        // Hinweis zur Kontextgrenze davor (Review Phase 7, N7).
        showTyping();

        // Das Protokoll hat kein aria-live mehr; der Tipp-Status wird deshalb
        // hier EINMAL angesagt (Konzept 8.3) und nie auf einem Timer wiederholt.
        announce(labels['status.typing'] ?? '');

        awaitingReply = true;
        // Der aktuelle Gespraechs-Zaehler: wird "Neues Gespraech beginnen"
        // waehrend dieser Anfrage betaetigt, darf die Antwort nicht mehr im
        // (dann bereits geleerten) Verlauf erscheinen.
        const epoch = conversationEpoch;
        // Bewusst kein disabled (Konzept 8.4): der Knopf bleibt fokussierbar
        // und ausloesbar, er bekommt nur eine andere Aufgabe - Abbrechen
        // statt Senden (Konzept 6.1).
        setSendMode('cancel');

        requestReply(text)
            .then((reply) => {
                if (epoch !== conversationEpoch) {
                    return;
                }

                hideTyping();

                if (reply.text !== '') {
                    addMessage({
                        role: 'bot',
                        text: reply.text,
                        links: reply.links,
                        navigation: reply.navigation,
                        choices: reply.choices,
                        suggestContact: reply.suggestContact,
                    });

                    // Kurze Antworten werden im Volltext angesagt, lange nur
                    // kurz gemeldet - eine sehr lange Live-Ansage laesst sich
                    // nicht anhalten und nicht wiederholen. Nachlesbar ist die
                    // Antwort in beiden Faellen im Verlauf (Kernprinzip 4).
                    announce(reply.text.length <= ANNOUNCE_FULL_MAX_CHARS
                        ? reply.text
                        : (labels['status.newanswer'] ?? ''));
                }
            })
            .catch((error) => {
                if (epoch !== conversationEpoch) {
                    return;
                }

                hideTyping();

                // Es darf niemals stilles Schweigen geben: jede Stoerung wird
                // als sichtbare Zeile ins Protokoll geschrieben und dadurch
                // ueber aria-live vorgelesen. Ein Abbruch durch den Nutzer
                // ist dabei keine Stoerung, sondern eine Information
                // (Konzept 6.1) - deshalb ohne Warnfarbe und ohne Notausgang.
                const cancelled = Boolean(error && error.acbCancelled === true);
                const errorText = (error && error.acbText)
                    || labels[(error && error.acbLabel) || 'error.request']
                    || labels['error.request']
                    || '';

                addSystemMessage(errorText, !cancelled, cancelled ? 'acb-msg--note' : 'acb-msg--system');

                // Pflicht seit Phase 5: ohne aria-live am Protokoll wuerde eine
                // Stoermeldung sonst gar nicht mehr angesagt (Exit-Kriterium
                // Phase 2: nie stilles Schweigen).
                announce(errorText);
            })
            .finally(() => {
                awaitingReply = false;
                cancelRequested = false;
                setSendMode('send');
            });
    }

    /* ---------- Einstiegsfragen und neues Gespraech ---------- */

    /**
     * Blendet die Einstiegsfragen ein oder aus (Konzept 8.6). Sie sind nur
     * sinnvoll, solange noch kein eigenes Gespraech steht - danach wuerden
     * sie unter jeder Nachricht ablenken.
     */
    function setStartersVisible(visible) {
        if (refs.starters) {
            refs.starters.hidden = !visible;
        }
    }

    /**
     * Oeffnet oder schliesst die Rueckfrage vor dem Loeschen des Gespraechs
     * (COGA 4.5.2). Der Fokus geht beim Oeffnen auf "Ja, Gespraech loeschen"
     * (die vorsichtigere Voreinstellung waere "Nein" - aber die Frage selbst
     * ist ueber aria-describedby an beiden Knoepfen verlinkt, sodass ein
     * Screenreader sie in jedem Fall vorliest, bevor ein Knopf ausgeloest
     * wird) und beim Schliessen zurueck auf den Ausloese-Knopf.
     */
    function setResetConfirmOpen(open) {
        if (!refs.resetConfirm || !refs.resetButton) {
            return;
        }

        refs.resetConfirm.hidden = !open;
        refs.resetButton.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            refs.resetYes?.focus();
        } else {
            refs.resetButton.focus();
        }
    }

    /**
     * Setzt das Gespraech zurueck (COGA 4.5.2, Konzept 4.6). Es werden
     * AUSSCHLIESSLICH "messages" und "pendingNavigation" geleert -
     * "isOpen" und "genderStyle" sind Besucher-Einstellungen und bleiben
     * unangetastet.
     */
    function resetConversation() {
        cancelRequest();
        // Eine noch laufende Antwort auf das ALTE Gespraech darf danach
        // nicht mehr im (jetzt geleerten) Verlauf erscheinen, siehe
        // sendMessage()/requestReply().
        conversationEpoch += 1;
        awaitingReply = false;
        setSendMode('send');
        hideTyping();
        stopReadAloud(false);

        state.messages = [];
        state.pendingNavigation = null;
        writeState(state);
        contextNoticeShown = false;

        // Alles ausser der Begruessung entfernen - sie bleibt fest im Markup
        // stehen (siehe Widget.html) und wird nicht neu erzeugt.
        Array.from(refs.list.children).forEach((item) => {
            if (item !== refs.greeting) {
                item.remove();
            }
        });

        setStartersVisible(true);
        setResetConfirmOpen(false);
        scrollLogToEnd(true);

        // Kernprinzip 4: die Bestaetigung bleibt nachlesbar im (jetzt
        // geleerten) Verlauf stehen.
        addSystemMessage(labels['status.conversationreset'] ?? '', false, 'acb-msg--note');
        announce(labels['status.conversationreset'] ?? '');
    }

    /* ---------- Spracheingabe ---------- */

    /**
     * Schaltet das Widget in den Zustand "Spracheingabe geht hier nicht".
     * Knopf und Datenfluss-Hinweis werden dabei IMMER wieder versteckt: diese
     * Funktion laeuft auch aus dem catch heraus, also moeglicherweise erst,
     * nachdem beide bereits sichtbar gemacht wurden. Sonst stuende ein toter
     * Knopf neben dem Hinweis, dass es nicht geht (Konzept 8.9).
     */
    function showSpeechUnavailable() {
        if (refs.micButton) {
            refs.micButton.hidden = true;
            refs.micButton.setAttribute('aria-pressed', 'false');
        }

        const micHint = root.querySelector('#acb-mic-hint');

        if (micHint !== null) {
            micHint.hidden = true;
        }

        const notice = root.querySelector('#acb-mic-unavailable');

        if (notice !== null) {
            notice.hidden = false;
        }
    }

    /**
     * TASK 9 (Konzept 8.9): lokale, geraeteinterne Spracherkennung, wenn der
     * Browser sie anbietet - sonst der bisherige Cloud-Weg. Beide Wege
     * benutzen dieselbe Schnittstelle (SpeechRecognition), der Unterschied
     * ist ausschliesslich die Instanz-Eigenschaft "processLocally".
     *
     * "SpeechRecognition.available()" ist eine ASYNCHRONE, statische Pruefung
     * (Stand 2026-08-13: Chrome/Edge 139+, Opera 123+, Desktop, kein W3C-
     * Standard). Deshalb steht der Cloud-Weg von Anfang an startklar da, und
     * die lokale Erkennung ersetzt ihn nur dann, wenn die Pruefung VOR dem
     * ersten Klick fertig ist - eine langsame oder nie aufloesende Zusage
     * darf den Mikrofon-Knopf niemals tot lassen.
     */
    function setUpSpeechInput() {
        const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;

        if (!refs.micButton) {
            return;
        }

        // Fehlt die Schnittstelle (Firefox in der Standardeinstellung) oder
        // laeuft die Seite nicht ueber eine sichere Verbindung (kein HTTPS und
        // nicht localhost), funktioniert die Spracheingabe nicht. Dann erscheint
        // ein sichtbarer Hinweis statt eines toten oder unsichtbaren Knopfes
        // (Konzept 8.9).
        if (!Recognition || window.isSecureContext !== true) {
            showSpeechUnavailable();
            return;
        }

        const pageLanguage = document.documentElement.lang;
        let listening = false;
        let starting = false;
        let handled = false;
        let firstClickHappened = false;

        /**
         * Baut eine Erkennungs-Instanz samt Ereignissen. Ausgelagert, weil
         * TASK 9 die Instanz austauschen kann, bevor der erste Klick
         * passiert ist (siehe unten) - beide Instanzen sollen sich exakt
         * gleich verhalten, nur eben lokal oder in der Cloud erkennen.
         */
        function buildRecognition(processLocally) {
            const instance = new Recognition();

            if (pageLanguage) {
                instance.lang = pageLanguage;
            }

            instance.continuous = false;
            instance.interimResults = false;
            instance.maxAlternatives = 1;

            // "processLocally" ist eine Instanz-Eigenschaft und muss VOR
            // start() gesetzt werden (Standard: false).
            if (processLocally) {
                instance.processLocally = true;
            }

            instance.addEventListener('start', () => {
                starting = false;
                listening = true;
                refs.micButton.setAttribute('aria-pressed', 'true');
                announce(labels['mic.listening'] ?? '');
            });

            instance.addEventListener('result', (event) => {
                handled = true;
                refs.input.value = event.results?.[0]?.[0]?.transcript ?? '';
                announce(labels['mic.recognized'] ?? '');
                refs.input.focus();
            });

            instance.addEventListener('error', (event) => {
                handled = true;
                starting = false;

                let text;
                if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                    text = labels['mic.denied'] ?? '';
                } else if (event.error === 'no-speech') {
                    text = labels['mic.nospeech'] ?? '';
                } else {
                    // Deckt u. a. "language-not-supported" ab: kommt beim
                    // lokalen Weg vor, wenn das Sprachpaket zwischenzeitlich
                    // fehlt, obwohl "available()" zuvor "available" meldete.
                    text = labels['mic.error'] ?? '';
                }

                // Kernprinzip 4 / Konzept 8.3: nichts existiert nur als
                // fluechtige Ansage - die Stoerung bleibt nachlesbar.
                addSystemMessage(text, false, 'acb-msg--note');
                announce(text);
            });

            instance.addEventListener('end', () => {
                starting = false;
                listening = false;
                refs.micButton.setAttribute('aria-pressed', 'false');

                if (!handled) {
                    announce(labels['mic.stopped'] ?? '');
                }
            });

            return instance;
        }

        // Cloud-Weg sofort startklar - unveraendertes Verhalten. Die lokale
        // Erkennung ist eine reine, spaeter eintreffende Verbesserung.
        let recognition = buildRecognition(false);

        refs.micButton.hidden = false;

        const micHint = root.querySelector('#acb-mic-hint');

        if (micHint !== null) {
            micHint.hidden = false;
        }

        refs.micButton.addEventListener('click', () => {
            firstClickHappened = true;

            if (listening) {
                recognition.stop();
                return;
            }

            // Zwischen Klick und "start"-Ereignis vergeht Zeit. Ein zweiter
            // recognition.start() in dieser Luecke wirft einen InvalidStateError -
            // bei schnellem Doppelklick genau der Fall.
            if (starting) {
                return;
            }

            handled = false;
            starting = true;

            try {
                recognition.start();
            } catch (error) {
                starting = false;
                announce(labels['mic.error'] ?? '');
            }
        });

        // TASK 9: lokale Erkennung pruefen. Nichts hiervon darf den bereits
        // startklaren Cloud-Weg gefaehrden - deshalb ausschliesslich additiv
        // und in einem eigenen try/catch.
        try {
            if (typeof Recognition.available === 'function') {
                Recognition.available({
                    langs: [pageLanguage || window.navigator.language],
                    processLocally: true,
                })
                    .then((availability) => {
                        if (firstClickHappened || availability !== 'available') {
                            // "downloadable"/"downloading": ABSICHTLICH kein
                            // install() - das koennte einen grossen Download
                            // ausloesen, ohne dass danach gefragt wurde. Der
                            // Cloud-Weg bleibt in diesem Fall bestehen.
                            return;
                        }

                        recognition = buildRecognition(true);

                        // Der Hinweistext muss den tatsaechlich genutzten
                        // Weg beschreiben (Konzept 8.9).
                        if (micHint !== null) {
                            micHint.textContent = labels['mic.hint.local'] ?? micHint.textContent;
                        }
                    })
                    .catch(() => {
                        /* bleibt beim Cloud-Weg */
                    });
            }
        } catch (error) {
            /* bleibt beim Cloud-Weg - ein Randfeature darf das
               Hauptfeature nie mitreissen (Testbefund Phase 5). */
        }
    }

    /* ---------- Vorlesen ---------- */

    // Haelt die gerade gesprochene Utterance fest: ohne eine Referenz aus
    // dem Modul-Gueltigkeitsbereich sammelt der Garbage Collector sie in
    // manchen Browsern mitten im Satz ein und die Ausgabe bricht ab.
    let currentUtterance = null;

    /**
     * Beendet eine laufende Vorlese-Ausgabe (Konzept 8.9).
     *
     * announceStop = false wird beim Zuruecksetzen des Gespraechs und beim
     * Verlassen der Seite (pagehide) benutzt: dort folgt entweder ohnehin
     * eine andere Ansage, oder es hoert niemand mehr zu.
     */
    function stopReadAloud(announceStop) {
        try {
            window.speechSynthesis.cancel();
        } catch (error) {
            /* bewusst ignoriert - ohne Sprachausgabe gibt es nichts zu stoppen */
        }

        currentUtterance = null;

        if (speakingButton !== null) {
            const button = speakingButton;
            speakingButton = null;

            button.removeAttribute('data-acb-speaking');
            button.textContent = labels['readaloud.start'] ?? button.textContent;

            if (announceStop) {
                announce(labels['readaloud.stopped'] ?? '');
            }
        }
    }

    /**
     * Liest die zugehoerige Bot-Antwort vor oder stoppt eine laufende
     * Ausgabe - derselbe Knopf startet und stoppt (Konzept 8.9).
     *
     * Vorgelesen wird AUSSCHLIESSLICH der Antworttext (".acb-msg__text"),
     * nicht die Bedienelemente drumherum - fremde Inhalte im Vorlesen
     * erhoehen sonst unnoetig die kognitive Last (COGA). Es spricht immer
     * nur eine Nachricht gleichzeitig.
     */
    function toggleReadAloud(button) {
        if (speakingButton === button) {
            stopReadAloud(true);
            return;
        }

        stopReadAloud(false);

        try {
            const message = button.closest('.acb-msg');
            const text = message ? (message.querySelector('.acb-msg__text')?.textContent ?? '') : '';

            if (text.trim() === '' || !('speechSynthesis' in window)) {
                return;
            }

            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = document.documentElement.lang || '';

            // Eine zur Seitensprache passende Stimme ist ein Zusatz: ist die
            // Stimmenliste noch nicht geladen (sie fuellt sich asynchron
            // ueber "voiceschanged"), waehlt der Browser anhand von "lang"
            // ohnehin selbst eine passende Stimme - das ist ein sauberer
            // Rueckfall und kein Fehler.
            const voices = window.speechSynthesis.getVoices();

            if (Array.isArray(voices) && voices.length > 0 && utterance.lang !== '') {
                const match = voices.find(
                    (voice) => typeof voice.lang === 'string'
                        && voice.lang.toLowerCase().startsWith(utterance.lang.toLowerCase())
                );

                if (match) {
                    utterance.voice = match;
                }
            }

            utterance.addEventListener('end', () => {
                if (speakingButton === button) {
                    stopReadAloud(false);
                }
            });
            utterance.addEventListener('error', () => {
                if (speakingButton === button) {
                    stopReadAloud(false);
                }
            });

            currentUtterance = utterance;
            speakingButton = button;
            button.setAttribute('data-acb-speaking', 'true');
            button.textContent = labels['readaloud.stop'] ?? button.textContent;

            window.speechSynthesis.speak(utterance);
        } catch (error) {
            stopReadAloud(false);
        }
    }

    /* ---------- Start ---------- */

    // 0. Ist die Vorlesefunktion abgeschaltet oder kann der Browser gar
    //    keine Sprachausgabe, verschwinden serverseitig gerenderte
    //    Vorlese-Knoepfe wieder (nur die Begruessung hat einen: der Rest
    //    des Verlaufs entsteht ohnehin erst durch JavaScript).
    if (!readAloudEnabled) {
        root.querySelectorAll('button.acb-speak').forEach((button) => button.remove());
    }

    // 1. Gespeicherten Verlauf wieder aufbauen.
    state.messages.forEach((message) => {
        refs.list.append(createMessageElement(message, labels, contactUrl, readAloudEnabled));
    });

    // 1b. Ankunft nach einer bestaetigten Navigation (Konzept 4.5).
    //
    //     Der Fokus wird dabei ausdruecklich NICHT gesetzt: automatisches
    //     Fokussieren beim Seitenladen laesst Screenreader nur die
    //     Beschriftung des fokussierten Elements vorlesen statt der Seite -
    //     Nutzende verlieren dadurch die Orientierung (MDN, Konzept 8.4).
    //
    //     Der Block steht bewusst VOR setOpen(): ist das Chatfenster zu,
    //     merkt announce() die Ansage und holt sie beim Oeffnen nach.
    const arrival = state.pendingNavigation;

    if (arrival !== null) {
        // Genau einmal: der Merker wird sofort geloescht.
        state.pendingNavigation = null;
        writeState(state);

        if (isCurrentPage(arrival.url)) {
            const arrivalText = formatLabel(labels['navigate.arrived'], arrival.title);

            addSystemMessage(arrivalText, false, 'acb-msg--arrival');

            // Direkt nach dem Seitenladen registrieren Screenreader eine
            // Aenderung in der role="status"-Region oft noch nicht als
            // Ansage, sondern als normalen Seiteninhalt. Eine Sekunde Abstand
            // ist KEIN Zeitlimit im Sinne von WCAG 2.2.1: es passiert dadurch
            // nichts, es wird nur etwas vorgelesen. Ist das Chatfenster zu,
            // merkt announce() die Ansage ohnehin fuer das naechste Oeffnen.
            window.setTimeout(() => announce(arrivalText), 1000);
        }
    }

    // 2. Gewaehlte Sprachform wiederherstellen.
    refs.genderInputs.forEach((option) => {
        option.checked = option.value === state.genderStyle;
    });

    // 2b. Einstiegsfragen (Konzept 8.6) nur zeigen, solange noch kein
    //     eigenes Gespraech steht - danach wuerden sie unter jeder
    //     Nachricht ablenken.
    setStartersVisible(state.messages.length === 0);

    // 3. Sichtbar machen: ohne JavaScript erscheint gar nichts.
    root.hidden = false;

    // 4. Offen/zu wiederherstellen - ohne den Fokus zu bewegen.
    setOpen(state.isOpen, false);

    // 5. Spracheingabe ZULETZT und abgesichert.
    //    Testbefund 2026-08-13: eine Ausnahme in der Sprach-Schnittstelle hat
    //    den Rest dieser Funktion abgebrochen - das Widget blieb hidden und war
    //    komplett verschwunden. Ein Randfeature darf das Hauptfeature nie
    //    mitreissen: das Widget ist bereits sichtbar und bedienbar, bevor hier
    //    ueberhaupt etwas passieren kann, und ein Fehler endet in einem
    //    sichtbaren Hinweis statt in einem toten Widget.
    try {
        setUpSpeechInput();
    } catch (error) {
        showSpeechUnavailable();
    }

    // 6. Vorlesen anhalten, wenn die Seite verlassen wird - ohne eigene
    //    Ansage, es hoert ohnehin niemand mehr zu.
    window.addEventListener('pagehide', () => stopReadAloud(false));

    /* ---------- Ereignisse ---------- */

    refs.toggle.addEventListener('click', () => {
        setOpen(refs.dialog.hidden, true);
    });

    refs.closeButton.addEventListener('click', () => {
        setOpen(false, true);
    });

    // RUECKFALLWEG fuer Browser ohne CloseWatcher (Stand 08/2026 vor allem
    // Safari - also fuer einen erheblichen Teil der Besucher der Normalfall,
    // nicht die Ausnahme). Bewusst am Dokument: Wer nach dem Oeffnen in die
    // Seite geklickt hat, muss den Chat trotzdem mit Escape schliessen
    // koennen.
    //
    // Die Pruefung auf closeWatcher stellt sicher, dass IMMER nur genau einer
    // der beiden Wege laeuft. Der Browser feuert erst dieses keydown und erst
    // danach die Close-Watcher; liefen beide, wuerde ein einziger Tastendruck
    // alles doppelt ausloesen. Umgekehrt laesst sich hier auch nicht an
    // event.defaultPrevented ablesen, ob ein Waechter schon reagiert hat -
    // dieser Handler laeuft dafuer zu frueh.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || closeWatcher !== null || refs.dialog.hidden) {
            return;
        }

        // Hoeflichkeit gegenueber der uebrigen Seite: hat eine andere
        // Komponente den Tastendruck bereits fuer sich beansprucht, haelt der
        // Chat sich heraus. Der CloseWatcher-Weg macht genau das von selbst -
        // der Browser reicht ein abgefangenes Escape gar nicht erst an die
        // Waechter weiter.
        if (event.defaultPrevented) {
            return;
        }

        // Das Standardverhalten wird nur unterdrueckt, wenn der Fokus im
        // Widget stand - fremde Komponenten der Seite duerfen nicht blockiert
        // werden (Konzept 8.3). Das entspricht 1:1 dem bisherigen Verhalten.
        if (handleCloseRequest()) {
            event.preventDefault();
        }
    });

    /*
     * Tastaturbedienung im Verlauf (Konzept 8.1): Pfeil runter/hoch gehen von
     * Nachricht zu Nachricht, Pos1/Ende an Anfang und Ende. Der Listener haengt
     * am Verlauf, greift also nur, wenn der Fokus dort drin steht - das
     * Eingabefeld liegt ausserhalb und behaelt Pos1/Ende fuer den Text.
     * Es sind keine Einzeltasten-Kuerzel im Sinne von WCAG 2.1.4, weil sie
     * ausserhalb des Verlaufs wirkungslos sind.
     */
    refs.log.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp'
            && event.key !== 'Home' && event.key !== 'End') {
            return;
        }

        // Nur echte Nachrichten. Die Tipp-Zeile hat bewusst kein tabindex
        // (sie ist ein voruebergehender Status, keine Nachricht) - stuende
        // sie in dieser Liste, liefe die Pfeiltasten-Navigation waehrend
        // einer laufenden Antwort ins Leere.
        const items = Array.from(refs.list.children).filter(
            (item) => item.hasAttribute('tabindex')
        );

        if (items.length === 0) {
            return;
        }

        const current = items.findIndex((item) => item.contains(document.activeElement));
        let next;

        if (event.key === 'Home') {
            next = 0;
        } else if (event.key === 'End') {
            next = items.length - 1;
        } else if (event.key === 'ArrowDown') {
            next = current < 0 ? 0 : Math.min(current + 1, items.length - 1);
        } else {
            next = current < 0 ? items.length - 1 : Math.max(current - 1, 0);
        }

        event.preventDefault();
        items[next].focus();
    });

    /*
     * Ein Klick im Verlauf, an genau einer Stelle behandelt (Ereignis-
     * Delegation). Das funktioniert dadurch auch fuer Nachrichten, die beim
     * Seitenaufruf aus dem sessionStorage wiederhergestellt wurden.
     */
    refs.list.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (target === null) {
            return;
        }

        // Auswahlknopf einer Rueckfrage: sendet die Praezisierung und
        // navigiert NICHT (WCAG 3.2.2). Der Weg fuehrt bewusst ueber
        // sendMessage(), damit exakt dieselben Pruefungen und Ansagen greifen
        // wie beim Tippen.
        const choice = target.closest('button.acb-choice');

        if (choice !== null) {
            // Solange eine Antwort laeuft, darf ein Klick den getippten
            // Entwurf im Eingabefeld nicht ueberschreiben - sendMessage()
            // wuerde ohnehin sofort zurueckkehren, der Text waere aber weg.
            if (awaitingReply) {
                announce(labels['status.busy'] ?? labels['status.typing'] ?? '');

                return;
            }

            refs.input.value = choice.dataset.acbChoice ?? '';
            sendMessage();

            // Der Knopf liegt IM Verlauf und hat jetzt den Fokus - ohne
            // "force" haelt scrollLogToEnd() an und die neue eigene Nachricht
            // samt Tipp-Zeile bliebe unsichtbar.
            scrollLogToEnd(true);

            return;
        }

        // Einstiegsfrage (Konzept 8.6): der Knopftext IST die Nachricht, die
        // abgeschickt wird - kein Ueberraschungseffekt. Der Klick navigiert
        // nicht (WCAG 3.2.2). Der Fokus geht VOR dem Verstecken der Knoepfe
        // ins Eingabefeld - sonst wuerde der Browser ihn auf <body> setzen,
        // sobald das geklickte Element verschwindet, und die Person stuende
        // unangekuendigt am Seitenanfang (Konzept 8.4).
        const starter = target.closest('button.acb-starter');

        if (starter !== null) {
            if (awaitingReply) {
                announce(labels['status.busy'] ?? labels['status.typing'] ?? '');

                return;
            }

            refs.input.value = starter.textContent ?? '';
            sendMessage();
            refs.input.focus();
            setStartersVisible(false);
            scrollLogToEnd(true);

            return;
        }

        // Vorlesen (Konzept 8.9): startet oder stoppt die Sprachausgabe
        // dieser einen Antwort.
        const speak = target.closest('button.acb-speak');

        if (speak !== null) {
            toggleReadAloud(speak);

            return;
        }

        // Navigationsangebot: hier wird NICHT navigiert. Der Seitenwechsel
        // passiert allein dadurch, dass der Nutzer einen ganz normalen Link
        // betaetigt hat. Gemerkt wird nur, wohin - damit auf der Zielseite
        // die Bestaetigung im Verlauf stehen kann.
        const navigate = target.closest('a.acb-navigate');

        if (navigate !== null) {
            state.pendingNavigation = {
                url: navigate.href,
                title: navigate.dataset.acbTitle ?? '',
            };
            writeState(state);
        }
    });

    refs.form.addEventListener('submit', (event) => {
        event.preventDefault();
        sendMessage();
    });

    // Der Sende-Knopf ist waehrend einer laufenden Antwort der Abbruch-
    // Knopf (Konzept 6.1). event.preventDefault() unterdrueckt hier bewusst
    // die eigentliche submit-Auswirkung des Knopfes (type="submit") - sonst
    // wuerde ZUSAETZLICH zum Abbrechen eine neue Anfrage losgeschickt.
    //
    // Enter im Eingabefeld bricht ABSICHTLICH NICHTS ab: dort loest Enter
    // weiterhin ganz normal "submit" aus und sendet, mit der Ansage "wird
    // gerade geschrieben" als Rueckmeldung - ein Enter zu viel darf die
    // eigene laufende Anfrage nicht killen.
    refs.send.addEventListener('click', (event) => {
        if (!awaitingReply) {
            return;
        }

        event.preventDefault();
        cancelRequest();
    });

    refs.resetButton?.addEventListener('click', () => {
        setResetConfirmOpen(refs.resetConfirm !== null && refs.resetConfirm.hidden);
    });
    refs.resetNo?.addEventListener('click', () => setResetConfirmOpen(false));
    refs.resetYes?.addEventListener('click', () => resetConversation());

    refs.genderInputs.forEach((option) => {
        option.addEventListener('change', () => {
            if (option.checked) {
                state.genderStyle = option.value;
                writeState(state);
            }
        });
    });
}

const widgetElement = document.getElementById('acb-widget');

if (widgetElement !== null) {
    initialiseWidget(widgetElement);
}
