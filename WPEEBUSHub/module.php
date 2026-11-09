<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/WPEEBFormular.php';

// ===========================================================================
// NRG-Stack WPEEBUSHub
//
// Wärmepumpe über EEBUS. Kind-Instanz des NRG-Stack EEBUS-Gateways (Kind-
// Schnittstelle 1.1, Rolle Energiemanagement): meldet sich als Energy Guard
// (LPC, Leistungsbegrenzung), Monitoring Appliance (MPC, Leistung/Energie) und
// CEM für OHPCF (optionaler Verdichterlauf für PV-Überschuss, ab 0.2.0) an. Abonnieren, Binden und erstes Lesen der Wärmepumpen-Entität übernimmt
// das Gateway; dieses Modul wertet die Daten aus, schreibt die Grenze und
// liefert den heatpump-Vertrag des Verbunds (wie WPModbusHub/WPHub/HeishaMon).
//
// Vorbild für Abläufe: eebus-go usecases/eg/lpc und usecases/ma/mpc (MIT).
// Geprüft gegen eebus-go examples/evse, erweitert um MPC, als
// HeatPumpAppliance (06.11.2026). An keiner echten Wärmepumpe geprüft.
// ===========================================================================

class WPEEBUSHub extends IPSModule
{
    use WPEEBFormular;

    private const GATEWAY_MODULE = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
    private const DATA_ZUM_GATEWAY = '{26D4DA14-6C0D-4686-AE00-D23234BAD90D}';
    private const CONTRACT_VERSION = '1.18';
    // Steuerhoheit (heatpump 1.18, WP-Steuermuster): wer diese Wärmepumpe steuern darf. Das EMS schreibt nur bei
    // none/ems und controllable; „other“ = ein anderes System steuert, das EMS hält sich heraus.
    private const STEUERHOHEIT = ['none', 'ems', 'other'];
    // Gemeinsame Variablenprofile des Verbunds (SUITE.md „Gemeinsame Variablenprofile“), wie MeterHub.
    private const ARCHIV_MODUL = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    // Hochgerechneter Zählerstand (wie MeterHub, CalculatedEnergy): Takt und längste Lücke, die noch
    // überbrückt wird. EEBUS meldet die Leistung nur bei Änderung, deshalb gilt der letzte Wert bis
    // zur nächsten Meldung (Treppenkurve), nicht der Mittelwert zweier Abfragen.
    private const ENERGIE_TAKT_MS = 60000;
    private const ENERGIE_MAX_LUECKE_S = 600;
    private const ENERGIE_MAX_W = 1000000.0;
    // Monitoring of DHW Temperature (MDT, wie evcc/eebus-go ma/mdt): Warmwasserkreis als eigene Entität.
    private const MDT_NAME = 'monitoringOfDhwTemperature';
    // [LPC-913] wie evcc AssertLimit: gewünschte Grenze an eine neu erschienene Grenze erneut senden, mit
    // wachsender Wartezeit, höchstens 50 s lang (die Spezifikation gibt dem Energy Guard 60 s).
    private const GRENZE_BESTAETIGEN_MAX_S = 50;
    private const OHPCF_NAME = 'optimizationOfSelfConsumptionByHeatPumpCompressorFlexibility';
    // Zustand des optionalen Verdichterlaufs (Variable OhpcfZustand, Profil WPEEB.OhpcfState).
    private const OHPCF_ZUSTAENDE = ['inactive' => 1, 'scheduled' => 2, 'running' => 3, 'paused' => 4, 'scheduledPaused' => 4, 'completed' => 5, 'invalid' => 0];

    // Entitätstypen, die als Wärmepumpe gelten (SPINE EntityTypeType), in absteigendem Vorrang: Meldet ein
    // Gerät mehrere, gilt die erste mit Leistungsbegrenzung oder Messwerten (Vorbild eebus-go eg/lpc,
    // validEntityTypes; Generic für Geräte, die keinen genaueren Typ melden).
    private const WP_ENTITAETEN = ['HeatPumpAppliance', 'Compressor', 'Generic'];

