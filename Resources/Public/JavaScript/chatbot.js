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

const widgetElement = document.getElementById('acb-widget');

if (widgetElement !== null) {
    initialiseWidget(widgetElement);
}

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
function createDefaultState() {
    return {
        messages: [],
        pendingNavigation: null,
        isOpen: false,
        genderStyle: 'pair',
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
function readState() {
    try {
        const raw = window.sessionStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return createDefaultState();
        }

        const parsed = JSON.parse(raw);

        return {
            messages: Array.isArray(parsed.messages) ? parsed.messages.filter(isValidMessage).map(sanitiseMessage) : [],
            pendingNavigation: sanitiseNavigation(parsed.pendingNavigation),
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
function createMessageElement(message, labels, contactUrl) {
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

        return item;
    }

    if (message.suggestContact === true && contactUrl !== '') {
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
    let announceTimeout = null;
    let pendingAnnouncement = '';

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
        refs.list.append(createMessageElement(message, labels, contactUrl));
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

        addMessage({ role: 'user', text });
        refs.input.value = '';
        showTyping();

        // Das Protokoll hat kein aria-live mehr; der Tipp-Status wird deshalb
        // hier EINMAL angesagt (Konzept 8.3) und nie auf einem Timer wiederholt.
        announce(labels['status.typing'] ?? '');

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
                hideTyping();

                // Es darf niemals stilles Schweigen geben: jede Stoerung wird
                // als sichtbare Zeile ins Protokoll geschrieben und dadurch
                // ueber aria-live vorgelesen.
                const errorText = (error && error.acbText)
                    || labels[(error && error.acbLabel) || 'error.request']
                    || labels['error.request']
                    || '';

                addSystemMessage(errorText);

                // Pflicht seit Phase 5: ohne aria-live am Protokoll wuerde eine
                // Stoermeldung sonst gar nicht mehr angesagt (Exit-Kriterium
                // Phase 2: nie stilles Schweigen).
                announce(errorText);
            })
            .finally(() => {
                awaitingReply = false;
                refs.send.removeAttribute('aria-disabled');
            });
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

        const recognition = new Recognition();
        const pageLanguage = document.documentElement.lang;

        if (pageLanguage) {
            recognition.lang = pageLanguage;
        }

        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;

        let listening = false;
        let starting = false;
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

        recognition.addEventListener('start', () => {
            starting = false;
            listening = true;
            refs.micButton.setAttribute('aria-pressed', 'true');
            announce(labels['mic.listening'] ?? '');
        });

        recognition.addEventListener('result', (event) => {
            handled = true;
            refs.input.value = event.results?.[0]?.[0]?.transcript ?? '';
            announce(labels['mic.recognized'] ?? '');
            refs.input.focus();
        });

        recognition.addEventListener('error', (event) => {
            handled = true;
            starting = false;

            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                announce(labels['mic.denied'] ?? '');
            } else if (event.error === 'no-speech') {
                announce(labels['mic.nospeech'] ?? '');
            } else {
                announce(labels['mic.error'] ?? '');
            }
        });

        recognition.addEventListener('end', () => {
            starting = false;
            listening = false;
            refs.micButton.setAttribute('aria-pressed', 'false');

            if (!handled) {
                announce(labels['mic.stopped'] ?? '');
            }
        });
    }

    /* ---------- Start ---------- */

    // 1. Gespeicherten Verlauf wieder aufbauen.
    state.messages.forEach((message) => {
        refs.list.append(createMessageElement(message, labels, contactUrl));
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
                announce(labels['status.typing'] ?? '');

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

    refs.genderInputs.forEach((option) => {
        option.addEventListener('change', () => {
            if (option.checked) {
                state.genderStyle = option.value;
                writeState(state);
            }
        });
    });
}
