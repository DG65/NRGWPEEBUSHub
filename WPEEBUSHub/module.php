<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/WPEEBFormular.php';

// ===========================================================================
// NRG-Stack WPEEBUSHub
//
// Wärmepumpe über EEBUS. Kind-Instanz des NRG-Stack EEBUS-Gateways (Kind-
// Schnittstelle 1.1, Rolle Energiemanagement): meldet sich als Energy Guard
// (LPC, Leistungsbegrenzung) und Monitoring Appliance (MPC, Leistung/Energie)
// an. Abonnieren, Binden und erstes Lesen der Wärmepumpen-Entität übernimmt
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
    private const CONTRACT_VERSION = '1.15';

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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        $this->MaintainVariable('Erreichbar', $this->t('Reachable'), VARIABLETYPE_BOOLEAN, '', 1, true);
        $this->MaintainVariable('Leistung', $this->t('Electrical power'), VARIABLETYPE_FLOAT, '~Watt', 2, true);
        $this->MaintainVariable('Energie', $this->t('Electrical energy consumed'), VARIABLETYPE_FLOAT, '~Electricity', 3, true);
        $this->MaintainVariable('Grenze', $this->t('Power limit'), VARIABLETYPE_FLOAT, '~Watt', 4, true);
        $this->MaintainVariable('GrenzeAktiv', $this->t('Power limit active'), VARIABLETYPE_BOOLEAN, '~Switch', 5, true);
        $this->MaintainVariable('Ausfallgrenze', $this->t('Failsafe power limit'), VARIABLETYPE_FLOAT, '~Watt', 6, true);
        $this->MaintainVariable('Nennleistung', $this->t('Maximum power consumption'), VARIABLETYPE_FLOAT, '~Watt', 7, true);
        $this->EnableAction('Grenze');
        $this->EnableAction('GrenzeAktiv');

        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->SetTimerInterval('Anmelden', 60000);
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
            ],
            'features' => [$client('LoadControl'), $client('DeviceConfiguration'), $client('ElectricalConnection'), $client('Measurement')],
            'remote' => [
                'entityTypes' => self::WP_ENTITAETEN,
                'features' => [
                    ['type' => 'LoadControl', 'subscribe' => true, 'bind' => true, 'read' => ['loadControlLimitDescriptionListData', 'loadControlLimitListData']],
                    ['type' => 'DeviceConfiguration', 'subscribe' => true, 'bind' => true, 'read' => ['deviceConfigurationKeyValueDescriptionListData', 'deviceConfigurationKeyValueListData']],
                    ['type' => 'ElectricalConnection', 'subscribe' => true, 'read' => ['electricalConnectionDescriptionListData', 'electricalConnectionParameterDescriptionListData', 'electricalConnectionCharacteristicListData']],
                    ['type' => 'Measurement', 'subscribe' => true, 'read' => ['measurementDescriptionListData', 'measurementListData']],
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
                }
                $this->statusAktualisieren();
                break;
            case 'data':
                $this->datenAuswerten((string)($e['function'] ?? ''), (array)($e['data'] ?? []));
                $this->WriteAttributeInteger('LastSeenAt', time());
                break;
            case 'result':
                $this->WriteAttributeString('LetztesErgebnis', (string)json_encode(['zeit' => time(), 'function' => $e['function'] ?? '',
                    'errorNumber' => (int)($e['errorNumber'] ?? 0), 'description' => (string)($e['description'] ?? '')]));
                if ((int)($e['errorNumber'] ?? 0) !== 0) {
                    $this->LogMessage($this->t('The heat pump rejected the limit: error %s %s', (string)$e['errorNumber'], (string)($e['description'] ?? '')), KL_WARNING);
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
        if (!in_array($typ, self::WP_ENTITAETEN, true) || (!in_array('LoadControl', $typen, true) && !in_array('Measurement', $typen, true))) {
            return;
        }
        $g = $this->geraet();
        if (!empty($g['entity']) && $g['entity'] != (array)$e['entity']
            && array_search($g['type'] ?? '', self::WP_ENTITAETEN, true) <= array_search($typ, self::WP_ENTITAETEN, true)) {
            return;
        }
        // Neue Entität: Kennungen der alten gelten nicht mehr.
        $this->WriteAttributeString('Geraet', (string)json_encode(['entity' => (array)$e['entity'], 'type' => $typ, 'seit' => time()]));
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
        if ($this->geraet() !== []) {
            $this->WriteAttributeString('Geraet', '{}');
        }
        $this->wertSetzen('Erreichbar', false);
        $this->statusAktualisieren();
    }

    /** Skalierte SPINE-Zahl {number, scale} als float. */
    private static function zahl($v): ?float
    {
        if (!is_array($v) || !isset($v['number'])) {
            return null;
        }
        return (float)$v['number'] * (10 ** (int)($v['scale'] ?? 0));
    }

    private function datenAuswerten(string $fn, array $d): void
    {
        $g = $this->geraet();
        switch ($fn) {
            case 'loadControlLimitDescriptionListData':
                // Grenze für den Bezug: Richtung consume, Wirkleistungsgrenze (wie eebus-go eg/lpc).
                foreach ((array)($d['loadControlLimitDescriptionData'] ?? []) as $l) {
                    if (($l['limitDirection'] ?? '') === 'consume' && ($l['scopeType'] ?? '') === 'activePowerLimit') {
                        $this->geraetAendern(['limitId' => (int)$l['limitId']]);
                        $this->grenzeAusStand();
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
                $this->geraetAendern(['messung' => $m]);
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
                        $this->wertSetzen('Leistung', $w);
                    } elseif ($id === (int)($m['acEnergyConsumed']['id'] ?? -2)) {
                        $einheit = strtolower((string)($m['acEnergyConsumed']['unit'] ?? 'Wh'));
                        $this->wertSetzen('Energie', $einheit === 'kwh' ? $w : $w / 1000);
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

    private function grenzeSchreiben(float $watt, bool $aktiv, int $dauer, ?array $g = null): bool
    {
        $g = $g ?? $this->geraet();
        if (empty($g['entity']) || !isset($g['limitId'])) {
            $this->LogMessage($this->t('Power limit not sent: the heat pump is not connected or offers no power limit (LPC).'), KL_WARNING);
            return false;
        }
        if (isset($g['aenderbar']) && $g['aenderbar'] === false) {
            $this->LogMessage($this->t('Power limit not sent: the heat pump currently does not allow changing it.'), KL_WARNING);
            return false;
        }
        $eintrag = ['limitId' => (int)$g['limitId'], 'isLimitActive' => $aktiv, 'value' => ['number' => (int)round($watt), 'scale' => 0]];
        if ($aktiv && $dauer > 0) {
            $eintrag['timePeriod'] = ['endTime' => 'PT' . $dauer . 'S'];
        }
        $r = $this->gateway(['Cmd' => 'Write', 'Entity' => $g['entity'], 'Feature' => 'LoadControl', 'Function' => 'loadControlLimitListData',
            'Data' => ['loadControlLimitData' => [$eintrag]]]);
        if (empty($r['ok'])) {
            $this->LogMessage($this->t('Power limit not sent: %s', (string)($r['error'] ?? $this->t('no answer from the gateway'))), KL_WARNING);
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
    // Verbund-Vertrag
    // ------------------------------------------------------------------

    /** WPEEB_GetFunctions($id): array — heatpump-Vertrag 1.15 wie WPModbusHub/WPHub/HeishaMon. */
    public function GetFunctions(): array
    {
        $id = function (string $ident): int {
            $v = @$this->GetIDForIdent($ident);
            return $v === false ? 0 : (int)$v;
        };
        return [[
            'contractVersion'      => self::CONTRACT_VERSION,
            'Type'                 => 'heatpump',
            'Caption'              => IPS_GetName($this->InstanceID),
            'PowerID'              => $id('Leistung'),
            'EnergyID'             => $id('Energie'),
            // Leistung und Energie misst die Wärmepumpe selbst (MPC).
            'Measured'             => true,
            'unit'                 => 'W',
            'reachable'            => (bool)@$this->GetValue('Erreichbar'),
            'outsideTempID'        => 0,
            'outdoorTemperatureID' => 0,
            'z1WaterTempID'        => 0,
            'z1WaterTargetTempID'  => 0,
            'z2WaterTempID'        => 0,
            'z2WaterTargetTempID'  => 0,
            'dhwTempID'            => 0,
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
            'managedBy'            => 'wpeeb',
            'lastSeenAt'           => $this->ReadAttributeInteger('LastSeenAt'),
        ]];
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
        $uc = $verbunden
            ? $this->t('Power limit (LPC): %s · Measured values (MPC): %s', isset($g['limitId']) ? $this->t('available') : $this->t('not offered'),
                !empty($g['messung']) ? $this->t('available') : $this->t('not offered'))
            : '';
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

        return ['verbindung' => $verbindung, 'grenze' => $grenze];
    }
}
