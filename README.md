# AllTune2

**Version 1.20.3**

AllTune2 is a web-based control and status dashboard for **AllStarLink 3 / Debian / DVSwitch** systems. It is designed to make day-to-day switching between BM, TGIF, YSF, AllStarLink, and EchoLink simpler and faster, while keeping the original app separate so you can test safely.

This project is intended for the common ASL3-style layout on Raspberry Pi based nodes and similar systems.

---

## What AllTune2 does well

AllTune2 is built around a simple idea: one main screen, clear status, and fast access to the things you actually use.

Current highlights include:

- **BrandMeister one-step connect**
- **BrandMeister quick talkgroup changes without disconnecting first**
- **BM private call support** using a trailing `#`
- **TGIF one-step connect**
- **YSF one-step connect**
- **AllStarLink one-step connect**
- **EchoLink one-step connect**
- **System Strip / ribbon bar** with live status details
- **DTMF pad / send control** from the dashboard
- **Shared favorites** with sorting on the dashboard
- **Direct AllStarLink / EchoLink live node tracking**
- **Per-node Disconnect buttons** for tracked direct nodes
- **Disconnect DVSwitch** and **Disconnect All** actions
- **Audio alerts** for node connect / disconnect activity with an on/off toggle
- **Config-aware mode availability** so unconfigured modes do not pretend to work
- **Installer-managed sudoers setup** for required helper actions
- **AllTune2-owned BM receive helper** and local `stfu/STFU` runtime copy

---

## Important current note about TGIF

TGIF remains available in AllTune2, but it should be considered **under active troubleshooting on some systems**.

On some ASL3 / DVSwitch environments, users may see:

- receive dropping after a short period
- timeout / reconnect behavior
- inconsistent TGIF behavior compared with BM

Because of that, **BM is currently the more trusted DMR path** in day-to-day use.

This does **not** mean TGIF is removed, but it does mean the README should be honest: TGIF may still need more system-specific troubleshooting on some nodes.

---

## Project paths

Active AllTune2 project:

- `/var/www/html/alltune2`

Main config file:

- `/var/www/html/alltune2/config.ini`

BM receive helper:

- `/var/www/html/alltune2/alltune2-bm-receive.sh`

Local BM runtime copy used by AllTune2:

- `/var/www/html/alltune2/stfu/STFU`

Favorites file:

- `/var/www/html/alltune2/data/favorites.txt`

---

## Main dashboard behavior

The dashboard is the heart of AllTune2.

It combines:

- mode selection
- target entry
- live status
- action buttons
- favorites loading
- direct node status
- activity details
- system strip / ribbon information

The main actions are:

- **Connect**
- **Disconnect**
- **Disconnect DVSwitch**
- **Disconnect All**
- **Send DTMF**

### Connect behavior by mode

#### BrandMeister

BrandMeister is now a **one-step connect flow**.

1. Enter or load a talkgroup.
2. Press **Connect** once.
3. Wait for the status line to confirm the session.

**BrandMeister can now be retuned without disconnecting first.**

That means if BM is already active, you can enter another BM talkgroup and press **Connect** again to change TGs quickly.

BrandMeister private calls are also supported with a trailing `#`.

Examples:

- `310997#` = BM parrot private call
- `1234567#` = private call to DMR ID `1234567`

#### TGIF

TGIF uses a **one-step connect flow** from the dashboard.

1. Enter or load a talkgroup.
2. Press **Connect** once.
3. Wait for the status line to confirm the connection.

**Important:** on some systems, TGIF may still show timeout or unstable behavior. If your node shows that behavior, BM is currently the safer DMR choice.

#### YSF

YSF remains a one-step connect:

1. Enter or load the YSF target.
2. Press **Connect** once.

#### AllStarLink and EchoLink

AllStarLink and EchoLink also use the same simple one-step flow.

If **Disconnect before Connect** is disabled, direct nodes can be added and tracked together.

If **Disconnect before Connect** is enabled, the next managed connect clears the earlier managed session first.

---

## System Strip / ribbon bar

AllTune2 includes a live **System Strip** / ribbon-style status area.

Its job is to give a quick at-a-glance view of what the node is doing without making the screen feel cluttered.

It is part of the current layout and should be treated as a normal part of the dashboard, not an experiment.

---

## DTMF support

The dashboard includes a **DTMF send control** so you can send valid DTMF commands directly from the web interface.

This is intended for convenience and quick node control without dropping back to the CLI.

---

## Favorites

Favorites are shared through:

- `data/favorites.txt`

Supported favorite modes include:

- BM
- TGIF
- YSF
- AllStarLink
- EchoLink

The dashboard favorites table supports sorting and quick load-back into the control form.

