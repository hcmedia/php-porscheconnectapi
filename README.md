# Porsche Connect API (PHP)

REST-API und PHP-Client für die **Porsche Connect**-Schnittstelle. Die Implementierung orientiert sich an der Python-Bibliothek [pyporscheconnectapi](https://github.com/CJNE/pyporscheconnectapi) von CJNE und portiert deren Funktionalität nach PHP – ergänzt um eine HTTP-Schicht zum einfachen Testen und Anbinden anderer Systeme.

> **Hinweis:** Dieses Projekt wird nicht offiziell von Porsche unterstützt. Die Schnittstelle kann sich jederzeit ändern oder ausfallen. Nutzung auf eigenes Risiko.

## Ursprung & Inspiration

| | Python (Original) | PHP (dieses Projekt) |
|---|---|---|
| Repository | [CJNE/pyporscheconnectapi](https://github.com/CJNE/pyporscheconnectapi) | `php-porsche-connect-api` |
| Sprache | Python ≥ 3.10 | PHP ≥ 8.2 |
| CLI | `porschecli` | REST-API + `bin/refresh-vehicle-summary.php` |
| Lizenz | MIT | MIT |

Funktional entsprechen u. a. Fahrzeugliste, Overview, Capabilities, Trip Statistics, Remote Commands (Klimatisierung, Laden, Ver- und Entriegeln, Lichthupe) dem Verhalten der Python-Bibliothek.

### Unterstützte Fahrzeuge

Wie bei [pyporscheconnectapi](https://github.com/CJNE/pyporscheconnectapi) gilt: **Porsche Connect** (nicht der Vorgänger Porsche Car Connect). Typischerweise u. a.:

- Boxster & Cayman (718)
- 911 (ab 992)
- Taycan
- Panamera (ab 2021, G2 PA)
- Macan (EV, ab 2024)
- Cayenne (ab 2017, E3)

Ob Ihr Modell Connect unterstützt, sehen Sie im [Porsche Connect Store](https://connect-store.porsche.com/). Ein aktives Connect-Abo ist erforderlich.

## Voraussetzungen

- PHP **8.2** oder höher (Extensions: `json`)
- [Composer](https://getcomposer.org/)
- Porsche-Connect-Zugangsdaten (E-Mail & Passwort)

## Installation

```bash
composer install
```

Der Webserver-Document-Root ist `public/`. Session-Daten werden unter `storage/sessions/` abgelegt (nicht versioniert).

## Server starten

**Entwicklung (PHP Built-in Server):**

```bash
php -S localhost:8080 -t public public/router.php
```

**Apache:** Document Root auf `public/` zeigen; URL-Rewriting ist über `.htaccess` vorkonfiguriert.

## Schnellstart

1. **Login** – Session anlegen und OAuth-Token holen:

```bash
curl -s -X POST http://localhost:8080/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"ihre@email.de","password":"ihr-passwort"}'
```

Antwort (201): `sessionId` und `token`. Den Header `X-Session-Id` bei allen weiteren Requests mitschicken.

2. **Fahrzeuge auflisten:**

```bash
curl -s http://localhost:8080/vehicles \
  -H "X-Session-Id: <sessionId>"
```

3. **Daten abrufen** – z. B. gespeichertes Overview für eine VIN:

```bash
curl -s http://localhost:8080/vehicles/<VIN>/overview/stored \
  -H "X-Session-Id: <sessionId>"
```

## Authentifizierung

| Endpoint | Methode | Beschreibung |
|----------|---------|--------------|
| `/auth/login` | POST | Login mit `email` / `password` oder vorhandenem `token`-Objekt |
| `/auth/captcha` | POST | Captcha nach 428-Antwort: `session_id`, `captcha_code`, `state` |
| `/auth/token` | GET | Aktuelles OAuth-Token der Session |

**Session-Übergabe:** Header `X-Session-Id` (empfohlen) oder Query-Parameter `session_id`.

Bei Captcha-Pflicht antwortet die API mit **428** und Feldern `captcha` sowie `state` – analog zum Python-Client.

## API-Referenz

### Health

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET | `/health` | Liveness-Check (`{"status":"ok"}`) |

### Fahrzeuge

| Methode | Pfad | Beschreibung |
|---------|------|--------------|
| GET | `/vehicles` | Alle Fahrzeuge der Session |
| GET | `/vehicles/{vin}` | Fahrzeugdetails inkl. Rohdaten |
| GET | `/vehicles/{vin}/overview/stored` | Gespeichertes Overview (Backend-Cache) |
| GET | `/vehicles/{vin}/overview/current` | Aktuelles Overview (Abfrage am Fahrzeug) |
| GET | `/vehicles/{vin}/overview/summary` | Aufbereitete Kurzübersicht; `?persist=1` schreibt JSON nach `public/data/{vin}.json` |
| GET | `/vehicles/{vin}/capabilities` | Fahrzeug-Capabilities |
| GET | `/vehicles/{vin}/trip-statistics` | Fahrtstatistik |
| GET | `/vehicles/{vin}/pictures` | Bild-URLs |
| GET | `/vehicles/{vin}/location` | Standort |
| GET | `/vehicles/{vin}/battery` | Hauptbatterie (BEV) |
| GET | `/vehicles/{vin}/doors` | Türen/Klappen, geschlossen-Status |

### Remote-Befehle

Alle Endpoints erfordern `POST` und eine gültige Session.

| Methode | Pfad | Body (optional) |
|---------|------|-----------------|
| POST | `/vehicles/{vin}/commands/flash` | — |
| POST | `/vehicles/{vin}/commands/honk-flash` | — |
| POST | `/vehicles/{vin}/commands/climatise-on` | `targetTemperature` (Kelvin, Standard 293.15), `frontLeft`, `frontRight`, `rearLeft`, `rearRight` |
| POST | `/vehicles/{vin}/commands/climatise-off` | — |
| POST | `/vehicles/{vin}/commands/direct-charge-on` | — |
| POST | `/vehicles/{vin}/commands/direct-charge-off` | — |
| POST | `/vehicles/{vin}/commands/lock` | — |
| POST | `/vehicles/{vin}/commands/unlock` | `pin` (hex, SPIN-Challenge) |
| POST | `/vehicles/{vin}/commands/charging-profile` | `profileId`, `minimumChargeLevel` |
| POST | `/vehicles/{vin}/commands/charging-settings` | `targetSoc` |

### Fehlercodes

| Code | Bedeutung |
|------|-----------|
| 401 | Ungültige Session oder Anmeldedaten |
| 404 | Route oder Fahrzeug nicht gefunden |
| 422 | Fehlende Pflichtfelder im Request-Body |
| 428 | Captcha erforderlich |
| 502 | Fehler der Porsche-Remote-Services |

## Postman

Eine vorkonfigurierte Collection liegt unter:

`postman/Porsche-Connect-API.postman_collection.json`

Variablen: `base_url`, `session_id`, `vin`. Nach dem Login-Request wird `session_id` automatisch gesetzt.

## CLI: Fahrzeug-Summary aktualisieren

Schreibt eine kompakte JSON-Datei nach `public/data/{vin}.json` (z. B. für Cronjobs oder statische Auslieferung):

```bash
php bin/refresh-vehicle-summary.php <session_id> <vin>
```

Die Session muss zuvor per API angelegt und beschreibbar sein (`storage/sessions/`).

## Projektstruktur

```
├── api/                 # REST-Schicht (Router, SessionManager, JsonResponse)
├── bin/                 # CLI-Hilfsskripte
├── postman/             # Postman-Collection
├── public/              # Document Root (index.php, router.php, data/)
├── src/                 # Porsche-Connect-Client (Port der Python-Library)
│   ├── OAuth2/
│   └── Exception/
└── storage/sessions/    # Persistierte Sessions (gitignored)
```

## Als Bibliothek nutzen

Composer-Paketname: `porsche-connect/php-porsche-connect-api`. Klassen liegen im Namespace `PorscheConnect\` – z. B. `PorscheConnectAccount`, `PorscheVehicle`, `RemoteServices`, `Connection`.

```php
use PorscheConnect\Connection;
use PorscheConnect\PorscheConnectAccount;

$connection = new Connection();
$account = new PorscheConnectAccount(email: '...', password: '...', connection: $connection);
$vehicles = $account->getVehicles();
```

Die REST-API in `public/index.php` nutzt dieselben Klassen über `PorscheConnect\Api\SessionManager`.

## Sicherheit

- Session-Dateien enthalten **Zugangsdaten und Tokens** – `storage/sessions/` nicht öffentlich zugänglich machen und nicht committen.
- Remote-Befehle (Verriegeln, Klimatisierung, Laden) wirken am echten Fahrzeug – nur in vertrauenswürdigen Umgebungen testen.
- HTTPS in Produktion verwenden.

## Lizenz

MIT – siehe `composer.json`.

Die referenzierte Python-Bibliothek [pyporscheconnectapi](https://github.com/CJNE/pyporscheconnectapi) steht unter der **MIT-Lizenz**.

## Danksagung

Vielen Dank an [CJNE](https://github.com/CJNE) und alle Mitwirkenden an [pyporscheconnectapi](https://github.com/CJNE/pyporscheconnectapi), ohne deren Reverse Engineering und Python-Implementierung dieses PHP-Projekt nicht entstanden wäre.
