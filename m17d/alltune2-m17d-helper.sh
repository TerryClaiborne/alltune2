#!/usr/bin/env bash
# Root-only backend boundary. Invoke via the dedicated sudoers entry only.
set -euo pipefail
UNIT=alltune2-m17d.service
WRAPPER=/var/www/html/alltune2/m17d/alltune2-m17d-runner.sh

case "$(/usr/bin/uname -m)" in
  aarch64|arm64)
    BINARY=/var/www/html/alltune2/m17d/bin/m17d-arm64
    ;;
  x86_64|amd64)
    BINARY=/var/www/html/alltune2/m17d/bin/m17amd64
    ;;
  *)
    echo "Unsupported architecture for M17D: $(/usr/bin/uname -m)" >&2
    exit 1
    ;;
esac

READY_FILE=/run/alltune2-m17-ready
STATE_FILE=/var/www/html/alltune2/run/alltune2-m17d.state
HOSTS_URL=https://m17-project.github.io/hostfiles/M17Hosts.json
# M17D-HELPER-CONFIG-DRIVEN-20261125
M17D_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
M17D_CONFIG="$M17D_ROOT/config/m17d.ini"

m17_ini_value(){
  /usr/bin/python3 - "$M17D_CONFIG" "$1" "$2" <<'M17_HELPER_INI_PY'
import configparser
import sys

path, section, key = sys.argv[1:4]

cfg = configparser.ConfigParser(interpolation=None)
with open(path, "r", encoding="utf-8") as fh:
    cfg.read_file(fh)

if not cfg.has_section(section) or not cfg.has_option(section, key):
    raise SystemExit(2)

print(cfg.get(section, key).strip())
M17_HELPER_INI_PY
}

[[ -r "$M17D_CONFIG" ]] || {
  echo "M17D config missing: $M17D_CONFIG" >&2
  exit 1
}

M17_NODE="$(m17_ini_value private_node mynode 2>/dev/null || true)"
M17_PRIVATE="$(m17_ini_value private_node private_node 2>/dev/null || true)"
ASTERISK_BIN="$(m17_ini_value private_node asterisk_bin 2>/dev/null || true)"
ANALOG_BRIDGE_SERVICE="$(m17_ini_value private_node analog_bridge_service 2>/dev/null || true)"
USRP_LISTEN_PORT="$(m17_ini_value usrp listen_port 2>/dev/null || true)"

READY_FILE="$(m17_ini_value runtime ready_file 2>/dev/null || true)"
RETUNE_FILE="$(m17_ini_value runtime retune_file 2>/dev/null || true)"
RETUNE_ACK_FILE="$(m17_ini_value runtime retune_ack_file 2>/dev/null || true)"
STATE_FILE="$(m17_ini_value runtime state_file 2>/dev/null || true)"

[[ "$M17_NODE" =~ ^[0-9]+$ ]] || {
  echo "M17D mynode missing or invalid in $M17D_CONFIG" >&2
  exit 1
}

[[ "$M17_PRIVATE" =~ ^[0-9]+$ ]] || {
  echo "M17D private_node missing or invalid in $M17D_CONFIG" >&2
  exit 1
}

[[ -x "$ASTERISK_BIN" ]] || {
  echo "M17D asterisk_bin missing or invalid in $M17D_CONFIG" >&2
  exit 1
}

[[ -n "$ANALOG_BRIDGE_SERVICE" ]] || {
  echo "M17D analog_bridge_service missing in $M17D_CONFIG" >&2
  exit 1
}

[[ "$USRP_LISTEN_PORT" =~ ^[0-9]+$ ]] || {
  echo "M17D USRP listen_port missing or invalid in $M17D_CONFIG" >&2
  exit 1
}

[[ -n "$READY_FILE" && -n "$RETUNE_FILE" &&
   -n "$RETUNE_ACK_FILE" && -n "$STATE_FILE" ]] || {
  echo "M17D runtime paths are incomplete in $M17D_CONFIG" >&2
  exit 1
}

state_value(){
  local key="$1"
  [[ -r "$STATE_FILE" ]] || return 0
  /usr/bin/awk -F= -v k="$key" '$1==k {sub(/^[^=]*=/,""); print; exit}' "$STATE_FILE"
}

