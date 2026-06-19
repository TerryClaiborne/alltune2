#!/bin/bash
set -euo pipefail

HB_DIR="${HB_DIR:-/var/www/html/alltune2/tgif-hblink}"
MMDVM_INI="${MMDVM_INI:-/opt/MMDVM_Bridge/MMDVM_Bridge.ini}"
MMDVM_HBLINK_INI="${MMDVM_HBLINK_INI:-/var/www/html/alltune2/tgif-hblink/MMDVM_Bridge.hblink.ini}"
ORIG_INI="${ORIG_INI:-/var/www/html/alltune2/tgif-hblink/MMDVM_Bridge.pre-hblink.ini}"
PID_FILE="/var/www/html/alltune2/run/alltune2-hblink-audio.pid"
STATE_FILE="/var/www/html/alltune2/run/alltune2-hblink-audio.state"
LOG_FILE="/dev/null"
RUN_DIR="/var/www/html/alltune2/run"
LOG_DIR="/var/www/html/alltune2/logs"
PRIMARY_CONFIG_FILE="/var/www/html/alltune2/config.ini"
FALLBACK_CONFIG_FILE="/var/www/html/alltune/config.ini"
CONFLICT_HBLINK_MATCH='/opt/hblink3/venv/bin/python hblink.py'
HB_PORT=62033

json_escape() {
    python3 - <<'PY' "$1"
import json, sys
print(json.dumps(sys.argv[1]))
PY
}

service_state() {
    local name="$1"
    if command -v systemctl >/dev/null 2>&1; then
        systemctl is-active "$name" 2>/dev/null || true
    else
        echo "unknown"
    fi
}

ensure_dirs() {
    mkdir -p "$RUN_DIR" "$LOG_DIR"
    chmod 755 "$RUN_DIR" "$LOG_DIR" || true
}

