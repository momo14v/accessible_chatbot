# RESEARCH.md — EXT:accessible_chatbot

Finding log for the accessibility-conformance review of `CONCEPT.md`.

**Scope of this research pass (2026-08-13):** The user explicitly ruled out prior-art / duplicate-extension research ("there are a few, none works perfectly"). This pass therefore does **not** answer "does this already exist". It answers one question only:

> Does `CONCEPT.md` cover everything that has to be covered for this to count as an accessible chatbot?

Sources are limited to standards bodies (W3C/WAI, ETSI), German federal accessibility bodies (BFIT-Bund, Bundesfachstelle Barrierefreiheit, DIN), German disability advocacy organisations (DBSV), EU legal texts, browser-vendor documentation (MDN, Chromium), and recognised accessibility practitioners / published design systems. No blog or forum post is used as a sole source.

**Never delete an entry.** If a finding turns out to be more or less relevant than first thought, move it to the correct relevance category — the file must always show everything that was ever checked.

**Entry format:** URL — what is at that URL — date checked — relevance tag — type tag.

Relevance tags: `helpful` / `might be helpful` / `not helpful`
Type tags: `Direct conflict with CONCEPT.md` / `Gap in CONCEPT.md` / `Technical reference` / `Confirms CONCEPT.md` / `Searched, nothing found`

---

## 1. Direct conflicts with CONCEPT.md

These findings contradict something `CONCEPT.md` currently states or mandates.

### 1.1 Automatic navigation after a fixed 2.5 s delay (CONCEPT.md §4.5, §8.5, Phase 5)

- https://www.w3.org/TR/WCAG22/#timing-adjustable — normative text of SC 2.2.1 Timing Adjustable (Level A), including all six exceptions (Turn off / Adjust / Extend / Real-time / Essential / 20 Hour) — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`
- https://www.w3.org/WAI/WCAG22/Understanding/timing-adjustable.html — Understanding SC 2.2.1. Defines a time limit as "any process that happens without user initiation after a set time or on a periodic basis". A fixed 2.5 s delay before navigating the user away is therefore a content-set time limit. The "Extend" route requires a warning plus at least 20 seconds to extend — not achievable at a 2.5 s timescale. The "Essential" exception applies only where the time limit is intrinsic to the activity's validity (e.g. an auction), which does not apply here — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`
- https://www.w3.org/WAI/WCAG22/Understanding/on-input.html — SC 3.2.2 On Input (Level A): an automatic change of context caused by user input conforms only if the user "has been advised of the behavior before using the component" — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`
- https://www.w3.org/TR/WCAG22/#change-on-request — SC 3.2.5 Change on Request (Level AAA): "Changes of context are made only when requested by the user, or a mechanism is available to turn off such changes." AAA, therefore not binding at AA — but it is the criterion that names the anti-pattern most precisely — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`
- WCAG failure techniques F40 / F41 (meta-refresh with a time limit), referenced from within the 2.2.1 and 3.2.5 Understanding pages above — established precedent that a timed automatic redirect is a documented failure of 2.2.1 — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`
- https://www.w3.org/TR/coga-usable/ — W3C COGA "Making Content Usable", pattern 4.5.1 "Ensure Controls and Content Do Not Move Unexpectedly" and 4.5.9 "Avoid Data Loss and 'Timeouts'". Argues against unannounced automatic transitions for users with cognitive disabilities — the exact target group CONCEPT.md §1 names — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`

**Conclusion:** the fixed, non-cancellable 2.5 s auto-navigation described in CONCEPT.md §4.5 is a conformance failure of SC 2.2.1 (Level A, therefore mandatory at AA) and conflicts with the intent of 3.2.2 and 3.2.5. The only realistic conformant pattern at this timescale is **user-confirmed navigation** — an explicit, focusable control the user activates themselves.

### 1.2 Gender colon mandated as the "screen-reader-friendly" option (CONCEPT.md §3.8)

CONCEPT.md §3.8 mandates the colon ("Autor:innen") over the asterisk, with the justification "da dieser von Screenreadern besser erfasst wird".

