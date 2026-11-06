# Changelog

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