read_simple_ini_value() {
    local key="$1"
    local file="$2"
    [[ -f "$file" ]] || return 0
    awk -F= -v k="$key" '
        /^[[:space:]]*[#;]/ { next }
        $0 ~ "^[[:space:]]*" k "[[:space:]]*=" {
            v=$0
            sub(/^[^=]*=/, "", v)
            sub(/[[:space:]]*[;#].*$/, "", v)
            gsub(/\r/, "", v)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", v)
            gsub(/^["'"'"']|["'"'"']$/, "", v)
            print v
            exit
        }
    ' "$file" 2>/dev/null
}

resolve_config_file() {
    if [[ -f "$PRIMARY_CONFIG_FILE" ]]; then
        printf '%s' "$PRIMARY_CONFIG_FILE"
        return 0
    fi
    if [[ -f "$FALLBACK_CONFIG_FILE" ]]; then
        printf '%s' "$FALLBACK_CONFIG_FILE"
        return 0
    fi
    return 1
}

read_config_value() {
    local key="$1"
    local file value
    for file in "$PRIMARY_CONFIG_FILE" "$FALLBACK_CONFIG_FILE"; do
        [[ -f "$file" ]] || continue
        value="$(read_simple_ini_value "$key" "$file")"
        if [[ -n "${value:-}" ]]; then
            printf '%s' "$value"
            return 0
        fi
    done
    return 1
}

read_main_node() {
    read_config_value "MYNODE" || true
}

read_dvswitch_node() {
    read_config_value "DVSWITCH_NODE" || true
}

read_local_tg() {
    local tg
    tg="$(read_simple_ini_value "gatewayDmrId" "/opt/Analog_Bridge/Analog_Bridge.ini")"
    if [[ ! "$tg" =~ ^[0-9]+$ ]]; then
        tg="$(read_simple_ini_value "txTg" "/opt/Analog_Bridge/Analog_Bridge.ini")"
    fi
    printf '%s' "$tg"
}

find_bridge_pid() {
    pgrep -af 'bridge.py' 2>/dev/null | awk '/\/var\/www\/html\/alltune2\/tgif-hblink\/venv\/bin\/python bridge\.py|\.\/venv\/bin\/python bridge\.py/ {print $1; exit}' || true
}

list_bridge_pids() {
    pgrep -af 'bridge.py' 2>/dev/null | awk '/\/var\/www\/html\/alltune2\/tgif-hblink\/venv\/bin\/python bridge\.py|\.\/venv\/bin\/python bridge\.py/ {print $1}' || true
}

kill_bridge_pids() {
    local pids
    pids="$(list_bridge_pids)"
    if [[ -n "$pids" ]]; then
        echo "$pids" | xargs -r kill >/dev/null 2>&1 || true
        sleep 1
        pids="$(list_bridge_pids)"
        if [[ -n "$pids" ]]; then
            echo "$pids" | xargs -r kill -9 >/dev/null 2>&1 || true
            sleep 1
        fi
    fi
    rm -f "$PID_FILE" || true
}

wait_port_clear() {
    local tries=40
    while (( tries > 0 )); do
        if ! ss -lunp 2>/dev/null | grep -q ":${HB_PORT}\b"; then
            return 0
        fi
        sleep 0.25
        tries=$((tries - 1))
    done
    return 1
}


wait_for_bridge_pid() {
    local tries="${1:-40}"
    while (( tries > 0 )); do
        local pid
        pid="$(find_bridge_pid)"
        if [[ -n "$pid" ]]; then
            printf '%s' "$pid"
            return 0
        fi
        sleep 0.10
        tries=$((tries - 1))
    done
    return 1
}

wait_for_service_active() {
    local service_name="$1"
    local tries="${2:-40}"
    while (( tries > 0 )); do
        if [[ "$(service_state "$service_name")" == "active" ]]; then
            return 0
        fi
        sleep 0.10
        tries=$((tries - 1))
    done
    return 1
}

reload_bridge_runtime() {
    local action="$1"
    local target="$2"
    local pid
    pid="$(find_bridge_pid)"
    [[ -n "$pid" ]] || fail_json "$action" "HBLink bridge is not running for runtime reload." "$target"
    kill -HUP "$pid" >/dev/null 2>&1 || fail_json "$action" "Failed to signal HBLink bridge runtime reload." "$target" "$pid"
    sleep 0.25
    kill -0 "$pid" >/dev/null 2>&1 || fail_json "$action" "HBLink bridge exited during runtime reload." "$target" "$pid"
    echo "$pid" > "$PID_FILE"
}

link_present() {
    local main_node="$1"
    local dvs_node="$2"
    /usr/sbin/asterisk -rx "rpt nodes ${main_node}" 2>/dev/null | grep -Eq "[TC]${dvs_node}"
}

local_audio_link_present() {
    local main_node dvs_node
    main_node="$(read_main_node)"
    dvs_node="$(read_dvswitch_node)"

    [[ "$main_node" =~ ^[0-9]+$ ]] || return 1
    [[ "$dvs_node" =~ ^[0-9]+$ ]] || return 1

    link_present "$main_node" "$dvs_node"
}

wait_for_local_audio_link() {
    local main_node="$1"
    local dvs_node="$2"
    local tries=45
    while (( tries > 0 )); do
        if link_present "$main_node" "$dvs_node"; then
            return 0
        fi
        sleep 1
        tries=$((tries - 1))
    done
    return 1
}

connect_local_audio_link() {
    local action="$1"
    local target="$2"
    local main_node dvs_node config_used
    main_node="$(read_main_node)"
    dvs_node="$(read_dvswitch_node)"
    config_used="$(resolve_config_file || true)"

    [[ "$main_node" =~ ^[0-9]+$ ]] || fail_json "$action" "MYNODE missing in config.ini. Checked: ${PRIMARY_CONFIG_FILE} and ${FALLBACK_CONFIG_FILE}" "$target"
    [[ "$dvs_node" =~ ^[0-9]+$ ]] || fail_json "$action" "DVSWITCH_NODE missing in config.ini. Checked: ${PRIMARY_CONFIG_FILE} and ${FALLBACK_CONFIG_FILE}" "$target"

    if link_present "$main_node" "$dvs_node"; then
        return 0
    fi

    /usr/sbin/asterisk -rx "rpt cmd ${main_node} ilink 1 ${dvs_node}" >/dev/null 2>&1 || true
    sleep 1
    /usr/sbin/asterisk -rx "rpt cmd ${main_node} ilink 3 ${dvs_node}" >/dev/null 2>&1 || true

    wait_for_local_audio_link "$main_node" "$dvs_node" || fail_json "$action" "Local audio link did not come up in time using MYNODE=${main_node} DVSWITCH_NODE=${dvs_node} from ${config_used:-unknown config}." "$target"
}

drop_local_audio_link() {
    local main_node dvs_node
    main_node="$(read_main_node)"
    dvs_node="$(read_dvswitch_node)"

    [[ "$main_node" =~ ^[0-9]+$ ]] || return 0
    [[ "$dvs_node" =~ ^[0-9]+$ ]] || return 0

    /usr/sbin/asterisk -rx "rpt cmd ${main_node} ilink 1 ${dvs_node}" >/dev/null 2>&1 || true
}

restore_local_tg() {
    local action="$1"
    local target="$2"
    local local_tg
    local_tg="$(read_local_tg)"
    [[ "$local_tg" =~ ^[0-9]+$ ]] || fail_json "$action" "Failed to determine local TG from Analog_Bridge.ini." "$target"
    /opt/MMDVM_Bridge/dvswitch.sh tune "$local_tg" >/dev/null 2>&1 || fail_json "$action" "Failed to restore local TG ${local_tg}." "$target"
}

force_dmr_runtime() {
    local action="$1"
    local target="$2"
    local mode_out=""

    /opt/MMDVM_Bridge/dvswitch.sh mode DMR >/dev/null 2>&1 || true
    sleep 1
    mode_out="$(/opt/MMDVM_Bridge/dvswitch.sh mode 2>/dev/null || true)"

    if [[ -n "$mode_out" ]]; then
        mode_out="$(printf '%s' "$mode_out" | tr -d '\r' | tr '[:lower:]' '[:upper:]')"
        if [[ "$mode_out" == *"STFU"* || "$mode_out" == *"YSF"* ]]; then
            fail_json "$action" "DVSwitch runtime did not leave ${mode_out} mode before TGIF startup." "$target"
        fi
    fi
}

is_running() {
    [[ -n "$(find_bridge_pid)" ]]
}

is_ready() {
    is_running || return 1
    [[ "$(service_state mmdvm_bridge)" == "active" ]] || return 1
    local_audio_link_present || return 1
    return 0
}

status_json() {
    local ok="$1"
    local action="$2"
    local message="$3"
    local active="$4"
    local target="${5-}"
    local pid="${6-}"
    local mmdvm analog bridge_state ready link_up config_used current_mode
    mmdvm="$(service_state mmdvm_bridge)"
    analog="$(service_state analog_bridge)"
    bridge_state="$( [[ -n "$(find_bridge_pid)" ]] && echo true || echo false )"
    ready="$( is_ready && echo true || echo false )"
    link_up="$( local_audio_link_present && echo true || echo false )"
    config_used="$(resolve_config_file || true)"
    current_mode="$(current_dvswitch_mode)"
    cat <<JSON
{
  "ok": ${ok},
  "action": $(json_escape "$action"),
  "message": $(json_escape "$message"),
  "active": ${active},
  "target": $(json_escape "$target"),
  "tgif_running": ${bridge_state},
  "tgif_ready": ${ready},
  "local_audio_link": ${link_up},
  "mmdvm_bridge": $(json_escape "$mmdvm"),
  "analog_bridge": $(json_escape "$analog"),
  "current_dvswitch_mode": $(json_escape "$current_mode"),
  "pid": $(json_escape "${pid:-}"),
  "config_file_used": $(json_escape "${config_used:-}"),
  "config_file": $(json_escape "$HB_DIR/hblink.cfg"),
  "state_file": $(json_escape "$STATE_FILE"),
  "pid_file": $(json_escape "$PID_FILE"),
  "log_file": $(json_escape "$LOG_FILE")
}
JSON
}

fail_json() {
    local action="$1"
    local message="$2"
    local target="${3-}"
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
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
    local file="$1"
    [[ -e "$file" ]] || fail_json "check" "Required file not found: $file"
}

ensure_backup_ini() {
    if [[ ! -f "$ORIG_INI" ]]; then
        cp -f "$MMDVM_INI" "$ORIG_INI" || fail_json "start" "Failed to save original MMDVM_Bridge.ini"
    fi
}


current_dvswitch_mode() {
    /opt/MMDVM_Bridge/dvswitch.sh mode 2>/dev/null || true
}

wait_media_settle() {
    local seconds="${1:-1}"
    sleep "$seconds"
}

fast_tune_possible() {
    is_running || return 1
    [[ "$(service_state mmdvm_bridge)" == "active" ]] || return 1
    local_audio_link_present || return 1
    local mode_out
    mode_out="$(current_dvswitch_mode)"
    mode_out="$(printf '%s' "$mode_out" | tr -d '\r' | tr '[:lower:]' '[:upper:]')"
    [[ "$mode_out" == *"DMR"* ]] || return 1
    return 0
}

fast_tune_bridge_only() {
    local tg="$1"
    local pid
    "$HB_DIR/set_hblink_tg.sh" "$tg" "$HB_DIR" >/dev/null 2>&1 || fail_json "tune" "Failed to write rules.py" "$tg"
    reload_bridge_runtime "tune" "$tg"
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    wait_media_settle 0.25
    local_audio_link_present || fail_json "tune" "Fast retune completed, but local audio link is not ready." "$tg"
    write_state true "$tg" "$pid"
    ok_json "tune" "HBLink TGIF retuned without bridge restart." true "$tg" "$pid"
}
read_state_target() {
    [[ -f "$STATE_FILE" ]] || return 0
    awk -F= '$1=="target"{print $2; exit}' "$STATE_FILE" 2>/dev/null || true
}

write_state() {
    local active="$1"
    local target="$2"
    local pid="${3-}"
    cat > "$STATE_FILE" <<STATE
active=$active
target=$target
pid=$pid
STATE
    [[ -n "${pid:-}" ]] && echo "$pid" > "$PID_FILE" || rm -f "$PID_FILE"
}

clear_state() {
    rm -f "$STATE_FILE" "$PID_FILE"
}

start_bridge_proc() {
    kill_bridge_pids
    sudo fuser -k -n udp ${HB_PORT} >/dev/null 2>&1 || true
    pkill -9 -f "$CONFLICT_HBLINK_MATCH" >/dev/null 2>&1 || true
    wait_port_clear || fail_json "start" "HBLink port 62033 did not clear."
    (
        cd "$HB_DIR" || exit 1
        nohup ./venv/bin/python bridge.py >>"$LOG_FILE" 2>&1 &
    )
    local pid
    pid="$(wait_for_bridge_pid 40)" || fail_json "start" "HBLink bridge failed to start. Check log."
    echo "$pid" > "$PID_FILE"
}

restart_bridge_stack() {
    local tg="$1"
    systemctl stop mmdvm_bridge >/dev/null 2>&1 || fail_json "start" "Failed to stop mmdvm_bridge." "$tg"
    cp -f "$MMDVM_HBLINK_INI" "$MMDVM_INI" || fail_json "start" "Failed to install HBLink MMDVM_Bridge.ini" "$tg"
    start_bridge_proc
    systemctl start mmdvm_bridge >/dev/null 2>&1 || fail_json "start" "Failed to start mmdvm_bridge." "$tg"
    wait_for_service_active mmdvm_bridge 40 || fail_json "start" "mmdvm_bridge did not become active." "$tg"
}

start_mode() {
    local tg="${1-}"
    local pid current_target
    [[ -n "$tg" ]] || fail_json "start" "You must supply a TGIF talkgroup."
    [[ "$tg" =~ ^[0-9]+$ ]] || fail_json "start" "Invalid TGIF talkgroup: $tg"
    ensure_dirs
    require_file "$HB_DIR/set_hblink_tg.sh"
    require_file "$HB_DIR/hblink.cfg"
    require_file "$HB_DIR/MMDVM_Bridge.hblink.ini"
    require_file "$HB_DIR/venv/bin/python"
    require_file "$HB_DIR/bridge.py"

    "$HB_DIR/set_hblink_tg.sh" "$tg" "$HB_DIR" >/dev/null 2>&1 || fail_json "start" "Failed to write rules.py" "$tg"
    ensure_backup_ini

    if is_running; then
        current_target="$(read_state_target)"
        if [[ "$current_target" == "$tg" ]] && is_ready; then
            pid="$(cat "$PID_FILE" 2>/dev/null || true)"
            write_state true "$tg" "$pid"
            ok_json "start" "HBLink TGIF already active." true "$tg" "$pid"
        fi
    fi

    restart_bridge_stack "$tg"
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    force_dmr_runtime "start" "$tg"
    restore_local_tg "start" "$tg"
    connect_local_audio_link "start" "$tg"
    wait_media_settle 1

    is_ready || fail_json "start" "HBLink started but local audio path is not ready." "$tg"

    write_state true "$tg" "$pid"
    ok_json "start" "HBLink TGIF started." true "$tg" "$pid"
}

tune_mode() {
    local tg="${1-}"
    local pid
    [[ -n "$tg" ]] || fail_json "tune" "You must supply a TGIF talkgroup."
    [[ "$tg" =~ ^[0-9]+$ ]] || fail_json "tune" "Invalid TGIF talkgroup: $tg"

    ensure_dirs
    require_file "$HB_DIR/set_hblink_tg.sh"
    require_file "$HB_DIR/hblink.cfg"
    require_file "$HB_DIR/MMDVM_Bridge.hblink.ini"
    require_file "$HB_DIR/venv/bin/python"
    require_file "$HB_DIR/bridge.py"

    if fast_tune_possible; then
        fast_tune_bridge_only "$tg"
    fi

    "$HB_DIR/set_hblink_tg.sh" "$tg" "$HB_DIR" >/dev/null 2>&1 || fail_json "tune" "Failed to write rules.py" "$tg"
    ensure_backup_ini
    restart_bridge_stack "$tg"

    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    force_dmr_runtime "tune" "$tg"
    restore_local_tg "tune" "$tg"
    connect_local_audio_link "tune" "$tg"
    wait_media_settle 1

    is_ready || fail_json "tune" "HBLink retuned but local audio path is not ready." "$tg"

    write_state true "$tg" "$pid"
    ok_json "tune" "HBLink TGIF retuned." true "$tg" "$pid"
}

stop_mode() {
    local pid target
    target="$(read_state_target)"
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"

    systemctl stop mmdvm_bridge >/dev/null 2>&1 || true
    kill_bridge_pids
    sudo fuser -k -n udp ${HB_PORT} >/dev/null 2>&1 || true
    wait_port_clear || true
    if [[ -f "$ORIG_INI" ]]; then
        cp -f "$ORIG_INI" "$MMDVM_INI" || true
    fi
    systemctl start mmdvm_bridge >/dev/null 2>&1 || true
    clear_state

    drop_local_audio_link
    ok_json "stop" "Returned to normal MMDVM_Bridge mode." false "${target-}" "${pid-}"
}

status_mode() {
    local target pid
    target="$(read_state_target)"
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    if is_ready; then
        ok_json "status" "HBLink TGIF is active." true "$target" "$pid"
    fi
    if is_running; then
        ok_json "status" "HBLink process is running, but local audio path is not ready yet." false "$target" "$pid"
    fi
    ok_json "status" "HBLink TGIF is not active." false "$target" "$pid"
}

usage() {
    cat <<USAGE
Usage: $0 {start|tune|stop|status} [tg]
USAGE
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
    usage
    exit 1
    ;;
esac