- https://www.dbsv.org/gendern.html — DBSV (Deutscher Blinden- und Sehbehindertenverband), position from April 2019, updated March 2024. States that gendering with punctuation/special characters is generally problematic for blind and visually-impaired people. Ranked recommendation: (1) gender-neutral wording ("Team", "Studierende", direct address), (2) paired forms ("Ärztinnen und Ärzte"), (3) **if a symbol is unavoidable, the asterisk** — because per the Rat für deutsche Rechtschreibung it is the most common short form. The **colon is explicitly advised against**: it produces "eine deutlich längere Satzzeichenpause", so a sentence can sound as if it has ended — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`
- https://www.genderleicht.de/barrierefrei-gendern-was-soll-ich-beachten/ — quotes the DBSV position directly; confirms neutral wording as first choice — 2026-08-13 — `helpful` — `Confirms CONCEPT.md` (for the "pair" default) / `Direct conflict with CONCEPT.md` (for the colon)
- https://www.genderleicht.de/gendern-mit-doppelpunkt-ist-fuer-sehbehinderte-am-besten/ — traces the "colon is better for screen readers" claim to its origin: an interview with Domingos de Oliveira, a blind editor and accessibility consultant. It is **personal professional opinion, not a study or test protocol**; he himself notes that screen-reader punctuation handling is user-configurable. This is the source of the claim in CONCEPT.md §3.8, and it is contradicted by DBSV — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`
- https://www.bfit-bund.de/DE/Publikation/digitale-barrierefreiheit-semiotik-genderzeichen.html (PDF: https://www.bfit-bund.de/DE/Publikation/digitale-barrierefreiheit-semiotik-genderzeichen-pdf.pdf?__blob=publicationFile&v=2) — BFIT-Bund (Überwachungsstelle des Bundes für Barrierefreiheit von Informationstechnik), publication dated 21.11.2023 (update of an August 2021 original). Important correction to a secondary claim: BFIT-Bund does **not** recommend one specific character. It restricts itself to the semiotic level and defines three criteria (barrierefreie Wahrnehmung / Verständlichkeit / Reproduzierbarkeit) without ranking `*` against `:` against `_`. No announcement testing is reported — 2026-08-13 — `helpful` — `Technical reference`

**Conclusion:** the justification in CONCEPT.md §3.8 ("colon is better for screen readers") is not backed by any authoritative source and is explicitly contradicted by the German advocacy organisation of blind and visually-impaired people. The concept's *default* (write both forms out in full) matches DBSV's second-choice recommendation and is sound. The *symbol option* should be reconsidered.

### 1.3 `role="dialog"` without `aria-modal` for the chat panel (CONCEPT.md §8.2)

- https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Reference/Roles/dialog_role — MDN explicitly recommends `role="region"` or `role="complementary"` (with an accessible name) instead of `role="dialog"` for floating panels that must not block interaction with the rest of the page — exactly the requirement CONCEPT.md §8.2 states ("Seite bleibt nutzbar") — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`
- https://www.scottohara.me/blog/2019/03/05/open-dialog.html — Scott O'Hara on non-modal dialogs: the screen-reader virtual cursor behaves inconsistently around a non-modal `role="dialog"` — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md`
- https://github.com/w3c/aria/issues/1210 — W3C ARIA working-group issue on modal/non-modal defaults; `aria-modal="false"` does not reliably undo modal-like behaviour across browser/AT pairings — 2026-08-13 — `might be helpful` — `Technical reference`
- https://accessuse.eu/en/non-modal-dialogs.html — a non-modal dialog that takes and cycles focus on open behaves like a modal for keyboard/screen-reader users regardless of the ARIA attribute — 2026-08-13 — `helpful` — `Technical reference`

### 1.4 Target norm "WCAG 2.1 AA plus sensible 2.2 criteria" (CONCEPT.md §1)

