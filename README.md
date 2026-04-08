# AllTune2

**Version 1.20.0 — Major Release**

Copyright Terry Claiborne - KC3KMV - kc3kmv@yahoo.com

AllTune2 is a web-based control and status dashboard for radio network switching and favorites management on **AllStarLink 3 / Debian Linux**.

This **1.20.0** release is a major update. The old two-step BM and TGIF workflow has been replaced with a much more usable one-step control flow, and BrandMeister receive handling now uses an **AllTune2-owned backend helper** with a **local AllTune2 STFU runtime copy**.

## 1.20.0 major release highlights

This release is a big change from earlier AllTune2 behavior.

### Major changes in this release

- **BrandMeister is now a one-step connect**
- **TGIF is now a one-step connect**
- **YSF remains a one-step connect**
- **AllStarLink remains a one-step connect**
- **EchoLink remains a one-step connect**
- **BrandMeister receive handling now uses an AllTune2-owned helper**
- **AllTune2 now uses its own local STFU runtime copy**
- **BM can remain active while adding direct AllStarLink / EchoLink connections**
- **Direct AllStarLink / EchoLink live detection and per-node disconnect remain supported**
- **Activity box compact behavior has been preserved**
- **The old BM/TGIF two-step wording and helper flow are no longer correct**

## Important

**AllTune2 will not work correctly until you edit `/var/www/html/alltune2/config.ini` and enter your real settings.**

**The installer creates a starter `config.ini` with placeholder values. You must change them before using AllTune2.**

## Current supported modes and behavior

AllTune2 currently supports:

- BrandMeister
- BrandMeister private calls with a trailing `#`
- TGIF
- YSF
- AllStarLink
- EchoLink
- DVSwitch auto-load
- Link Mode selection:
  - Transceive
  - Local Monitor
- Shared favorites management
- Dashboard favorites sorting
- Live direct AllStarLink / EchoLink node detection
- Per-node disconnect of a selected direct AllStarLink / EchoLink node
- Disconnect of the DVSwitch link only
- Full Disconnect All cleanup via Asterisk restart
- Config-aware mode availability
- Helper warnings for unconfigured modes
- Connect disabled for modes that are not truly configured
- Backend validation that rejects placeholder/default config values
- Audio alerts for connect / disconnect events
- Audio alerts toggle on / off

## Important paths

Original working app remains untouched:

- `/var/www/html/alltune`

Active AllTune2 project:

- `/var/www/html/alltune2`

Current AllTune2 BM receive helper:

- `/var/www/html/alltune2/alltune2-bm-receive.sh`

Current AllTune2 local STFU runtime copy:

- `/var/www/html/alltune2/stfu/STFU`

Current AllTune2 config file:

- `/var/www/html/alltune2/config.ini`

Original STFU web panel, not required by AllTune2 runtime:

- `/var/www/html/stfu`

## Features

- Safer web dashboard for connect / disconnect control
- Shared favorites management
- **BrandMeister one-step connect workflow**
- BrandMeister private call support with a trailing `#`
- **TGIF one-step connect workflow**
- YSF one-step connect workflow
- AllStarLink one-step connect workflow
- EchoLink one-step connect workflow
- DVSwitch auto-load option
- Link Mode selection:
  - Transceive
  - Local Monitor
- Direct AllStarLink / EchoLink node list with per-node Disconnect buttons
- Separate Disconnect DVSwitch action
- Separate Disconnect All action
- Config-aware mode availability
- Unconfigured modes show helper warnings
- Connect is disabled for modes that are not truly configured
- Backend validation prevents placeholder/default config values from pretending to connect
- Separate app config file
- Installer script for setup and permissions
- Automatic sudoers rule creation during install
- Improved dashboard action responsiveness
- Compact Activity box behavior preserved

## Project structure

- `public/index.php` - main dashboard
- `public/favorites.php` - favorites manager
- `public/alltune2_ribbon_bar.php` - ribbon/status UI include
- `api/connect.php` - connect / disconnect actions
- `api/status.php` - live status endpoint
- `app/` - application classes and support code
- `public/assets/js/app.js` - frontend logic
- `public/assets/css/style.css` - frontend styling
- `data/favorites.txt` - shared favorites file
- `config.ini` - local app configuration file
- `config.ini.example` - starter config example
- `alltune2-bm-receive.sh` - BM receive helper owned by AllTune2
- `stfu/STFU` - local AllTune2 STFU runtime copy used by the BM helper
- `setup_alltune2.sh` - install / setup script

