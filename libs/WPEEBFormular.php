<?php

declare(strict_types=1);

// ===========================================================================
// Formular-Rahmen nach der NRG-Stack-Konvention (übernommen aus EEBGWFormular) (SUITE.md „Einheitliche
// Formular-Optik“): „Wozu dieses Modul?“ → „Neu bis Version“ →
// „Dokumentation & Hilfe“ → Fachpanels → Rückmeldungen → „Über dieses Modul“.
// Vorbild: RoboHubFormular. Trait-Name mit Modul-Präfix, weil Klassen- und
// Trait-Namen in Symcon prozessweit global sind.
// ===========================================================================

trait WPEEBFormular
{
    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $WPEEB_NEWS_VERSIONS = [
        '0.1.0' => [
            '• First version: connects a heat pump via EEBUS to Symcon - power and energy (MPC) are read, a power limit (LPC) can be set by hand, by script or by an energy management.',
            '• Hangs as a child instance on the NRG-Stack EEBUS gateway, which takes care of certificate, pairing and connection.',
            '• Delivers the heat pump contract of the NRG-Stack like the other heat pump modules, with measured power and energy.',
        ],
        '0.4.0' => [
            '• 🎛️ The NRG-Stack EMS can now use the heat pump: optional run, its power and the power limit are part of the heat pump contract (version 1.18). New setting “Who controls this heat pump?” - with “another system” the EMS stays out.',
        ],
        '0.3.0' => [
            '• 📈 Energy: if the heat pump reports no meter reading (e.g. Vaillant), the module calculates it from the measured power (variable “Electrical energy consumed (calculated)”, marked as calculated in the contract).',
            '• 🔗 Fits the NRG-Stack: power and energy use the shared profiles NRG.Watt/NRG.kWh like MeterHub, and the contract also carries the MeterHub fields (function, powerID, energyImportID) for the energy flow of the dashboard.',
            '• 📈 New panel “Energy and archive”: one click switches on archiving of power and meter reading, which the heat pump monitor and the energy flow need. Nothing is switched on without your click.',
            '• ☀️ Like evcc: while “Run optional compressor run” is on, every run the heat pump newly offers is scheduled immediately and again every 10 minutes (adjustable). Pausing has priority over aborting.',
            '• 🎚️ Like evcc: if the heat pump offers its power limit anew (e.g. after a restart), the module sends the desired limit again until the heat pump confirms it.',
            '• 🌡️ New: hot water temperature, if the heat pump reports it via EEBUS (use case MDT).',
        ],
        '0.2.2' => [
            '• 💬 Feedback and questions now go to the forum thread for this module - the button in “Feedback” opens it.',
        ],
        '0.2.1' => [
            '• The module is now called NRG-Stack WPEEBUSHub, like the other NRG-Stack heat pump modules.',
        ],
        '0.2.0' => [
            '• ☀️ New: optional compressor run for PV surplus (EEBUS use case OHPCF). The heat pump offers an additional run, the module shows it and starts, pauses, resumes or aborts it - by hand, by script or by an energy management.',
        ],
    ];

    private static $WPEEB_REPO_URL     = 'https://github.com/DG65/NRGWPEEBUSHub';
    // Modul-Thread im Symcon-Forum (Kurzform mit Themen-ID, bleibt gültig, auch wenn der Titel geändert wird).
    private static $WPEEB_FORUM_URL    = 'https://community.symcon.de/t/144877';
    private static $WPEEB_LICENSE_URL  = 'https://github.com/DG65/NRGWPEEBUSHub/blob/main/LICENSE';
    private static $WPEEB_PAYPAL_URL   = 'https://paypal.me/DietmarGureth';
    private static $WPEEB_LIBRARY_GUID = '{A48F1429-D712-4753-9B62-BFE0CB821E32}';

    /** @var array Übersetzungen dieses Aufrufs (Stolperstein 24: jeden Text nur einmal übersetzen). */
    private $wpeebUebersetzt = [];

    /**
     * Englischen Quelltext in die Sprache der Konsole übersetzen (locale.json), Platzhalter %s wie sprintf.
     * Jeder Text wird je Aufruf nur einmal an Symcon gegeben.
     */
    protected function t(string $text, string ...$werte): string
    {
        if (!isset($this->wpeebUebersetzt[$text])) {
            $this->wpeebUebersetzt[$text] = (string)$this->Translate($text);
        }
        $aus = $this->wpeebUebersetzt[$text];
        if ($werte === []) {
            return $aus;
        }
        $mit = @vsprintf($aus, $werte);
        return $mit === false ? $aus : $mit;
    }