- https://www.w3.org/WAI/standards-guidelines/wcag/new-in-22/ — official W3C list of the nine criteria new in WCAG 2.2: 2.4.11 Focus Not Obscured (Minimum, **AA**), 2.4.12 Focus Not Obscured (Enhanced, AAA), 2.4.13 Focus Appearance (AAA), 2.5.7 Dragging Movements (**AA**), 2.5.8 Target Size (Minimum, **AA**), 3.2.6 Consistent Help (**A**), 3.3.7 Redundant Entry (**A**), 3.3.8 Accessible Authentication (Minimum, **AA**), 3.3.9 Accessible Authentication (Enhanced, AAA). SC 4.1.1 Parsing was removed — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.w3.org/TR/WCAG22/#focus-not-obscured-minimum — SC 2.4.11 (AA): "When a user interface component receives keyboard focus, the component is not entirely hidden due to author-created content." Directly relevant: a fixed-position widget bottom-right can completely cover a focused element behind it — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.w3.org/TR/WCAG22/#consistent-help — SC 3.2.6 (A): "Help and support mechanisms are presented in the same relative order in each web page within a set of web pages." A site-wide chat widget IS a help mechanism in the sense of this criterion — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.w3.org/TR/WCAG22/#redundant-entry — SC 3.3.7 (A): information already provided in the same process must be auto-populated or selectable, not re-entered. Relevant to the 10-message history cap — 2026-08-13 — `might be helpful` — `Gap in CONCEPT.md`
- https://www.w3.org/TR/WCAG22/#target-size-minimum — SC 2.5.8 (AA): 24×24 CSS px minimum, with Spacing / Equivalent / Inline / User Agent Control / Essential exceptions — 2026-08-13 — `helpful` — `Confirms CONCEPT.md` (§8.4 already requires this)

**Note:** "plus sinnvolle WCAG-2.2-Kriterien" is not a testable target. The criteria left undecided by that wording are exactly the ones that bite a floating chat widget hardest.

---

## 2. Gaps in CONCEPT.md — accessibility requirements that are not covered at all

### 2.1 Reviewable conversation transcript (only live-region announcement is planned)

CONCEPT.md §8.2 plans `role="log"` + `aria-live="polite"` and nothing else.

- https://www.w3.org/WAI/WCAG21/Techniques/aria/ARIA23 — ARIA23: using `role="log"` for sequential information updates. Confirms `role="log"` is the semantically closest role — 2026-08-13 — `helpful` — `Confirms CONCEPT.md`
- https://a11ysupport.io/tech/aria/log_role — support data for `role="log"`: announcement mechanics work in NVDA/JAWS/VoiceOver, but the role's **name is frequently not announced** (JAWS: no support; VoiceOver: no support; NVDA: Chrome/Edge only; Narrator/Edge: none) — 2026-08-13 — `helpful` — `Technical reference`
- https://github.com/microsoft/BotFramework-WebChat/issues/3236 — Microsoft's own chat-accessibility discussion; the team argues for removing `role="log"` from the visible transcript and instead using plain list semantics plus a **separate, short-lived announcer region** carrying only the new message text — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://craigabbott.co.uk/blog/web-chat-accessibility-considerations/ — same dual-structure recommendation: a navigable transcript (list semantics, per-message accessible names, `tabindex="-1"` focus targets) plus a separate announcer — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://sarahmhigley.com/ — Sarah Higley, "The Many Lives of a Notification": a live-region announcement is a one-shot event; there is no way to go back and re-hear it — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.w3.org/WAI/ARIA/apg/patterns/feed/ — APG Feed pattern, checked and **rejected** as a match: it is built for infinite-scroll article collections with `aria-posinset`/`aria-setsize` reading-mode semantics, not for a chat — 2026-08-13 — `not helpful` — `Technical reference`
- https://www.w3.org/WAI/ARIA/apg/ — checked: the APG has **no dedicated chat pattern**. This is a genuine gap in the normative guidance, not an oversight in the search — 2026-08-13 — `helpful` — `Searched, nothing found`
- https://tetralogical.com/blog/2024/05/01/why-are-my-live-regions-not-working/ — the live-region container must exist in the DOM before content is injected; creating and filling it in the same tick frequently fails to announce — 2026-08-13 — `helpful` — `Confirms CONCEPT.md` (already implemented correctly)
- https://adrianroselli.com/2020/01/defining-toast-messages.html — `aria-atomic` behaviour: `role="log"` defaults to `aria-atomic="false"` (announce only the added node — correct for a chat); `role="status"` defaults to `aria-atomic="true"` (re-announce the whole container — wrong for a growing transcript) — 2026-08-13 — `helpful` — `Technical reference`

### 2.2 Text-to-speech / read-aloud

CONCEPT.md §3.2 names "text-to-speech und speech-to-text" as a core principle, but §8 (the binding acceptance criteria) only requires speech **input**. The concept contradicts itself.

