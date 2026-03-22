# AllTune2

© Terry Claiborne - KC3KMV - kc3kmv@yahoo.com

A web-based control and status dashboard for radio network switching and favorites management.

## IMPORTANT

**AllTune2 will not work correctly until you edit `/var/www/html/alltune2/config.ini` and enter your real settings.**

**The installer may create a starter `config.ini` with placeholder values. You must change them before using AllTune2.**

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

## Project Structure

- `public/index.php` — main dashboard
- `public/favorites.php` — favorites manager
- `api/connect.php` — connect/disconnect actions
- `api/status.php` — status endpoint
- `app/` — application classes and support code
- `data/favorites.txt` — shared favorites file
- `config.ini` — local app configuration file
- `setup_alltune2.sh` — install/setup script

## Requirements

- Debian / Linux system
- Apache
- PHP
- Asterisk installed at:
  `/usr/sbin/asterisk`

## Install

Run this from the terminal:

```bash
git clone https://github.com/TerryClaiborne/alltune2.git && cd alltune2 && sudo ./setup_alltune2.sh