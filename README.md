# AllTune2

© Terry Claiborne - KC3KMV - kc3kmv@yahoo.com

AllTune2 is a web-based control and status dashboard for radio network switching and favorites management on AllStarLink 3 / Debian Bookworm.

It is designed to provide a cleaner, safer control flow for BrandMeister, TGIF, YSF, and AllStar, while keeping the original working AllTune install untouched in `/var/www/html/alltune`.

## Important

**AllTune2 will not work correctly until you edit `/var/www/html/alltune2/config.ini` and enter your real settings.**

**The installer will create a starter config.ini with placeholder values. You must change them before using AllTune2.**

## Features

- Simple web dashboard
- Shared favorites management
- BrandMeister support
- TGIF support
- YSF support
- AllStar support
- DVSwitch auto-load option
- DVSwitch load mode selection:
  - Transceive
  - Local Monitor
- Separate app config file
- Installer script for setup and permissions
- Automatic Asterisk sudoers rule creation during install

## Important paths

- Original app left untouched:
  - `/var/www/html/alltune`
- AllTune2 app:
  - `/var/www/html/alltune2`

## Project structure

- `public/index.php` — main dashboard
- `public/favorites.php` — favorites manager
- `api/connect.php` — connect/disconnect actions
- `api/status.php` — status endpoint
- `app/` — application classes and support code
- `public/assets/js/app.js` — frontend logic
- `public/assets/css/style.css` — frontend styling
- `data/favorites.txt` — shared favorites file
- `config.ini` — local app configuration file
- `config.ini.example` — starter config example
- `setup_alltune2.sh` — install/setup script

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
## Fresh install

Run this from the terminal:

```bash
git clone https://github.com/TerryClaiborne/alltune2.git && cd alltune2 && sudo bash setup_alltune2.sh

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

Or for a direct local example:

```text
http://YOUR-IP/alltune2/public/
```

## Notes

- Dashboard and Status are the same main screen.
- Favorites uses one shared file: `data/favorites.txt`
- AllTune2 uses its own `config.ini` in the app root.
- The working original AllTune app can remain untouched while AllTune2 is tested separately.
- Some users may use Allmon3 instead of Allscan, so AllTune2 should not depend on Allscan existing.

## Git / safety

The project `.gitignore` should prevent uploading local runtime files such as:

- `config.ini`
- `data/favorites.txt`
- `*.bak`
- `*.old`
- `*.orig`
- `*.save`

## Current UI behavior

- BM and TGIF are two-step connects:
  - press Connect once to prepare the network
  - wait until the system is ready
  - press Connect again for the final talkgroup connect
- YSF is a one-step connect
- AllStar is a one-step connect
- DVSwitch auto-load supports:
  - Transceive
  - Local Monitor

## Next steps after install

1. Edit `/var/www/html/alltune2/config.ini` and set real values.
2. Confirm the sudoers file exists and is valid.
3. Open `/alltune2/public/` in the browser.
4. Test BM, TGIF, YSF, AllStar, DVSwitch auto-load, and disconnects.
5. Confirm favorites save correctly.

## License / sharing

This project is being prepared for GitHub-ready installation and sharing.