current_target(){
  state_value target
}

write_state(){
  local state_active="$1" state_target="$2" state_host="$3" state_port="$4" state_module="$5"
  /usr/bin/mkdir -p /var/www/html/alltune2/run
  /usr/bin/python3 - "$STATE_FILE" "$state_active" "$state_target" "$state_host" "$state_port" "$state_module" <<'M17_STATE_PY'
from pathlib import Path
import os
import sys
import tempfile

p = Path(sys.argv[1])
text = (
    f"active={sys.argv[2]}\n"
    f"target={sys.argv[3]}\n"
    f"host={sys.argv[4]}\n"
    f"port={sys.argv[5]}\n"
    f"module={sys.argv[6]}\n"
)

fd, tmp = tempfile.mkstemp(prefix=p.name + ".", dir=str(p.parent))
try:
    with os.fdopen(fd, "w") as f:
        f.write(text)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, p)
finally:
    if os.path.exists(tmp):
        os.unlink(tmp)
M17_STATE_PY
  /usr/bin/chown root:www-data "$STATE_FILE"
  /usr/bin/chmod 0640 "$STATE_FILE"
}

resolve_target(){
  /usr/bin/python3 - "$1" "$HOSTS_URL" <<'M17_RESOLVE_PY'
import json
import re
import sys
import urllib.request

raw = re.sub(r'\s+', ' ', sys.argv[1].strip().upper())
parts = raw.rsplit(' ', 1)

if len(parts) != 2:
    raise SystemExit("Target must be REFLECTOR MODULE, for example M17-432 C")

designator, module = parts

if not re.fullmatch(r'M17-[A-Z0-9-]+', designator):
    raise SystemExit("Invalid M17 reflector designator")

if not re.fullmatch(r'[A-Z]', module):
    raise SystemExit("Invalid M17 module")

with urllib.request.urlopen(sys.argv[2], timeout=10) as response:
    data = json.load(response)

matches = [
    row for row in data.get("reflectors", [])
    if str(row.get("designator", "")).upper() == designator
]

if len(matches) != 1:
    raise SystemExit(
        f"Reflector {designator} was not found uniquely in M17Hosts.json"
    )

row = matches[0]

modules = [str(x).upper() for x in row.get("modules", [])]
if module not in modules:
    raise SystemExit(f"Module {module} is not advertised by {designator}")

host = row.get("dns") or row.get("ipv4")
port = row.get("port")

if not host:
    raise SystemExit(f"{designator} has no usable IPv4/DNS host")

if not isinstance(port, int) or not 1 <= port <= 65535:
    raise SystemExit(f"{designator} has no usable reflector port")

print("\t".join((f"{designator} {module}", str(host), str(port), module)))
M17_RESOLVE_PY
}
ready(){
  [[ -r "$READY_FILE" ]] || return 1
  local pid path
  read -r pid < "$READY_FILE" || return 1
  [[ "$pid" =~ ^[0-9]+$ ]] || return 1
  [[ -r "/proc/$pid/exe" ]] || return 1
  path=$(/usr/bin/readlink -f "/proc/$pid/exe") || return 1
  [[ "$path" == "$BINARY" ]] || return 1
  /usr/bin/kill -0 "$pid" 2>/dev/null
}

