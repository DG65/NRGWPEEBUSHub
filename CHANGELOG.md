# Changelog

## 0.5.0 (Build 8, 09.11.2026)

- Leistungsbegrenzung (LPC, Energy Guard) mit LoadControl- und DeviceConfiguration-Client auf einer eigenen
  GridGuard-Entität wie evcc (Kind-Schnittstelle 1.3, Gateway 0.7.0). Messwerte, OHPCF und Warmwasser bleiben
  auf der CEM-Entität. Mit älterem Gateway wird wie bisher alles auf einer Entität angemeldet.

## 0.4.1 (Build 7, 09.11.2026)

- Behoben: Ob die Wärmepumpe einen eigenen Zählerstand liefert, entscheidet jetzt ein tatsächlich empfangener Wert
  (`energieGemeldet`), nicht die Beschreibung. Die Vaillant VR940f beschreibt `acEnergyConsumed`, schickt aber
  nie einen Wert - der hochgerechnete Zählerstand blieb deshalb stehen (Forum 144877 #14).
- Neu: Variable `GrenzeBis` (~UnixTimestamp, „Leistungsgrenze endet um“) aus `timePeriod.endTime` der aktiven Grenze.

## 0.4.0 (Build 6, 09.11.2026)

- WP-Steuermuster (SUITE.md, heatpump 1.18, Dietmar 09.11.2026): `optionalRunID` (OhpcfLauf, schaltbar),
  `optionalRunPowerID`/`optionalRunPowerMaxID`, `powerLimitID`/`powerLimitActiveID` (Grenze/GrenzeAktiv) - je
  nur, wenn die Wärmepumpe OHPCF bzw. LPC anbietet, sonst 0 -, `controllable` (verbunden und steuerbar).
- `managedBy` aus der neuen Eigenschaft `Steuerhoheit` (none/ems/other, Standard none) statt fest `wpeeb`.

## 0.3.0 (Build 5, 09.11.2026)

- Neu: Hochgerechneter Zählerstand `EnergieHochgerechnet` (NRG.kWh), wenn die Wärmepumpe keinen eigenen
  liefert (MPC ohne `acEnergyConsumed`, z. B. Vaillant VR940f, Thread 144877 #10). Treppenkurve (EEBUS
  meldet nur Änderungen), Takt 60 s, Lücken über 10 min werden nicht überbrückt, Werte über 1 MW ignoriert
  (Muster MeterHub CalculatedEnergy).
- Vertrag `heatpump` 1.17 (additiv): `EnergyID` zeigt auf den Zählerstand der Wärmepumpe oder den
  hochgerechneten, `energyMeasured` (false = hochgerechnet, wie MeterHub 1.3), dazu die MeterHub-Felder
  `function` `heatpump`, `label`, `instanceID`, `powerID`, `energyImportID`, `energyExportID`, `energyKind`
  `counter`, `measured` für den Energiefluss (+ = Verbrauch).
- Profile: Leistungen `NRG.Watt`, Energie `NRG.kWh` (gemeinsame Verbund-Profile wie MeterHub) statt
  `~Watt`/`~Electricity`.
- Neu: Bereich „Energie und Archiv“ mit Knopf zum Einschalten der Archivierung von Leistung (Standard) und
  Zählerstand (Zähler) - nur auf Klick (Store-Checkliste 19, wie MeterHubVirtual).
- evcc-Parität OHPCF (charger/eebus-ohpcf.go): `WPEEB_SetOptionalRun` ist ein Ein/Aus-Wunsch
  (`OhpcfWunsch`); bei „Ein“ wird ein neu angebotener Lauf sofort eingeplant (evcc #31549) und alle
  `ReboostMinuten` (Standard 10, 0 = aus) erneut; gesendet wird nur bei Zustandswechsel; Aus = pausieren, wenn
  erlaubt, sonst abbrechen, wenn erlaubt. Testknöpfe setzen den Wunsch mit.
- [LPC-913] wie evcc AssertLimit: die zuletzt gewünschte Grenze (`GrenzeWunsch`) wird an eine neu erschienene
  Grenze erneut gesendet, Wartezeit 1-2-4-8-16 s, höchstens 50 s, bis ein `result` ohne Fehler kommt.
- Neu: MDT (Monitoring of DHW Temperature) als Monitoring Appliance; Warmwasserkreis (`DHWCircuit`) wird mit
  abonniert, Daten nach Entität getrennt; Variable `Warmwasser` (NRG.Celsius) entsteht erst mit dem ersten Wert,
  im Vertrag `dhwTempID`.

## 0.2.2 (Build 4, 06.11.2026)

- Rückmeldungen: Der Knopf im Formular führt jetzt zum Forum-Thread des Moduls statt zu GitHub.

## 0.2.1 (Build 3, 06.11.2026)

- Name im Store und in der Konsole jetzt „NRG-Stack WPEEBUSHub“ wie die anderen Wärmepumpen-Module
  (Aliase „Wärmepumpe EEBUS“ / „Heat pump EEBUS“ zum Finden). Das EEBUS-Gateway steht in der Anleitung
  als Voraussetzung.

## 0.2.0 (Build 2, 06.11.2026)

- ☀️ Neu: **OHPCF** (optionaler Verdichterlauf für PV-Überschuss). Anmeldung zusätzlich als CEM für
  `optimizationOfSelfConsumptionByHeatPumpCompressorFlexibility` mit Client-Feature
  `SmartEnergyManagementPs`; die Verdichter-Entität (eebus-go: `Compressor`) wird neben der Entität mit
  Grenze und Messwerten geführt.
- Variablen „Optionaler Verdichterlauf“ (Profil `WPEEB.OhpcfState`: nicht angeboten, angeboten,
  eingeplant, läuft, pausiert, beendet), „Optionalen Verdichterlauf ausführen“ (schaltbar), erwartete und
  höchste Leistung des Laufs.
- `WPEEB_SetOptionalRun(bool)` (Ein/Aus wie evcc: an = sofort einplanen bzw. fortsetzen, aus = pausieren,
  sonst abbrechen), `WPEEB_StartOptionalRun(int $DelaySeconds)`, `WPEEB_PauseOptionalRun`,
  `WPEEB_ResumeOptionalRun`, `WPEEB_AbortOptionalRun`; Pausieren/Abbrechen nur, wenn die Wärmepumpe es
  erlaubt. Schreibweise wie eebus-go `cem/ohpcf` (Startzeit bzw. Zustand der Leistungsfolge).
- Formular: Anwendungsfälle zeigen OHPCF, neuer Bereich „Optionalen Verdichterlauf testen“.
- Braucht NRG-Stack EEBUS-Gateway 0.5.0 (Rückfall ohne `partial`).
- Geprüft gegen einen selbst gebauten Verdichter-Simulator (eebus-go/spine-go, `-ohpcf`): anbieten,
  einplanen, laufen (+2400 W gemessen), pausieren, fortsetzen, abbrechen.

## 0.1.0 (Build 1, 06.11.2026)

- Erste Fassung: Wärmepumpe über EEBUS als Kind des NRG-Stack EEBUS-Gateways (ab 0.4.0,
  Kind-Schnittstelle 1.1, Rolle Energiemanagement).
- Anmeldung als Energy Guard (LPC) und Monitoring Appliance (MPC); das Gateway bindet die
  Wärmepumpen-Entität (`HeatPumpAppliance`, `Compressor`, `Generic`) selbst an.
- Variablen Erreichbar, Leistung, Energie, Grenze, Grenze aktiv, Ausfallgrenze, maximale Leistungsaufnahme.
- `WPEEB_SetPowerLimit`, `WPEEB_ClearPowerLimit`; Grenze auch über die Variablen schaltbar und im
  Aktionsbereich testbar.
- `WPEEB_GetFunctions`: Wärmepumpen-Vertrag 1.15 wie WPModbusHub/WPHub/HeishaMon, mit gemessener
  Leistung und Energie.
- Formular nach NRG-Stack-Konvention mit „Formular: Aufbau“, zweisprachig Deutsch/Englisch.
- Geprüft gegen eebus-go `evse` mit MPC als Wärmepumpe: Grenze 3500 W gesetzt, Leistung sinkt,
  Grenze aufgehoben, Leistung steigt wieder.