- https://w3c.github.io/coga/techniques/index.html — W3C COGA techniques: simultaneous text-and-audio ("hear and read at the same time"), ideally with synchronised highlighting, benefits people with dyslexia and other cognitive/learning disabilities. Caveats: must not read extraneous content (increases cognitive load), needs correct punctuation/localisation — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.w3.org/TR/coga-voice/ — COGA Voice Systems research module; warns against moving/appending conversation content unless user-initiated, and stresses a reviewable, navigable history as a cognitive-load concern — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.netz-barrierefrei.de/en/text-to-speech.html — important framing: an integrated read-aloud button is a **convenience feature, not an accessibility feature**. It does not replace a screen reader and must not be marketed as making a widget accessible — 2026-08-13 — `helpful` — `Technical reference`
- WCAG 2.2 checked in full: **no success criterion requires TTS/read-aloud** — 2026-08-13 — `helpful` — `Searched, nothing found`

### 2.3 Human fallback / escape hatch

- https://www.w3.org/TR/coga-usable/ — COGA pattern **4.8.1 "Provide Human Help"** and Objective 7 / pattern 4.8.5 "Make It Easy to Find Help and Give Feedback ... from any point". Supports a mandatory, always-reachable path to a human or to normal site navigation from inside the chat — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`

CONCEPT.md offers `contactPageUid` but its default is `0` (= no fallback at all), and it is only offered on error or "I don't know", never unconditionally.

### 2.4 Cancelling a running request / no way out of a 30 s wait

- https://www.w3.org/WAI/WCAG22/Understanding/timing-adjustable.html — 2.2.1's scope is time limits *imposed on the user*; a passive wait for a user-requested response is arguably outside it. So this is a UX/COGA concern rather than a hard AA failure — but see COGA 4.5.9 above — 2026-08-13 — `might be helpful` — `Gap in CONCEPT.md`
- https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html — SC 4.1.3 Status Messages (AA): an in-progress wait must be communicated to AT via `role="status"` without moving focus. Correct technique confirmed — 2026-08-13 — `helpful` — `Confirms CONCEPT.md`
- https://www.w3.org/WAI/WCAG21/Techniques/aria/ARIA22 — ARIA22, `role="status"` technique. Also: insert the "typing" status once, never re-announce on a timer, and remove it as the answer is inserted so it is not double-announced — 2026-08-13 — `helpful` — `Confirms CONCEPT.md`
- No authoritative source defines a numeric latency threshold for AT users — 2026-08-13 — `not helpful` — `Searched, nothing found`

### 2.5 "Simple language" is not defined or measurable

- https://www.w3.org/WAI/WCAG22/Understanding/reading-level.html — SC 3.1.5 Reading Level is **Level AAA**, as are 3.1.3 Unusual Words and 3.1.4 Abbreviations. A WCAG 2.1/2.2 **AA** claim therefore carries *no* binding reading-level requirement. The concept's plain-language ambition must be justified via COGA / DIN SPEC, not via WCAG AA — 2026-08-13 — `helpful` — `Technical reference`
- https://www.din.de/resource/blob/901382/6abb95434c717f0b168af958748c80bd/din-spec-33429-leichte-sprache-fassung-data.pdf — DIN SPEC 33429 (published early 2025), the German norm for **Leichte Sprache**: strict rule set, covers linguistic *and* visual/layout rules, and requires **user testing with the actual target group** as a defining quality criterion — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.bundesfachstelle-barrierefreiheit.de/SharedDocs/Kurzmeldungen/DE/din-spec-leichte-sprache-veroeffentlicht — Bundesfachstelle Barrierefreiheit announcement of DIN SPEC 33429 — 2026-08-13 — `might be helpful` — `Technical reference`
- https://www.bmas.de/DE/Service/Presse/Meldungen/2025/einheitliche-empfehlungen-leichte-sprache.html — BMAS on unified Leichte-Sprache recommendations — 2026-08-13 — `might be helpful` — `Technical reference`

**Conclusion:** "kurze, klare Sätze ohne Fachbegriffe" (CONCEPT.md §3.7) describes **Einfache Sprache** at best. It is not enough to claim **Leichte Sprache**, which is a distinct, normed register.

### 2.6 Floating widget: obscuring content, DOM order, reachability