The Favorites page supports:

- add
- edit
- delete

---

## Direct node tracking

AllTune2 tracks direct AllStarLink / EchoLink nodes connected by AllTune2 and shows them in the live status area.

Supported behavior includes:

- direct node count
- live node list
- per-node Disconnect buttons
- support for Transceive and Local Monitor labels

Normal **Disconnect** removes the most recently tracked direct node first when direct nodes are present.

**Disconnect DVSwitch** removes only the configured DVSwitch link.

**Disconnect All** performs a full cleanup by restarting Asterisk so stubborn sessions are cleared reliably.

---

## Audio alerts

AllTune2 supports browser-side audio alerts for node activity.

Current behavior includes:

- node connect announcement
- node disconnect announcement
- duplicate suppression to reduce repeats
- user toggle on / off

---

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

Example:

```ini
MYNODE="67040"
DVSWITCH_NODE="1957"
BM_SelfcarePassword="YOUR_REAL_PASSWORD"
TGIF_HotspotSecurityKey="YOUR_REAL_KEY"
```

Placeholder values are treated as **not configured**. That is intentional.

If a mode is not truly configured:

- helper text explains what is missing
- Connect is disabled for that mode
- backend validation rejects fake/default values

That protects the dashboard from looking like it worked when it really did not.

---

## Requirements

Expected environment:

- Debian / Linux
- Apache
- PHP
- Asterisk at `/usr/sbin/asterisk`
- DVSwitch / MMDVM_Bridge installed with:
  - `/opt/MMDVM_Bridge/dvswitch.sh`
  - `/opt/MMDVM_Bridge/DVSwitch.ini`

---

## Fresh install

```bash
git clone https://github.com/TerryClaiborne/alltune2.git /var/www/html/alltune2
cd /var/www/html/alltune2
sudo bash setup_alltune2.sh
```

After install:

1. Edit `/var/www/html/alltune2/config.ini`
2. Enter your real node values and credentials
3. Open `/alltune2/public/` in a browser

---

## Updating an existing install

If you already have AllTune2 installed:

```bash
cd /var/www/html/alltune2
git pull origin main
sudo bash setup_alltune2.sh
```

That second command matters.

A plain `git pull` updates the repo files, but it does **not** automatically apply all required live-node setup outside the repo. The setup script is what should refresh things like:

- helper permissions
- required directories
- installer-managed sudoers rules
- config bootstrap if needed
- file ownership and executable bits

If you are restoring an older image, moving to another node, or updating a node that already existed before newer helper features were added, **run `setup_alltune2.sh` again**.

---

## Sudoers / permissions

AllTune2 expects installer-managed sudoers handling for at least:

- `/usr/sbin/asterisk`
- `/var/www/html/alltune2/alltune2-bm-receive.sh`

If BM helper actions fail after a pull or restore, the first thing to check is whether the setup script was rerun and whether the helper sudoers entry exists.

---

## BM helper note

BrandMeister receive is now handled by an AllTune2-owned helper and local runtime copy instead of depending on the older separate STFU web workflow.

That is a major change in how BM is packaged and managed.

The helper should only be active when BM receive mode is actually being used. Recent work in this repo also improved BM helper process tracking so BM can stay available without the helper misreporting the runtime state.

---

## Current supported files and structure

Key project files:

- `public/index.php` - main dashboard
- `public/favorites.php` - favorites manager
- `public/alltune2_ribbon_bar.php` - system strip / ribbon include
- `api/connect.php` - connect and disconnect actions
- `api/status.php` - live status endpoint
- `public/assets/js/app.js` - frontend logic
- `public/assets/css/style.css` - frontend styling
- `alltune2-bm-receive.sh` - BM receive helper
- `setup_alltune2.sh` - install / update script
- `stfu/STFU` - local BM runtime copy used by AllTune2

---

## Current practical guidance

At the time of this writing:

- **BM is the best-tested DMR path in AllTune2**
- **BM quick TG changes are supported**
- **TGIF remains available, but may still show timeout issues on some systems**
- **AllStarLink, EchoLink, and YSF remain part of the supported workflow**

That is the honest current state.

---

## Browser path

Open AllTune2 at:

```text
/alltune2/public/
```

Example:

```text
http://YOUR-IP/alltune2/public/
```

---

## Git safety

The project should not upload local runtime files such as:

- `config.ini`
- local favorites data
- backup files like `*.bak`
- temporary local runtime state

Review changes before every push, especially on live nodes.

---

## Final note

AllTune2 is meant to be practical, readable, and usable on a real live radio node.

The goal is not fancy wording. The goal is a dashboard that makes sense, works quickly, and tells the truth about what is and is not stable.