    /**
     * Kennzeichnet einen festen Formulartext, der nicht direkt hinter 'caption' => steht.
     * Übersetzt NICHT (das macht die Konsole, Store-Checkliste 25); nur damit
     * .tools/i18n-texte.php den Text für locale.json findet.
     */
    protected static function fest(string $text): string
    {
        return $text;
    }

    protected function registerFormAttributes(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        // Gespeichert wird die tatsächlich installierte Version (SUITE.md Formular-Optik Punkt 1).
        $this->WriteAttributeString('SeenNews', $this->wpeebInstalledVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    protected function wpeebLibrary(): array
    {
        $lib = function_exists('IPS_GetLibrary') ? @IPS_GetLibrary(self::$WPEEB_LIBRARY_GUID) : false;
        return is_array($lib) ? $lib : [];
    }

    protected function wpeebInstalledVersion(): string
    {
        $lib = $this->wpeebLibrary();
        if (!empty($lib['Version'])) {
            return (string)preg_replace('/-.*$/', '', (string)$lib['Version']);
        }
        $keys = array_keys(self::$WPEEB_NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string)end($keys);
    }

    protected function formPurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'This module connects a heat pump with EEBUS interface to Symcon: it shows the electrical power and energy of the heat pump and can limit its power consumption (EEBUS use cases MPC and LPC).'],
                ['type' => 'Label', 'caption' => 'The connection itself (certificate, pairing, encryption) is handled by the NRG-Stack EEBUS gateway above this instance. Create one gateway per heat pump.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'WPEEB_AckPurposeIntro($id);'],
            ],
        ];
    }

    protected function formNewsBanner(): ?array
    {
        $seen = $this->ReadAttributeString('SeenNews');
        $pending = [];
        foreach (self::$WPEEB_NEWS_VERSIONS as $version => $lines) {
            if (version_compare((string)$version, $seen === '' ? '0' : $seen, '>')) {
                $pending[(string)$version] = $lines;
            }
        }
        if ($pending === []) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        foreach ($pending as $version => $lines) {
            $items[] = ['type' => 'Label', 'caption' => $this->t('Version %s:', (string)$version)];
            foreach ($lines as $line) {
                // Feste Texte übersetzt die Konsole selbst (Store-Checkliste 25).
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'WPEEB_AckNews($id);'];
        $keys = array_keys($pending);
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => $this->t('🆕  New up to version %s', (string)end($keys)),
            'items' => $items,
        ];
    }

    protected function formDocPanel(): array
    {
        $lib = $this->wpeebLibrary();
        $ver = isset($lib['Version'])
            ? 'ℹ️ ' . $this->t('WPEEBUSHub version %s (build %s)', (string)$lib['Version'], (string)($lib['Build'] ?? '?'))
            : self::fest('ℹ️ WPEEBUSHub');
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Documentation & help',
            'items' => [
                ['type' => 'Label', 'caption' => $ver],
                ['type' => 'Label', 'caption' => 'Setup: 1. Create this instance; Symcon asks for the parent instance - choose “NRG-Stack EEBUS gateway” (create a new one per heat pump). 2. In the gateway, enter address and port of the heat pump (see the manufacturer app or manual), check the remote device and trust its SKI. 3. Trust the SKI of the gateway in the manufacturer app of the heat pump (usually under Network → EEBUS). As soon as the connection is up, power, energy and limit appear here.'],
                ['type' => 'Label', 'caption' => 'The power limit (LPC) is a request to the heat pump: it decides itself how it complies. Some devices ignore small limits or do not offer LPC at all - then the limit stays empty here.'],
                ['type' => 'Label', 'caption' => 'Heat pumps that only open the connection themselves (e.g. Wolf Link): set the gateway (version 0.5.0 or newer) to “The device connects to the gateway”. The optional compressor run for PV surplus (OHPCF) is only started by hand, by script or by an energy management - the module itself does not decide when.'],
                ['type' => 'Button', 'caption' => 'Manual on GitHub', 'onClick' => "echo '" . self::$WPEEB_REPO_URL . "';", 'link' => true],
            ],
        ];
    }

    protected function formForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 This module is new and so far only tested against a simulated heat pump. Which heat pump did you connect, and did it work? Feedback with manufacturer, model and the use cases shown helps a lot.'],
                ['type' => 'Button', 'caption' => 'Open the forum thread', 'onClick' => "echo '" . self::$WPEEB_FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'WPEEB_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of real enthusiasm for my own setup — and quite a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::$WPEEB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'You like the module and would like to leave something anyway? I am happy about a small donation — completely voluntary, nothing required in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::$WPEEB_PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Fachpanels in Standardreihenfolge, Schlüssel => Panel (liefert das Modul). */
    abstract protected function wpeebFachpanels(): array;

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus „FormLayout“ (SUITE.md 3a, Vorbild Batteriewächter).
     * Unbekannte oder fehlende Einträge fallen auf Standard und Standardposition zurück.
     * Rückgabe: Schlüssel => ['open' => std|open|closed|hidden, 'pos' => int, 'idx' => int], sortiert.
     */
    protected function wpeebFormLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach (array_values($keys) as $i => $k) {
            $r = $by[$k] ?? [];
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt; kein Haken = Standard.
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = ['open' => $open, 'pos' => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1, 'idx' => $i];
        }
        uasort($out, function ($a, $b) {
            return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']];
        });
        return $out;
    }

    private static function wpeebPanelName(array $panel, string $key): string
    {
        return trim((string)preg_replace('/\s*\(.*$/u', '', (string)($panel['caption'] ?? $key)));
    }

    protected function formLayoutPanel(array $layout, array $fach): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = self::wpeebPanelName($fach[$key], (string)$key);
            $anzeige = $this->t($name) . (self::wpeebBereich($fach[$key]) === 'actions' ? $this->t(' (below, with the actions)') : '');
            $rows[] = ['Key' => (string)$key, 'Panel' => $anzeige, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $this->t($name)));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => $this->t('🙈 Hidden: %s — to show again, clear the “Hidden” checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $this->t('🪟  Form: layout') . ($hidden ? $this->t(' (%s hidden)', (string)count($hidden)) : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing, so this setting applies every time the form opens – after “Apply”, from the next opening on.'],
                ['type' => 'Label', 'caption' => 'No checkbox means “default”: as the module intends (e.g. “Connection” expanded as long as the heat pump is not reachable). With equal positions the default order applies. “Hidden” only removes a section from the display; its settings are kept and stay in effect. If several boxes are ticked, “Hidden” wins over “Collapsed” over “Expanded”. The sections at the top (purpose, news, documentation) and at the bottom (feedback, about this module) stay fixed.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Sections', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Section', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo WPEEB_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** WPEEB_ResetFormLayout($id): string — Aufbau im offenen Formular auf Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $rows = [];
        $i = 0;
        foreach ($this->wpeebFachpanels() as $key => $panel) {
            $anzeige = $this->t(self::wpeebPanelName($panel, (string)$key)) . (self::wpeebBereich($panel) === 'actions' ? $this->t(' (below, with the actions)') : '');
            $rows[] = ['Key' => (string)$key, 'Panel' => $anzeige, 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', (string)json_encode($rows, JSON_UNESCAPED_UNICODE));
        return 'ℹ️ ' . $this->t('Set to default. Click “Apply” below to save.');
    }

    /** Bereich eines Fachpanels: 'elements' (Standard) oder 'actions' (Felder ohne Eigenschaft). */
    private static function wpeebBereich(array $panel): string
    {
        return ($panel['bereich'] ?? 'elements') === 'actions' ? 'actions' : 'elements';
    }

    protected function wpeebBuildForm(array $fachpanels, array $actions, array $status): string
    {
        $layout = $this->wpeebFormLayout(array_keys($fachpanels));
        $bereiche = ['elements' => [], 'actions' => []];
        foreach ($layout as $key => $l) {
            $panel = $fachpanels[$key];
            $bereich = self::wpeebBereich($panel);
            unset($panel['bereich']);
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen“ erhalten.
                $panel['visible'] = false;
            }
            // Die Position gilt innerhalb des jeweiligen Bereichs (SUITE.md 3a).
            $bereiche[$bereich][] = $panel;
        }
        $elements = array_values(array_filter(array_merge(
            [$this->formPurposeIntro(), $this->formNewsBanner(), $this->formDocPanel()],
            $bereiche['elements'],
            [$this->formLayoutPanel($layout, $fachpanels), $this->formForumHint(), $this->formLicenseHint()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => array_merge($bereiche['actions'], $actions), 'status' => $status]);
    }
}
