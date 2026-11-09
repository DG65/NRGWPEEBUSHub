<?php
/**
 * test-wpeeb.php — Prüfstand für WPEEBUSHub ohne laufendes Symcon.
 *
 * Lädt WPEEBUSHub zusammen mit dem echten NRG-Stack EEBUS-Gateway (Kind-Schnittstelle 1.1)
 * in eine nachgebildete Symcon-Umgebung, in der Kind und Gateway wie im Datenfluss miteinander
 * reden (SendDataToParent → ForwardData, SendDataToChildren → ReceiveData).
 *
 * Teil 1 (immer): Anmeldung, Auswertung der Gerätedaten, Vertrag, Formular, Zweisprachigkeit.
 * Teil 2 (--live <verbraucher-sim> <cert> <key>): simulierte Wärmepumpe (eebus-go evse mit
 *   MPC, -type heatpump) über echte TLS-Verbindung; Grenze setzen, Leistung muss sinken.
 *
 * Aufruf:   php .tools/test-wpeeb.php [--live verbraucher-sim cert key]
 *           Gateway-Quellen: Umgebungsvariable EEBGW_DIR, sonst ../EEBUSGateway.
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const IM_CHANGESTATUS = 10505;
const FM_CONNECT = 11101;
const FM_DISCONNECT = 11102;
const IS_ACTIVE = 102;
const KL_MESSAGE = 10201;
const KL_NOTIFY = 10203;
const KL_WARNING = 10204;
const KL_ERROR = 10205;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;

$wurzel = dirname(__DIR__);
$gwDir = getenv('EEBGW_DIR') ?: dirname($wurzel) . '/EEBUSGateway';
$GLOBALS['INST'] = [];
$GLOBALS['VARS'] = [];
$GLOBALS['LANG'] = 'de';
$GLOBALS['LOCALE'] = [
    'WPEEBUSHub' => json_decode((string)file_get_contents($wurzel . '/WPEEBUSHub/locale.json'), true)['translations']['de'] ?? [],
    'EEBUSGateway' => json_decode((string)file_get_contents($gwDir . '/EEBUSGateway/locale.json'), true)['translations']['de'] ?? [],
];
$GLOBALS['OK'] = 0;
$GLOBALS['FEHLER'] = 0;

function pruefe(bool $b, string $text): void
{
    $GLOBALS[$b ? 'OK' : 'FEHLER']++;
    echo ($b ? '  ok   ' : '  FEHL ') . $text . "\n";
}

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_SemaphoreEnter(string $n, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['INST'][$id]); }
function IPS_GetName(int $id): string { return $GLOBALS['INST'][$id]->name ?? ('#' . $id); }
function IPS_GetInstance(int $id): array
{
    $i = $GLOBALS['INST'][$id];
    return ['ConnectionID' => $i->parentId ?? 0, 'InstanceStatus' => $i->status ?? 102, 'ModuleInfo' => ['ModuleID' => $i->modul ?? '']];
}
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['INST'][$id]->config); }
function IPS_SetConfiguration(int $id, string $c): bool { $GLOBALS['INST'][$id]->config = array_merge($GLOBALS['INST'][$id]->config, json_decode($c, true)); return true; }
function IPS_SetProperty(int $id, string $n, $v): bool { $GLOBALS['INST'][$id]->config[$n] = $v; return true; }
function IPS_ApplyChanges(int $id): bool { $i = $GLOBALS['INST'][$id] ?? null; if ($i !== null && method_exists($i, 'anwenden')) { $i->anwenden(); } return true; }
function IPS_GetInstanceListByModuleID(string $g): array { return $g === '{43192F0B-135B-4CE7-A0A7-1475603F3060}' && $GLOBALS['ARCHIV'] > 0 ? [$GLOBALS['ARCHIV']] : []; }
function IPS_GetLibrary(string $guid): array
{
    $l = json_decode((string)file_get_contents(dirname(__DIR__) . '/library.json'), true);
    return ['Version' => $l['version'], 'Build' => $l['build']];
}
function GetValue(int $id) { return $GLOBALS['VARS'][$id] ?? null; }
$GLOBALS['PROFILE'] = [];
function IPS_VariableProfileExists(string $n): bool { return isset($GLOBALS['PROFILE'][$n]); }
function IPS_CreateVariableProfile(string $n, int $t): bool { $GLOBALS['PROFILE'][$n] = []; return true; }
function IPS_SetVariableProfileAssociation(string $n, $w, string $text, string $icon, int $farbe): bool { $GLOBALS['PROFILE'][$n][$w] = $text; return true; }
function IPS_SetVariableProfileDigits(string $n, int $d): bool { $GLOBALS['PROFILE'][$n]['_stellen'] = $d; return true; }
function IPS_SetVariableProfileText(string $n, string $pre, string $suf): bool { $GLOBALS['PROFILE'][$n]['_suffix'] = $suf; return true; }
function IPS_SetVariableProfileIcon(string $n, string $i): bool { return true; }
// Archiv: $GLOBALS['ARCHIV'] = Archiv-ID (0 = keins), $GLOBALS['LOG'][vid] = [geloggt, Aggregation].
$GLOBALS['ARCHIV'] = 0;
$GLOBALS['LOG'] = [];
function AC_GetLoggingStatus(int $a, int $v): bool { return $GLOBALS['LOG'][$v][0] ?? false; }
function AC_SetLoggingStatus(int $a, int $v, bool $an): bool { $GLOBALS['LOG'][$v][0] = $an; return true; }
function AC_SetAggregationType(int $a, int $v, int $t): bool { $GLOBALS['LOG'][$v][1] = $t; return true; }

class IPSModule
{
    public $InstanceID;
    public $props = [];
    public $attrs = [];
    public $buffers = [];
    public $timers = [];
    public $vars = [];
    public $ids = [];
    public $status = 0;
    public $parentId = 0;
    public $name = '';
    public $modul = '';
    public $log = [];
    public $form = [];
    public $gesendet = [];

    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = $v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] = $v; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function ReadPropertyString($n) { return $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return $this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return $this->props[$n]; }
    protected function ReadAttributeString($n) { return $this->attrs[$n]; }
    protected function ReadAttributeInteger($n) { return $this->attrs[$n]; }
    protected function ReadAttributeBoolean($n) { return $this->attrs[$n]; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterTimer($n, $i, $s) { $this->timers[$n] = $i; }
    protected function SetTimerInterval($n, $i) { $this->timers[$n] = $i; }
    protected function RequireParent($g) {}
    protected function RegisterMessage($id, $m) {}
    protected function UnregisterMessage($id, $m) {}
    protected function SetStatus($s) { $this->status = $s; }
    protected function GetBuffer($n) { return $this->buffers[$n] ?? ''; }
    protected function SetBuffer($n, $v) { $this->buffers[$n] = $v; }
    protected function SendDebug($t, $m, $f) { if (getenv('WPEEB_DEBUG')) { echo "      [dbg {$this->InstanceID}] $t: $m\n"; } }
    protected function LogMessage($m, $t) { $this->log[] = $m; }
    protected function MaintainVariable($ident, $name, $typ, $profil, $pos, $behalten)
    {
        if (!isset($this->ids[$ident])) {
            $this->ids[$ident] = $this->InstanceID * 100 + count($this->ids) + 1;
            $this->vars[$ident] = null;
        }
    }
    protected function EnableAction($ident) {}
    protected function GetIDForIdent($ident) { return $this->ids[$ident] ?? false; }
    protected function GetValue($ident) { return $this->vars[$ident] ?? null; }
    protected function SetValue($ident, $v) { $this->vars[$ident] = $v; $GLOBALS['VARS'][$this->ids[$ident]] = $v; }
    protected function UpdateFormField($f, $p, $v) { $this->form[$f][$p] = $v; }
    public function Translate($text) { return $GLOBALS['LANG'] === 'de' ? ($GLOBALS['LOCALE'][get_class($this)][$text] ?? $text) : $text; }
    protected function SendDataToParent($json)
    {
        $eltern = $GLOBALS['INST'][$this->parentId] ?? null;
        if ($eltern instanceof EEBUSGateway) {
            return $eltern->ForwardData($json);
        }
        // Gateway → Client Socket: in die echte TLS-Verbindung (Teil 2).
        $d = json_decode($json, true);
        $this->gesendet[] = mb_convert_encoding($d['Buffer'], 'ISO-8859-1', 'UTF-8');
        if (isset($GLOBALS['BRUECKE'])) { fwrite($GLOBALS['BRUECKE'], end($this->gesendet)); }
        return '';
    }
    protected function SendDataToChildren($json)
    {
        foreach ($GLOBALS['INST'] as $i) {
            if ($i instanceof WPEEBUSHub && $i->parentId === $this->InstanceID) {
                $i->ReceiveData($json);
            }
        }
    }
}

class FakeSocket
{
    public $InstanceID;
    public $config = ['Open' => false, 'Host' => '', 'Port' => 0];
    public $status = 104;
    public $modul = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    public $name = 'Client Socket';
    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function anwenden(): void { $this->status = $this->config['Open'] ? 102 : 104; }
}

require $gwDir . '/EEBUSGateway/module.php';
require $wurzel . '/WPEEBUSHub/module.php';

/** Jede Symcon-Anfrage beginnt mit leerem Übersetzungsspeicher. */
function neueAnfrage($i): void
{
    foreach (['eebgwUebersetzt', 'wpeebUebersetzt'] as $p) {
        if (property_exists($i, $p)) {
            $r = new ReflectionProperty(get_class($i), $p);
            $r->setValue($i, []);
        }
    }
}