## Requirements

- Debian / Linux system
- Apache
- PHP
- Asterisk installed at:
  - `/usr/sbin/asterisk`
- DVSwitch / MMDVM_Bridge installed with:
  - `/opt/MMDVM_Bridge/dvswitch.sh`
  - `/opt/MMDVM_Bridge/DVSwitch.ini`

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

- AllStarLink only
- EchoLink only
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

## Installer note for 1.20.0

This 1.20.0 release changes the packaging requirements.

The installer for this release should fully handle:

- required directory creation
- helper file permissions
- local STFU runtime path under AllTune2
- config file creation if missing
- favorites file creation if missing
- ownership and permissions
- PHP syntax checks
- sudoers creation and validation
- required DVSwitch file checks
- required Asterisk checks

For 1.20.0, the installer should perform this automatically so users do **not** need to manually create sudoers rules or manually set executable bits after install.

## Required sudoers handling

AllTune2 needs Apache / `www-data` to be able to run Asterisk commands without a password.

AllTune2 also needs Apache / `www-data` to be able to run the AllTune2 BM receive helper without a password.

For this release, installer-created sudoers handling should cover:

- `/usr/sbin/asterisk`
- `/var/www/html/alltune2/alltune2-bm-receive.sh`

The live tested design expects installer-managed sudoers setup, not manual post-install steps.

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

- **Connect** - starts the selected network / node workflow
- **Disconnect** - removes the current managed connection, or removes the last tracked direct AllStarLink / EchoLink node first when one is present
- **Disconnect DVSwitch** - removes only the configured DVSwitch link
- **Disconnect All** - full cleanup by restarting Asterisk

## Network behavior

### BrandMeister

BrandMeister now uses a **one-step connect flow**.

1. Enter or load a talkgroup.
2. Press **Connect** once.
3. Wait for the status to confirm the BM receive session.

BrandMeister private calls are also supported.

To place a BM private call, enter the destination DMR ID with `#` at the end.

Examples:

- `310997#` = BrandMeister Parrot private call
- `1234567#` = private call to DMR ID `1234567`

Notes:

- BM private call support is BrandMeister-only
- TGIF, YSF, AllStarLink, and EchoLink do not use the trailing `#`
- Only digits with an optional single trailing `#` are valid
- BM receive handling uses the AllTune2 helper:
  - `/var/www/html/alltune2/alltune2-bm-receive.sh`
- BM receive handling uses the AllTune2 local STFU runtime copy:
  - `/var/www/html/alltune2/stfu/STFU`
- The separate STFU web panel at `/var/www/html/stfu` is **not required** for AllTune2 operation

### TGIF

TGIF now uses a **one-step connect flow**.

1. Enter or load a talkgroup.
2. Press **Connect** once.
3. Wait for the status to confirm the TGIF connection.

### YSF

YSF uses a one-step connect flow:

1. Enter or load the YSF target.
2. Press **Connect** once.

### AllStarLink / EchoLink

AllStarLink / EchoLink uses a one-step connect flow:

1. Enter or load the AllStarLink / EchoLink node.
2. Press **Connect** once.

If **Disconnect before Connect** is off, additional direct AllStarLink / EchoLink nodes can be added and tracked.

If **Disconnect before Connect** is on, the next managed connect clears earlier managed links first.

## Disconnect Before Connect

This setting is important and should be understood before use.

When **Disconnect before Connect** is enabled, AllTune2 clears the earlier managed session before starting the next managed connect.

This matters most for DVSwitch-based modes such as:

- BrandMeister
- TGIF
- YSF

When it is **off**, BrandMeister can remain active while you add direct AllStarLink / EchoLink connections.

When it is **on**, the next managed DVSwitch connect clears the earlier managed DVSwitch session first.

This behavior should be documented clearly in both the UI helper text and the installer / README documentation.

## Config-aware mode availability

AllTune2 reads `config.ini` and checks whether a mode is truly configured before allowing Connect.

Configuration rules:

