# AllTune2

© Terry Claiborne - KC3KMV - kc3kmv@yahoo.com

AllTune2 is a web-based control and status dashboard for radio network switching and favorites management on AllStarLink 3 / Debian Linux.

It is designed to provide a cleaner, safer control flow for BrandMeister, TGIF, YSF, AllStar, Echolink.

## Important

**AllTune2 will not work correctly until you edit `/var/www/html/alltune2/config.ini` and enter your real settings.**

**The installer creates a starter `config.ini` with placeholder values. You must change them before using AllTune2.**

## Current status

AllTune2 currently supports:

- BrandMeister
- TGIF
- YSF
- AllStar
- Echolink
- DVSwitch auto-load
- DVSwitch link mode selection:
  - Transceive
  - Local Monitor
- Shared favorites management
- Direct AllStar node tracking
- Disconnect of a specific selected direct AllStar node
- Direct Echolink node tracking
- Disconnect of a specific selected direct Echolink node
- Disconnect of the DVSwitch link only
- Full Disconnect All cleanup via Asterisk restart
- Config-aware mode availability
- Helper warnings for unconfigured modes
- Connect disabled for modes that are not truly configured
- Backend validation that rejects placeholder/default config values

## Important paths

Original working app remains untouched:

- `/var/www/html/alltune`

Active AllTune2 project:

- `/var/www/html/alltune2`

## Features

- Safer web dashboard for connect / disconnect control
- Shared favorites management
- BrandMeister two-step connect workflow
- TGIF two-step connect workflow
- YSF one-step connect workflow
- AllStar one-step connect workflow
- Echolink one-step connect workflow
- DVSwitch auto-load option
- DVSwitch link mode selection:
  - Transceive
  - Local Monitor
- Direct AllStar - Echolink node list with per-node Disconnect buttons
- Separate Disconnect DVSwitch action
- Separate Disconnect All action
- Config-aware mode availability
- Unconfigured modes show helper warnings
- Connect is disabled for modes that are not truly configured
- Backend validation prevents placeholder/default config values from pretending to connect
- Separate app config file
- Installer script for setup and permissions
- Automatic Asterisk sudoers rule creation during install

## Project structure

- `public/index.php` — main dashboard
- `public/favorites.php` — favorites manager
- `api/connect.php` — connect / disconnect actions
- `api/status.php` — live status endpoint
- `app/` — application classes and support code
- `public/assets/js/app.js` — frontend logic
- `public/assets/css/style.css` — frontend styling
- `data/favorites.txt` — shared favorites file
- `config.ini` — local app configuration file
- `config.ini.example` — starter config example
- `setup_alltune2.sh` — install / setup script

## Requirements

- Debian / Linux system
- Apache
- PHP
- Asterisk installed at:
  `/usr/sbin/asterisk`

## Config file

AllTune2 uses its own config file:

- `/var/www/html/alltune2/config.ini`

Expected keys:

```ini
MYNODE="YOUR NODE"
DVSWITCH_NODE="YOUR DVSWITCH NODE"
BM_SelfcarePassword="CHANGE_ME"
TGIF_HotspotSecurityKey="CHANGE_ME"
```

Typical local setup example:

```ini
MYNODE="67040"
DVSWITCH_NODE="1957"
BM_SelfcarePassword="YOUR_REAL_PASSWORD"
TGIF_HotspotSecurityKey="YOUR_REAL_KEY"
```

Placeholder or default values such as these are treated as **not configured**:

- `CHANGE_ME`
- `YOUR NODE`
- `YOUR DVSWITCH NODE`
- `YOUR_REAL_PASSWORD`
- `YOUR_REAL_KEY`

This allows safer behavior for systems that may have:

- AllStar only
- Echolink only
- BrandMeister only
- TGIF only
- BrandMeister + TGIF
- full DVSwitch support

## Fresh install

Run this from the terminal:

```bash
sudo git clone https://github.com/TerryClaiborne/alltune2.git /var/www/html/alltune2 && cd /var/www/html/alltune2 && sudo bash setup_alltune2.sh
```

## Existing install update

If you already have AllTune2 installed, use:

```bash
cd /var/www/html/alltune2
cp config.ini config.ini.bak
cp data/favorites.txt data/favorites.txt.bak
git pull
sudo bash setup_alltune2.sh
```

## What the installer does

The installer:

- creates `config.ini.example` if missing
- creates `config.ini` if missing
- creates `data/favorites.txt` if missing
- sets ownership and permissions
- checks required project files
- runs PHP syntax checks
- checks required config keys
- creates the required Asterisk sudoers file
- validates the sudoers file
- shows a setup summary

## Required sudoers rule

AllTune2 needs Apache / `www-data` to be able to run Asterisk commands without a password.

The installer creates this file:

- `/etc/sudoers.d/alltune2-asterisk`

With this rule:

```text
www-data ALL=(ALL) NOPASSWD: /usr/sbin/asterisk
```

## Browser access

Open:

```text
/alltune2/public/
```

Direct local example:

```text
http://YOUR-IP/alltune2/public/
```

## Dashboard behavior

The dashboard and status are the same main screen.