    public function Create()
    {
        parent::Create();
        // Formular: Aufbau (SUITE.md 3a).
        $this->RegisterPropertyString('FormLayout', '[]');
        // Zuletzt bekannter Stand der Wärmepumpe (Entität, Kennungen, Werte), damit Formular und Vertrag
        // auch zwischen zwei Meldungen antworten können.
        $this->RegisterAttributeString('Geraet', '{}');
        $this->RegisterAttributeInteger('LastSeenAt', 0);
        $this->RegisterAttributeString('LetztesErgebnis', '{}');
        $this->registerFormAttributes();
        // Anmeldung beim Gateway regelmäßig auffrischen (z. B. nach Neuanlage des Gateways); das Gateway
        // vergleicht selbst und tut bei gleicher Anmeldung nichts.
        $this->RegisterTimer('Anmelden', 0, 'WPEEB_Anmelden($_IPS[\'TARGET\']);');
        // Hochgerechneter Zählerstand, wenn die Wärmepumpe keinen eigenen liefert: {t, p} der letzten Leistung.
        $this->RegisterAttributeString('EnergieRechnung', '{}');
        // Gewünschter optionaler Lauf (Ein/Aus wie evcc): bei „Ein“ wird ein neu angebotener Lauf sofort und
        // danach alle ReboostMinuten erneut eingeplant (evcc reboost, Standard 10 min, 0 = aus).
        $this->RegisterAttributeBoolean('OhpcfWunsch', false);
        $this->RegisterPropertyInteger('ReboostMinuten', 10);
        $this->RegisterPropertyString('Steuerhoheit', 'none');
        $this->RegisterTimer('Reboost', 0, 'WPEEB_ReboostTakt($_IPS[\'TARGET\']);');
        // Zuletzt gewünschte Grenze {watt, aktiv, bis} und laufende Bestätigung {seit, warte}.
        $this->RegisterAttributeString('GrenzeWunsch', '{}');
        $this->RegisterAttributeString('GrenzeBestaetigung', '{}');
        $this->RegisterTimer('GrenzeBestaetigen', 0, 'WPEEB_GrenzeBestaetigen($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Energie', 0, 'WPEEB_EnergieTakt($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        $this->MaintainVariable('Erreichbar', $this->t('Reachable'), VARIABLETYPE_BOOLEAN, '', 1, true);
        // Leistung und Energie mit den gemeinsamen NRG-Profilen wie MeterHub (ab 0.3.0, vorher ~Watt/~Electricity).
        $this->nrgProfil('NRG.Watt', ' W', 0);
        $this->nrgProfil('NRG.kWh', ' kWh', 1);
        $this->MaintainVariable('Leistung', $this->t('Electrical power'), VARIABLETYPE_FLOAT, 'NRG.Watt', 2, true);
        $this->MaintainVariable('Energie', $this->t('Electrical energy consumed'), VARIABLETYPE_FLOAT, 'NRG.kWh', 3, true);
        $this->MaintainVariable('EnergieHochgerechnet', $this->t('Electrical energy consumed (calculated)'), VARIABLETYPE_FLOAT, 'NRG.kWh', 4, true);
        $this->MaintainVariable('Grenze', $this->t('Power limit'), VARIABLETYPE_FLOAT, 'NRG.Watt', 5, true);
        $this->MaintainVariable('GrenzeAktiv', $this->t('Power limit active'), VARIABLETYPE_BOOLEAN, '~Switch', 6, true);
        $this->MaintainVariable('GrenzeBis', $this->t('Power limit ends at'), VARIABLETYPE_INTEGER, '~UnixTimestamp', 6, true);
        $this->MaintainVariable('Ausfallgrenze', $this->t('Failsafe power limit'), VARIABLETYPE_FLOAT, 'NRG.Watt', 7, true);
        $this->MaintainVariable('Nennleistung', $this->t('Maximum power consumption'), VARIABLETYPE_FLOAT, 'NRG.Watt', 8, true);
        $this->profileSicherstellen();
        $this->MaintainVariable('OhpcfZustand', $this->t('Optional compressor run'), VARIABLETYPE_INTEGER, 'WPEEB.OhpcfState', 9, true);
        $this->MaintainVariable('OhpcfLauf', $this->t('Run optional compressor run'), VARIABLETYPE_BOOLEAN, '~Switch', 10, true);
        $this->MaintainVariable('OhpcfLeistung', $this->t('Optional run: expected power'), VARIABLETYPE_FLOAT, 'NRG.Watt', 11, true);
        $this->MaintainVariable('OhpcfLeistungMax', $this->t('Optional run: maximum power'), VARIABLETYPE_FLOAT, 'NRG.Watt', 12, true);
        $this->EnableAction('Grenze');
        $this->EnableAction('GrenzeAktiv');
        $this->EnableAction('OhpcfLauf');

        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->SetTimerInterval('Anmelden', 60000);
        $this->SetTimerInterval('Energie', self::ENERGIE_TAKT_MS);
        $this->reboostTimer();
        $this->Anmelden();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        if (($Message === FM_CONNECT || $Message === FM_DISCONNECT) && IPS_GetKernelRunlevel() === KR_READY) {
            if ($Message === FM_DISCONNECT) {
                $this->geraetVergessen();
            }
            $this->Anmelden();
        }
    }

    // ------------------------------------------------------------------
    // Gateway
    // ------------------------------------------------------------------

    private function gatewayID(): int
    {
        $p = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($p <= 0 || !IPS_InstanceExists($p)) {
            return 0;
        }
        return (string)(IPS_GetInstance($p)['ModuleInfo']['ModuleID'] ?? '') === self::GATEWAY_MODULE ? $p : 0;
    }

    /** Anfrage an das Gateway (Kind-Schnittstelle 1.1); Antwort als Array, [] ohne Gateway. */
    private function gateway(array $anfrage): array
    {
        if ($this->gatewayID() === 0) {
            return [];
        }
        try {
            $antwort = $this->SendDataToParent((string)json_encode(['DataID' => self::DATA_ZUM_GATEWAY, 'Sender' => $this->InstanceID] + $anfrage));
        } catch (Throwable $e) {
            $this->SendDebug('Gateway', $anfrage['Cmd'] . ': ' . $e->getMessage(), 0);
            return [];
        }
        return json_decode((string)$antwort, true) ?: [];
    }

    /** Profil für den Zustand des optionalen Laufs; Namen in der Sprache, die Symcon beim Anlegen hat (wie WPModbusHub). */
    private function profileSicherstellen(): void
    {
        if (IPS_VariableProfileExists('WPEEB.OhpcfState')) {
            return;
        }
        IPS_CreateVariableProfile('WPEEB.OhpcfState', VARIABLETYPE_INTEGER);
        foreach ([0 => self::fest('not offered'), 1 => self::fest('offered'), 2 => self::fest('scheduled'), 3 => self::fest('running'),
            4 => self::fest('paused'), 5 => self::fest('completed')] as $wert => $text) {
            IPS_SetVariableProfileAssociation('WPEEB.OhpcfState', $wert, $this->t($text), '', -1);
        }
    }

    /** Anmeldung beim Gateway: Energy Guard (LPC) und Monitoring Appliance (MPC) mit den nötigen Client-Features. */
    private static function anmeldung(): array
    {
        $client = function (string $typ): array {
            return ['type' => $typ, 'role' => 'client', 'functions' => []];
        };
        return [
            'type' => 'CEM',
            'useCases' => [
                ['actor' => 'EnergyGuard', 'name' => 'limitationOfPowerConsumption', 'version' => '1.0.0', 'subRevision' => 'release', 'scenarios' => [1, 2, 3, 4]],
                ['actor' => 'MonitoringAppliance', 'name' => 'monitoringOfPowerConsumption', 'version' => '1.0.0', 'subRevision' => 'release', 'scenarios' => [1, 2, 3, 4, 5]],
                ['actor' => 'CEM', 'name' => self::OHPCF_NAME, 'version' => '1.0.0', 'subRevision' => 'release', 'scenarios' => [1, 2]],
                ['actor' => 'MonitoringAppliance', 'name' => self::MDT_NAME, 'version' => '1.0.0', 'subRevision' => 'release', 'scenarios' => [1]],
            ],
            'features' => [$client('LoadControl'), $client('DeviceConfiguration'), $client('ElectricalConnection'), $client('Measurement'), $client('SmartEnergyManagementPs')],
            'remote' => [
                // Der Warmwasserkreis (MDT) ist keine Wärmepumpen-Entität, wird aber mit abonniert.
                'entityTypes' => array_merge(self::WP_ENTITAETEN, ['DHWCircuit']),
                'features' => [
                    ['type' => 'LoadControl', 'subscribe' => true, 'bind' => true, 'read' => ['loadControlLimitDescriptionListData', 'loadControlLimitListData']],
                    ['type' => 'DeviceConfiguration', 'subscribe' => true, 'bind' => true, 'read' => ['deviceConfigurationKeyValueDescriptionListData', 'deviceConfigurationKeyValueListData']],
                    ['type' => 'ElectricalConnection', 'subscribe' => true, 'read' => ['electricalConnectionDescriptionListData', 'electricalConnectionParameterDescriptionListData', 'electricalConnectionCharacteristicListData']],
                    ['type' => 'Measurement', 'subscribe' => true, 'read' => ['measurementDescriptionListData', 'measurementListData']],
                    ['type' => 'SmartEnergyManagementPs', 'subscribe' => true, 'bind' => true, 'read' => ['smartEnergyManagementPsData']],
                ],
            ],
        ];
    }

    /** WPEEB_Anmelden($id) — beim Gateway anmelden (auch per Timer, gleiche Anmeldung ändert dort nichts). */
    public function Anmelden(): void
    {
        if ($this->gatewayID() === 0) {
            $this->SetStatus(104);
            $this->wertSetzen('Erreichbar', false);
            return;
        }
        $r = $this->gateway(['Cmd' => 'Register', 'Entity' => self::anmeldung()]);
        if (empty($r['ok'])) {
            $this->SendDebug('Gateway', 'Register: ' . json_encode($r), 0);
            $this->SetStatus(201);
            return;
        }
        if (version_compare((string)($r['interface'] ?? '1.0'), '1.1', '<')) {
            // Gateway zu alt: Rolle Energiemanagement erst ab Kind-Schnittstelle 1.1.
            $this->SetStatus(202);
            return;
        }
        $this->statusAktualisieren();
    }

    private function statusAktualisieren(): void
    {
        $g = $this->geraet();
        $this->SetStatus(!empty($g['entity']) ? IS_ACTIVE : 203);
    }

    // ------------------------------------------------------------------
    // Ereignisse des Gateways
    // ------------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $e = json_decode((string)$JSONString, true);
        if (!is_array($e) || !in_array((int)($e['Target'] ?? 0), [0, $this->InstanceID], true)) {
            return '';
        }
        switch ((string)($e['Event'] ?? '')) {
            case 'connection':
                if (($e['state'] ?? '') !== 'connected') {
                    $this->geraetVergessen();
                }
                break;
            case 'remoteEntity':
                if (($e['state'] ?? '') === 'added') {
                    $this->entitaetPruefen($e);
                } elseif ((array)($e['entity'] ?? []) == (array)($this->geraet()['entity'] ?? [])) {
                    $this->geraetVergessen();
                } elseif ((array)($e['entity'] ?? []) == (array)($this->geraet()['dhwEntity'] ?? [-1])) {
                    $g = $this->geraet();
                    unset($g['dhwEntity'], $g['dhwMessung']);
                    $this->WriteAttributeString('Geraet', (string)json_encode($g));
                } elseif ((array)($e['entity'] ?? []) == (array)($this->geraet()['ohpcfEntity'] ?? [])) {
                    $g = $this->geraet();
                    unset($g['ohpcfEntity'], $g['ohpcf']);
                    $this->WriteAttributeString('Geraet', (string)json_encode($g));
                    $this->wertSetzen('OhpcfZustand', 0);
                }
                $this->statusAktualisieren();
                break;
            case 'data':
                $this->datenAuswerten((string)($e['function'] ?? ''), (array)($e['data'] ?? []), isset($e['entity']) ? (array)$e['entity'] : null);
                $this->WriteAttributeInteger('LastSeenAt', time());
                break;
            case 'result':
                $this->WriteAttributeString('LetztesErgebnis', (string)json_encode(['zeit' => time(), 'function' => $e['function'] ?? '',
                    'errorNumber' => (int)($e['errorNumber'] ?? 0), 'description' => (string)($e['description'] ?? '')]));
                if ((int)($e['errorNumber'] ?? 0) !== 0) {
                    $this->LogMessage($this->t('The heat pump rejected the limit: error %s %s', (string)$e['errorNumber'], (string)($e['description'] ?? '')), KL_WARNING);
                } elseif (($e['function'] ?? '') === 'loadControlLimitListData' && $this->ReadAttributeString('GrenzeBestaetigung') !== '{}') {
                    // Grenze angenommen: Wiederholung beenden.
                    $this->WriteAttributeString('GrenzeBestaetigung', '{}');
                    $this->SetTimerInterval('GrenzeBestaetigen', 0);
                }
                break;
            case 'heartbeat':
                $this->geraetAendern(['heartbeatVerloren' => ($e['state'] ?? '') === 'lost']);
                break;
        }
        return '';
    }

