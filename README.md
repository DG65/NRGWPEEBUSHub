# NRG-Stack WPEEBUSHub

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Modul Version](https://img.shields.io/badge/Modul%20Version-0.2.0-blue.svg)](library.json)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.0%2B-green.svg)](https://www.symcon.de)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey.svg)](LICENSE)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue.svg)](https://paypal.me/DietmarGureth)

**Deutsch** · [English](README.en.md)

Bindet eine **Wärmepumpe mit EEBUS-Schnittstelle** an Symcon an: elektrische Leistung und Energie
lesen (EEBUS-Anwendungsfall MPC), die Leistungsaufnahme begrenzen (LPC) und einen optionalen
Verdichterlauf für PV-Überschuss starten (OHPCF). Die Verbindung selbst
(Zertifikat, Kopplung, Verschlüsselung) übernimmt das **NRG-Stack EEBUS-Gateway**, an dem diese
Instanz als Kind hängt.

> Stand 0.2.0: geprüft gegen eine simulierte Wärmepumpe (eebus-go `evse`, erweitert um Messwerte und
> einen Verdichter mit OHPCF). An keiner echten Wärmepumpe getestet.

## Welche Wärmepumpen?

Laut Herstellerangaben und Praxisberichten (u. a. evcc, gridX) bieten EEBUS mit Leistung/Energie und
meist auch Leistungsbegrenzung u. a.: Viessmann Vitocal (über Vitoconnect), Bosch/Buderus mit MX400,
NIBE S-Serie, Vaillant über VR940f (Messwerte, ohne LPC). Wolf Link baut die Verbindung nur selbst
auf: dafür das Gateway (ab 0.5.0) auf „Das Gerät verbindet sich zum Gateway“ stellen. Rückmeldungen
mit echten Geräten sind sehr willkommen.

## Einrichtung

1. Instanz „NRG-Stack Wärmepumpe (EEBUS)“ anlegen. Symcon fragt nach der übergeordneten Instanz:
   **NRG-Stack EEBUS-Gateway** wählen bzw. neu anlegen - je Wärmepumpe ein eigenes Gateway.
2. Im Gateway Adresse und Port der Wärmepumpe eintragen (Hersteller-App/Anleitung),
   „Gegenstelle prüfen“ und ihren SKI übernehmen.
3. Den SKI des Gateways in der Hersteller-App der Wärmepumpe freigeben (meist Netzwerk → EEBUS).
4. Sobald die Verbindung steht, erscheinen Leistung, Energie, Grenze, Ausfallgrenze und
   maximale Leistungsaufnahme.

## Variablen

| Ident | Bedeutung |
|---|---|
| `Erreichbar` | Wärmepumpe über EEBUS verbunden |
| `Leistung` | Elektrische Leistung in W (gemessen von der Wärmepumpe) |
| `Energie` | Bezogene elektrische Energie in kWh |
| `Grenze` | Leistungsgrenze in W (schaltbar; zeigt den von der Wärmepumpe bestätigten Wert) |
| `GrenzeAktiv` | Leistungsgrenze aktiv (schaltbar) |
| `Ausfallgrenze` | Grenze, die die Wärmepumpe ohne Lebenszeichen des Energiemanagements anwendet |
| `Nennleistung` | Maximale Leistungsaufnahme laut Wärmepumpe |
| `OhpcfZustand` | Optionaler Verdichterlauf: 0 nicht angeboten, 1 angeboten, 2 eingeplant, 3 läuft, 4 pausiert, 5 beendet |
| `OhpcfLauf` | Optionalen Lauf ausführen (schaltbar: an = starten/fortsetzen, aus = pausieren/abbrechen) |
| `OhpcfLeistung`, `OhpcfLeistungMax` | Erwartete und höchste Leistung des optionalen Laufs in W |

## Funktionen

| Funktion | Rückgabe |
|---|---|
| `WPEEB_SetPowerLimit(int $id, float $Watt, int $DurationSeconds)` | bool: Grenze gesendet (0 s = ohne Ende) |
| `WPEEB_ClearPowerLimit(int $id)` | bool: Grenze aufgehoben |
| `WPEEB_SetOptionalRun(int $id, bool $Run)` | bool: optionalen Lauf ein (sofort) oder aus (pausieren, sonst abbrechen) |
| `WPEEB_StartOptionalRun(int $id, int $DelaySeconds)` | bool: angebotenen Lauf einplanen |
| `WPEEB_PauseOptionalRun` / `WPEEB_ResumeOptionalRun` / `WPEEB_AbortOptionalRun` (`int $id`) | bool |
| `WPEEB_GetFunctions(int $id)` | Wärmepumpen-Vertrag des NRG-Stack (`Type` `heatpump`, Version 1.15), gemessene Leistung/Energie |
| `WPEEB_GetState(int $id)` | JSON: Zustand zur Diagnose |

Die Grenze ist eine Vorgabe: Wie die Wärmepumpe sie einhält, entscheidet sie selbst. Ob sie sie
angenommen hat, steht im Formular und im Meldungsprotokoll; die Variable `Grenze` folgt erst der
Bestätigung der Wärmepumpe.

## Noch nicht enthalten

- Automatik „bei PV-Überschuss starten“: entscheidet ein Energiemanagement oder ein Skript; der
  Wärmepumpen-Vertrag des Verbunds hat dafür noch keine Felder.
- Temperaturen und Betriebsarten (EEBUS bietet sie nur bei wenigen Geräten).

## Für Entwickler

Prüfstand: `php .tools/test-wpeeb.php` (ohne Netz, lädt das Gateway aus `../EEBUSGateway` oder
`EEBGW_DIR`) bzw. mit `--live <verbraucher-sim> <cert> <key>` gegen die simulierte Wärmepumpe.

## Lizenz

[PolyForm Noncommercial 1.0.0](LICENSE) – frei für private und nicht-kommerzielle Nutzung.