json() {
  local ok="$1" action="$2" message="$3" active="$4" target="${5:-}"
  local running_state="${6:-false}" ready_state="${7:-false}" recovered_state="${8:-false}"
  /usr/bin/python3 - "$ok" "$action" "$message" "$active" "$target" "$running_state" "$ready_state" "$recovered_state" <<'M17_JSON_PY'
import json
import sys

print(json.dumps(dict(
    ok=sys.argv[1] == 'true',
    action=sys.argv[2],
    message=sys.argv[3],
    active=sys.argv[4] == 'true',
    target=sys.argv[5] if sys.argv[4] == 'true' else '',
    running=sys.argv[6] == 'true',
    ready=sys.argv[7] == 'true',
    audio_recovered=sys.argv[8] == 'true',
)))
M17_JSON_PY
}
active(){ /usr/bin/systemctl is-active --quiet "$UNIT"; }
service_present(){
  local state
  state="$(/usr/bin/systemctl show "$UNIT" -p ActiveState --value 2>/dev/null || true)"
  [[ "$state" == active || "$state" == activating || "$state" == deactivating || "$state" == reloading ]]
}
binary_running(){
  local exe path
  for exe in /proc/[0-9]*/exe; do
    [[ -e "$exe" ]] || continue
    path=$(/usr/bin/readlink -f "$exe" 2>/dev/null || true)
    [[ "$path" == "$BINARY" ]] && return 0
  done
  return 1
}
running(){ service_present || binary_running; }
analog_recovered(){
  ! running && /usr/bin/systemctl is-active --quiet "$ANALOG_BRIDGE_SERVICE" &&     /usr/bin/ss -lunpH | /usr/bin/grep -E ":${USRP_LISTEN_PORT}[[:space:]]" | /usr/bin/grep -q Analog_Bridge
}