/** Formular wie in der Konsole: feste caption-Texte übersetzt Symcon selbst. */
function formular(WPEEBUSHub $wp): string
{
    neueAnfrage($wp);
    $f = json_decode($wp->GetConfigurationForm(), true);
    $walk = function (&$x) use (&$walk) {
        if (!is_array($x)) {
            return;
        }
        foreach ($x as $k => &$v) {
            if ($k === 'caption' && is_string($v) && $GLOBALS['LANG'] === 'de') {
                $v = $GLOBALS['LOCALE']['WPEEBUSHub'][$v] ?? $v;
            } else {
                $walk($v);
            }
        }
    };
    $walk($f);
    return (string)json_encode($f, JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------------------
echo "Teil 1: Bausteine\n";

$sock = new FakeSocket(30001);
$gw = new EEBUSGateway(30002);
$gw->name = 'EEBUS-Gateway Wärmepumpe';
$gw->modul = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
$gw->parentId = 30001;
$gw->Create();
$gw->ApplyChanges();

// Ohne Gateway
$allein = new WPEEBUSHub(30010);
$allein->modul = '{731C4932-48B3-4360-BFED-1CA034978BCB}';
$allein->Create();
$allein->ApplyChanges();
pruefe($allein->status === 104, 'Ohne Gateway: Status 104');
pruefe($allein->SetPowerLimit(4000, 0) === false, 'Ohne Gateway: Grenze wird nicht gesendet');
unset($GLOBALS['INST'][30010]);

$wp = new WPEEBUSHub(30003);
$wp->name = 'Wärmepumpe';
$wp->modul = '{731C4932-48B3-4360-BFED-1CA034978BCB}';
$wp->parentId = 30002;
$wp->Create();
$wp->ApplyChanges();
$anm = json_decode($gw->attrs['Anmeldungen'], true)['30003'] ?? [];
pruefe(($anm['type'] ?? '') === 'CEM' && count($anm['features'] ?? []) === 5 && in_array('HeatPumpAppliance', $anm['remote']['entityTypes'] ?? [], true),
    'Beim Gateway angemeldet: CEM mit 5 Client-Features (inkl. SmartEnergyManagementPs), Wärmepumpen-Entitäten');
pruefe($wp->status === 203, 'Angemeldet, Wärmepumpe noch nicht da: Status 203');
pruefe($wp->timers['Anmelden'] === 60000, 'Anmeldung wird jede Minute aufgefrischt');

// Gerätedaten, wie sie das Gateway meldet
$sende = function (array $e) use ($wp) {
    $wp->ReceiveData(json_encode(['DataID' => '{12D8B8A8-3EE7-491F-A68A-D632304165D4}', 'Target' => 30003] + $e));
};
$sende(['Event' => 'remoteEntity', 'state' => 'added', 'entity' => [2], 'type' => 'DHWCircuit', 'features' => [['type' => 'Measurement']]]);
pruefe(empty(json_decode($wp->attrs['Geraet'], true)['entity']), 'Warmwasserkreis ist keine Wärmepumpe: nicht übernommen');
$sende(['Event' => 'remoteEntity', 'state' => 'added', 'entity' => [1], 'type' => 'HeatPumpAppliance',
    'features' => [['type' => 'LoadControl'], ['type' => 'Measurement'], ['type' => 'DeviceConfiguration']]]);
pruefe($wp->status === 102 && $wp->vars['Erreichbar'] === true, 'Wärmepumpe angebunden: Status 102, Erreichbar');
$sende(['Event' => 'remoteEntity', 'state' => 'added', 'entity' => [3], 'type' => 'Generic', 'features' => [['type' => 'Measurement']]]);
pruefe(json_decode($wp->attrs['Geraet'], true)['entity'] === [1], 'Generic-Entität verdrängt die Wärmepumpen-Entität nicht');

$sende(['Event' => 'data', 'function' => 'measurementDescriptionListData', 'data' => ['measurementDescriptionData' => [
    ['measurementId' => 0, 'measurementType' => 'power', 'unit' => 'W', 'scopeType' => 'acPowerTotal'],
    ['measurementId' => 1, 'measurementType' => 'power', 'unit' => 'W', 'scopeType' => 'acPower'],
    ['measurementId' => 4, 'measurementType' => 'energy', 'unit' => 'Wh', 'scopeType' => 'acEnergyConsumed']]]]);
$sende(['Event' => 'data', 'function' => 'measurementListData', 'data' => ['measurementData' => [
    ['measurementId' => 0, 'value' => ['number' => 23456, 'scale' => -1]],
    ['measurementId' => 1, 'value' => ['number' => 999, 'scale' => 0]],
    ['measurementId' => 4, 'value' => ['number' => 1234567, 'scale' => 0]]]]]);
pruefe(abs($wp->vars['Leistung'] - 2345.6) < 0.01 && abs($wp->vars['Energie'] - 1234.567) < 0.001, 'MPC: Gesamtleistung 2345,6 W (Skala −1), Energie Wh → 1234,567 kWh, Phasenwert ignoriert');

$sende(['Event' => 'data', 'function' => 'deviceConfigurationKeyValueDescriptionListData', 'data' => ['deviceConfigurationKeyValueDescriptionData' => [
    ['keyId' => 0, 'keyName' => 'failsafeConsumptionActivePowerLimit', 'valueType' => 'scaledNumber', 'unit' => 'W'],
    ['keyId' => 1, 'keyName' => 'failsafeDurationMinimum', 'valueType' => 'duration']]]]);
$sende(['Event' => 'data', 'function' => 'deviceConfigurationKeyValueListData', 'data' => ['deviceConfigurationKeyValueData' => [
    ['keyId' => 0, 'value' => ['scaledNumber' => ['number' => 4200, 'scale' => 0]]], ['keyId' => 1, 'value' => ['duration' => 'PT2H']]]]]);
$sende(['Event' => 'data', 'function' => 'electricalConnectionCharacteristicListData', 'data' => ['electricalConnectionCharacteristicData' => [
    ['characteristicId' => 0, 'characteristicType' => 'powerConsumptionNominalMax', 'value' => ['number' => 9, 'scale' => 3]]]]]);
pruefe($wp->vars['Ausfallgrenze'] === 4200.0 && $wp->vars['Nennleistung'] === 9000.0, 'Ausfallgrenze 4200 W und Nennleistung 9 kW gelesen');

$sende(['Event' => 'data', 'function' => 'loadControlLimitListData', 'data' => ['loadControlLimitData' => [
    ['limitId' => 7, 'isLimitChangeable' => true, 'isLimitActive' => false, 'value' => ['number' => 6000, 'scale' => 0]]]]]);
pruefe($wp->vars['Grenze'] === null, 'Grenze ohne Beschreibung noch nicht zugeordnet');
$sende(['Event' => 'data', 'function' => 'loadControlLimitDescriptionListData', 'data' => ['loadControlLimitDescriptionData' => [
    ['limitId' => 6, 'limitDirection' => 'produce', 'scopeType' => 'activePowerLimit'],
    ['limitId' => 7, 'limitDirection' => 'consume', 'scopeType' => 'activePowerLimit', 'limitType' => 'signDependentAbsValueLimit']]]]);
$sende(['Event' => 'data', 'function' => 'loadControlLimitListData', 'data' => ['loadControlLimitData' => [
    ['limitId' => 6, 'isLimitActive' => true, 'value' => ['number' => -1, 'scale' => 0]],
    ['limitId' => 7, 'isLimitChangeable' => true, 'isLimitActive' => true, 'value' => ['number' => 4200, 'scale' => 0], 'timePeriod' => ['endTime' => 'PT1H']]]]]);
pruefe($wp->vars['Grenze'] === 4200.0 && $wp->vars['GrenzeAktiv'] === true, 'LPC: Bezugsgrenze (limitId 7, consume) 4200 W aktiv, Einspeisegrenze übergangen');

$f = $wp->GetFunctions()[0];
pruefe($f['Type'] === 'heatpump' && $f['contractVersion'] === '1.18' && $f['Measured'] === true && $f['PowerID'] === $wp->ids['Leistung']
    && $f['EnergyID'] === $wp->ids['Energie'] && $f['energyMeasured'] === true && $f['reachable'] === true && $f['managedBy'] === 'none', 'Vertrag heatpump 1.18: gemessene Leistung und Energie der Wärmepumpe, erreichbar');
pruefe($f['function'] === 'heatpump' && $f['powerID'] === $wp->ids['Leistung'] && $f['energyImportID'] === $wp->ids['Energie'] && $f['energyExportID'] === 0
    && $f['energyKind'] === 'counter' && $f['instanceID'] === 30003 && $f['label'] !== '', 'Vertrag: dieselben Felder in MeterHub-Form (function/powerID/energyImportID) für den Energiefluss');
pruefe(($GLOBALS['PROFILE']['NRG.Watt']['_suffix'] ?? '') === ' W' && ($GLOBALS['PROFILE']['NRG.kWh']['_stellen'] ?? -1) === 1, 'Gemeinsame Profile NRG.Watt/NRG.kWh wie MeterHub angelegt');
// Hochgerechneter Zählerstand (rein): Treppenkurve, Lücken nicht überbrückt, unplausible Werte ignoriert.
[$k, $z] = WPEEBUSHub::energieRechnen([], 1000, 2000.0, true, true);
pruefe($k === 0.0 && $z === ['t' => 1000, 'p' => 2000.0], 'Hochrechnung: erster Wert startet nur');
[$k, $z] = WPEEBUSHub::energieRechnen($z, 1060, null, true, true);
pruefe(abs($k - 2000 * 60 / 3600000) < 1e-9 && $z['p'] === 2000.0, 'Hochrechnung: Takt ohne neue Meldung zählt den letzten Wert weiter (60 s × 2 kW)');
[$k, $z] = WPEEBUSHub::energieRechnen($z, 1120, 500.0, true, true);
pruefe(abs($k - 2000 * 60 / 3600000) < 1e-9 && $z['p'] === 500.0, 'Hochrechnung: neue Leistung gilt erst ab jetzt (Treppenkurve)');
[$k, $z2] = WPEEBUSHub::energieRechnen($z, 1120 + 3600, null, true, true);
pruefe($k === 0.0 && $z2['t'] === 4720, 'Hochrechnung: Lücke über 10 min wird nicht überbrückt');
[$k, $z3] = WPEEBUSHub::energieRechnen($z, 1180, 5.0e7, true, true);
pruefe(abs($k - 500 * 60 / 3600000) < 1e-9 && $z3['p'] === 500.0, 'Hochrechnung: unplausibler Wert (50 MW) wird nicht übernommen');
[$k, $z4] = WPEEBUSHub::energieRechnen($z, 1180, null, false, true);
pruefe($k > 0 && $z4 === [], 'Hochrechnung: bei Trennung bis jetzt zählen, dann aufhören');

// WP-Steuermuster 1.18: Stellgrößen als Variablen (EMS schaltet per RequestAction), Steuerhoheit aus der Instanz.
pruefe($f['controllable'] === true && $f['powerLimitID'] === $wp->ids['Grenze'] && $f['powerLimitActiveID'] === $wp->ids['GrenzeAktiv']
    && $f['optionalRunID'] === 0, 'Vertrag 1.18: steuerbar, Grenze/Grenze aktiv als Stellgrößen; ohne OHPCF-Angebot kein optionalRunID');
$wp->props['Steuerhoheit'] = 'other';
pruefe($wp->GetFunctions()[0]['managedBy'] === 'other', 'Steuerhoheit „anderes System“ im Vertrag (EMS hält sich heraus)');
$wp->props['Steuerhoheit'] = 'kaputt';
pruefe($wp->GetFunctions()[0]['managedBy'] === 'none', 'Unbekannte Steuerhoheit gilt als „keine Vorgabe“');
$wp->props['Steuerhoheit'] = 'none';

$st = json_decode($wp->GetState(), true);
pruefe($st['lpc'] === true && $st['mpc'] === true && $st['entity'] === [1], 'GetState: LPC und MPC vorhanden');

// OHPCF: Verdichter-Entität mit SmartEnergyManagementPs (eigene Entität neben der Wärmepumpe)
pruefe(($GLOBALS['PROFILE']['WPEEB.OhpcfState'][3] ?? '') === 'läuft', 'Profil WPEEB.OhpcfState angelegt (3 = läuft)');
pruefe($wp->SetOptionalRun(true) === false, 'Ohne Angebot: optionaler Lauf nicht möglich');
$sende(['Event' => 'remoteEntity', 'state' => 'added', 'entity' => [2], 'type' => 'Compressor', 'features' => [['type' => 'SmartEnergyManagementPs']]]);
$fo = $wp->GetFunctions()[0];
pruefe($fo['optionalRunID'] === $wp->ids['OhpcfLauf'] && $fo['optionalRunPowerMaxID'] === $wp->ids['OhpcfLeistungMax'], 'Vertrag 1.18: mit OHPCF-Angebot optionalRunID und Leistungen gesetzt');
pruefe(json_decode($wp->attrs['Geraet'], true)['entity'] === [1] && json_decode($wp->attrs['Geraet'], true)['ohpcfEntity'] === [2], 'Verdichter [2] als OHPCF-Entität, Wärmepumpe [1] bleibt Haupt-Entität');
$ohpcf = function (string $state, bool $pause = true) {
    return ['alternatives' => [['powerSequence' => [[
        'description' => ['sequenceId' => 7, 'powerUnit' => 'W'],
        'state' => ['sequenceId' => 7, 'state' => $state],
        'operatingConstraintsInterrupt' => ['sequenceId' => 7, 'isPausable' => $pause, 'isStoppable' => true],
        'operatingConstraintsDuration' => ['sequenceId' => 7, 'activeDurationMin' => 'PT2M', 'pauseDurationMin' => 'PT1M'],
        'powerTimeSlot' => [['valueList' => ['value' => [
            ['sequenceId' => 7, 'valueType' => 'power', 'value' => ['number' => 24, 'scale' => 2]],
            ['sequenceId' => 7, 'valueType' => 'powerMax', 'value' => ['number' => 3000, 'scale' => 0]]]]]],
    ]]]]];
};
$sende(['Event' => 'data', 'entity' => [2], 'function' => 'smartEnergyManagementPsData', 'data' => $ohpcf('inactive')]);
pruefe($wp->vars['OhpcfZustand'] === 1 && $wp->vars['OhpcfLeistung'] === 2400.0 && $wp->vars['OhpcfLeistungMax'] === 3000.0 && $wp->vars['OhpcfLauf'] === false,
    'OHPCF: angeboten, 2400 W erwartet (Skala 2), 3000 W höchstens');
$sende(['Event' => 'data', 'entity' => [2], 'function' => 'smartEnergyManagementPsData', 'data' => $ohpcf('running', false)]);
pruefe($wp->vars['OhpcfZustand'] === 3 && $wp->vars['OhpcfLauf'] === true, 'OHPCF: läuft, Schalter an');
pruefe($wp->PauseOptionalRun() === false && strpos(end($wp->log), 'Pausieren') !== false, 'Nicht pausierbar: Pausieren abgelehnt, Meldung');
pruefe($wp->StartOptionalRun(0) === false, 'Läuft schon: Starten abgelehnt');
$sende(['Event' => 'data', 'entity' => [2], 'function' => 'smartEnergyManagementPsData', 'data' => ['alternatives' => []]]);
pruefe($wp->vars['OhpcfZustand'] === 0, 'Kein Angebot mehr: Zustand „nicht angeboten“');
$sende(['Event' => 'data', 'entity' => [2], 'function' => 'smartEnergyManagementPsData', 'data' => $ohpcf('inactive')]);

// Grenze schreiben: ohne Verbindung lehnt das Gateway ab
pruefe($wp->SetPowerLimit(3000, 600) === false && strpos(end($wp->log), 'not connected') !== false, 'Grenze ohne SHIP-Verbindung: Gateway lehnt ab, Meldung im Protokoll');
$sende(['Event' => 'result', 'msgCounter' => 9, 'function' => 'loadControlLimitListData', 'errorNumber' => 7, 'description' => 'write not allowed']);
pruefe(strpos(end($wp->log), 'abgelehnt') !== false, 'Abgelehnter Schreibbefehl wird gemeldet');

// evcc-Regeln OHPCF: Ein/Aus-Wunsch, Sofortreaktion auf ein neues Angebot, Reboost-Timer, Testknöpfe setzen den Wunsch.
$wp->log = [];
$wp->SetOptionalRun(true);
pruefe($wp->attrs['OhpcfWunsch'] === true && $wp->timers['Reboost'] === 600000, 'Optionaler Lauf an: Wunsch gemerkt, Reboost alle 10 min');
$wp->log = [];
$sende(['Event' => 'data', 'entity' => [2], 'function' => 'smartEnergyManagementPsData', 'data' => $ohpcf('inactive')]);
pruefe(strpos(implode("\n", $wp->log), 'nicht gesendet') !== false, 'Neues Angebot bei „Ein“: sofort einplanen versucht (evcc #31549; Gateway ohne Verbindung lehnt ab)');
$wp->log = [];
$sende(['Event' => 'data', 'entity' => [2], 'function' => 'smartEnergyManagementPsData', 'data' => $ohpcf('running')]);
pruefe($wp->log === [], 'Läuft schon: nichts gesendet (nur bei Zustandswechsel)');
$wp->OptionalTesten('abort');
pruefe($wp->attrs['OhpcfWunsch'] === false && $wp->timers['Reboost'] === 0, 'Testknopf „Abbrechen“ schaltet den Wunsch aus, kein Reboost');
$wp->log = [];
$sende(['Event' => 'data', 'entity' => [2], 'function' => 'smartEnergyManagementPsData', 'data' => $ohpcf('inactive')]);
pruefe($wp->log === [], 'Wunsch aus: ein neues Angebot wird nicht eingeplant');

// [LPC-913]: Gewünschte Grenze an eine neu erschienene Grenze erneut senden, bis zur Annahme.
$wp->SetPowerLimit(3500, 0);
pruefe((json_decode($wp->attrs['GrenzeWunsch'], true)['watt'] ?? 0) == 3500, 'Grenze 3500 W als Wunsch gemerkt');
$sende(['Event' => 'data', 'function' => 'loadControlLimitDescriptionListData', 'data' => ['loadControlLimitDescriptionData' => [
    ['limitId' => 9, 'limitDirection' => 'consume', 'scopeType' => 'activePowerLimit']]]]);
pruefe(isset(json_decode($wp->attrs['GrenzeBestaetigung'], true)['seit']) && $wp->timers['GrenzeBestaetigen'] === 1000, 'Neue Grenze erschienen: Wunsch erneut gesendet, nächster Versuch in 1 s');
$wp->GrenzeBestaetigen();
pruefe($wp->timers['GrenzeBestaetigen'] === 2000, 'Wiederholung mit wachsender Wartezeit (2 s)');
$sende(['Event' => 'result', 'msgCounter' => 11, 'function' => 'loadControlLimitListData', 'errorNumber' => 0]);
pruefe($wp->attrs['GrenzeBestaetigung'] === '{}' && $wp->timers['GrenzeBestaetigen'] === 0, 'Grenze angenommen: Wiederholung beendet');

// MDT: Warmwassertemperatur vom Warmwasserkreis, getrennt von den Messwerten der Wärmepumpe.
$sende(['Event' => 'remoteEntity', 'state' => 'added', 'entity' => [4], 'type' => 'DHWCircuit', 'features' => [['type' => 'Measurement']]]);
$sende(['Event' => 'data', 'entity' => [4], 'function' => 'measurementDescriptionListData', 'data' => ['measurementDescriptionData' => [
    ['measurementId' => 0, 'measurementType' => 'temperature', 'unit' => 'degC', 'scopeType' => 'dhwTemperature']]]]);
$leistungVorher = $wp->vars['Leistung'];
$sende(['Event' => 'data', 'entity' => [4], 'function' => 'measurementListData', 'data' => ['measurementData' => [['measurementId' => 0, 'value' => ['number' => 525, 'scale' => -1]]]]]);
pruefe(($wp->vars['Warmwasser'] ?? null) === 52.5 && $wp->vars['Leistung'] === $leistungVorher && $wp->GetFunctions()[0]['dhwTempID'] === $wp->ids['Warmwasser'],
    'MDT: Warmwasser 52,5 °C (eigene Entität, Messwert-ID 0 stört die Leistung nicht), im Vertrag als dhwTempID');
$anm = json_decode($gw->attrs['Anmeldungen'], true)['30003'] ?? [];
pruefe(in_array('monitoringOfDhwTemperature', array_column($anm['useCases'] ?? [], 'name'), true) && in_array('DHWCircuit', $anm['remote']['entityTypes'] ?? [], true),
    'Angemeldet mit MDT, Warmwasserkreis wird mit abonniert');

// Vaillant VR940f (Forum 144877 #14): beschreibt acEnergyConsumed, schickt aber nie einen Wert -> trotzdem hochrechnen.
$sende(['Event' => 'data', 'function' => 'measurementDescriptionListData', 'data' => ['measurementDescriptionData' => [
    ['measurementId' => 0, 'measurementType' => 'power', 'unit' => 'W', 'scopeType' => 'acPowerTotal']]]]); // früheren Zählerstand vergessen
$sende(['Event' => 'data', 'function' => 'measurementDescriptionListData', 'data' => ['measurementDescriptionData' => [
    ['measurementId' => 0, 'measurementType' => 'power', 'unit' => 'W', 'scopeType' => 'acPowerTotal'],
    ['measurementId' => 4, 'measurementType' => 'energy', 'unit' => 'Wh', 'scopeType' => 'acEnergyConsumed']]]]);
$sende(['Event' => 'data', 'function' => 'measurementListData', 'data' => ['measurementData' => [['measurementId' => 0, 'value' => ['number' => 1500, 'scale' => 0]]]]]);
pruefe($wp->GetFunctions()[0]['energyMeasured'] === false && $wp->GetFunctions()[0]['EnergyID'] === $wp->ids['EnergieHochgerechnet'],
    'Energiezähler beschrieben, aber nie ein Wert: hochgerechneter Zählerstand gilt (Vaillant)');
// Befristete Grenze: Ende als Zeitpunkt sichtbar.
$sende(['Event' => 'data', 'function' => 'loadControlLimitListData', 'data' => ['loadControlLimitData' => [
    ['limitId' => 9, 'isLimitChangeable' => true, 'isLimitActive' => true, 'value' => ['number' => 4200, 'scale' => 0], 'timePeriod' => ['endTime' => 'PT3600S']]]]]);
pruefe(abs(($wp->vars['GrenzeBis'] ?? 0) - (time() + 3600)) <= 2, 'Befristete Grenze: „Leistungsgrenze endet um“ = jetzt + 1 h');
$sende(['Event' => 'data', 'function' => 'loadControlLimitListData', 'data' => ['loadControlLimitData' => [
    ['limitId' => 9, 'isLimitChangeable' => true, 'isLimitActive' => false, 'value' => ['number' => 4200, 'scale' => 0]]]]]);
pruefe($wp->vars['GrenzeBis'] === 0, 'Grenze aufgehoben: Ende 0');

// Wärmepumpe ohne eigenen Zählerstand (wie die Vaillant VR940f, Thread 144877 #10): Zählerstand hochrechnen.
$sende(['Event' => 'data', 'function' => 'measurementDescriptionListData', 'data' => ['measurementDescriptionData' => [
    ['measurementId' => 0, 'measurementType' => 'power', 'unit' => 'W', 'scopeType' => 'acPowerTotal']]]]);
$f = $wp->GetFunctions()[0];
pruefe($f['EnergyID'] === $wp->ids['EnergieHochgerechnet'] && $f['energyImportID'] === $f['EnergyID'] && $f['energyMeasured'] === false && $f['Measured'] === true,
    'Ohne Zählerstand der Wärmepumpe: Vertrag meldet den hochgerechneten (energyMeasured=false), Leistung bleibt gemessen');
$wp->attrs['EnergieRechnung'] = json_encode(['t' => time() - 120, 'p' => 3000.0]);
$wp->vars['EnergieHochgerechnet'] = 10.0;
$wp->EnergieTakt();
pruefe(abs($wp->vars['EnergieHochgerechnet'] - 10.1) < 0.002, 'Takt: 2 min × 3 kW = 0,1 kWh aufaddiert');
$sende(['Event' => 'data', 'function' => 'measurementListData', 'data' => ['measurementData' => [['measurementId' => 0, 'value' => ['number' => 1880, 'scale' => 0]]]]]);
pruefe((float)json_decode($wp->attrs['EnergieRechnung'], true)['p'] === 1880.0, 'Neue Leistung 1880 W gilt ab jetzt für die Hochrechnung');
// Archivierung: nur auf Klick, Leistung als Standard, Zählerstand als Zähler.
$GLOBALS['ARCHIV'] = 99001;
$html = formular($wp);
pruefe(strpos($html, 'Archivierung für diese Variablen einschalten') !== false && ($GLOBALS['LOG'][$wp->ids['Leistung']][0] ?? false) === false, 'Archiv fehlt: Knopf im Formular, nichts von selbst eingeschaltet');
$antwort = $wp->ArchivierungEinschalten();
pruefe(($GLOBALS['LOG'][$wp->ids['Leistung']] ?? []) === [true, 0] && ($GLOBALS['LOG'][$wp->ids['EnergieHochgerechnet']] ?? []) === [true, 1] && strpos($antwort, '2') !== false,
    'Knopf: Leistung (Standard) und hochgerechneter Zählerstand (Zähler) archiviert');
pruefe(strpos(formular($wp), 'Leistung und Zählerstand werden archiviert') !== false, 'Danach: Formular bestätigt die Archivierung');
$GLOBALS['ARCHIV'] = 0;

$html = formular($wp);
pruefe(strpos($html, 'Wozu dieses Modul') !== false && strpos($html, 'Über dieses Modul') !== false && strpos($html, 'Formular: Aufbau') !== false, 'Formular: Rahmen nach Konvention (Zweck, Aufbau, Über dieses Modul)');
pruefe(strpos($html, 'Wärmepumpe verbunden: HeatPumpAppliance') !== false && strpos($html, 'Leistungsbegrenzung (LPC): vorhanden') !== false
    && strpos($html, 'PV-Überschuss (OHPCF): vorhanden') !== false, 'Formular: Verbindung und Anwendungsfälle (LPC, MPC, OHPCF) sichtbar');
pruefe(strpos($html, 'Optionaler Lauf: angeboten, pausierbar: ja, abbrechbar: ja, Mindestlauf PT2M') !== false && strpos($html, 'WPEEB_OptionalTesten') !== false, 'Formular: OHPCF-Angebot und Testknöpfe');
pruefe(strpos($html, 'Letzte Antwort der Wärmepumpe') !== false && strpos($html, 'angenommen') !== false, 'Formular: letzte Antwort der Wärmepumpe sichtbar (zuletzt angenommen)');
$aktionen = json_decode($wp->GetConfigurationForm(), true)['actions'];
pruefe(strpos(json_encode($aktionen), 'WPEEB_GrenzeTesten') !== false, 'Grenze testen steht im Aktionsbereich');

$sende(['Event' => 'remoteEntity', 'state' => 'removed', 'entity' => [3]]);
pruefe($wp->vars['Erreichbar'] === true, 'Wegfall einer fremden Entität ändert nichts');
$sende(['Event' => 'connection', 'state' => 'disconnected']);
pruefe($wp->vars['Erreichbar'] === false && $wp->status === 203 && $wp->GetFunctions()[0]['reachable'] === false, 'Verbindung weg: nicht erreichbar, Status 203');

// Zweisprachigkeit
require_once __DIR__ . '/i18n-texte.php';
$quellen = wpeebQuelltexte($wurzel);
$fehlt = array_diff_key($quellen, $GLOBALS['LOCALE']['WPEEBUSHub']);
pruefe($fehlt === [], 'Zweisprachig: jeder Quelltext steht in locale.json' . ($fehlt ? ' – fehlt: ' . implode(' | ', array_keys($fehlt)) : ''));
$GLOBALS['LANG'] = 'en';
$wp->name = 'Heat pump';
$gw->name = 'EEBUS gateway';
$en = formular($wp);
pruefe(!preg_match('/Wärmepumpe|Leistungsgrenze|Verbindung/u', $en), 'Formular englisch: kein deutscher Text');
$GLOBALS['LANG'] = 'de';

// ---------------------------------------------------------------------------
// Teil 2: live gegen die simulierte Wärmepumpe
$lv = array_search('--live', $argv, true);
if ($lv !== false) {
    [$bin, $vCert, $vKey] = array_slice($argv, $lv + 1, 3);
    echo "\nTeil 2: live gegen simulierte Wärmepumpe (eebus-go, LPC + MPC)\n";
    $port = 4715;
    $sock2 = new FakeSocket(31001);
    $gw2 = new EEBUSGateway(31002);
    $gw2->modul = '{32809AEC-3A90-455F-8FE4-188DF37D594F}';
    $gw2->parentId = 31001;
    $gw2->Create();
    $gw2->ApplyChanges();
    $wp2 = new WPEEBUSHub(31003);
    $wp2->name = 'Wärmepumpe (live)';
    $wp2->modul = '{731C4932-48B3-4360-BFED-1CA034978BCB}';
    $wp2->parentId = 31002;
    $wp2->Create();
    $wp2->ApplyChanges();
    $log = sys_get_temp_dir() . '/wpeeb-sim.log';
    $proc = proc_open([$bin, '-port', (string)$port, '-certpath', $vCert, '-keypath', $vKey, '-remoteski', $gw2->attrs['EigenerSki'], '-type', 'heatpump', '-ohpcf'],
        [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    sleep(2);
    try {
        $gw2->props['Host'] = '127.0.0.1';
        $gw2->props['Port'] = $port;
        $gw2->props['ErwarteterSki'] = (string)EEBGWCert::skiAusZertifikat((string)file_get_contents($vCert));
        $gw2->ApplyChanges();
        $antwort = $gw2->GegenstellePruefen();
        pruefe(strpos($antwort, '✅') === 0, 'Gateway: Gegenstelle geprüft und übernommen');
        $dir = sys_get_temp_dir();
        file_put_contents("$dir/wpeeb-c.pem", base64_decode($sock2->config['Certificate']));
        file_put_contents("$dir/wpeeb-k.pem", base64_decode($sock2->config['PrivateKey']));
        file_put_contents("$dir/wpeeb-ca.pem", base64_decode($sock2->config['CertificateAuthority']));
        $s = stream_socket_client("tls://127.0.0.1:$port", $en, $es, 5, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => [
            'local_cert' => "$dir/wpeeb-c.pem", 'local_pk' => "$dir/wpeeb-k.pem", 'cafile' => "$dir/wpeeb-ca.pem",
            'verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => true,
            'ciphers' => 'ECDHE-ECDSA-AES128-SHA256', 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT]]));
        pruefe($s !== false, 'TLS 1.2 mit Pflicht-Chiffre und Pinning');
        $GLOBALS['BRUECKE'] = $s;
        stream_set_blocking($s, false);
        $gw2->MessageSink(time(), 31001, IM_CHANGESTATUS, [IS_ACTIVE, 0]);
        $phase = 'warten';
        $ende = time() + 120;
        while (time() < $ende && $phase !== 'fertig') {
            $r = [$s]; $w = $x = null;
            if (stream_select($r, $w, $x, 1) > 0) {
                $d = fread($s, 65536);
                if ($d === '' && feof($s)) { break; }
                $gw2->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => mb_convert_encoding($d, 'UTF-8', 'ISO-8859-1')]));
            }
            $gw2->Takt();
            if ($phase === 'warten' && $wp2->status === 102 && $wp2->vars['Leistung'] !== null && $wp2->vars['Grenze'] !== null) {
                pruefe(true, 'Wärmepumpe angebunden: Leistung ' . $wp2->vars['Leistung'] . ' W, Grenze ' . $wp2->vars['Grenze'] . ' W (aktiv: ' . json_encode($wp2->vars['GrenzeAktiv']) . ')');
                $phase = 'schreiben';
                pruefe($wp2->SetPowerLimit(3500, 900) === true, 'WPEEB_SetPowerLimit(3500 W, 15 min) gesendet');
            } elseif ($phase === 'schreiben' && $wp2->vars['GrenzeAktiv'] === true && $wp2->vars['Grenze'] === 3500.0) {
                $phase = 'aufheben';
                pruefe(true, 'Wärmepumpe bestätigt die Grenze 3500 W');
                pruefe($wp2->ClearPowerLimit() === true, 'WPEEB_ClearPowerLimit gesendet');
            } elseif ($phase === 'aufheben' && $wp2->vars['GrenzeAktiv'] === false && $wp2->vars['Leistung'] == 800.0 && $wp2->vars['OhpcfZustand'] === 1) {
                $phase = 'ohpcf';
                pruefe(true, 'Grenze aufgehoben, Grundlast 800 W; Verdichter bietet optionalen Lauf an (' . $wp2->vars['OhpcfLeistung'] . ' W)');
                pruefe($wp2->SetOptionalRun(true) === true, 'WPEEB_SetOptionalRun(true): Lauf sofort einplanen');
            } elseif ($phase === 'ohpcf' && $wp2->vars['OhpcfZustand'] === 3 && $wp2->vars['Leistung'] == 3200.0) {
                $phase = 'ohpcfPause';
                pruefe(true, 'Verdichter läuft, gemessene Leistung 3200 W (800 + 2400)');
                pruefe($wp2->SetOptionalRun(false) === true, 'WPEEB_SetOptionalRun(false): pausieren');
            } elseif ($phase === 'ohpcfPause' && $wp2->vars['OhpcfZustand'] === 4 && $wp2->vars['Leistung'] == 800.0) {
                $phase = 'ohpcfWeiter';
                pruefe(true, 'Verdichter pausiert, Leistung wieder 800 W');
                pruefe($wp2->ResumeOptionalRun() === true, 'WPEEB_ResumeOptionalRun');
            } elseif ($phase === 'ohpcfWeiter' && $wp2->vars['OhpcfZustand'] === 3) {
                $phase = 'ohpcfAbbruch';
                pruefe(true, 'Verdichter läuft wieder');
                pruefe($wp2->AbortOptionalRun() === true, 'WPEEB_AbortOptionalRun');
            } elseif ($phase === 'ohpcfAbbruch' && $wp2->vars['OhpcfZustand'] === 1 && $wp2->vars['Leistung'] == 800.0) {
                $phase = 'fertig';
                pruefe(true, 'Abgebrochen: Lauf wieder angeboten, Leistung 800 W');
            }
        }
        pruefe($phase === 'fertig', 'Ablauf vollständig (Stand: ' . $phase . ')');
        $erg = json_decode($wp2->attrs['LetztesErgebnis'], true);
        pruefe(($erg['errorNumber'] ?? -1) === 0, 'Letzte Antwort der Wärmepumpe: angenommen');
        pruefe((float)$wp2->vars['Energie'] > 0 && $wp2->vars['Nennleistung'] === 32000.0 && $wp2->vars['Ausfallgrenze'] === 4200.0,
            'Energie ' . $wp2->vars['Energie'] . ' kWh, Nennleistung ' . $wp2->vars['Nennleistung'] . ' W, Ausfallgrenze ' . $wp2->vars['Ausfallgrenze'] . ' W');
        $simLog = (string)file_get_contents($log);
        pruefe(strpos($simLog, 'SIM limit 3500 W active true') !== false && strpos($simLog, 'SIM limit 3500 W active false') !== false, 'Simulator: Grenze gesetzt und wieder aufgehoben');
        pruefe(strpos($simLog, 'SIM ohpcf scheduled, start now') !== false && strpos($simLog, 'SIM ohpcf state paused') !== false && strpos($simLog, 'SIM ohpcf aborted') !== false,
            'Simulator: Lauf eingeplant, pausiert, fortgesetzt, abgebrochen');
        fclose($s);
        unset($GLOBALS['BRUECKE']);
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}

echo "\n" . $GLOBALS['OK'] . ' bestanden, ' . $GLOBALS['FEHLER'] . " fehlgeschlagen\n";
exit($GLOBALS['FEHLER'] > 0 ? 1 : 0);
