# NRG-Stack WPEEBUSHub

[![Symcon](https://img.shields.io/badge/Symcon-PHP%20module-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Module version](https://img.shields.io/badge/Module%20version-0.3.0-blue.svg)](library.json)
[![Symcon version](https://img.shields.io/badge/Symcon%20version-9.0%2B-green.svg)](https://www.symcon.de)
[![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-lightgrey.svg)](LICENSE)
[![PayPal](https://img.shields.io/badge/PayPal-Donate-blue.svg)](https://paypal.me/DietmarGureth)

[Deutsch](README.md) · **English**

Connects a **heat pump with EEBUS interface** to Symcon: reads electrical power and energy (EEBUS use
case MPC), limits the power consumption (LPC) and starts an optional compressor run for PV surplus
(OHPCF). The connection itself (certificate, pairing,
encryption) is handled by the **NRG-Stack EEBUS gateway**, to which this instance is attached as a child.

> Version 0.3.0: tested against a simulated heat pump (eebus-go) and on a real Vaillant
> (power and optional compressor run confirmed, gateway 0.6.2 or newer).

## Which heat pumps?

According to manufacturers and field reports (e.g. evcc, gridX), EEBUS with power/energy and mostly
power limitation is offered by e.g. Viessmann Vitocal (via Vitoconnect), Bosch/Buderus with MX400,
NIBE S series, Vaillant via VR940f (measurements, no LPC). Wolf Link only opens the connection itself:
set the gateway (0.5.0 or newer) to “The device connects to the gateway”. Feedback with real devices is
very welcome.

## Requirements

- Symcon 9.0 or newer
- **NRG-Stack EEBUS gateway version 0.5.0 or newer** (separate module in the store): it holds certificate
  and pairing and establishes the connection to the heat pump. WPEEBUSHub is attached to it as a child
  instance, one gateway per heat pump.
- A heat pump with enabled EEBUS interface in the same network

## Setup

1. Create the instance “NRG-Stack WPEEBUSHub” and choose or create an EEBUS gateway as parent instance.
2. In the gateway, enter address and port of the heat pump (manufacturer app/manual), click
   “Check remote device” and trust its SKI.
3. Trust the SKI of the gateway in the manufacturer app of the heat pump (usually Network → EEBUS).
4. As soon as the connection is up, power, energy, limit, failsafe limit and maximum power appear.

## Variables

| Ident | Meaning |
|---|---|
| `Erreichbar` | Heat pump connected via EEBUS |
| `Leistung` | Electrical power in W (measured by the heat pump) |
| `Energie` | Electrical energy consumed in kWh |
| `EnergieHochgerechnet` | Meter reading in kWh, calculated from the measured power if the heat pump reports none (e.g. Vaillant) |
| `Grenze` | Power limit in W (switchable; shows the value confirmed by the heat pump) |
| `GrenzeAktiv` | Power limit active (switchable) |
| `Ausfallgrenze` | Limit the heat pump applies without a heartbeat of the energy management |
| `Nennleistung` | Maximum power consumption according to the heat pump |
| `OhpcfZustand` | Optional compressor run: 0 not offered, 1 offered, 2 scheduled, 3 running, 4 paused, 5 completed |
| `OhpcfLauf` | Run the optional run (switchable: on = start/resume, off = pause/abort) |
| `OhpcfLeistung`, `OhpcfLeistungMax` | Expected and maximum power of the optional run in W |
| `Warmwasser` | Hot water temperature in °C, only if the heat pump reports it via EEBUS (MDT) |

## Behaviour like evcc

- Optional compressor run: on/off. While on, every run the heat pump newly offers is scheduled immediately
  and again every “Reschedule offered runs every” minutes (default 10). Off pauses if the heat pump allows
  it, otherwise aborts.
- If the heat pump offers its power limit anew, the module sends the last desired limit again until it is
  confirmed (at most 50 s).

## In the NRG-Stack

Power and meter reading use the shared profiles `NRG.Watt`/`NRG.kWh` like MeterHub. For the heat pump
monitor and the energy flow of the NRG-Stack dashboard to show the history, both must be archived: one
click under “Energy and archive” in the form is enough. The module never switches archiving on by itself.

## Functions

| Function | Returns |
|---|---|
| `WPEEB_SetPowerLimit(int $id, float $Watt, int $DurationSeconds)` | bool: limit sent (0 s = no end) |
| `WPEEB_ClearPowerLimit(int $id)` | bool: limit lifted |
| `WPEEB_SetOptionalRun(int $id, bool $Run)` | bool: optional run on (now) or off (pause, otherwise abort) |
| `WPEEB_StartOptionalRun(int $id, int $DelaySeconds)` | bool: schedule the offered run |
| `WPEEB_PauseOptionalRun` / `WPEEB_ResumeOptionalRun` / `WPEEB_AbortOptionalRun` (`int $id`) | bool |
| `WPEEB_GetFunctions(int $id)` | NRG-Stack heat pump contract (`Type` `heatpump`, version 1.17), measured power/energy |
| `WPEEB_GetState(int $id)` | JSON: state for diagnosis |

The limit is a request: the heat pump decides itself how it complies. Whether it accepted it is shown
in the form and the message log; the variable `Grenze` only follows the heat pump's confirmation.

## Not included yet

- Automatic “start on PV surplus”: decided by an energy management or a script; the NRG-Stack heat pump
  contract has no fields for it yet.
- Temperatures and operating modes (EEBUS offers them only on few devices).

## For developers

Test bench: `php .tools/test-wpeeb.php` (offline, loads the gateway from `../EEBUSGateway` or
`EEBGW_DIR`) or with `--live <verbraucher-sim> <cert> <key>` against the simulated heat pump.

## License

[PolyForm Noncommercial 1.0.0](LICENSE) – free for private and non-commercial use.