- https://www.w3.org/TR/WCAG22/#focus-not-obscured-minimum — SC 2.4.11 (AA), see §1.4 above. For a repositionable widget, the *default* position is what is tested — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.w3.org/WAI/WCAG21/Understanding/bypass-blocks.html — SC 2.4.1 Bypass Blocks (A). The underlying principle supports a skip mechanism to/from a persistent widget — 2026-08-13 — `might be helpful` — `Gap in CONCEPT.md`
- https://www.makethingsaccessible.com/guides/how-to-build-an-accessible-chatbot/ — the only detailed chatbot-specific build guide found. Recommends: trigger among the **last** elements in DOM/focus order, paired with a "Skip to chat" link near the top; `aria-haspopup="dialog"` + `aria-expanded` on the trigger; focus into the panel on open, back to the trigger on Escape. Single source, logically well-founded but not cross-verified — 2026-08-13 — `helpful` — `Gap in CONCEPT.md` (CONTESTED / single-source for the DOM-order recommendation specifically)
- https://www.w3.org/WAI/WCAG21/Understanding/content-on-hover-or-focus.html — SC 1.4.13 checked and found **not applicable**: its scope is content revealed by hover or focus, not a panel opened by a deliberate click — 2026-08-13 — `not helpful` — `Technical reference`
- https://www.w3.org/WAI/WCAG22/Techniques/client-side-script/SCR39.html — SCR39, the "dismissible, hoverable, persistent" engineering pattern. A technique, not a criterion; no WCAG SC mandates that a click-opened persistent overlay be dismissible — 2026-08-13 — `might be helpful` — `Technical reference`

### 2.7 Focus management across the automatic page change

- https://developer.mozilla.org/en-US/docs/Web/HTML/Global_attributes/autofocus — MDN documents auto-focusing on page load as a known accessibility problem: it "teleports" screen-reader users without warning, causes unexpected scroll, can trigger on-screen keyboards, and — critically — screen readers announce only the focused control's label, not the preceding page content, so users lose orientation about where they landed — 2026-08-13 — `helpful` — `Direct conflict with CONCEPT.md` (§4.5 step 5 plans exactly this)
- The specific "restore widget state across a navigation" scenario is not addressed by any APG/WCAG page — 2026-08-13 — `not helpful` — `Searched, nothing found`

### 2.8 Message attribution, timestamps, status

- https://www.w3.org/WAI/WCAG22/Understanding/sensory-characteristics.html — SC 1.3.3 Sensory Characteristics (A): attribution by colour/position/avatar alone fails; it must be programmatically determinable. Techniques (visually-hidden prefix / `aria-label` on the list item / `<dl>`) are author's choice, none normatively mandated — 2026-08-13 — `helpful` — `Confirms CONCEPT.md` (§8.2 already requires this; implemented as a visible sender prefix)
- Timestamps: keep out of the announcer text and never mark them `aria-live` themselves, otherwise every message re-announces boilerplate. No dedicated normative pattern exists — 2026-08-13 — `might be helpful` — `Technical reference`

---

## 3. Speech input and output — technical and privacy reference

- https://developer.mozilla.org/en-US/docs/Web/API/SpeechRecognition — browser support. Chrome/Edge/Opera: full support, audio sent to a **server-based** recognition engine by default (Google's cloud service in Chrome), does not work offline. Safari: since 14.1 (macOS) / 14.5 (iOS), prefixed, also server-based by default. Firefox: implemented but **disabled by default** behind `dom.webspeech.recognition.enable` — not viable in production — 2026-08-13 — `helpful` — `Confirms CONCEPT.md` (README already documents this data flow correctly)
- https://developer.mozilla.org/en-US/docs/Web/API/SpeechRecognition/install_static — new **on-device** path: `SpeechRecognition.available()` and the static `install()` method allow checking/installing language packs and forcing `processLocally: true`, so neither audio nor transcript leaves the device — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://github.com/WebAudio/web-speech-api/blob/main/explainers/on-device-speech-recognition.md — explainer for the on-device path — 2026-08-13 — `might be helpful` — `Technical reference`
- https://groups.google.com/a/chromium.org/g/blink-dev/c/VNOok2dbmHM/m/gwbtzV-lAQAJ — Chromium intent-to-ship; landed in Chrome 139 — 2026-08-13 — `might be helpful` — `Technical reference`
- https://www.d4b.dev/blog/2025-12-29-accessible-markup-for-speech-input — accessible markup pattern for a speech-input button: `aria-pressed` toggle, accessible-name change, paired polite status region, same control starts and stops. Matches the current implementation — 2026-08-13 — `helpful` — `Confirms CONCEPT.md`
- https://dl.acm.org/doi/10.1145/3544548.3581224 — CHI 2023, peer-reviewed: speech recognition is **not universally accessible**. Word error rates commonly exceed 30 % for moderate dysarthria and 60 %+ for severe; people who stutter get degraded recognition. WCAG has **no criterion** covering recovery from misheard voice input — a documented gap — 2026-08-13 — `helpful` — `Technical reference`
- https://support.microsoft.com/en-us/accessibility/windows/voice-access/voice-access-frequently-asked-questions-faqs — Microsoft Voice Access FAQ. Background voice apps and exclusive-mode microphone access can conflict. No source found documenting a *named* conflict between a page's own `SpeechRecognition` capture and Dragon/Voice Access/Voice Control specifically — plausible but unconfirmed risk — 2026-08-13 — `might be helpful` — `Technical reference` (NOT VERIFIABLE as a documented conflict)
- https://www.qualibooth.com/resources/text-to-speech-versus-screen-readers/ — a page's own TTS can talk over an active screen reader — 2026-08-13 — `might be helpful` — `Technical reference`

