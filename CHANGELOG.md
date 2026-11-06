# Changelog

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