    /** Angebotene Entität übernehmen, wenn sie passt und keine mit höherem Vorrang schon gewählt ist. */
    private function entitaetPruefen(array $e): void
    {
        $typen = array_column((array)($e['features'] ?? []), 'type');
        $typ = (string)($e['type'] ?? '');
        if ($typ === 'DHWCircuit') {
            if (in_array('Measurement', $typen, true)) {
                $this->geraetAendern(['dhwEntity' => (array)$e['entity']]);
            }
            return;
        }
        if (!in_array($typ, self::WP_ENTITAETEN, true)) {
            return;
        }
        if (in_array('SmartEnergyManagementPs', $typen, true)) {
            // OHPCF sitzt meist auf einer eigenen Verdichter-Entität (eebus-go: nur Compressor), unabhängig
            // von der Entität mit Grenze und Messwerten.
            $this->geraetAendern(['ohpcfEntity' => (array)$e['entity']]);
            $this->wertSetzen('Erreichbar', true);
        }
        if (!in_array('LoadControl', $typen, true) && !in_array('Measurement', $typen, true)) {
            return;
        }
        $g = $this->geraet();
        if (!empty($g['entity']) && $g['entity'] != (array)$e['entity']
            && array_search($g['type'] ?? '', self::WP_ENTITAETEN, true) <= array_search($typ, self::WP_ENTITAETEN, true)) {
            return;
        }
        // Neue Entität: Kennungen der alten gelten nicht mehr (die OHPCF-Entität bleibt).
        $alt = $this->geraet();
        $neu = ['entity' => (array)$e['entity'], 'type' => $typ, 'seit' => time()];
        foreach (['ohpcfEntity', 'ohpcf'] as $k) {
            if (isset($alt[$k])) {
                $neu[$k] = $alt[$k];
            }
        }
        $this->WriteAttributeString('Geraet', (string)json_encode($neu));
        $this->wertSetzen('Erreichbar', true);
        $this->LogMessage($this->t('Heat pump found (%s, entity %s).', $typ, (string)json_encode($e['entity'])), KL_MESSAGE);
    }

    private function geraet(): array
    {
        return json_decode($this->ReadAttributeString('Geraet'), true) ?: [];
    }

    private function geraetAendern(array $werte): void
    {
        $neu = array_merge($this->geraet(), $werte);
        $this->WriteAttributeString('Geraet', (string)json_encode($neu));
    }

    private function geraetVergessen(): void
    {
        // Bis zur Trennung noch hochrechnen, danach keine Lücke überbrücken.
        $this->energieSchritt(null, true);
        if ($this->geraet() !== []) {
            $this->WriteAttributeString('Geraet', '{}');
        }
        $this->wertSetzen('Erreichbar', false);
        $this->wertSetzen('OhpcfZustand', 0);
        $this->statusAktualisieren();
    }