---

## 4. AI-specific output handling

- https://www.w3.org/TR/WCAG21/#link-purpose-in-context — SC 2.4.4 Link Purpose (In Context, Level A) applies unchanged to links inside AI-generated output. Generic "click here" link text fails — 2026-08-13 — `helpful` — `Confirms CONCEPT.md` (the extension already builds link text server-side from real page titles)
- https://design.va.gov/accessibility/when-a-screen-reader-needs-to-announce-content — VA.gov Design System: screen readers do **not** vocalise raw markdown (`**bold**`, `- ` lists) as emphasis or structure. Raw markdown syntax left in the DOM is either read literally as noise or silently drops the intended structure. Correct handling: parse to semantic HTML, or forbid it at the source — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.boia.org/blog/emojis-and-web-accessibility-best-practices — screen readers announce each emoji's literal Unicode description (three rocket emoji → "rocket rocket rocket"). Decorative emoji in AI output should be stripped or `aria-hidden` — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://www.sheribyrnehaber.com/making-chatbots-accessible/ — practitioner guidance on chatbot text: active voice, short sentences and paragraphs, common low-syllable words — 2026-08-13 — `might be helpful` — `Confirms CONCEPT.md`
- https://w3c.github.io/ai-accessibility/ — W3C draft "Accessibility of machine learning and generative AI", Editor's Draft, March 2026, incomplete. Does **not** yet cover markdown/emoji formatting or AI self-disclosure. Checked so the gap is documented, not so it can be cited — 2026-08-13 — `might be helpful` — `Searched, nothing found`
- https://www.w3.org/WAI/research/ai2023/ — W3C WAI AI symposium. Flags "chatbots don't ask how confident they are ... no self-awareness of what's wrong" as an open, unresolved accessibility concern — 2026-08-13 — `might be helpful` — `Technical reference`

---

## 5. Legal and normative frame

### 5.1 BFSG / BITV / EN 301 549

- https://www.gesetze-im-internet.de/bfsg/ — BFSG, in force since **28 June 2025**, implementing EU Directive 2019/882 (European Accessibility Act). Applies to private economic operators, **not** public bodies (those fall under BGG/BITV). §1 Abs. 3 Nr. 5 covers "Dienstleistungen im elektronischen Geschäftsverkehr" — decisive criterion is that the service is directed at concluding a **consumer contract**. Microenterprises (<10 employees, ≤ €2 m) are exempt from the service obligations. Note: only the table of contents could be retrieved on fetch — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://bfsg-gesetz.de/1-bfsg/ — mirror/paraphrase of §1 BFSG used because the primary text could not be fetched in full — 2026-08-13 — `might be helpful` — `Technical reference`
- https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32019L0882 — Directive (EU) 2019/882, the European Accessibility Act — 2026-08-13 — `might be helpful` — `Technical reference`
- https://www.barrierefreiheit-dienstekonsolidierung.bund.de/Webs/PB/DE/gesetze-und-richtlinien/en301549/en301549-artikel.html — EN 301 549 **V3.2.1 (2021-03)** is the currently referenced version; Clause 9 (Web) references **WCAG 2.1 Level A + AA**. A forthcoming V4.1.1 (expected harmonised around October 2026) is reported to move the baseline to **WCAG 2.2 AA** — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://accessible.canada.ca/en-301-549-accessibility-requirements-ict-products-and-services-6-ict-two-way-voice-communication — EN 301 549 Clause 6 ("ICT with two-way voice communication", including Real-Time Text). Checked deliberately: a text-based chat widget with no live two-way voice channel does **not** trigger Clause 6. The operative clause remains Clause 9 → WCAG — 2026-08-13 — `helpful` — `Searched, nothing found`
- https://www.barrierefreiheit-dienstekonsolidierung.bund.de/Webs/PB/DE/gesetze-und-richtlinien/bitv2-0/bitv2-0-node.html — BITV 2.0 references EN 301 549 V3.2.1 → WCAG 2.1 A/AA. Adds, for **public bodies only**: a formal Barrierefreiheitserklärung with feedback mechanism, plus a homepage explanation of the site's essential content in Deutsche Gebärdensprache **and** Leichte Sprache — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- Whether a chatbot on a purely informational company website (no contract conclusion) falls under BFSG is **not clarified in the legal text**; legal-advisory sources are unanimous that this is undecided — 2026-08-13 — `helpful` — `Searched, nothing found` (CONTESTED)

