# AllTune2

AllTune2 is a safer refactor of the original AllTune application for AllStarLink 3 / Debian Bookworm.

The goal is to keep the simple old-AllTune workflow while moving the risky connect/disconnect behavior out of fragile UI logic and into backend handling.

## Project goals

- Keep the original AllTune feel:
  - simple dashboard
  - shared favorites
  - quick network switching
- Make connect/disconnect handling safer and more predictable
- Support one shared favorites system for:
  - BrandMeister
  - TGIF
  - YSF
  - AllStar
- Keep AllTune2 separate from the original AllTune working copy
- Make the project GitHub-ready with a repeatable install path

## Important paths

- Original app left untouched:
  - `/var/www/html/alltune`
- AllTune2 refactor workspace:
  - `/var/www/html/alltune2`

## Current app structure

- Main dashboard:
  - `/var/www/html/alltune2/public/index.php`
- Shared favorites manager:
  - `/var/www/html/alltune2/public/favorites.php`
- Connect/disconnect API:
  - `/var/www/html/alltune2/api/connect.php`
- Status API:
  - `/var/www/html/alltune2/api/status.php`
- Config loader:
  - `/var/www/html/alltune2/app/Support/Config.php`
- Status mapper:
  - `/var/www/html/alltune2/app/State/StatusMapper.php`
- Frontend JavaScript:
  - `/var/www/html/alltune2/public/assets/js/app.js`
- Frontend CSS:
  - `/var/www/html/alltune2/public/assets/css/style.css`

## Required config file

AllTune2 uses its own config file:

- `/var/www/html/alltune2/config.ini`

Example:

```ini
MYNODE="67040"
DVSWITCH_NODE="1957"
BM_SelfcarePassword="CHANGE_ME"
TGIF_HotspotSecurityKey="CHANGE_ME"