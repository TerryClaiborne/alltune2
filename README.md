# AllTune2

© Terry Claiborne - KC3KMV - kc3kmv@yahoo.com

AllTune2 is a safer refactor of the original AllTune application for AllStarLink 3 / Debian Bookworm.

The goal is to keep the simple old-AllTune workflow while moving risky connect/disconnect behavior out of fragile UI logic and into backend handling.

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