# NRG-Stack WPEEBUSHub

[![Symcon](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Modul Version](https://img.shields.io/badge/Modul%20Version-0.4.0-blue.svg)](library.json)
[![Symcon Version](https://img.shields.io/badge/Symcon%20Version-9.0%2B-green.svg)](https://www.symcon.de)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey.svg)](LICENSE)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue.svg)](https://paypal.me/DietmarGureth)

**Deutsch** · [English](README.en.md)

Bindet eine **Wärmepumpe mit EEBUS-Schnittstelle** an Symcon an: elektrische Leistung und Energie
lesen (EEBUS-Anwendungsfall MPC), die Leistungsaufnahme begrenzen (LPC) und einen optionalen
Verdichterlauf für PV-Überschuss starten (OHPCF). Die Verbindung selbst
(Zertifikat, Kopplung, Verschlüsselung) übernimmt das **NRG-Stack EEBUS-Gateway**, an dem diese
Instanz als Kind hängt.

> Stand 0.3.0: geprüft gegen eine simulierte Wärmepumpe (eebus-go) und an einer echten Vaillant
> (Leistung und optionaler Verdichterlauf bestätigt, Gateway ab 0.6.2).

## Welche Wärmepumpen?

Laut Herstellerangaben und Praxisberichten (u. a. evcc, gridX) bieten EEBUS mit Leistung/Energie und
meist auch Leistungsbegrenzung u. a.: Viessmann Vitocal (über Vitoconnect), Bosch/Buderus mit MX400,
NIBE S-Serie, Vaillant über VR940f (Messwerte, ohne LPC). Wolf Link baut die Verbindung nur selbst
auf: dafür das Gateway (ab 0.5.0) auf „Das Gerät verbindet sich zum Gateway“ stellen. Rückmeldungen
mit echten Geräten sind sehr willkommen.

## Voraussetzungen

- Symcon 9.0 oder neuer
- **NRG-Stack EEBUS-Gateway ab Version 0.5.0** (eigenes Modul im Store): Es hält Zertifikat und Kopplung
  und baut die Verbindung zur Wärmepumpe auf. WPEEBUSHub hängt als Kind-Instanz daran, je Wärmepumpe
  ein Gateway.
- Eine Wärmepumpe mit freigeschalteter EEBUS-Schnittstelle im selben Netz

## Einrichtung

1. Instanz „NRG-Stack WPEEBUSHub“ anlegen und als übergeordnete Instanz ein EEBUS-Gateway wählen bzw.
   neu anlegen.
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
| `Energie` | Bezogene elektrische Energie in kWh (Zählerstand der Wärmepumpe, falls sie einen meldet) |
| `EnergieHochgerechnet` | Zählerstand in kWh, aus der gemessenen Leistung hochgerechnet, wenn die Wärmepumpe keinen meldet (z. B. Vaillant) |
| `Grenze` | Leistungsgrenze in W (schaltbar; zeigt den von der Wärmepumpe bestätigten Wert) |
| `GrenzeAktiv` | Leistungsgrenze aktiv (schaltbar) |
| `Ausfallgrenze` | Grenze, die die Wärmepumpe ohne Lebenszeichen des Energiemanagements anwendet |
| `Nennleistung` | Maximale Leistungsaufnahme laut Wärmepumpe |
| `OhpcfZustand` | Optionaler Verdichterlauf: 0 nicht angeboten, 1 angeboten, 2 eingeplant, 3 läuft, 4 pausiert, 5 beendet |
| `OhpcfLauf` | Optionalen Lauf ausführen (schaltbar: an = starten/fortsetzen, aus = pausieren/abbrechen) |
| `OhpcfLeistung`, `OhpcfLeistungMax` | Erwartete und höchste Leistung des optionalen Laufs in W |
| `Warmwasser` | Warmwassertemperatur in °C, nur wenn die Wärmepumpe sie über EEBUS meldet (MDT) |

## Verhalten wie evcc

- Optionaler Verdichterlauf: Ein/Aus. Bei „Ein“ wird jeder neu angebotene Lauf sofort und erneut alle
  „Angebotene Läufe erneut einplanen“-Minuten eingeplant (Standard 10). Bei „Aus“ wird pausiert, wenn die
  Wärmepumpe das erlaubt, sonst abgebrochen.
- Bietet die Wärmepumpe ihre Leistungsgrenze neu an, sendet das Modul die zuletzt gewünschte Grenze erneut,
  bis sie bestätigt ist (höchstens 50 s).

## Im NRG-Stack

Das NRG-Stack EMS kann die Wärmepumpe über den Vertrag nutzen (WP-Steuermuster, Version 1.18): Es schaltet
den Zusatzlauf bei PV-Überschuss über die Variable „Optionalen Verdichterlauf ausführen“. Die Einstellung
„Wer steuert diese Wärmepumpe?“ legt fest, ob es das darf - bei „ein anderes System“ hält es sich heraus.
Die §-14a-Grenze bleibt beim SteuerboxHub.

Leistung und Zählerstand nutzen die gemeinsamen Profile `NRG.Watt`/`NRG.kWh` wie MeterHub. Damit
WP-Monitor und Energiefluss des NRG-Stack Dashboards den Verlauf zeigen können, müssen beide archiviert
werden: Im Formular unter „Energie und Archiv“ genügt ein Klick. Das Modul schaltet die Archivierung
nie von selbst ein.

## Funktionen

| Funktion | Rückgabe |
|---|---|
| `WPEEB_SetPowerLimit(int $id, float $Watt, int $DurationSeconds)` | bool: Grenze gesendet (0 s = ohne Ende) |
| `WPEEB_ClearPowerLimit(int $id)` | bool: Grenze aufgehoben |
| `WPEEB_SetOptionalRun(int $id, bool $Run)` | bool: optionalen Lauf ein (sofort) oder aus (pausieren, sonst abbrechen) |
| `WPEEB_StartOptionalRun(int $id, int $DelaySeconds)` | bool: angebotenen Lauf einplanen |
| `WPEEB_PauseOptionalRun` / `WPEEB_ResumeOptionalRun` / `WPEEB_AbortOptionalRun` (`int $id`) | bool |
| `WPEEB_GetFunctions(int $id)` | Wärmepumpen-Vertrag des NRG-Stack (`Type` `heatpump`, Version 1.18): Leistung gemessen, Energie gemessen oder hochgerechnet (`energyMeasured`), zusätzlich die MeterHub-Felder für den Energiefluss |
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
