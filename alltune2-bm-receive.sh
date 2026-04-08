#!/bin/bash
set -u

CONFIG_FILE="/var/www/html/alltune2/config.ini"
STFU_DIR="/var/www/html/alltune2/stfu"
STFU_BIN="/var/www/html/alltune2/stfu/STFU"
DVSWITCH_SH="/opt/MMDVM_Bridge/dvswitch.sh"
DVSWITCH_INI="/opt/MMDVM_Bridge/DVSwitch.ini"
PID_FILE="/var/run/alltune2-bm-receive.pid"
STATE_FILE="/var/run/alltune2-bm-receive.state"
LOG_FILE="/var/log/alltune2-bm-receive.log"
VERSION="2026-04-08b"

json_escape() {
    local s=${1-}
    s=${s//\\/\\\\}
    s=${s//"/\\"}
    s=${s//$'\n'/\\n}
    s=${s//$'\r'/\\r}
    s=${s//$'\t'/\\t}
    printf '%s' "$s"
}

service_state() {
    local name="$1"
    if command -v systemctl >/dev/null 2>&1; then
        systemctl is-active "$name" 2>/dev/null || true
    else
        echo "unknown"
    fi
}

read_config_value() {
    local key="$1"
    local file="$2"
    local line value

    [[ -f "$file" ]] || return 1

    line=$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" | tail -n 1 || true)
    [[ -n "$line" ]] || return 1

    value=${line#*=}
    value=$(printf '%s' "$value" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')

    if [[ "$value" == '"'*'"' && ${#value} -ge 2 ]]; then
        value=${value:1:${#value}-2}
    elif [[ "$value" == "'"*"'" && ${#value} -ge 2 ]]; then
        value=${value:1:${#value}-2}
    fi

    printf '%s' "$value"
    return 0
}

load_nodes() {
    MAIN_NODE=$(read_config_value "MYNODE" "$CONFIG_FILE" 2>/dev/null || true)
    DVSWITCH_NODE=$(read_config_value "DVSWITCH_NODE" "$CONFIG_FILE" 2>/dev/null || true)
}

is_numeric_node() {
    [[ "${1-}" =~ ^[0-9]+$ ]]
}

require_file() {
    local file="$1"
    [[ -e "$file" ]] || fail_json "check" "Required file not found: $file"
}

ensure_symlink() {
    mkdir -p "$STFU_DIR"
    ln -sf "$DVSWITCH_INI" "$STFU_DIR/DVSwitch.ini"
}

is_stfu_running() {
    [[ -f "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE" 2>/dev/null)" 2>/dev/null
}

read_state_target() {
    if [[ -f "$STATE_FILE" ]]; then
        grep -E '^TARGET=' "$STATE_FILE" | tail -n 1 | cut -d'=' -f2- || true
    fi
}

write_state() {
    local active="$1"
    local target="$2"
    local pid="${3-}"
    umask 022
    cat > "$STATE_FILE" <<STATE
ACTIVE=$active
TARGET=$target
PID=$pid
MAIN_NODE=$MAIN_NODE
DVSWITCH_NODE=$DVSWITCH_NODE
VERSION=$VERSION
STATE
}

clear_state() {
    rm -f "$STATE_FILE"
}

status_json() {
    local ok="$1"
    local action="$2"
    local message="$3"
    local active="$4"
    local target="$5"
    local pid="$6"
    local stfu_running="false"
    local bridge

    if is_stfu_running; then
        stfu_running="true"
        [[ -z "$pid" && -f "$PID_FILE" ]] && pid=$(cat "$PID_FILE" 2>/dev/null || true)
    fi

    bridge=$(service_state mmdvm_bridge)

    cat <<JSON
{
  "ok": $ok,
  "action": "$(json_escape "$action")",
  "message": "$(json_escape "$message")",
  "active": $active,
  "target": "$(json_escape "$target")",
  "main_node": "$(json_escape "${MAIN_NODE-}")",
  "dvswitch_node": "$(json_escape "${DVSWITCH_NODE-}")",
  "stfu_running": $stfu_running,
  "mmdvm_bridge": "$(json_escape "$bridge")",
  "pid": "$(json_escape "$pid")",
  "config_file": "$(json_escape "$CONFIG_FILE")",
  "state_file": "$(json_escape "$STATE_FILE")",
  "pid_file": "$(json_escape "$PID_FILE")",
  "log_file": "$(json_escape "$LOG_FILE")",
  "version": "$(json_escape "$VERSION")"
}
JSON
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
    local target="$4"
    local pid="${5-}"
    status_json true "$action" "$message" "$active" "$target" "$pid"
    exit 0
}

ensure_node_configured() {
    load_nodes

    if ! is_numeric_node "$MAIN_NODE"; then
        fail_json "check" "MYNODE is not configured in $CONFIG_FILE"
    fi

    if ! is_numeric_node "$DVSWITCH_NODE"; then
        fail_json "check" "DVSWITCH_NODE is not configured in $CONFIG_FILE"
    fi
}

connect_dvswitch_node() {
    /usr/sbin/asterisk -rx "rpt fun ${MAIN_NODE} *3${DVSWITCH_NODE}" >/dev/null
}

disconnect_dvswitch_node() {
    /usr/sbin/asterisk -rx "rpt fun ${MAIN_NODE} *1${DVSWITCH_NODE}" >/dev/null 2>&1 || true
}

start_stfu_process() {
    if is_stfu_running; then
        return 0
    fi

    ensure_symlink
    touch "$LOG_FILE"

    (
        cd "$STFU_DIR" || exit 1
        nohup "$STFU_BIN" >>"$LOG_FILE" 2>&1 &
        echo $! > "$PID_FILE"
    )

    sleep 2

    if ! is_stfu_running; then
        rm -f "$PID_FILE"
        fail_json "start" "STFU failed to start. Check $LOG_FILE"
    fi
}

stop_stfu_process() {
    if is_stfu_running; then
        local pid
        pid=$(cat "$PID_FILE" 2>/dev/null || true)
        if [[ -n "$pid" ]]; then
            kill "$pid" 2>/dev/null || true
            sleep 1
            kill -9 "$pid" 2>/dev/null || true
        fi
        rm -f "$PID_FILE"
    else
        rm -f "$PID_FILE"
        pkill -f '^/var/www/html/alltune2/stfu/STFU$' 2>/dev/null || true
    fi
}

start_mode() {
    local tg="${1-}"
    local pid

    [[ -n "$tg" ]] || fail_json "start" "You must supply a BM talkgroup."
    [[ "$tg" =~ ^[0-9#]+$ ]] || fail_json "start" "Invalid BM talkgroup/private target: $tg"

    ensure_node_configured
    require_file "$STFU_BIN"
    require_file "$DVSWITCH_SH"
    require_file "$DVSWITCH_INI"

    if is_stfu_running; then
        "$DVSWITCH_SH" tune "$tg" >/dev/null 2>&1 || fail_json "start" "STFU is running but tune failed." "$tg"
        pid=$(cat "$PID_FILE" 2>/dev/null || true)
        write_state true "$tg" "$pid"
        ok_json "start" "BM receive mode already active; retuned to $tg." true "$tg" "$pid"
    fi

    if command -v systemctl >/dev/null 2>&1; then
        systemctl stop mmdvm_bridge || fail_json "start" "Failed to stop mmdvm_bridge."
    fi

    connect_dvswitch_node || {
        command -v systemctl >/dev/null 2>&1 && systemctl start mmdvm_bridge >/dev/null 2>&1 || true
        fail_json "start" "Failed to connect DVSwitch node ${DVSWITCH_NODE}."
    }
    sleep 1

    "$DVSWITCH_SH" mode STFU >/dev/null 2>&1 || {
        disconnect_dvswitch_node
        command -v systemctl >/dev/null 2>&1 && systemctl start mmdvm_bridge >/dev/null 2>&1 || true
        fail_json "start" "Failed to switch DVSwitch to STFU mode."
    }
    sleep 1

    start_stfu_process

    "$DVSWITCH_SH" tune "$tg" >/dev/null 2>&1 || {
        stop_stfu_process
        disconnect_dvswitch_node
        command -v systemctl >/dev/null 2>&1 && systemctl start mmdvm_bridge >/dev/null 2>&1 || true
        clear_state
        fail_json "start" "Failed to tune BM target $tg." "$tg"
    }

    pid=$(cat "$PID_FILE" 2>/dev/null || true)
    write_state true "$tg" "$pid"
    ok_json "start" "BM receive mode started on $tg." true "$tg" "$pid"
}

tune_mode() {
    local tg="${1-}"
    local pid

    [[ -n "$tg" ]] || fail_json "tune" "You must supply a BM talkgroup."
    [[ "$tg" =~ ^[0-9#]+$ ]] || fail_json "tune" "Invalid BM talkgroup/private target: $tg"

    ensure_node_configured
    require_file "$DVSWITCH_SH"

    if ! is_stfu_running; then
        fail_json "tune" "BM receive mode is not active." "$tg"
    fi

    "$DVSWITCH_SH" tune "$tg" >/dev/null 2>&1 || fail_json "tune" "Failed to tune BM target $tg." "$tg"
    pid=$(cat "$PID_FILE" 2>/dev/null || true)
    write_state true "$tg" "$pid"
    ok_json "tune" "Retuned BM receive mode to $tg." true "$tg" "$pid"
}

stop_mode() {
    local pid target

    ensure_node_configured
    target=$(read_state_target)
    pid=$(cat "$PID_FILE" 2>/dev/null || true)

    stop_stfu_process
    disconnect_dvswitch_node
    if command -v systemctl >/dev/null 2>&1; then
        systemctl start mmdvm_bridge >/dev/null 2>&1 || true
    fi
    clear_state

    ok_json "stop" "Returned to normal MMDVM_Bridge mode." false "${target-}" "${pid-}"
}

status_mode() {
    local target pid active=false

    load_nodes
    target=$(read_state_target)
    pid=$(cat "$PID_FILE" 2>/dev/null || true)

    if is_stfu_running; then
        active=true
    fi

    if ! is_numeric_node "$MAIN_NODE"; then
        fail_json "check" "MYNODE is not configured in $CONFIG_FILE" "$target" "$pid"
    fi

    if ! is_numeric_node "$DVSWITCH_NODE"; then
        fail_json "check" "DVSWITCH_NODE is not configured in $CONFIG_FILE" "$target" "$pid"
    fi

    if [[ "$active" == true ]]; then
        ok_json "status" "BM receive mode is active." true "$target" "$pid"
    else
        ok_json "status" "BM receive mode is not active." false "$target" "$pid"
    fi
}

case "${1-}" in
    start)
        start_mode "${2-}"
        ;;
    tune)
        tune_mode "${2-}"
        ;;
    stop)
        stop_mode
        ;;
    status)
        status_mode
        ;;
    *)
        cat <<USAGE
Usage:
  sudo $0 start <talkgroup>
  sudo $0 tune <talkgroup>
  sudo $0 stop
  sudo $0 status
USAGE
        exit 1
        ;;
esac
