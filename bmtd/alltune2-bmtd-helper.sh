#!/bin/bash
set -uo pipefail

APP_DIR="${APP_DIR:-/var/www/html/alltune2}"
BMTD_DIR="${BMTD_DIR:-$APP_DIR/bmtd}"
CFG_FILE="${CFG_FILE:-$BMTD_DIR/config/bmtd.ini}"
BIN_FILE="${BIN_FILE:-$BMTD_DIR/bin/bmtd}"
DVSWITCH_SH="${DVSWITCH_SH:-/opt/MMDVM_Bridge/dvswitch.sh}"
RUN_DIR="$APP_DIR/run"
LOG_DIR="$APP_DIR/logs"
PID_FILE="$RUN_DIR/alltune2-bmtd.pid"
STATE_FILE="$RUN_DIR/alltune2-bmtd.state"
TMP_CFG="$RUN_DIR/alltune2-bmtd-live.ini"
LOG_FILE="$LOG_DIR/bmtd.log"
ALLTUNE_CFG="$APP_DIR/config.ini"

json_escape() {
python3 - "$1" <<'JSON_ESCAPE'
import json
import sys
print(json.dumps(sys.argv[1]))
JSON_ESCAPE
}

ensure_dirs() {
  mkdir -p "$RUN_DIR" "$LOG_DIR"
  chmod 755 "$RUN_DIR" "$LOG_DIR" >/dev/null 2>&1 || true
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

read_config_value() {
  local key="$1"
  awk -F= -v k="$key" '
    $1 ~ "^[[:space:]]*" k "[[:space:]]*$" {
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
  ' "$ALLTUNE_CFG" 2>/dev/null
}

read_mynode() {
  read_config_value "MYNODE"
}

read_private_node() {
  read_config_value "DVSWITCH_NODE"
}

valid_target() {
  local target="$1"
  [[ -n "$target" && "$target" =~ ^[0-9]+#?$ ]]
}

listener_31100() {
  ss -H -lunp 2>/dev/null | awk '$4 ~ /:31100$/ && $0 ~ /Analog_Bridge/ {found=1} END {exit found ? 0 : 1}'
}

link_present() {
  local main_node="$1"
  local private_node="$2"
  [[ -n "$main_node" && -n "$private_node" ]] || return 1

  /usr/sbin/asterisk -rx "rpt nodes ${main_node}" 2>/dev/null \
    | grep -Eq "(^|[[:space:]])[TC]${private_node}([[:space:]]|$)"
}

write_state() {
  local active="$1"
  local target="$2"
  local pid="${3-}"

  cat > "$STATE_FILE" <<EOF
active=$active
target=$target
pid=$pid
EOF

  if [[ -n "$pid" ]]; then
    echo "$pid" > "$PID_FILE"
  else
    rm -f "$PID_FILE"
  fi
}

clear_state() {
  rm -f "$STATE_FILE" "$PID_FILE" "$TMP_CFG"
}

status_json() {
  local ok="$1"
  local action="$2"
  local message="$3"
  local active="$4"
  local target="$5"
  local pid="$6"
  local main_node private_node link="false"

  main_node="$(read_mynode)"
  private_node="$(read_private_node)"

  if link_present "$main_node" "$private_node"; then
    link="true"
  fi

  cat <<EOF
{
  "ok": ${ok},
  "action": $(json_escape "$action"),
  "message": $(json_escape "$message"),
  "active": ${active},
  "target": $(json_escape "$target"),
  "bmtd_running": $( [[ -n "$pid" ]] && echo true || echo false ),
  "mmdvm_bridge": $(json_escape "$(service_state mmdvm_bridge)"),
  "analog_bridge": $(json_escape "$(service_state analog_bridge)"),
  "pid": $(json_escape "$pid"),
  "config_file": $(json_escape "$TMP_CFG"),
  "state_file": $(json_escape "$STATE_FILE"),
  "pid_file": $(json_escape "$PID_FILE"),
  "log_file": $(json_escape "$LOG_FILE"),
  "private_node_linked": ${link}
}
EOF
}

fail_json() {
  local action="$1"
  local message="$2"
  local target="${3-}"
  local pid="${4-}"
  status_json false "$action" "$message" false "$target" "$pid"
  exit 1
}

ok_json() {
  local action="$1"
  local message="$2"
  local active="$3"
  local target="${4-}"
  local pid="${5-}"
  status_json true "$action" "$message" "$active" "$target" "$pid"
  exit 0
}

require_file() {
  [[ -e "$1" ]] || fail_json "check" "Required file not found: $1"
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

  pgrep -af "$BIN_FILE $TMP_CFG" 2>/dev/null | awk '{print $1; exit}' || true
}

disconnect_private_node() {
  local main_node private_node
  main_node="$(read_mynode)"
  private_node="$(read_private_node)"
  [[ -n "$main_node" && -n "$private_node" ]] || return 0

  /usr/sbin/asterisk -rx "rpt cmd ${main_node} ilink 1 ${private_node}" >/dev/null 2>&1 || true
}

make_live_config() {
  local target="$1"

  cp "$CFG_FILE" "$TMP_CFG"

  cat >> "$TMP_CFG" <<EOF

; Runtime values managed by AllTune2.
[tlv]
rx_port=31103
tx_host=127.0.0.1
tx_port=31100

[behavior]
startup_tg=${target}

[testing]
enable_bm_network=true
EOF
}

prepare_audio_path() {
  local main_node private_node

  # 2-second target audio-safe path.
  # This is a test. Keep private-node link and Analog_Bridge restart.
  systemctl stop mmdvm_bridge >/dev/null 2>&1 || true

  main_node="$(read_mynode)"
  private_node="$(read_private_node)"

  if [[ -n "$main_node" && -n "$private_node" ]]; then
    /usr/sbin/asterisk -rx "rpt cmd ${main_node} ilink 1 ${private_node}" >/dev/null 2>&1 || true
    sleep 0.05

    /usr/sbin/asterisk -rx "rpt cmd ${main_node} ilink 3 ${private_node}" >/dev/null 2>&1 || true
    sleep 0.15
  fi

  if systemctl is-active --quiet analog_bridge; then
    systemctl restart analog_bridge >/dev/null 2>&1 || true
  else
    systemctl start analog_bridge >/dev/null 2>&1 || true
  fi

  for _ in 1 2 3; do
    listener_31100 && break
    sleep 0.05
  done

  return 0
}

stop_proc() {
  local pid
  pid="$(find_pid)"

  if [[ -n "$pid" ]]; then
    kill "$pid" >/dev/null 2>&1 || true

    for _ in 1 2 3 4 5; do
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

  for _ in 1 2 3 4 5; do
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
  local target="$1"

  python3 - "$target" <<'BMTD_TLV_TUNE'
import socket
import sys

target = sys.argv[1].encode("ascii")

if len(target) > 255:
    raise SystemExit("target too long")

sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
sock.sendto(bytes([0x03, len(target)]) + target, ("127.0.0.1", 31103))
sock.close()
BMTD_TLV_TUNE
}

start_backend() {
  local target="$1"
  ensure_dirs
  require_file "$BIN_FILE"
  require_file "$CFG_FILE"
  valid_target "$target" || fail_json "start" "Invalid BM talkgroup/private target." "$target"

  prepare_audio_path
  stop_proc
  make_live_config "$target"

  (
    cd "$BMTD_DIR" || return 1
    nohup "$BIN_FILE" "$TMP_CFG" >/dev/null 2>&1 &
  )

  local pid
  pid="$(wait_for_pid)" || fail_json "start" "BMTD failed to start. Check $LOG_FILE." "$target"

  tune_analog_bridge_target "$target"
  write_state true "$target" "$pid"
  ok_json "start" "BMTD started." true "$target" "$pid"
}

start_mode() {
  local target="${1-}"
  start_backend "$target"
}

tune_mode() {
  local target="${1-}"
  ensure_dirs
  require_file "$BIN_FILE"
  require_file "$CFG_FILE"
  valid_target "$target" || fail_json "tune" "Invalid BM talkgroup/private target." "$target"

  local pid
  pid="$(find_pid)"

  if [[ -z "$pid" ]]; then
    start_backend "$target"
  fi

  send_tlv_tune "$target"
  write_state true "$target" "$pid"
  ok_json "tune" "BMTD tuned." true "$target" "$pid"
}

tune_analog_bridge_target() {
  local target="$1"

  if [[ ! -x "$DVSWITCH_SH" ]]; then
    fail_json "start" "dvswitch.sh is missing or not executable." "$target"
  fi

  "$DVSWITCH_SH" tune "$target" >/dev/null 2>&1 || {
    stop_proc
    reset_analog_bridge_tg0
    disconnect_private_node
    clear_state
    fail_json "start" "Failed to tune BM target $target." "$target"
  }
}

reset_analog_bridge_tg0() {
  if [[ -x "$DVSWITCH_SH" ]]; then
    "$DVSWITCH_SH" tune 0 >/dev/null 2>&1 || true
  fi
}

stop_mode() {
  local main_node private_node

  stop_proc
  reset_analog_bridge_tg0
  disconnect_private_node
  systemctl stop mmdvm_bridge >/dev/null 2>&1 || true

  main_node="$(read_mynode)"
  private_node="$(read_private_node)"

  if [[ -n "$main_node" && -n "$private_node" ]]; then
    for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
      if ! link_present "$main_node" "$private_node"; then
        break
      fi
      sleep 0.1
    done
  fi

  status_json true "stop" "Returned to normal mode." false "" ""
}

status_mode() {
  ensure_dirs
  local pid target
  pid="$(find_pid)"
  target=""

  if [[ -f "$STATE_FILE" ]]; then
    target="$(awk -F= '/^target=/{print $2; exit}' "$STATE_FILE" 2>/dev/null || true)"
  fi

  if [[ -n "$pid" ]]; then
    ok_json "status" "BMTD is active." true "$target" "$pid"
  fi

  ok_json "status" "BMTD is not active." false "$target" ""
}

case "${1-}" in
  start) start_mode "${2-}" ;;
  tune) tune_mode "${2-}" ;;
  stop) stop_mode ;;
  status) status_mode ;;
  *) echo "Usage: $0 {start|tune|stop|status} [target]" >&2; exit 1 ;;
esac