### 5.2 EU AI Act

- https://artificialintelligenceact.eu/transparency-rules-article-50/ — Article 50(1): providers of AI systems intended for direct interaction with natural persons must inform users that they are interacting with an AI system, unless obvious from context. Article **50(5)**: the disclosure itself must be **accessible** — not buried in T&Cs. Applies from **2 August 2026** — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://digital-strategy.ec.europa.eu/en/faqs/transparency-obligations-under-article-50-ai-act — European Commission FAQ on Art. 50 — 2026-08-13 — `helpful` — `Technical reference`
- https://www.cooley.com/news/insight/2026/2026-08-03-eu-ai-act-transparency-obligations-take-effect-2-august-2026 — legal summary confirming the 2 August 2026 date and the transitional grace period to 2 December 2026 for systems already on the market — 2026-08-13 — `might be helpful` — `Technical reference`
- The primary Official Journal text (Regulation (EU) 2024/1689) was **not** fetched directly; all Art. 50 findings rest on consistent secondary legal-tracking sources plus the Commission FAQ — 2026-08-13 — `helpful` — `Searched, nothing found` (verify against the OJ text before publishing any legal wording)

### 5.3 Conformance claims

- https://bf-check.de/blog/barrierefreiheitserklaerung-vorlage-generator — the three defined statuses for a Barrierefreiheitserklärung ("vollständig konform" / "teilweise konform" / "nicht konform"), the requirement to name the technical basis explicitly, and to disclose assessment method and date — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`
- https://bitvtest.de/test-methodik/web/beschreibung-des-pruefverfahrens — the BITV-Test procedure: 98 test steps against EN 301 549, performed and certified only by qualified human testers at accredited BIK-Prüfstellen. No self-certification for the official mark — 2026-08-13 — `helpful` — `Gap in CONCEPT.md`

**Conclusion:** no unqualified "WCAG 2.1 AA compliant" claim may be made. The correct output is a **dated self-assessment (Selbstbewertung)** naming the technical basis, the method, and the known gaps.

---

## 6. Checked and found not to apply

- https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/ — APG Modal Dialog pattern. Requires a focus trap. Checked and **rejected**: a focus trap contradicts the concept's explicit requirement that the page stays usable while the chat is open — 2026-08-13 — `not helpful` — `Technical reference`
- https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html — SC 2.2.2 Pause, Stop, Hide. Checked: primarily governs moving/blinking/auto-updating *visual* content; it does not itself govern a timed redirect (2.2.1 does). Marginally relevant to the typing indicator and auto-scroll — 2026-08-13 — `not helpful` — `Technical reference`
- https://www.w3.org/WAI/WCAG22/Understanding/on-focus.html — SC 3.2.1 On Focus (A). Checked: the toggle button correctly does not open the panel on focus alone. No change needed — 2026-08-13 — `not helpful` — `Confirms CONCEPT.md`
- EN 301 549 Clause 11 (Software) — checked: largely superseded by Clause 9 (Web) for browser-based content — 2026-08-13 — `not helpful` — `Technical reference`

---

## 7. During Development

*(Reserved. Only hard blockers found by `typo3-researcher` during an active build phase get appended here — nothing else is edited after planning closes.)*