    /** ISO-8601-Dauer (PnDTnHnMnS) in Sekunden, 0 bei unbekanntem Format. */
    private static function dauerSekunden(string $iso): int
    {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $iso, $m)) {
            return 0;
        }
        return (int)((int)($m[1] ?? 0) * 86400 + (int)($m[2] ?? 0) * 3600 + (int)($m[3] ?? 0) * 60 + (float)($m[4] ?? 0));
    }

    /** Skalierte SPINE-Zahl {number, scale} als float. */
    private static function zahl($v): ?float
    {
        if (!is_array($v) || !isset($v['number'])) {
            return null;
        }
        return (float)$v['number'] * (10 ** (int)($v['scale'] ?? 0));
    }

    private function datenAuswerten(string $fn, array $d, ?array $ent = null): void
    {
        $g = $this->geraet();
        if ($ent !== null && !empty($g['dhwEntity']) && $ent == $g['dhwEntity']) {
            $this->warmwasserAuswerten($fn, $d);
            return;
        }
        switch ($fn) {
            case 'smartEnergyManagementPsData':
                $this->ohpcfAuswerten($d);
                break;
            case 'loadControlLimitDescriptionListData':
                // Grenze für den Bezug: Richtung consume, Wirkleistungsgrenze (wie eebus-go eg/lpc).
                foreach ((array)($d['loadControlLimitDescriptionData'] ?? []) as $l) {
                    if (($l['limitDirection'] ?? '') === 'consume' && ($l['scopeType'] ?? '') === 'activePowerLimit') {
                        $neu = !isset($g['limitId']) || (int)$g['limitId'] !== (int)$l['limitId'];
                        $this->geraetAendern(['limitId' => (int)$l['limitId']]);
                        $this->grenzeAusStand();
                        if ($neu) {
                            $this->grenzeBestaetigungStarten();
                        }
                    }
                }
                break;
            case 'loadControlLimitListData':
                $this->grenzeAusStand($d);
                break;
            case 'deviceConfigurationKeyValueDescriptionListData':
                $keys = [];
                foreach ((array)($d['deviceConfigurationKeyValueDescriptionData'] ?? []) as $k) {
                    $keys[(string)($k['keyName'] ?? '')] = (int)($k['keyId'] ?? -1);
                }
                $this->geraetAendern(['keys' => $keys]);
                break;
            case 'deviceConfigurationKeyValueListData':
                $id = (int)($g['keys']['failsafeConsumptionActivePowerLimit'] ?? -1);
                foreach ((array)($d['deviceConfigurationKeyValueData'] ?? []) as $k) {
                    if ((int)($k['keyId'] ?? -2) === $id && ($w = self::zahl($k['value']['scaledNumber'] ?? null)) !== null) {
                        $this->wertSetzen('Ausfallgrenze', $w);
                    }
                }
                break;
            case 'electricalConnectionCharacteristicListData':
                foreach ((array)($d['electricalConnectionCharacteristicData'] ?? []) as $c) {
                    if (stripos((string)($c['characteristicType'] ?? ''), 'ConsumptionNominalMax') !== false && ($w = self::zahl($c['value'] ?? null)) !== null) {
                        $this->wertSetzen('Nennleistung', $w);
                    }
                }
                break;
            case 'measurementDescriptionListData':
                // Gesamtleistung und bezogene Energie über scopeType erkennen (eebus-go ma/mpc).
                $m = [];
                foreach ((array)($d['measurementDescriptionData'] ?? []) as $md) {
                    $scope = (string)($md['scopeType'] ?? '');
                    if ($scope === 'acPowerTotal' || $scope === 'acEnergyConsumed') {
                        $m[$scope] = ['id' => (int)$md['measurementId'], 'unit' => (string)($md['unit'] ?? '')];
                    }
                }
                // Ohne beschriebenen Zählerstand gilt auch ein früher gemeldeter nicht mehr.
                $this->geraetAendern(['messung' => $m] + (isset($m['acEnergyConsumed']) ? [] : ['energieGemeldet' => false]));
                break;
            case 'measurementListData':
                $m = (array)($g['messung'] ?? []);
                foreach ((array)($d['measurementData'] ?? []) as $md) {
                    $w = self::zahl($md['value'] ?? null);
                    if ($w === null) {
                        continue;
                    }
                    $id = (int)($md['measurementId'] ?? -1);
                    if ($id === (int)($m['acPowerTotal']['id'] ?? -2)) {
                        $this->energieSchritt($w);
                        $this->wertSetzen('Leistung', $w);
                    } elseif ($id === (int)($m['acEnergyConsumed']['id'] ?? -2)) {
                        $einheit = strtolower((string)($m['acEnergyConsumed']['unit'] ?? 'Wh'));
                        $this->wertSetzen('Energie', $einheit === 'kwh' ? $w : $w / 1000);
                        if (empty($g['energieGemeldet'])) {
                            $this->geraetAendern(['energieGemeldet' => true]);
                        }
                    }
                }
                break;
        }
    }

    /** Grenze aus dem gemeldeten Stand übernehmen (ohne Daten: zuletzt bekannten beim Gateway abfragen). */
    private function grenzeAusStand(?array $d = null): void
    {
        $g = $this->geraet();
        if (!isset($g['limitId']) || empty($g['entity'])) {
            return;
        }
        if ($d === null) {
            $r = $this->gateway(['Cmd' => 'GetRemoteData', 'Entity' => $g['entity'], 'Feature' => 'LoadControl', 'Function' => 'loadControlLimitListData']);
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
        }
        foreach ((array)($d['loadControlLimitData'] ?? []) as $l) {
            if ((int)($l['limitId'] ?? -1) !== (int)$g['limitId']) {
                continue;
            }
            if (($w = self::zahl($l['value'] ?? null)) !== null) {
                $this->wertSetzen('Grenze', $w);
            }
            $this->wertSetzen('GrenzeAktiv', !empty($l['isLimitActive']));
            // Ende einer befristeten Grenze (endTime ist eine Restdauer wie PT3600S oder ein Zeitpunkt), 0 = ohne Ende.
            $ende = (string)($l['timePeriod']['endTime'] ?? '');
            $bis = 0;
            if (!empty($l['isLimitActive']) && $ende !== '') {
                $sek = $ende[0] === 'P' ? self::dauerSekunden($ende) : 0;
                $bis = $ende[0] === 'P' ? ($sek > 0 ? time() + $sek : 0) : (int)strtotime($ende);
            }
            $this->wertSetzen('GrenzeBis', $bis);
            $this->geraetAendern(['aenderbar' => (bool)($l['isLimitChangeable'] ?? true),
                'ende' => (string)($l['timePeriod']['endTime'] ?? '')]);
        }
    }

    private function wertSetzen(string $ident, $wert): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false && GetValue($id) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    /** Gemeinsames NRG-Profil anlegen, falls es noch fehlt (idempotent wie MeterHub ensureSharedProfile). */
    private function nrgProfil(string $name, string $suffix, int $stellen): void
    {
        if (IPS_VariableProfileExists($name)) {
            return;
        }
        IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileDigits($name, $stellen);
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileIcon($name, 'Electricity');
    }

    // ------------------------------------------------------------------
    // Hochgerechneter Zählerstand
    // ------------------------------------------------------------------

    /** Liefert die Wärmepumpe selbst einen Zählerstand (MPC acEnergyConsumed)? */
    /**
     * Liefert die Wärmepumpe selbst einen Zählerstand? Erst, wenn tatsächlich ein Wert kam: Die Vaillant VR940f
     * beschreibt acEnergyConsumed, schickt aber nie einen Wert (Forum 144877 #14).
     */
    private function energieVomGeraet(): bool
    {
        return !empty($this->geraet()['energieGemeldet']);
    }

    /** Timer: Zählerstand auch ohne neue Leistungsmeldung fortschreiben (EEBUS meldet nur Änderungen). */
    public function EnergieTakt(): void
    {
        $this->energieSchritt(null);
    }

    /**
     * Zählerstand aus der Leistung hochrechnen (Treppenkurve: der letzte Wert gilt bis zur nächsten Meldung),
     * nur solange die Wärmepumpe erreichbar ist und selbst keinen Zählerstand liefert. Lücken über
     * ENERGIE_MAX_LUECKE_S (Symcon-Neustart, Verbindungsabbruch) werden nicht überbrückt - lieber etwas zu
     * wenig als erfundener Verbrauch (wie MeterHub CalcEnergyStep).
     * $neu: neue Leistung in W (null = unverändert), $ende: danach nicht weiterrechnen.
     */
    private function energieSchritt(?float $neu, bool $ende = false): void
    {
        $st = json_decode($this->ReadAttributeString('EnergieRechnung'), true) ?: [];
        $jetzt = time();
        $aktiv = !$this->energieVomGeraet() && (bool)@$this->GetValue('Erreichbar');
        [$kwh, $st] = self::energieRechnen($st, $jetzt, $neu, $aktiv && !$ende ? true : false, $aktiv);
        if ($kwh > 0) {
            $this->SetValue('EnergieHochgerechnet', (float)$this->GetValue('EnergieHochgerechnet') + $kwh);
        }
        $this->WriteAttributeString('EnergieRechnung', (string)json_encode($st));
    }

    /**
     * Ein Rechenschritt, rein (für den Prüfstand): [kWh zum Aufaddieren, neuer Stand].
     * $weiter: danach weiterrechnen; $zaehlen: den abgelaufenen Abschnitt gutschreiben.
     */
    public static function energieRechnen(array $st, int $jetzt, ?float $neu, bool $weiter, bool $zaehlen): array
    {
        $kwh = 0.0;
        if ($zaehlen && isset($st['t'], $st['p'])) {
            $dt = $jetzt - (int)$st['t'];
            if ($dt > 0 && $dt <= self::ENERGIE_MAX_LUECKE_S) {
                $kwh = max(0.0, (float)$st['p']) * $dt / 3600000.0;
            }
        }
        if (!$weiter) {
            return [$kwh, []];
        }
        $p = $neu ?? (isset($st['p']) ? (float)$st['p'] : null);
        if ($p === null || !is_finite($p) || $p > self::ENERGIE_MAX_W) {
            // Ohne gültige Leistung (oder bei einem unplausiblen Wert) nicht weiterrechnen.
            return [$kwh, $neu === null ? [] : $st + ['t' => $jetzt]];
        }
        return [$kwh, ['t' => $jetzt, 'p' => max(0.0, $p)]];
    }

    // ------------------------------------------------------------------
    // Archivierung (nur auf Klick, Store-Checkliste 19, wie MeterHubVirtual)
    // ------------------------------------------------------------------

    private function archivID(): int
    {
        if (!function_exists('AC_GetLoggingStatus')) {
            return 0;
        }
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIV_MODUL);
        return (int)($ids[0] ?? 0);
    }

    /** Variablen, die für Verlauf, WP-Monitor und Energiefluss archiviert sein sollten: [vid => Zählerstand?]. */
    private function archivFehlt(): array
    {
        $ac = $this->archivID();
        if ($ac <= 0) {
            return [];
        }
        $fehlt = [];
        foreach (['Leistung' => false, $this->energieIdent() => true] as $ident => $zaehler) {
            $vid = (int)@$this->GetIDForIdent($ident);
            if ($vid > 0 && !@AC_GetLoggingStatus($ac, $vid)) {
                $fehlt[$vid] = $zaehler;
            }
        }
        return $fehlt;
    }

    /** WPEEB_ArchivierungEinschalten($id): string — Archivierung für Leistung und Zählerstand einschalten (Knopf). */
    public function ArchivierungEinschalten(): string
    {
        $ac = $this->archivID();
        if ($ac <= 0) {
            return '⛔ ' . $this->t('No archive instance found.');
        }
        $n = 0;
        foreach ($this->archivFehlt() as $vid => $zaehler) {
            AC_SetLoggingStatus($ac, $vid, true);
            // Zählerstand als Zähler aggregieren (Verbrauch je Zeitraum), Leistung als Standard.
            AC_SetAggregationType($ac, $vid, $zaehler ? 1 : 0);
            $n++;
        }
        if ($n > 0) {
            IPS_ApplyChanges($ac);
        }
        $this->UpdateFormField('ArchivPanel', 'visible', false);
        return $n === 0 ? 'ℹ️ ' . $this->t('Nothing was missing - all variables are already archived.')
            : '✅ ' . $this->t('Archiving enabled for %s variable(s).', (string)$n);
    }

    /** Ident der Energie-Variablen, die der Vertrag meldet: Zählerstand der Wärmepumpe oder hochgerechnet. */
    private function energieIdent(): string
    {
        return $this->energieVomGeraet() ? 'Energie' : 'EnergieHochgerechnet';
    }

    // ------------------------------------------------------------------
    // Grenze setzen
    // ------------------------------------------------------------------

    /**
     * WPEEB_SetPowerLimit($id, $Watt, $DurationSeconds): bool — Leistungsgrenze für den Bezug setzen und
     * aktivieren (LPC). $DurationSeconds 0 = ohne Ende. true heißt: an die Wärmepumpe geschickt; ob sie
     * angenommen wurde, steht danach im Formular bzw. im Meldungsprotokoll.
     */
    public function SetPowerLimit(float $Watt, int $DurationSeconds): bool
    {
        return $this->grenzeSchreiben(max(0.0, $Watt), true, max(0, $DurationSeconds));
    }

    /** WPEEB_ClearPowerLimit($id): bool — Leistungsgrenze aufheben (bleibt gespeichert, ist aber nicht aktiv). */
    public function ClearPowerLimit(): bool
    {
        $g = $this->geraet();
        $wert = @$this->GetValue('Grenze');
        return $this->grenzeSchreiben(is_numeric($wert) ? (float)$wert : 0.0, false, 0, $g);
    }

    private function grenzeSchreiben(float $watt, bool $aktiv, int $dauer, ?array $g = null, bool $merken = true): bool
    {
        if ($merken) {
            // Wunsch merken, damit er einer neu erschienenen Grenze erneut gesagt werden kann ([LPC-913]).
            $this->WriteAttributeString('GrenzeWunsch', (string)json_encode(['watt' => $watt, 'aktiv' => $aktiv, 'bis' => $aktiv && $dauer > 0 ? time() + $dauer : 0]));
        }
        $g = $g ?? $this->geraet();
        if (empty($g['entity']) || !isset($g['limitId'])) {
            if ($merken) {
                $this->LogMessage($this->t('Power limit not sent: the heat pump is not connected or offers no power limit (LPC).'), KL_WARNING);
            }
            return false;
        }
        if (isset($g['aenderbar']) && $g['aenderbar'] === false) {
            if ($merken) {
                $this->LogMessage($this->t('Power limit not sent: the heat pump currently does not allow changing it.'), KL_WARNING);
            }
            return false;
        }
        $eintrag = ['limitId' => (int)$g['limitId'], 'isLimitActive' => $aktiv, 'value' => ['number' => (int)round($watt), 'scale' => 0]];
        if ($aktiv && $dauer > 0) {
            $eintrag['timePeriod'] = ['endTime' => 'PT' . $dauer . 'S'];
        }
        $r = $this->gateway(['Cmd' => 'Write', 'Entity' => $g['entity'], 'Feature' => 'LoadControl', 'Function' => 'loadControlLimitListData',
            'Data' => ['loadControlLimitData' => [$eintrag]]]);
        if (empty($r['ok'])) {
            if ($merken) {
                $this->LogMessage($this->t('Power limit not sent: %s', (string)($r['error'] ?? $this->t('no answer from the gateway'))), KL_WARNING);
            }
            return false;
        }
        $this->SendDebug('LPC', 'write ' . json_encode($eintrag) . ' msgCounter ' . ($r['msgCounter'] ?? '?'), 0);
        return true;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Grenze':
                $this->SetPowerLimit((float)$Value, 0);
                break;
            case 'OhpcfLauf':
                $this->SetOptionalRun((bool)$Value);
                break;
            case 'GrenzeAktiv':
                if ($Value) {
                    $w = @$this->GetValue('Grenze');
                    $this->SetPowerLimit(is_numeric($w) ? (float)$w : 0.0, 0);
                } else {
                    $this->ClearPowerLimit();
                }
                break;
            default:
                throw new Exception('Invalid ident ' . $Ident);
        }
        // Der Variablenwert folgt erst der Bestätigung der Wärmepumpe (notify), nicht dem Wunsch.
    }

    /** Aktionsbereich: Grenze aus dem Formular testen. */
    public function GrenzeTesten(float $Watt, int $Minuten): string
    {
        return $this->SetPowerLimit($Watt, $Minuten * 60)
            ? '✅ ' . $this->t('Limit of %s W sent to the heat pump. Its answer appears in “Power limit” in a few seconds.', (string)round($Watt))
            : '⛔ ' . $this->t('The limit could not be sent. Details are in the message log.');
    }

    public function GrenzeAufheben(): string
    {
        return $this->ClearPowerLimit()
            ? '✅ ' . $this->t('Limit lifted. The heat pump confirms it in a few seconds.')
            : '⛔ ' . $this->t('The limit could not be sent. Details are in the message log.');
    }

    // ------------------------------------------------------------------
    // OHPCF: optionaler Verdichterlauf (PV-Überschuss)
    // ------------------------------------------------------------------

    /**
     * Angebot der Wärmepumpe auswerten: genau eine Alternative mit genau einer Leistungsfolge (wie eebus-go
     * cem/ohpcf isDataAvailable), Leistung je Werttyp power/powerMax, Zustand, Unterbrechbarkeit, Mindestzeiten.
     */
    private function ohpcfAuswerten(array $d): void
    {
        $alt = (array)($d['alternatives'] ?? []);
        $seq = count($alt) === 1 && count((array)($alt[0]['powerSequence'] ?? [])) === 1 ? $alt[0]['powerSequence'][0] : null;
        if (!is_array($seq) || !isset($seq['description']['sequenceId'])) {
            $this->geraetAendern(['ohpcf' => null]);
            $this->wertSetzen('OhpcfZustand', 0);
            return;
        }
        $o = [
            'sequenceId' => (int)$seq['description']['sequenceId'],
            'state' => (string)($seq['state']['state'] ?? ''),
            'pausierbar' => (bool)($seq['operatingConstraintsInterrupt']['isPausable'] ?? false),
            'abbrechbar' => (bool)($seq['operatingConstraintsInterrupt']['isStoppable'] ?? false),
            'laufMin' => (string)($seq['operatingConstraintsDuration']['activeDurationMin'] ?? ''),
            'pauseMin' => (string)($seq['operatingConstraintsDuration']['pauseDurationMin'] ?? ''),
            'start' => (string)($seq['schedule']['startTime'] ?? ''),
        ];
        foreach ((array)($seq['powerTimeSlot'][0]['valueList']['value'] ?? []) as $v) {
            $w = self::zahl($v['value'] ?? null);
            if ($w !== null && ($v['valueType'] ?? '') === 'power') {
                $this->wertSetzen('OhpcfLeistung', $w);
            } elseif ($w !== null && ($v['valueType'] ?? '') === 'powerMax') {
                $this->wertSetzen('OhpcfLeistungMax', $w);
            }
        }
        $this->geraetAendern(['ohpcf' => $o]);
        $this->wertSetzen('OhpcfZustand', self::OHPCF_ZUSTAENDE[$o['state']] ?? 0);
        $this->wertSetzen('OhpcfLauf', in_array($o['state'], ['running', 'scheduled'], true));
        // evcc #31549: auf ein frisch angebotenes Einplanen/Fortsetzen sofort reagieren, nicht erst beim nächsten Reboost.
        if ($this->ReadAttributeBoolean('OhpcfWunsch')) {
            $this->ohpcfAngleichen(true);
        }
    }

    /** Schreibbefehl an die Leistungsfolge (Startzeit oder Zustand), wie eebus-go cem/ohpcf. */
    private function ohpcfSchreiben(array $teil, string $aktion): bool
    {
        $g = $this->geraet();
        $o = $g['ohpcf'] ?? null;
        if (empty($g['ohpcfEntity']) || !is_array($o)) {
            $this->LogMessage($this->t('Optional run not possible: the heat pump currently offers no optional compressor run (OHPCF).'), KL_WARNING);
            return false;
        }
        $seq = ['description' => ['sequenceId' => $o['sequenceId']]] + $teil;
        $r = $this->gateway(['Cmd' => 'Write', 'Entity' => $g['ohpcfEntity'], 'Feature' => 'SmartEnergyManagementPs', 'Function' => 'smartEnergyManagementPsData',
            'Data' => ['alternatives' => [['powerSequence' => [$seq]]]]]);
        if (empty($r['ok'])) {
            $this->LogMessage($this->t('Optional run not sent: %s', (string)($r['error'] ?? $this->t('no answer from the gateway'))), KL_WARNING);
            return false;
        }
        $this->SendDebug('OHPCF', $aktion . ' msgCounter ' . ($r['msgCounter'] ?? '?'), 0);
        return true;
    }

    /** WPEEB_StartOptionalRun($id, $DelaySeconds): bool — angebotenen Lauf einplanen (0 = sofort). */
    public function StartOptionalRun(int $DelaySeconds): bool
    {
        $o = $this->geraet()['ohpcf'] ?? null;
        if (is_array($o) && !in_array($o['state'], ['inactive', 'scheduled'], true)) {
            $this->LogMessage($this->t('Optional run not started: it is currently %s.', $this->t(self::zustandText($o['state']))), KL_WARNING);
            return false;
        }
        return $this->ohpcfSchreiben(['schedule' => ['startTime' => 'PT' . max(0, $DelaySeconds) . 'S']], 'schedule');
    }

    /** WPEEB_PauseOptionalRun($id): bool — laufenden Lauf pausieren (nur wenn die Wärmepumpe das erlaubt). */
    public function PauseOptionalRun(): bool
    {
        $o = $this->geraet()['ohpcf'] ?? null;
        if (is_array($o) && !$o['pausierbar']) {
            $this->LogMessage($this->t('The heat pump does not allow pausing the optional run.'), KL_WARNING);
            return false;
        }
        return $this->ohpcfSchreiben(['state' => ['state' => 'paused']], 'pause');
    }

    /** WPEEB_ResumeOptionalRun($id): bool — pausierten Lauf fortsetzen. */
    public function ResumeOptionalRun(): bool
    {
        return $this->ohpcfSchreiben(['state' => ['state' => 'running']], 'resume');
    }

    /** WPEEB_AbortOptionalRun($id): bool — Lauf abbrechen (nur wenn die Wärmepumpe das erlaubt). */
    public function AbortOptionalRun(): bool
    {
        $o = $this->geraet()['ohpcf'] ?? null;
        if (is_array($o) && !$o['abbrechbar']) {
            $this->LogMessage($this->t('The heat pump does not allow aborting the optional run.'), KL_WARNING);
            return false;
        }
        return $this->ohpcfSchreiben(['state' => ['state' => 'invalid']], 'abort');
    }

    /**
     * WPEEB_SetOptionalRun($id, $Run): bool — Ein/Aus wie evcc: an = angebotenen Lauf sofort starten bzw.
     * pausierten fortsetzen; aus = laufenden pausieren, sonst abbrechen.
     */
    public function SetOptionalRun(bool $Run): bool
    {
        // Ein/Aus wie evcc: der Wunsch bleibt bestehen; bei „Ein“ wird jeder neu angebotene Lauf eingeplant.
        $this->WriteAttributeBoolean('OhpcfWunsch', $Run);
        $this->reboostTimer();
        return $this->ohpcfAngleichen($Run);
    }

    /**
     * Lauf an den Wunsch angleichen, nur bei einem Zustandswechsel (evcc ohpcfControlAction): angeboten → einplanen,
     * pausiert → fortsetzen; aus und läuft/eingeplant → pausieren, wenn erlaubt, sonst abbrechen, wenn erlaubt.
     */
    private function ohpcfAngleichen(bool $Run): bool
    {
        $o = $this->geraet()['ohpcf'] ?? null;
        $state = is_array($o) ? $o['state'] : '';
        if ($Run) {
            if (in_array($state, ['paused', 'scheduledPaused'], true)) {
                return $this->ResumeOptionalRun();
            }
            if ($state === 'inactive') {
                return $this->StartOptionalRun(0);
            }
            // Läuft schon, ist eingeplant oder gerade nicht angeboten: nichts zu tun.
            return in_array($state, ['running', 'scheduled'], true);
        }
        if (!in_array($state, ['running', 'scheduled'], true)) {
            return true;
        }
        if (is_array($o) && $o['pausierbar']) {
            return $this->PauseOptionalRun();
        }
        if (is_array($o) && $o['abbrechbar']) {
            return $this->AbortOptionalRun();
        }
        $this->LogMessage($this->t('Optional run cannot be stopped: the heat pump allows neither pausing nor aborting it.'), KL_WARNING);
        return false;
    }

    /** Reboost-Timer nur, solange der optionale Lauf gewünscht ist. */
    private function reboostTimer(): void
    {
        $min = max(0, $this->ReadPropertyInteger('ReboostMinuten'));
        $this->SetTimerInterval('Reboost', $min > 0 && $this->ReadAttributeBoolean('OhpcfWunsch') ? $min * 60000 : 0);
    }

    /** Timer: einen neu angebotenen Lauf erneut einplanen (evcc reboost). */
    public function ReboostTakt(): void
    {
        if ($this->ReadAttributeBoolean('OhpcfWunsch')) {
            $this->ohpcfAngleichen(true);
        } else {
            $this->SetTimerInterval('Reboost', 0);
        }
    }

    // ------------------------------------------------------------------
    // Grenze nach dem Erscheinen bestätigen ([LPC-913], evcc AssertLimit)
    // ------------------------------------------------------------------

    /** Gewünschte Grenze an eine neu erschienene Grenze senden und bis zur Annahme wiederholen. */
    private function grenzeBestaetigungStarten(): void
    {
        $w = json_decode($this->ReadAttributeString('GrenzeWunsch'), true) ?: [];
        if (!isset($w['watt']) || (!empty($w['bis']) && (int)$w['bis'] <= time())) {
            return;
        }
        $this->WriteAttributeString('GrenzeBestaetigung', (string)json_encode(['seit' => time(), 'warte' => 1]));
        $this->GrenzeBestaetigen();
    }

    /** Timer: gewünschte Grenze erneut senden, Wartezeit verdoppeln, nach 50 s aufgeben. */
    public function GrenzeBestaetigen(): void
    {
        $b = json_decode($this->ReadAttributeString('GrenzeBestaetigung'), true) ?: [];
        $w = json_decode($this->ReadAttributeString('GrenzeWunsch'), true) ?: [];
        if (!isset($b['seit'], $w['watt']) || time() - (int)$b['seit'] > self::GRENZE_BESTAETIGEN_MAX_S) {
            if (isset($b['seit'])) {
                $this->LogMessage($this->t('The heat pump did not confirm the power limit within %s s.', (string)self::GRENZE_BESTAETIGEN_MAX_S), KL_WARNING);
            }
            $this->WriteAttributeString('GrenzeBestaetigung', '{}');
            $this->SetTimerInterval('GrenzeBestaetigen', 0);
            return;
        }
        $rest = empty($w['bis']) ? 0 : max(1, (int)$w['bis'] - time());
        $this->grenzeSchreiben((float)$w['watt'], (bool)$w['aktiv'], $rest, null, false);
        $warte = (int)$b['warte'];
        $this->WriteAttributeString('GrenzeBestaetigung', (string)json_encode(['seit' => (int)$b['seit'], 'warte' => min(16, $warte * 2)]));
        $this->SetTimerInterval('GrenzeBestaetigen', $warte * 1000);
    }

    // ------------------------------------------------------------------
    // Warmwassertemperatur (MDT)
    // ------------------------------------------------------------------

    private function warmwasserAuswerten(string $fn, array $d): void
    {
        if ($fn === 'measurementDescriptionListData') {
            foreach ((array)($d['measurementDescriptionData'] ?? []) as $md) {
                if (($md['scopeType'] ?? '') === 'dhwTemperature') {
                    $this->geraetAendern(['dhwMessung' => (int)$md['measurementId']]);
                }
            }
            return;
        }
        if ($fn !== 'measurementListData') {
            return;
        }
        $id = $this->geraet()['dhwMessung'] ?? null;
        foreach ((array)($d['measurementData'] ?? []) as $md) {
            if ($id !== null && (int)($md['measurementId'] ?? -1) === (int)$id && ($w = self::zahl($md['value'] ?? null)) !== null) {
                if (@$this->GetIDForIdent('Warmwasser') === false) {
                    // Erst anlegen, wenn die Wärmepumpe die Temperatur wirklich liefert (nur wenige tun das).
                    $this->nrgProfil('NRG.Celsius', ' °C', 1);
                    $this->MaintainVariable('Warmwasser', $this->t('Hot water temperature'), VARIABLETYPE_FLOAT, 'NRG.Celsius', 13, true);
                }
                $this->wertSetzen('Warmwasser', $w);
            }
        }
    }

    private static function zustandText(string $state): string
    {
        $map = ['inactive' => 'offered', 'scheduled' => 'scheduled', 'running' => 'running', 'paused' => 'paused', 'scheduledPaused' => 'paused', 'completed' => 'completed'];
        return $map[$state] ?? 'not offered';
    }

    public function OptionalTesten(string $Aktion): string
    {
        switch ($Aktion) {
            case 'start':
                $ok = $this->StartOptionalRun(0);
                break;
            case 'pause':
                $ok = $this->PauseOptionalRun();
                break;
            case 'resume':
                $ok = $this->ResumeOptionalRun();
                break;
            default:
                $ok = $this->AbortOptionalRun();
        }
        // Wie „Optionalen Verdichterlauf ausführen“: Starten/Fortsetzen = Ein, Pausieren/Abbrechen = Aus (sonst plant der
        // Reboost einen gerade abgebrochenen Lauf sofort wieder ein).
        $this->WriteAttributeBoolean('OhpcfWunsch', in_array($Aktion, ['start', 'resume'], true));
        $this->reboostTimer();
        return $ok ? '✅ ' . $this->t('Sent to the heat pump. Its answer appears in “Optional compressor run” in a few seconds.')
            : '⛔ ' . $this->t('Could not be sent. Details are in the message log.');
    }

    // ------------------------------------------------------------------
    // Verbund-Vertrag
    // ------------------------------------------------------------------

    /** WPEEB_GetFunctions($id): array — heatpump-Vertrag 1.17 wie WPModbusHub/WPHub/HeishaMon. */
    public function GetFunctions(): array
    {
        $id = function (string $ident): int {
            $v = @$this->GetIDForIdent($ident);
            return $v === false ? 0 : (int)$v;
        };
        $g = $this->geraet();
        $energieGemessen = $this->energieVomGeraet();
        $energieID = $id($this->energieIdent());
        return [[
            'contractVersion'      => self::CONTRACT_VERSION,
            'Type'                 => 'heatpump',
            'Caption'              => IPS_GetName($this->InstanceID),
            'PowerID'              => $id('Leistung'),
            // Kumulativer Zählerstand in kWh: der der Wärmepumpe (MPC) oder, wenn sie keinen liefert, der aus
            // ihrer gemessenen Leistung hochgerechnete (energyMeasured=false, wie MeterHub 1.3).
            'EnergyID'             => $energieID,
            // Die Leistung misst die Wärmepumpe selbst (MPC).
            'Measured'             => true,
            'energyMeasured'       => $energieGemessen,
            'unit'                 => 'W',
            // Dieselben Felder in der Verbund-Form von MeterHub/ChargerHub (function/label/powerID/…), damit
            // Energiefluss und Messmodule die Wärmepumpe ohne Sonderübersetzung lesen (+ = Verbrauch).
            'function'             => 'heatpump',
            'label'                => IPS_GetName($this->InstanceID),
            'instanceID'           => $this->InstanceID,
            'powerID'              => $id('Leistung'),
            'energyImportID'       => $energieID,
            'energyExportID'       => 0,
            'energyKind'           => 'counter',
            'measured'             => true,
            'reachable'            => (bool)@$this->GetValue('Erreichbar'),
            'outsideTempID'        => 0,
            'outdoorTemperatureID' => 0,
            'z1WaterTempID'        => 0,
            'z1WaterTargetTempID'  => 0,
            'z2WaterTempID'        => 0,
            'z2WaterTargetTempID'  => 0,
            'dhwTempID'            => $id('Warmwasser'),
            'dhwTargetTempID'      => 0,
            'mainInletTempID'      => 0,
            'mainOutletTempID'     => 0,
            'compressorStartsID'   => 0,
            'bufferTempID'         => 0,
            'quietModeID'          => 0,
            'ecoComfortModeID'     => 0,
            'holidayTimerID'       => 0,
            'operatingModeNormID'  => 0,
            'operatingModeID'      => 0,
            // WP-Steuermuster (1.18): Stellgrößen als Variablen, geschaltet per RequestAction wie beim charger-Vertrag.
            'managedBy'            => $this->steuerhoheit(),
            'controllable'         => $this->steuerbar(),
            'optionalRunID'        => !empty($g['ohpcfEntity']) ? $id('OhpcfLauf') : 0,
            'optionalRunPowerID'   => !empty($g['ohpcfEntity']) ? $id('OhpcfLeistung') : 0,
            'optionalRunPowerMaxID' => !empty($g['ohpcfEntity']) ? $id('OhpcfLeistungMax') : 0,
            'powerLimitID'         => isset($g['limitId']) ? $id('Grenze') : 0,
            'powerLimitActiveID'   => isset($g['limitId']) ? $id('GrenzeAktiv') : 0,
            'lastSeenAt'           => $this->ReadAttributeInteger('LastSeenAt'),
        ]];
    }

    private function steuerhoheit(): string
    {
        $h = $this->ReadPropertyString('Steuerhoheit');
        return in_array($h, self::STEUERHOHEIT, true) ? $h : 'none';
    }

    /** Steuerbar im Sinne des Vertrags: verbunden und bietet einen Zusatzlauf (OHPCF) oder eine Grenze (LPC) an. */
    private function steuerbar(): bool
    {
        $g = $this->geraet();
        return (bool)@$this->GetValue('Erreichbar') && (!empty($g['ohpcfEntity']) || isset($g['limitId']));
    }

    /** WPEEB_GetState($id): string — Zustand als JSON (Diagnose). */
    public function GetState(): string
    {
        $g = $this->geraet();
        return (string)json_encode([
            'gateway' => $this->gatewayID(),
            'reachable' => (bool)@$this->GetValue('Erreichbar'),
            'entity' => $g['entity'] ?? null,
            'entityType' => $g['type'] ?? '',
            'lpc' => isset($g['limitId']),
            'mpc' => !empty($g['messung']),
            'ohpcf' => $g['ohpcf'] ?? null,
            'ohpcfEntity' => $g['ohpcfEntity'] ?? null,
            'limitW' => @$this->GetValue('Grenze'),
            'limitActive' => (bool)@$this->GetValue('GrenzeAktiv'),
            'powerW' => @$this->GetValue('Leistung'),
            'energyKwh' => @$this->GetValue('Energie'),
            'lastResult' => json_decode($this->ReadAttributeString('LetztesErgebnis'), true),
            'lastSeenAt' => $this->ReadAttributeInteger('LastSeenAt'),
        ]);
    }

    // ------------------------------------------------------------------
    // Formular
    // ------------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Heat pump connected'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'No EEBUS gateway assigned'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'The EEBUS gateway did not accept the registration'],
            ['code' => 202, 'icon' => 'error', 'caption' => 'The EEBUS gateway is too old (version 0.4.0 or newer required)'],
            ['code' => 203, 'icon' => 'inactive', 'caption' => 'Waiting for the heat pump'],
        ];
        return $this->wpeebBuildForm($this->wpeebFachpanels(), [], $status);
    }

    protected function wpeebFachpanels(): array
    {
        $gw = $this->gatewayID();
        $g = $this->geraet();
        $verbunden = !empty($g['entity']);
        $gwZeile = $gw > 0
            ? '✅ ' . $this->t('EEBUS gateway #%s (%s)', (string)$gw, IPS_GetName($gw))
            : self::fest('ℹ️ No EEBUS gateway assigned yet: choose “Change gateway” at the top and select or create an NRG-Stack EEBUS gateway (one per heat pump).');
        $geraetZeile = $verbunden
            ? '✅ ' . $this->t('Heat pump connected: %s (entity %s)', (string)($g['type'] ?? '?'), (string)json_encode($g['entity']))
                . (!empty($g['heartbeatVerloren']) ? ' ' . $this->t('(heartbeat missing)') : '')
            : self::fest('ℹ️ No heat pump connected yet. Check the connection in the EEBUS gateway and trust its SKI in the manufacturer app.');
        $uc = $verbunden || !empty($g['ohpcfEntity'])
            ? $this->t('Power limit (LPC): %s · Measured values (MPC): %s · PV surplus (OHPCF): %s', isset($g['limitId']) ? $this->t('available') : $this->t('not offered'),
                !empty($g['messung']) ? $this->t('available') : $this->t('not offered'), !empty($g['ohpcfEntity']) ? $this->t('available') : $this->t('not offered'))
            : '';
        $o = is_array($g['ohpcf'] ?? null) ? $g['ohpcf'] : null;
        $ohpcfZeile = $o === null
            ? self::fest('ℹ️ The heat pump currently offers no optional compressor run.')
            : $this->t('Optional run: %s, pausable: %s, can be aborted: %s, minimum run %s, minimum pause %s', $this->t(self::zustandText($o['state'])),
                $o['pausierbar'] ? $this->t('yes') : $this->t('no'), $o['abbrechbar'] ? $this->t('yes') : $this->t('no'),
                $o['laufMin'] !== '' ? $o['laufMin'] : '–', $o['pauseMin'] !== '' ? $o['pauseMin'] : '–');
        $erg = json_decode($this->ReadAttributeString('LetztesErgebnis'), true) ?: [];
        $ergZeile = isset($erg['zeit'])
            ? $this->t('Last answer of the heat pump to a limit (%s): %s', date($this->t('Y-m-d H:i:s'), (int)$erg['zeit']),
                (int)$erg['errorNumber'] === 0 ? $this->t('accepted') : $this->t('rejected (error %s %s)', (string)$erg['errorNumber'], (string)$erg['description']))
            : '';

        $verbindung = [
            'type' => 'ExpansionPanel', 'expanded' => !$verbunden, 'caption' => '🔌  Connection',
            'items' => array_values(array_filter([
                ['type' => 'Label', 'caption' => $gwZeile],
                ['type' => 'Label', 'caption' => $geraetZeile, 'color' => $verbunden ? 0x2E8B3D : -1],
                $uc !== '' ? ['type' => 'Label', 'caption' => $uc] : null,
            ])),
        ];

        $grenze = [
            'type' => 'ExpansionPanel', 'bereich' => 'actions', 'expanded' => false, 'caption' => '🎚️  Test the power limit',
            'items' => array_values(array_filter([
                ['type' => 'Label', 'caption' => 'Sends a power limit (LPC) to the heat pump, e.g. to test the connection. In operation the limit is set via the variable “Power limit”, by script (WPEEB_SetPowerLimit) or by an energy management.'],
                ['type' => 'NumberSpinner', 'name' => 'TestWatt', 'caption' => 'Limit', 'suffix' => 'W', 'minimum' => 0, 'maximum' => 50000, 'value' => 4200],
                ['type' => 'NumberSpinner', 'name' => 'TestMinuten', 'caption' => 'Duration (0 = no end)', 'suffix' => 'min', 'minimum' => 0, 'maximum' => 1440, 'value' => 15],
                ['type' => 'Button', 'caption' => 'Send limit', 'onClick' => 'echo WPEEB_GrenzeTesten($id, $TestWatt, $TestMinuten);'],
                ['type' => 'Button', 'caption' => 'Lift limit', 'onClick' => 'echo WPEEB_GrenzeAufheben($id);'],
                $ergZeile !== '' ? ['type' => 'Label', 'caption' => $ergZeile] : null,
            ])),
        ];

        $ohpcf = [
            'type' => 'ExpansionPanel', 'bereich' => 'actions', 'expanded' => false, 'caption' => '☀️  Test the optional compressor run (PV surplus)',
            'items' => [
                ['type' => 'Label', 'caption' => 'With OHPCF the heat pump offers to run its compressor additionally (e.g. to heat the hot water higher). An energy management starts this run when there is PV surplus. In operation use the variable “Run optional compressor run” or WPEEB_SetOptionalRun.'],
                ['type' => 'Label', 'caption' => $ohpcfZeile],
                ['type' => 'Button', 'caption' => 'Start now', 'onClick' => 'echo WPEEB_OptionalTesten($id, "start");'],
                ['type' => 'Button', 'caption' => 'Pause', 'onClick' => 'echo WPEEB_OptionalTesten($id, "pause");'],
                ['type' => 'Button', 'caption' => 'Resume', 'onClick' => 'echo WPEEB_OptionalTesten($id, "resume");'],
                ['type' => 'Button', 'caption' => 'Abort', 'onClick' => 'echo WPEEB_OptionalTesten($id, "abort");'],
            ],
        ];

        // Energie und Archivierung: Woher der Zählerstand kommt, und ob Verlauf, WP-Monitor und Energiefluss
        // auf archivierte Werte zugreifen können (Archivierung nur auf Klick, Store-Checkliste 19).
        $fehlt = $this->archivFehlt();
        $energie = [
            'type' => 'ExpansionPanel', 'name' => 'ArchivPanel', 'bereich' => 'actions', 'expanded' => $fehlt !== [], 'caption' => '📈  Energy and archive',
            'items' => array_values(array_filter([
                ['type' => 'Label', 'caption' => $this->energieVomGeraet()
                    ? self::fest('✅ The heat pump reports its own meter reading; it is used for the energy.')
                    : self::fest('ℹ️ The heat pump reports no meter reading. The module calculates it from the measured power (variable “Electrical energy consumed (calculated)”).')],
                $fehlt !== [] ? ['type' => 'Label', 'caption' => 'For the history, the heat pump monitor of the NRG-Stack dashboard and the energy flow, power and meter reading must be archived. The module does not switch archiving on by itself - that is your decision.'] : null,
                $fehlt !== [] ? ['type' => 'Label', 'caption' => $this->t('Not archived yet: %s', implode(', ', array_map('IPS_GetName', array_keys($fehlt))))] : null,
                $fehlt !== [] ? ['type' => 'Button', 'caption' => '📈  Enable archiving for these variables', 'onClick' => 'echo WPEEB_ArchivierungEinschalten($id);'] : null,
                $fehlt === [] && $this->archivID() > 0 ? ['type' => 'Label', 'caption' => self::fest('✅ Power and meter reading are archived.')] : null,
            ])),
        ];

        $einstellungen = [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '⚙️  PV surplus and control',
            'items' => [
                ['type' => 'Label', 'caption' => 'While “Run optional compressor run” is on, the module schedules every run the heat pump newly offers - immediately and again after this interval (like evcc “reboost”). 0 = only immediately.'],
                ['type' => 'NumberSpinner', 'name' => 'ReboostMinuten', 'caption' => 'Reschedule offered runs every', 'suffix' => 'min', 'minimum' => 0, 'maximum' => 240],
                ['type' => 'Select', 'name' => 'Steuerhoheit', 'caption' => 'Who controls this heat pump?', 'width' => '420px', 'options' => [
                    ['caption' => 'No specification (EMS may use the optional run)', 'value' => 'none'],
                    ['caption' => 'NRG-Stack EMS', 'value' => 'ems'],
                    ['caption' => 'Another system (EMS stays out)', 'value' => 'other'],
                ]],
                ['type' => 'Label', 'caption' => 'The NRG-Stack EMS only switches the heat pump if this is not set to “another system”. It first uses only the optional run for PV surplus; the § 14a limit stays with the SteuerboxHub.'],
            ],
        ];
        return ['verbindung' => $verbindung, 'einstellungen' => $einstellungen, 'energie' => $energie, 'grenze' => $grenze, 'ohpcf' => $ohpcf];
    }
}
