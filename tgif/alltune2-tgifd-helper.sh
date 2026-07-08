#!/bin/bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html/alltune2}"
TGIFD_DIR="${TGIFD_DIR:-$APP_DIR/tgif}"
CFG_FILE="${CFG_FILE:-$TGIFD_DIR/config/tgifd.ini}"
default_backend_binary() {
  local arch
  arch="$(dpkg --print-architecture 2>/dev/null || uname -m 2>/dev/null || true)"

  case "$arch" in
    amd64|x86_64)
      printf '%s\n' "$TGIFD_DIR/bin/tgifdamd"
      ;;
    arm64|aarch64)
      printf '%s\n' "$TGIFD_DIR/bin/tgifd"
      ;;
    *)
      echo "Unsupported architecture for TGIFD helper: ${arch:-unknown}. Supported: arm64/aarch64 and amd64/x86_64." >&2
      return 1
      ;;
  esac
}

BIN_FILE="${BIN_FILE:-$(default_backend_binary)}" || exit 1
DVSWITCH_SH="${DVSWITCH_SH:-/opt/MMDVM_Bridge/dvswitch.sh}"
RUN_DIR="$APP_DIR/run"
LOG_DIR="$APP_DIR/logs"
PID_FILE="$RUN_DIR/alltune2-tgifd.pid"
STATE_FILE="$RUN_DIR/alltune2-tgifd.state"
LOG_FILE="$LOG_DIR/tgifd.log"
ALLTUNE_CFG="$APP_DIR/config.ini"

json_escape() {
python3 - <<'PY' "$1"
import json,sys
print(json.dumps(sys.argv[1]))
PY
}

ensure_dirs() {
  mkdir -p "$RUN_DIR" "$LOG_DIR"
  chmod 755 "$RUN_DIR" "$LOG_DIR" || true
}

service_state() {
  local name="$1"
  systemctl is-active "$name" 2>/dev/null || true
}