The main control actions are:

- **Connect** — starts the selected network / node workflow
- **Disconnect** — removes the current managed connection, or removes the last tracked direct AllStar/Echolink node first when one is present
- **Disconnect DVSwitch** — removes only the configured DVSwitch link
- **Disconnect All** — full cleanup by restarting Asterisk

## Network behavior

### BrandMeister

BrandMeister uses a two-step connect flow:

1. Enter or load a talkgroup.
2. Press **Connect** once.
3. Wait for the system to show that BrandMeister is ready.
4. Press **Connect** again for the final talkgroup connect.

### TGIF

TGIF uses a two-step connect flow:

1. Enter or load a talkgroup.
2. Press **Connect** once.
3. Wait for the system to show that TGIF is ready.
4. Press **Connect** again for the final talkgroup connect.

### YSF

YSF uses a one-step connect flow:

1. Enter or load the YSF target.
2. Press **Connect** once.

### AllStar / Echolink

AllStar / Echolink uses a one-step connect flow:

1. Enter or load the AllStar/Echolink node.
2. Press **Connect** once.

If **Disconnect before Connect** is off, additional direct AllStar/Echolink nodes can be added and tracked.

If **Disconnect before Connect** is on, the next managed connect clears earlier managed links first.

## Config-aware mode availability

AllTune2 now reads `config.ini` and checks whether a mode is truly configured before allowing Connect.

Configuration rules:

- **AllStar** requires a real `MYNODE`
- **Echolink** requires a real `MYNODE`
- **YSF** requires real `MYNODE` and `DVSWITCH_NODE`
- **BrandMeister** requires real `MYNODE`, `DVSWITCH_NODE`, and `BM_SelfcarePassword`
- **TGIF** requires real `MYNODE`, `DVSWITCH_NODE`, and `TGIF_HotspotSecurityKey`

If a mode is not configured:

- the helper text explains what is missing
- the Connect button is disabled for that mode
- backend validation also rejects fake/default config values

## Mixed-link behavior

AllTune2 supports mixed operation where DVSwitch can stay up while direct AllStar nodes are also connected.

Confirmed working behavior includes:

- BrandMeister + AllStar + Echolink
- TGIF + AllStar + Echolink
- YSF + AllStar + Echolink
- DVSwitch local node in Local Monitor + AllStar + Echolink in Transceive

This is one of the main design goals of AllTune2.

## AllStar/Echolink live status

The AllStar/Echolink Live Status box shows tracked direct AllStar/Echolink nodes connected by AllTune2.

It includes:

- direct connected node count
- direct node numbers
- mode labels such as:
  - Transceive
  - Local Monitor

Important:

- only direct tracked nodes connected by AllTune2 are intended to be acted on
- downstream nodes beyond the direct link are not the target of per-node disconnect control

## Disconnect behavior

### Disconnect

Normal **Disconnect** uses managed disconnect behavior.

When direct AllStar/Echolink nodes are present, it removes the most recently tracked direct AllStar/Echolink node first.

If no tracked direct AllStar/Echolink node is present, it disconnects the current managed mode as appropriate.

### Disconnect DVSwitch

**Disconnect DVSwitch** removes only the configured DVSwitch link and should not disturb direct AllStar/Echolink nodes unless a different action is chosen.

### Disconnect Selected AllStar/Echolink Node

The AllStar/Echolink Live Status area includes a Disconnect button beside each tracked direct AllStar node.

This allows disconnect of one specific direct AllStar/Echolink node without removing the others.

### Disconnect All

**Disconnect All** is intentionally different from normal Disconnect.

It performs full cleanup by restarting Asterisk so that stubborn sessions are cleared reliably.

This is the intended design and should remain that way.

## Favorites

Favorites are stored in one shared file:

- `data/favorites.txt`

Favorites support:

- BM
- TGIF
- YSF
- AllStar
- Echolink

The dashboard can load saved favorites into the control form.

The Favorites page can:

- add favorites
- edit favorites
- remove selected favorites

## Notes

- AllTune2 uses its own `config.ini` in the app root.
- The original working AllTune app can remain untouched while AllTune2 is tested separately.
- Some users may use Allmon3 instead of Allscan, so AllTune2 should not depend on Allscan existing.
- UI helper text explains the current selected network workflow.
- Button state is part of the workflow and users should wait for the status line and button state to update before the next action.

## Git / safety

The project `.gitignore` should prevent uploading local runtime files such as:

- `config.ini`
- `data/favorites.txt`
- `*.bak`
- `*.old`
- `*.orig`
- `*.save`

## Next steps after install

1. Edit `/var/www/html/alltune2/config.ini` and set real values.
2. Open `/alltune2/public/` in the browser.
3. Test BM, TGIF, YSF, AllStar, Echolink, DVSwitch auto-load, and disconnect actions.
4. Confirm favorites save correctly.
5. Confirm direct AllStar nodes show correctly in Live Status.
6. Confirm Disconnect DVSwitch and Disconnect All behave as expected.
7. Confirm unconfigured modes show warnings and disable Connect as expected.

## License / sharing

This project is being prepared for GitHub-ready installation and sharing.