- **AllStarLink** requires a real `MYNODE`
- **EchoLink** requires a real `MYNODE` and a working EchoLink configuration on the ASL3 system
- **YSF** requires real `MYNODE` and `DVSWITCH_NODE`
- **BrandMeister** requires real `MYNODE`, `DVSWITCH_NODE`, and `BM_SelfcarePassword`
- **TGIF** requires real `MYNODE`, `DVSWITCH_NODE`, and `TGIF_HotspotSecurityKey`

If a mode is not configured:

- the helper text explains what is missing
- the Connect button is disabled for that mode
- backend validation also rejects fake/default config values

## Mixed-link behavior

AllTune2 supports mixed operation where DVSwitch can stay up while direct AllStarLink nodes are also connected.

Confirmed working behavior includes:

- BrandMeister + AllStarLink + EchoLink
- TGIF + AllStarLink + EchoLink
- YSF + AllStarLink + EchoLink
- DVSwitch local node in Local Monitor + AllStarLink + EchoLink in Transceive

This is one of the main design goals of AllTune2.

## AllStarLink / EchoLink live status

The AllStarLink / EchoLink Live Status box shows tracked direct AllStarLink / EchoLink nodes connected by AllTune2.

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

When direct AllStarLink / EchoLink nodes are present, it removes the most recently tracked direct AllStarLink / EchoLink node first.

If no tracked direct AllStarLink / EchoLink node is present, it disconnects the current managed mode as appropriate.

### Disconnect DVSwitch

**Disconnect DVSwitch** removes only the configured DVSwitch link.

If BM receive mode is active, this action also stops the BM receive session cleanly.

### Disconnect Selected AllStarLink / EchoLink Node

The AllStarLink / EchoLink Live Status area includes a Disconnect button beside each tracked direct AllStarLink / EchoLink node.

This allows disconnect of one specific direct AllStarLink / EchoLink node without removing the others.

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
- AllStarLink
- EchoLink

The dashboard can load saved favorites into the control form.

The dashboard saved favorites table supports click-to-sort for:

- TG / Node / YSF
- Station Name
- Description
- Mode

The Favorites page can:

- add favorites
- edit favorites
- remove selected favorites

## Audio alerts

AllTune2 supports audio alerts for direct node connect / disconnect activity.

Current supported behavior includes:

- audio alerts for node connect
- audio alerts for node disconnect
- user toggle for audio alerts on / off
- browser-side speech handling
- protection against repeated duplicate announcements

## Live status and Activity box

The Live Status area reflects:

- BrandMeister
- TGIF
- YSF
- AllStarLink / EchoLink

The Activity box is intended to stay compact and should not be forced open with filler rows containing meaningless placeholder values.

Accepted 1.20.0 behavior:

- compact Activity layout
- real status values shown
- empty filler rows not forced open unnecessarily

## Notes

- AllTune2 uses its own `config.ini` in the app root.
- The original working AllTune app can remain untouched while AllTune2 is tested separately.
- Some users may use Allmon3 instead of Allscan, so AllTune2 should not depend on Allscan existing.
- UI helper text explains the current selected network workflow.
- Button state is part of the workflow and users should wait for the status line and button state to update before the next action.
- The STFU web folder at `/var/www/html/stfu` is not required for AllTune2 runtime operation.

## Git / safety

The project `.gitignore` should prevent uploading local runtime files such as:

- `config.ini`
- `data/favorites.txt`
- `*.bak`
- `*.old`
- `*.orig`
- `*.save`

Review whether the local STFU runtime copy should be committed directly or created by the installer for the public package. That packaging decision should remain deliberate.

## Next steps after install

1. Edit `/var/www/html/alltune2/config.ini` and set real values.
2. Open `/alltune2/public/` in the browser.
3. Test BM, TGIF, YSF, AllStarLink, EchoLink, DVSwitch auto-load, and disconnect actions.
4. Confirm favorites save correctly.
5. Confirm direct AllStarLink nodes show correctly in Live Status.
6. Confirm Disconnect DVSwitch and Disconnect All behave as expected.
7. Confirm audio alerts behave as expected.
8. Confirm unconfigured modes show warnings and disable Connect as expected.

## License / sharing

This project is being prepared for GitHub-ready installation and sharing.
