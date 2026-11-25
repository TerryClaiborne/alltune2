#!/usr/bin/env bash
# AllTune2 M17D backend through configured public/private nodes.
# WARNING: Temporarily interrupts the normal Analog_Bridge digital path.
# This is the AllTune2 development service launcher; never run it manually.
# Temporarily hands the configured Analog_Bridge USRP receive port to M17D.
# Restores service and removes the temporary link on every ordinary exit.
set -euo pipefail
# M17D-CONFIG-DRIVEN-IDENTITY-20261125
ROOT=/var/www/html/alltune2/m17d
CONFIG="$ROOT/config/m17d.ini"

case "$(uname -m)" in
 aarch64|arm64)
  BIN="$ROOT/bin/m17d-arm64"
  EXPECTED_SHA256=ff1993270094bb52da0b88f2a229b21182c0bc9bcab8746a5f788b2a9de47259
  ;;
 x86_64|amd64)
  BIN="$ROOT/bin/m17amd64"
  EXPECTED_SHA256=82ba4b964a36a2ba58ab5d8ec5d35326d78992aedeb639f98e80a40c989aa887
  ;;
 *)
  echo "STOP: unsupported M17D architecture: $(uname -m)" >&2
  exit 1
  ;;
esac

TARGET="${1:-}"
HOST="${2:-}"
PORT="${3:-}"
MODULE="${4:-}"
DESTINATION="${5:-@ALL}"
linked=0
handoff=0
child=''

fail(){ echo "STOP: $*" >&2; exit 1; }

ini_value(){
  /usr/bin/python3 - "$CONFIG" "$1" "$2" <<'M17_INI_PY'
import configparser
import sys

path, section, key = sys.argv[1:4]

cfg = configparser.ConfigParser(interpolation=None)
with open(path, "r", encoding="utf-8") as fh:
    cfg.read_file(fh)

if not cfg.has_section(section) or not cfg.has_option(section, key):
    raise SystemExit(2)

print(cfg.get(section, key).strip())
M17_INI_PY
}

[[ -r "$CONFIG" ]] || fail "M17D config missing: $CONFIG"

CALLSIGN="$(ini_value identity callsign 2>/dev/null || true)"
NODE="$(ini_value private_node mynode 2>/dev/null || true)"
PRIVATE="$(ini_value private_node private_node 2>/dev/null || true)"
AST="$(ini_value private_node asterisk_bin 2>/dev/null || true)"
SERVICE="$(ini_value private_node analog_bridge_service 2>/dev/null || true)"
USRP_HOST="$(ini_value usrp host 2>/dev/null || true)"
USRP_LISTEN_PORT="$(ini_value usrp listen_port 2>/dev/null || true)"
USRP_SEND_PORT="$(ini_value usrp send_port 2>/dev/null || true)"

CALLSIGN="${CALLSIGN^^}"

[[ "$CALLSIGN" =~ ^[A-Z0-9./-]{1,9}$ ]] ||
  fail "M17 callsign missing or invalid in $CONFIG"

[[ "$NODE" =~ ^[0-9]+$ ]] ||
  fail "M17 mynode missing or invalid in $CONFIG"

[[ "$PRIVATE" =~ ^[0-9]+$ ]] ||
  fail "M17 private_node missing or invalid in $CONFIG"

[[ -x "$AST" ]] ||
  fail "M17 asterisk_bin missing or invalid in $CONFIG"

[[ -n "$SERVICE" ]] ||
  fail "M17 analog_bridge_service missing in $CONFIG"

[[ "$USRP_HOST" == "127.0.0.1" ]] ||
  fail "M17 USRP host must currently be 127.0.0.1"

[[ "$USRP_LISTEN_PORT" =~ ^[0-9]+$ ]] ||
  fail "M17 USRP listen_port missing or invalid"

[[ "$USRP_SEND_PORT" =~ ^[0-9]+$ ]] ||
  fail "M17 USRP send_port missing or invalid"

(( USRP_LISTEN_PORT >= 1 && USRP_LISTEN_PORT <= 65535 )) ||
  fail "M17 USRP listen_port out of range"

(( USRP_SEND_PORT >= 1 && USRP_SEND_PORT <= 65535 )) ||
  fail "M17 USRP send_port out of range"