port_m17(){ /usr/bin/ss -uanpH | /usr/bin/grep -E ":${USRP_LISTEN_PORT}[[:space:]]" | /usr/bin/grep -E 'm17d-multi|m17d' >/dev/null || return 1; }
linked(){
  # Transceive links are visible in `rpt nodes`.
  if "$ASTERISK_BIN" -rx "rpt nodes $M17_NODE" 2>/dev/null \
      | /usr/bin/grep -Eq "(^|[[:space:],])[TC]${M17_PRIVATE}([[:space:],]|$)"; then
    return 0
  fi

  # app_rpt Local Monitor (ilink 8) intentionally disappears from `rpt nodes`
  # but remains a real connection in `rpt stats`.
  "$ASTERISK_BIN" -rx "rpt stats $M17_NODE" 2>/dev/null \
    | /usr/bin/grep -E 'Nodes currently connected to us' \
    | /usr/bin/grep -Eq "(^|[^0-9])${M17_PRIVATE}([^0-9]|$)"
}
case "${1:-}" in
 status)
   target="$(current_target)"

   if active && port_m17 && linked && ready; then
     json true status 'M17D active.' true "$target" true true false
   elif running; then
     json true status 'M17D running but not ready.' false "" true false false
   else
     write_state false "$target" "" "" ""
     if analog_recovered; then
       json true status 'M17D inactive; audio path recovered.' false "" false false true
     else
       json true status 'M17D inactive; audio path recovery not verified.' false "" false false false
     fi
   fi
   ;;

 start|tune)
   requested="${2:-}"

   [[ -n "$requested" ]] || {
     json false "$1" 'M17 target required.' false ""
     exit 0
   }

   if [[ "${requested^^}" != "M17-M17#" ]]; then
     if ! resolved="$(resolve_target "$requested" 2>&1)"; then
       json false "$1" "$resolved" false ""
       exit 0
     fi
   fi

   destination="@ALL"
   if [[ "${requested^^}" == "M17-M17#" ]]; then
     requested="M17-M17 Z"
     destination="#PARROT"
     resolved="$(resolve_target "$requested" 2>&1)" || {
       json false "$1" "$resolved" false ""
       exit 0
     }
     IFS=$'\t' read -r resolved_target host port module <<<"$resolved"
     target="M17-M17#"
   else
     IFS=$'\t' read -r target host port module <<<"$resolved"
   fi

   [[ -n "$target" ]] || {
     json false "$1" 'Resolved M17 target is empty.' false ""
     exit 0
   }

   [[ -n "$host" ]] || {
     json false "$1" 'Resolved M17 host is empty.' false ""
     exit 0
   }

   [[ "$port" =~ ^[0-9]+$ ]] || {
     json false "$1" 'Resolved M17 port is invalid.' false ""
     exit 0
   }

   [[ "$module" =~ ^[A-Z]$ ]] || {
     json false "$1" 'Resolved M17 module is invalid.' false ""
     exit 0
   }

   [[ -x "$BINARY" && -x "$WRAPPER" ]] || {
     json false "$1" 'M17D binary or launcher not installed.' false ""
     exit 0
   }

   if running; then
     current="$(current_target)"

     if ! active || ! port_m17 || ! linked || ! ready; then
       json false "$1" 'M17D exists but is not ready; stop/recover before retrying.' false "" true false false
       exit 0
     fi

     if [[ "$current" == "$target" ]]; then
       json true "$1" 'M17D already connected.' true "$target" true true false
       exit 0
     fi

     /usr/bin/systemctl stop "$UNIT" >/dev/null 2>&1 || {
       json false "$1" 'Unable to stop current M17 session for retune.' true "$current" true true false
       exit 0
     }

     recovered=false

     for _ in {1..80}; do
       if /usr/bin/systemctl is-active --quiet "$ANALOG_BRIDGE_SERVICE"; then
         if /usr/bin/ss -lunpH | /usr/bin/grep -E ":${USRP_LISTEN_PORT}[[:space:]]" | /usr/bin/grep -q Analog_Bridge; then
           if ! linked; then
             recovered=true
             break
           fi
         fi
       fi
       /usr/bin/sleep 0.25
     done

     if [[ "$recovered" != true ]]; then
       json false "$1" 'M17 retune recovery not verified; new target not started.' false "" false false false
       exit 0
     fi

     write_state false "$current" "" "" ""
   fi
   # The guarded wrapper independently rejects active links, running backends and incorrect port ownership.
   # Reject a stale certificate before starting the new service.
   /usr/bin/rm -f "$READY_FILE"
   /usr/bin/systemctl reset-failed "$UNIT" >/dev/null 2>&1 || true
   if ! /usr/bin/systemd-run --quiet --collect --unit=alltune2-m17d \
     --property=KillMode=mixed --property=TimeoutStopSec=25s \
     /usr/bin/bash "$WRAPPER" "$target" "$host" "$port" "$module" "${destination:-@ALL}" >/dev/null 2>&1; then
     json false "$1" 'Unable to start M17D service.' false; exit 0
   fi
   for _ in {1..60}; do
     if active && port_m17 && linked && ready; then
       # Real reflector ACK, USRP ownership and private link are verified above.
       if active && port_m17 && linked && ready; then
         write_state true "$target" "$host" "$port" "$module"
         json true "$1" 'M17 reflector acknowledged; audio path verified.' true "$target" true true false
         exit 0
       fi
       break
     fi
     active || break
     /usr/bin/sleep 0.2
   done
   # On failure stop service and let its EXIT trap restore normal audio.
   /usr/bin/systemctl stop "$UNIT" >/dev/null 2>&1 || true
   json false "$1" "M17D failed to become ready; verify system journal and Analog_Bridge port $USRP_LISTEN_PORT." false "" false false false
   ;;
 stop)
   if running; then
     /usr/bin/systemctl stop "$UNIT" >/dev/null 2>&1 || { json false stop 'M17 service stop failed.' false "" true false false; exit 0; }
   fi
   for _ in {1..40}; do
     running || break
     /usr/bin/sleep 0.25
   done
   if running; then
     json false stop 'M17 process/service is still present after stop.' false "" true false false
     exit 0
   fi

   # If the M17 EXIT trap did not restore the configured USRP port, repair the normal audio path
   # only after proving the M17 process/service is gone.
   if ! /usr/bin/ss -lunpH | /usr/bin/grep -E ":${USRP_LISTEN_PORT}[[:space:]]" | /usr/bin/grep -q Analog_Bridge; then
     /usr/bin/systemctl restart "$ANALOG_BRIDGE_SERVICE" >/dev/null 2>&1 || true
   fi

   for _ in {1..80}; do
     if analog_recovered && ! linked; then
       /usr/bin/rm -f "$READY_FILE"
       old_target="$(current_target)"
       write_state false "$old_target" "" "" ""
       json true stop "M17 disconnected; Analog_Bridge owns UDP $USRP_LISTEN_PORT." false "" false false true
       exit 0
     fi
     /usr/bin/sleep 0.25
   done
   json false stop "Audio recovery not verified. Inspect Analog_Bridge and private node $M17_PRIVATE before retrying." false "" false false false
   ;;
 *) json false "${1:-invalid}" 'Usage: status|start|tune|stop.' false ;;
esac