read_ini_value() {
  local section="$1"
  local key="$2"
  local file="$3"
  awk -F= -v s="$section" -v k="$key" '
    BEGIN{insec=0}
    /^[[:space:]]*\[/ {
      sec=$0
      gsub(/^[[:space:]]*\[/,"",sec)
      gsub(/\][[:space:]]*$/,"",sec)
      insec=(sec==s)
      next
    }
    insec && $1 ~ "^[[:space:]]*" k "[[:space:]]*$" {
      v=$2
      sub(/;.*/, "", v)
      gsub(/\r/, "", v)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", v)
      if (v ~ /^".*"$/) {
        sub(/^"/, "", v)
        sub(/"$/, "", v)
      }
      print v
      exit
    }
  ' "$file" 2>/dev/null
}

write_ini_value() {
  local section="$1"
  local key="$2"
  local value="$3"
  local file="$4"
  python3 - "$section" "$key" "$value" "$file" <<'PY'
import configparser, sys
section,key,value,file=sys.argv[1:]
cp=configparser.ConfigParser(interpolation=None)
cp.optionxform=str
cp.read(file)
if section not in cp:
    cp[section] = {}
cp[section][key] = value
with open(file, 'w') as f:
    cp.write(f)
PY
}

sync_from_alltune() {
  [[ -f "$ALLTUNE_CFG" ]] || return 0
  local key mynode dvnode
  key="$(awk -F= '/^[[:space:]]*TGIF_HotspotSecurityKey[[:space:]]*=/{v=$2; sub(/;.*/,"",v); gsub(/\r/,"",v); gsub(/^[[:space:]]+|[[:space:]]+$/,"",v); print v; exit}' "$ALLTUNE_CFG" 2>/dev/null || true)"
  mynode="$(awk -F= '/^[[:space:]]*MYNODE[[:space:]]*=/{v=$2; sub(/;.*/,"",v); gsub(/\r/,"",v); gsub(/^[[:space:]]+|[[:space:]]+$/,"",v); print v; exit}' "$ALLTUNE_CFG" 2>/dev/null || true)"
  dvnode="$(awk -F= '/^[[:space:]]*DVSWITCH_NODE[[:space:]]*=/{v=$2; sub(/;.*/,"",v); gsub(/\r/,"",v); gsub(/^[[:space:]]+|[[:space:]]+$/,"",v); print v; exit}' "$ALLTUNE_CFG" 2>/dev/null || true)"
  if [[ -n "$key" && "$key" != "CHANGE_ME" ]]; then
    write_ini_value "tgif" "security_key" "$key" "$CFG_FILE"
  fi
  if [[ -n "$mynode" ]]; then
    write_ini_value "private_node" "mynode" "$mynode" "$CFG_FILE"
    write_ini_value "private_node" "enabled" "true" "$CFG_FILE"
  fi
  if [[ -n "$dvnode" ]]; then
    write_ini_value "private_node" "private_node" "$dvnode" "$CFG_FILE"
  fi
}

find_pid() {
  if [[ -f "$PID_FILE" ]]; then
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      echo "$pid"
      return 0
    fi
  fi
  pgrep -af "$BIN_FILE $CFG_FILE" 2>/dev/null | awk '{print $1; exit}' || true
}

read_target() {
  read_ini_value "tgif" "startup_tg" "$CFG_FILE"
}

read_mynode() {
  read_ini_value "private_node" "mynode" "$CFG_FILE"
}

read_private_node() {
  read_ini_value "private_node" "private_node" "$CFG_FILE"
}

read_tlv_rx_port() {
  local port
  port="$(read_ini_value "tlv" "rx_port" "$CFG_FILE")"
  if [[ -z "$port" ]]; then
    echo "31103"
  else
    echo "$port"
  fi
}

link_present() {
  local main_node="$1"
  local private_node="$2"
  [[ -n "$main_node" && -n "$private_node" ]] || return 1

  # Asterisk shows connected private nodes as tokens like T<node> or C<node>.
  # Match token boundaries so a partial number cannot create a false hit.
  /usr/sbin/asterisk -rx "rpt nodes ${main_node}" 2>/dev/null \
    | grep -Eq "(^|[[:space:]])[TC]${private_node}([[:space:]]|$)"
}

write_state() {
  local active="$1" target="$2" pid="${3-}"
  cat > "$STATE_FILE" <<EOF
active=$active
target=$target
pid=$pid
EOF
  [[ -n "$pid" ]] && echo "$pid" > "$PID_FILE" || rm -f "$PID_FILE"
}

clear_state() {
  rm -f "$STATE_FILE" "$PID_FILE"
}

status_json() {
  local ok="$1" action="$2" message="$3" active="$4" target="$5" pid="$6"
  local main_node private_node link="false"
  main_node="$(read_mynode)"
  private_node="$(read_private_node)"
  if link_present "$main_node" "$private_node"; then link="true"; fi
  cat <<EOF
{
  "ok": ${ok},
  "action": $(json_escape "$action"),
  "message": $(json_escape "$message"),
  "active": ${active},
  "target": $(json_escape "$target"),
  "tgif_running": $( [[ -n "$pid" ]] && echo true || echo false ),
  "mmdvm_bridge": $(json_escape "$(service_state mmdvm_bridge)"),
  "analog_bridge": $(json_escape "$(service_state analog_bridge)"),
  "pid": $(json_escape "$pid"),
  "config_file": $(json_escape "$CFG_FILE"),
  "state_file": $(json_escape "$STATE_FILE"),
  "pid_file": $(json_escape "$PID_FILE"),
  "log_file": $(json_escape "$LOG_FILE"),
  "private_node_linked": ${link}
}
EOF
}

fail_json() {
  local action="$1" message="$2" target="${3-}" pid="${4-}"
  status_json false "$action" "$message" false "$target" "$pid"
  exit 1
}

ok_json() {
  local action="$1" message="$2" active="$3" target="${4-}" pid="${5-}"
  status_json true "$action" "$message" "$active" "$target" "$pid"
  exit 0
}

require_file() {
  [[ -e "$1" ]] || fail_json "check" "Required file not found: $1"
}

disconnect_private_node() {
  local main_node private_node
  main_node="$(read_mynode)"
  private_node="$(read_private_node)"
  [[ -n "$main_node" && -n "$private_node" ]] || return 0
  /usr/sbin/asterisk -rx "rpt cmd ${main_node} ilink 1 ${private_node}" >/dev/null 2>&1 || true
}

refresh_private_node() {
  local main_node private_node
  main_node="$(read_mynode)"
  private_node="$(read_private_node)"
  [[ -n "$main_node" && -n "$private_node" ]] || return 0

  /usr/sbin/asterisk -rx "rpt cmd ${main_node} ilink 1 ${private_node}" >/dev/null 2>&1 || true
  sleep 0.50
  /usr/sbin/asterisk -rx "rpt cmd ${main_node} ilink 3 ${private_node}" >/dev/null 2>&1 || true
  sleep 1.50
}


wait_for_private_node_link() {
  local main_node private_node
  main_node="$(read_mynode)"
  private_node="$(read_private_node)"
  [[ -n "$main_node" && -n "$private_node" ]] || return 0

  for _ in 1 2 3 4 5 6 7 8 9 10; do
    if link_present "$main_node" "$private_node"; then
      return 0
    fi
    sleep 0.25
  done

  return 0
}

settle_audio_path() {
  # Keep the private-node refresh only. COP 6 priming removed.
  sleep 1.25
  refresh_private_node
  sleep 1.25
}

stop_proc() {
  local pid
  pid="$(find_pid)"

  if [[ -n "$pid" ]]; then
    kill "$pid" >/dev/null 2>&1 || true

    for _ in 1 2 3 4 5 6 7 8 9 10; do
      if ! kill -0 "$pid" 2>/dev/null; then
        break
      fi
      sleep 0.1
    done

    if kill -0 "$pid" 2>/dev/null; then
      kill -9 "$pid" >/dev/null 2>&1 || true
    fi
  fi

  clear_state
}

wait_for_pid() {
  local pid=""

  for _ in 1 2 3 4 5 6 7 8 9 10; do
    pid="$(find_pid)"
    if [[ -n "$pid" ]]; then
      echo "$pid"
      return 0
    fi
    sleep 0.1
  done

  return 1
}

send_tlv_tune() {
  local tg="$1"
  local rx_port="$2"
  python3 - "$tg" "$rx_port" <<'PY'
import socket, sys
tg = sys.argv[1].encode()
port = int(sys.argv[2])
sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
pkts = [
    bytes([0x03, len(tg)]) + tg,
    bytes([0x05, len(b"txTg=" + tg)]) + (b"txTg=" + tg),
]
for pkt in pkts:
    sock.sendto(pkt, ("127.0.0.1", port))
sock.close()
PY
}


tune_analog_bridge_target() {
  local target="$1"
  [[ -x "$DVSWITCH_SH" ]] || return 0

  "$DVSWITCH_SH" mode DMR >/dev/null 2>&1 || true
  "$DVSWITCH_SH" tune "$target" >/dev/null 2>&1 || true
}

start_backend() {
  local target="$1"
  sync_from_alltune
  systemctl stop mmdvm_bridge >/dev/null 2>&1 || true
  systemctl restart analog_bridge >/dev/null 2>&1 || true
  tune_analog_bridge_target "$target"
  stop_proc
  (
    cd "$TGIFD_DIR" || exit 1
    # TGIFD writes to log_file in tgifd.ini. Do not also redirect stdout/stderr
    # to the same file, or every TGIFD log line is duplicated.
    nohup "$BIN_FILE" "$CFG_FILE" >/dev/null 2>&1 &
  )
  local pid
  pid="$(wait_for_pid)" || fail_json "start" "TGIFD failed to start. Check /var/www/html/alltune2/logs/tgifd.log." "$target"

  write_state true "$target" "$pid"

  # Do not refresh the private DVSwitch node after TGIFD is already live.
  # On faster nodes this delayed refresh can cause a second TGIF audio blip.
  # Wait briefly for the existing TGIFD/private-node attach to settle before
  # reporting success to the web UI.
  wait_for_private_node_link

  ok_json "start" "TGIFD started." true "$target" "$pid"
}

start_mode() {
  local tg="${1-}"
  ensure_dirs
  require_file "$BIN_FILE"
  require_file "$CFG_FILE"
  [[ -n "$tg" && "$tg" =~ ^[0-9]+$ ]] || fail_json "start" "Invalid TGIF talkgroup." "$tg"
  write_ini_value "tgif" "startup_tg" "$tg" "$CFG_FILE"
  write_ini_value "tgif" "options" "StartRef=${tg};RelinkTime=60" "$CFG_FILE"
  if [[ -n "$(find_pid)" ]]; then
    tune_mode "$tg"
  fi
  start_backend "$tg"
}

tune_mode() {
  local tg="${1-}"
  ensure_dirs
  require_file "$BIN_FILE"
  require_file "$CFG_FILE"
  [[ -n "$tg" && "$tg" =~ ^[0-9]+$ ]] || fail_json "tune" "Invalid TGIF talkgroup." "$tg"
  write_ini_value "tgif" "startup_tg" "$tg" "$CFG_FILE"
  write_ini_value "tgif" "options" "StartRef=${tg};RelinkTime=60" "$CFG_FILE"
  sync_from_alltune

  # Reliability first: live TGIF retune can leave stale TLV/Analog_Bridge
  # receive state after local PTT or TG changes. A controlled restart matches
  # the manual Disconnect DVSwitch -> Connect flow that restores audio.
  start_backend "$tg"
}

stop_mode() {
  local pid target
  target="$(read_target)"
  pid="$(find_pid)"
  stop_proc
  disconnect_private_node
  # Do not restart mmdvm_bridge after TGIFD disconnect.
  # Other DVSwitch modes start mmdvm_bridge when they need it.
  systemctl stop mmdvm_bridge >/dev/null 2>&1 || true

  # Do not pass the old PID to status_json after stop. The process was
  # already stopped, so passing the stale PID made tgif_running report true.
  ok_json "stop" "Returned to normal mode." false "$target" ""
}

status_mode() {
  ensure_dirs
  local pid target
  pid="$(find_pid)"
  target="$(read_target)"
  if [[ -n "$pid" ]]; then
    ok_json "status" "TGIFD is active." true "$target" "$pid"
  fi
  ok_json "status" "TGIFD is not active." false "$target" ""
}

case "${1-}" in
  start) start_mode "${2-}" ;;
  tune) tune_mode "${2-}" ;;
  stop) stop_mode ;;
  status) status_mode ;;
  *) echo "Usage: $0 {start|tune|stop|status} [tg]" >&2; exit 1 ;;
esac