links(){ "$AST" -rx "rpt lstats $1"; }
link_present(){ "$AST" -rx "rpt nodes $NODE" | grep -Eq "(^|[[:space:],])[TC]${PRIVATE}([[:space:],]|$)"; }
port_owner(){ ss -lunpH | grep -E ":${USRP_LISTEN_PORT}[[:space:]]" || true; }
cleanup(){
 local status=$?; trap - EXIT INT TERM
 echo '=== RESTORE EXISTING DIGITAL AUDIO PATH ==='
 if ((linked)); then
  "$AST" -rx "rpt cmd $NODE ilink 1 $PRIVATE" >/dev/null 2>&1 || true
  for _ in {1..25}; do link_present || break; sleep 0.2; done
 if link_present; then echo "WARNING: private node $PRIVATE link still shown; disconnect manually with AllStar Connect" >&2; status=1; fi
 fi
 if [[ -n "$child" ]]; then
  kill -TERM "$child" 2>/dev/null || true
  wait "$child" 2>/dev/null || true
 fi
 if ((handoff)); then
  if ! systemctl start "$SERVICE"; then echo 'CRITICAL: Analog_Bridge restart failed; inspect systemctl status analog_bridge' >&2; status=1; fi
  if ! systemctl is-active --quiet "$SERVICE"; then echo 'CRITICAL: Analog_Bridge is not running' >&2; status=1; fi
# systemd can be active before Analog_Bridge rebinds the configured USRP port.
  for _ in {1..60}; do
   [[ "$(port_owner)" == *Analog_Bridge* ]] && break
   sleep 0.25
  done
  echo 'Analog_Bridge state:';systemctl is-active "$SERVICE" || true
  echo 'USRP port ownership:';port_owner
  if [[ "$(port_owner)" != *Analog_Bridge* ]]; then echo "CRITICAL: Analog_Bridge has not reclaimed UDP 34001" >&2;status=1;fi
 fi
 exit "$status"
}
[[ $EUID -eq 0 ]] || fail 'Run with sudo'
[[ "$TARGET" == "M17-M17#" || "$TARGET" =~ ^M17-[A-Z0-9-]+[[:space:]][A-Z]$ ]] || fail 'Invalid resolved M17 target'
[[ -n "$HOST" ]] || fail 'Missing M17 reflector host'
[[ "$PORT" =~ ^[0-9]+$ ]] || fail 'Invalid M17 reflector port'
[[ "$MODULE" =~ ^[A-Z]$ ]] || fail 'Invalid M17 module'
[[ "$DESTINATION" == "@ALL" || "$DESTINATION" == "#PARROT" ]] || fail 'Invalid M17 destination' 
# Prevent concurrent executions of the M17 launcher.
exec 9>/run/lock/alltune2-m17d.lock
flock -n 9 || fail 'Another M17 streaming service is running'
[[ -x "$BIN" ]] || fail "M17D binary missing: $BIN"
[[ -r "$CONFIG" ]] || fail "M17D config missing: $CONFIG"
[[ "$(sha256sum "$BIN" | awk '{print $1}')" == "$EXPECTED_SHA256" ]] || fail "M17D binary hash mismatch; no live start"
[[ -f /etc/asterisk/rpt.conf ]] || fail 'Missing rpt.conf'
# Require valid configured public/private local node definitions.
# ASL3 installations may use an explicit local IAX port (for example 4568)
# or omit the port, so do not hard-code a node-specific IAX registration form.
node_registration_present() {
 local check_node="$1"
 grep -Eq "^[[:space:]]*${check_node}[[:space:]]*=[[:space:]]*radio@127\\.0\\.0\\.1(:[0-9]+)?/${check_node},NONE([[:space:]]*(;.*)?)?$" /etc/asterisk/rpt.conf
}

node_registration_present "$NODE" || fail "$NODE local registration not found"
node_registration_present "$PRIVATE" || fail "$PRIVATE local registration not found"
grep -Fqx "rxchannel = USRP/$USRP_HOST:$USRP_LISTEN_PORT:$USRP_SEND_PORT" /etc/asterisk/rpt.conf || fail "$PRIVATE USRP configuration differs"
out=$(links "$PRIVATE") || fail "Cannot query links for $PRIVATE"
echo "$out"
grep -q 'CONNECT STATE' <<< "$out" || fail "Cannot parse links for $PRIVATE"
if awk 'NR>2 && $1~/^[0-9]+$/ {found=1} END {exit found?0:1}' <<< "$out"; then
 fail "Private node $PRIVATE has live links"
fi
[[ -z "$(pgrep -x bmtd || true)" ]] || fail 'BMTD active'
[[ -z "$(pgrep -x tgifd || true)" ]] || fail 'TGIFD active'
[[ -z "$(pgrep -x ipsc3d-arm64 || true)" && -z "$(pgrep -x ipsc3d-amd64 || true)" ]] || fail 'IPSC3D active'
systemctl is-active --quiet "$SERVICE" || fail 'Analog_Bridge not active before handoff'
[[ "$(port_owner)" == *Analog_Bridge* ]] || fail 'Analog_Bridge does not own 34001'
ss -uanpH | grep -E ":${USRP_SEND_PORT}[[:space:]].*asterisk" >/dev/null || fail "Asterisk does not own USRP receive port $USRP_SEND_PORT"
if link_present; then fail "Private node $PRIVATE already linked"; fi
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
echo "=== RELEASE ANALOG_BRIDGE USRP $USRP_LISTEN_PORT ==="
handoff=1
systemctl stop "$SERVICE"
for _ in {1..25}; do [[ -z "$(port_owner)" ]] && break; sleep 0.2; done
[[ -z "$(port_owner)" ]] || fail "USRP port $USRP_LISTEN_PORT still occupied; no M17 launch"
echo '=== START DIRECT M17D USRP BRIDGE ==='
stdbuf -oL -eL "$BIN" --live-usrp-qso --host "$HOST" --port "$PORT" --module "$MODULE" --callsign "$CALLSIGN" --destination "$DESTINATION" --config "$CONFIG" &
child=$!
for _ in {1..30}; do
 if ss -uanpH | grep -E ":${USRP_LISTEN_PORT}[[:space:]]" >/dev/null; then break; fi
 kill -0 "$child" 2>/dev/null || fail 'M17D exited before USRP ready'
 sleep 0.1
done
ss -uanpH | grep -E ":${USRP_LISTEN_PORT}[[:space:]]" >/dev/null || fail "M17D USRP port $USRP_LISTEN_PORT was not opened"
echo "=== $NODE -> $PRIVATE TRANSCEIVE LINK ==="
"$AST" -rx "rpt cmd $NODE ilink 3 $PRIVATE"
linked=1
for _ in {1..30}; do link_present && break; sleep 0.2; done
link_present || fail "Link to $PRIVATE not confirmed"
echo '=== M17 STREAMING ACTIVE; STOP USING ALLTUNE2 DISCONNECT ==='
echo "M17D normal QSO path active on $TARGET through private node $PRIVATE."
set +e
wait "$child"
result=$?
set -e
child=''
if ((result)); then echo "M17D bridge returned $result" >&2; exit "$result"; fi
echo 'M17 streaming session completed; restoring prior audio path.'
