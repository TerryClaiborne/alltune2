#!/usr/bin/env bash
set -euo pipefail

APP_NAME="AllTune2"
APP_DIR="/var/www/html/alltune2"
SCRIPT_PATH="$(readlink -f "${BASH_SOURCE[0]:-$0}" 2>/dev/null || printf '%s' "${BASH_SOURCE[0]:-$0}")"
PUBLIC_DIR="$APP_DIR/public"
ASSETS_DIR="$PUBLIC_DIR/assets"
CSS_DIR="$ASSETS_DIR/css"
JS_DIR="$ASSETS_DIR/js"
API_DIR="$APP_DIR/api"
APP_CODE_DIR="$APP_DIR/app"
DATA_DIR="$APP_DIR/data"
DOCS_DIR="$APP_DIR/docs"
LOGS_DIR="$APP_DIR/logs"
RUN_DIR="$APP_DIR/run"
LEGACY_STFU_DIR="$APP_DIR/stfu"
BMTD_DIR="$APP_DIR/bmtd"
BMTD_CONFIG_DIR="$BMTD_DIR/config"
BMTD_DOCS_DIR="$BMTD_DIR/docs"
TGIF_DIR="$APP_DIR/tgif"
TGIF_CONFIG_DIR="$TGIF_DIR/config"
TGIF_DOCS_DIR="$TGIF_DIR/docs"
TOOLS_DIR="$APP_DIR/tools"

CONFIG_FILE="$APP_DIR/config.ini"
CONFIG_EXAMPLE_FILE="$APP_DIR/config.ini.example"
FAVORITES_FILE="$DATA_DIR/favorites.txt"
VERSION_FILE="$APP_DIR/VERSION"

LEGACY_BM_RECEIVE_HELPER="$APP_DIR/alltune2-bm-receive.sh"
LEGACY_LOCAL_STFU_BIN="$LEGACY_STFU_DIR/STFU"

BMTD_HELPER="$BMTD_DIR/alltune2-bmtd-helper.sh"
BMTD_CONFIG_FILE="$BMTD_CONFIG_DIR/bmtd.ini"
BMTD_CONFIG_CREATED_THIS_RUN=0
BMTD_CONFIG_EXAMPLE="$BMTD_CONFIG_DIR/bmtd.ini.example"
BMTD_BINARY="$BMTD_DIR/bin/bmtd"
BMTD_LOG_FILE="$LOGS_DIR/bmtd.log"
BMTD_CONFIG_HAS_PLACEHOLDERS=0

TGIF_HELPER="$TGIF_DIR/alltune2-tgifd-helper.sh"
TGIF_CONFIG_FILE="$TGIF_CONFIG_DIR/tgifd.ini"
TGIF_CONFIG_CREATED_THIS_RUN=0
TGIF_CONFIG_EXAMPLE="$TGIF_CONFIG_DIR/tgifd.ini.example"
TGIF_BINARY="$TGIF_DIR/bin/tgifd"
TGIF_COPYRIGHT_NOTICE="$TGIF_DOCS_DIR/TGIFD-COPYRIGHT-NOTICE.md"
TGIF_LOG_FILE="$LOGS_DIR/tgifd.log"
TGIFD_CONFIG_HAS_PLACEHOLDERS=0
SETUP_COMPLETED=0
SKIP_APT="${ALLTUNE2_SKIP_APT:-0}"

WEB_USER="www-data"
WEB_GROUP="www-data"
INSTALLER_MODE="${INSTALLER_MODE:-quiet}"
QUIET_COMMAND_LOG="${QUIET_COMMAND_LOG:-/tmp/alltune2-setup-command-output.log}"
AUTH_ACTION="normal"

case "${1:-}" in
    --set-admin-password|--auth)
        AUTH_ACTION="set-password"
        shift
        ;;
    --disable-auth)
        AUTH_ACTION="disable-auth"
        shift
        ;;
    --help|-h)
        echo "Usage:"
        echo "  sudo /var/www/html/alltune2/setup_alltune2.sh"
        echo "  sudo /var/www/html/alltune2/setup_alltune2.sh --set-admin-password"
        echo "  sudo /var/www/html/alltune2/setup_alltune2.sh --disable-auth"
        echo
        echo "Normal setup/update preserves existing config.ini and auth settings."
        echo "Normal setup/update preserves existing user/system settings when they are already present."
        echo "Set ALLTUNE2_SKIP_APT=1 to skip automatic apt package installation."
        echo "--set-admin-password changes only the AllTune2 web login password."
        echo "--disable-auth sets ALLTUNE2_AUTH_ENABLED=0 and keeps the saved hash."
        exit 0
        ;;
    "")
        ;;
    *)
        echo "[ERROR] Unknown option: ${1}" >&2
        echo "Run: sudo /var/www/html/alltune2/setup_alltune2.sh --help" >&2
        exit 1
        ;;
esac

ASTERISK_BIN="/usr/sbin/asterisk"
DVSWITCH_SH="/opt/MMDVM_Bridge/dvswitch.sh"
DVSWITCH_INI="/opt/MMDVM_Bridge/DVSwitch.ini"
MMDVM_BRIDGE_INI="/opt/MMDVM_Bridge/MMDVM_Bridge.ini"
ANALOG_BRIDGE_INI="/opt/Analog_Bridge/Analog_Bridge.ini"

ASTERISK_SUDOERS_FILE="/etc/sudoers.d/alltune2-asterisk"
LEGACY_BM_RECEIVE_SUDOERS_FILE="/etc/sudoers.d/alltune2-bm-receive"
BMTD_HELPER_SUDOERS_FILE="/etc/sudoers.d/alltune2-bmtd"
TGIF_HELPER_SUDOERS_FILE="/etc/sudoers.d/alltune2-tgifd"
MMDVM_BRIDGE_SUDOERS_FILE="/etc/sudoers.d/alltune2-mmdvm-bridge"
ASTERISK_SERVICE_SUDOERS_FILE="/etc/sudoers.d/alltune2-asterisk-service"
YSFGATEWAY_SUDOERS_FILE="/etc/sudoers.d/alltune2-ysfgateway"

LEGACY_BM_RECEIVE_LOG_FILE="$LOGS_DIR/STFU.log"
LEGACY_BM_RECEIVE_LOGROTATE_FILE="/etc/logrotate.d/alltune2-bm-receive"
BMTD_LOGROTATE_FILE="/etc/logrotate.d/alltune2-bmtd"
TGIF_LOGROTATE_FILE="/etc/logrotate.d/alltune2-tgifd"
APACHE_SECURITY_CONF_NAME="alltune2-security"
APACHE_SECURITY_CONF_FILE="/etc/apache2/conf-available/${APACHE_SECURITY_CONF_NAME}.conf"

EXPECTED_ASTERISK_SUDOERS_RULE="${WEB_USER} ALL=(root) NOPASSWD: ${ASTERISK_BIN}"
EXPECTED_BMTD_HELPER_SUDOERS_RULE="${WEB_USER} ALL=(root) NOPASSWD: ${BMTD_HELPER} *"
SYSTEMCTL_BIN="/usr/bin/systemctl"
EXPECTED_TGIF_HELPER_SUDOERS_RULE="${WEB_USER} ALL=(root) NOPASSWD: ${TGIF_HELPER} *"
EXPECTED_MMDVM_BRIDGE_SUDOERS_RULE="${WEB_USER} ALL=(root) NOPASSWD: ${SYSTEMCTL_BIN} start mmdvm_bridge.service"
EXPECTED_ASTERISK_SERVICE_SUDOERS_RULE="${WEB_USER} ALL=(root) NOPASSWD: ${SYSTEMCTL_BIN} restart asterisk.service"
EXPECTED_YSFGATEWAY_SUDOERS_RULE="${WEB_USER} ALL=(root) NOPASSWD: ${SYSTEMCTL_BIN} start ysfgateway.service, ${SYSTEMCTL_BIN} stop ysfgateway.service"

validate_installer_mode() {
    case "$INSTALLER_MODE" in
        quiet|verbose) ;;
        *)
            fail "INSTALLER_MODE must be 'quiet' or 'verbose'."
            ;;
    esac
}

log() {
    if [[ "$INSTALLER_MODE" == "verbose" ]]; then
        echo "[INFO] $*"
    fi
}

quiet_detail() {
    if [[ "$INSTALLER_MODE" == "verbose" ]]; then
        echo "[INFO] $*"
    else
        printf '[INFO] %s\n' "$*" >> "$QUIET_COMMAND_LOG"
    fi
}

init_quiet_command_log() {
    if [[ "$INSTALLER_MODE" != "verbose" ]]; then
        mkdir -p "$(dirname "$QUIET_COMMAND_LOG")"
        : > "$QUIET_COMMAND_LOG"
        chmod 0600 "$QUIET_COMMAND_LOG" 2>/dev/null || true
    fi
}

step() {
    echo "[STEP] $*"
}

warn() {
    echo "[WARN] $*" >&2
}

fail() {
    echo "[ERROR] $*" >&2
    exit 1
}

run_quiet_command() {
    local label="$1"
    shift

    if [[ "$INSTALLER_MODE" == "verbose" ]]; then
        "$@"
        return
    fi

    {
        echo
        echo "===== ${label} ====="
        printf 'Command:'
        printf ' %q' "$@"
        echo
    } >> "$QUIET_COMMAND_LOG"

    if ! "$@" >> "$QUIET_COMMAND_LOG" 2>&1; then
        warn "${label} failed. Last command output:"
        tail -n 80 "$QUIET_COMMAND_LOG" >&2 || true
        return 1
    fi
}

on_error() {
    local exit_code="$?"
    local line_no="${1:-unknown}"

    trap - ERR

    if [[ "${SETUP_COMPLETED:-0}" == "1" ]]; then
        exit "$exit_code"
    fi

    echo >&2
    echo "[ERROR] AllTune2 setup failed near line ${line_no}." >&2
    echo "[INFO] Review the error before rerunning setup repeatedly." >&2

    exit "$exit_code"
}

trap 'on_error $LINENO' ERR

require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        fail "Run this script as root."
    fi
}

require_app_dir() {
    if [[ ! -d "$APP_DIR" ]]; then
        fail "Application directory not found: $APP_DIR"
    fi
}

config_has_key() {
    local key="$1"
    grep -qE "^[[:space:]]*${key}[[:space:]]*=" "$CONFIG_FILE"
}

append_config_key_if_missing() {
    local key="$1"
    local value="$2"

    if ! config_has_key "$key"; then
        printf '%s=%s\n' "$key" "$value" >> "$CONFIG_FILE"
    fi
}

set_config_key() {
    local key="$1"
    local value="$2"

    if [[ ! -f "$CONFIG_FILE" ]]; then
        fail "Missing config.ini: $CONFIG_FILE"
    fi

    if config_has_key "$key"; then
        sed -i "s|^[[:space:]]*${key}[[:space:]]*=.*|${key}=${value}|" "$CONFIG_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >> "$CONFIG_FILE"
    fi
}

ensure_auth_config_defaults() {
    if [[ ! -f "$CONFIG_FILE" ]]; then
        fail "Missing config.ini: $CONFIG_FILE"
    fi

    # Safe defaults only. Existing values are never changed here.
    append_config_key_if_missing "ALLTUNE2_AUTH_ENABLED" "0"
    append_config_key_if_missing "ALLTUNE2_ADMIN_USER" '"admin"'
    append_config_key_if_missing "ALLTUNE2_ADMIN_PASSWORD_HASH" '""'
}

run_auth_password_setup() {
    echo
    echo "AllTune2 Web Login Password Setup"
    echo "================================="
    echo
    echo "This changes only the AllTune2 web login password."
    echo "The password hash is created automatically."
    echo "The plain password is not stored."
    echo

    local pass1=""
    local pass2=""
    local hash=""

    read -rsp "New admin password: " pass1
    echo

    if [[ -z "$pass1" ]]; then
        echo
        echo "[ERROR] No password was entered."
        echo "No changes were made."
        echo
        echo "Next steps:"
        echo "- To try again, run: sudo /var/www/html/alltune2/setup_alltune2.sh --set-admin-password"
        echo "- To turn login off, run: sudo /var/www/html/alltune2/setup_alltune2.sh --disable-auth"
        echo "- To leave things as they are, do nothing."
        exit 1
    fi

    read -rsp "Confirm admin password: " pass2
    echo

    if [[ "$pass1" != "$pass2" ]]; then
        echo
        echo "[ERROR] Passwords did not match."
        echo "No changes were made."
        echo
        echo "Next steps:"
        echo "- Run sudo /var/www/html/alltune2/setup_alltune2.sh --set-admin-password and try again."
        echo "- Your old saved password/hash was not changed."
        exit 1
    fi

    hash="$(printf '%s' "$pass1" | php -r '$p = stream_get_contents(STDIN); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;')"

    set_config_key "ALLTUNE2_ADMIN_USER" '"admin"'
    set_config_key "ALLTUNE2_AUTH_ENABLED" "1"
    set_config_key "ALLTUNE2_ADMIN_PASSWORD_HASH" "\"${hash}\""

    unset pass1 pass2 hash

    chmod 0640 "$CONFIG_FILE"
    chown root:"$WEB_GROUP" "$CONFIG_FILE"

    echo
    echo "[OK] Web login enabled."
    echo "[OK] Password hash saved to config.ini."
    echo
    echo "Next steps:"
    echo "1. Open /alltune2/public/ in your browser."
    echo "2. Click Login."
    echo "3. Enter the password you just set."
    echo
    echo "Notes:"
    echo "- The plain password was not stored."
    echo "- Running sudo /var/www/html/alltune2/setup_alltune2.sh normally will not change this password."
    echo "- To disable login later, run: sudo /var/www/html/alltune2/setup_alltune2.sh --disable-auth"
}

run_auth_disable() {
    echo
    echo "AllTune2 Web Login Disable"
    echo "=========================="
    echo
    echo "This changes only ALLTUNE2_AUTH_ENABLED."
    echo "The existing password hash will be kept."
    echo

    set_config_key "ALLTUNE2_ADMIN_USER" '"admin"'
    set_config_key "ALLTUNE2_AUTH_ENABLED" "0"

    chmod 0640 "$CONFIG_FILE"
    chown root:"$WEB_GROUP" "$CONFIG_FILE"

    echo "[OK] Web login disabled."
    echo "[OK] Existing password hash was kept."
    echo
    echo "Next steps:"
    echo "1. Open /alltune2/public/ in your browser."
    echo "2. AllTune2 should show No Login/Normal mode and work normally."
    echo
    echo "To re-enable login later:"
    echo "- Set ALLTUNE2_AUTH_ENABLED=1 in config.ini to reuse the saved password, or"
    echo "- Run sudo /var/www/html/alltune2/setup_alltune2.sh --set-admin-password to set a new password."
}

install_minimum_packages_if_possible() {
    log "Ensuring minimum runtime packages are installed when apt is available..."

    if [[ "$SKIP_APT" == "1" ]]; then
        quiet_detail "ALLTUNE2_SKIP_APT=1 set. Skipping automatic package installation; required tools will be checked next."
        return
    fi

    if ! command -v apt-get >/dev/null 2>&1; then
        quiet_detail "apt-get not found. Skipping automatic package installation; required tools will be checked next."
        return
    fi

    local packages=(
        apache2
        php
        libapache2-mod-php
        php-cli
        sudo
        git
        ca-certificates
        python3
        cron
        logrotate
        openssl
        libstdc++6
        zlib1g
        libzstd1
    )

    export DEBIAN_FRONTEND=noninteractive
    run_quiet_command "apt package index update" apt-get update
    run_quiet_command "apt package installation" apt-get install -y "${packages[@]}"
}


check_auth_runtime_tools() {
    log "Checking runtime tools for auth-only action..."
    command -v php >/dev/null 2>&1 || fail "php is not installed or not in PATH."
    command -v sudo >/dev/null 2>&1 || fail "sudo is not installed or not in PATH."
    command -v python3 >/dev/null 2>&1 || fail "python3 is not installed or not in PATH."
}

check_runtime_tools() {
    log "Checking runtime tools..."
    command -v php >/dev/null 2>&1 || fail "php is not installed or not in PATH."
    command -v sudo >/dev/null 2>&1 || fail "sudo is not installed or not in PATH."
    command -v visudo >/dev/null 2>&1 || fail "visudo is not installed or not in PATH."
    command -v python3 >/dev/null 2>&1 || fail "python3 is not installed or not in PATH."
 
    if command -v apache2ctl >/dev/null 2>&1; then
        log "apache2ctl found."
    else
        quiet_detail "apache2ctl not found in PATH."
    fi
}

check_web_user() {
    if id "$WEB_USER" >/dev/null 2>&1; then
        log "Web user exists: $WEB_USER"
    else
        fail "Web user does not exist: $WEB_USER"
    fi
}

make_dirs() {
    log "Ensuring required directories exist..."
    mkdir -p "$PUBLIC_DIR" "$ASSETS_DIR" "$CSS_DIR" "$JS_DIR" "$API_DIR" "$APP_CODE_DIR"
    mkdir -p "$DATA_DIR" "$DOCS_DIR" "$LOGS_DIR" "$RUN_DIR" "$BMTD_DIR" "$BMTD_CONFIG_DIR" "$BMTD_DOCS_DIR" "$TGIF_DIR" "$TGIF_CONFIG_DIR" "$TGIF_DOCS_DIR" "$TOOLS_DIR"
}

create_config_example() {
    if [[ ! -f "$CONFIG_EXAMPLE_FILE" ]]; then
        log "Creating config.ini.example..."
        cat > "$CONFIG_EXAMPLE_FILE" <<'EOF'
MYNODE="YOUR NODE"
DVSWITCH_NODE="YOUR DVSWITCH NODE"
BM_SelfcarePassword="CHANGE_ME"
TGIF_HotspotSecurityKey="CHANGE_ME"
DSTAR_ENABLED=0
P25_ENABLED=0
NXDN_ENABLED=0

# Optional web login / security
# Disabled by default. Leave ALLTUNE2_AUTH_ENABLED=0 for normal current behavior.
ALLTUNE2_AUTH_ENABLED=0
ALLTUNE2_ADMIN_USER="admin"
ALLTUNE2_ADMIN_PASSWORD_HASH=""

EOF
    else
        log "config.ini.example already exists."
    fi

    chmod 0644 "$CONFIG_EXAMPLE_FILE"
    chown root:root "$CONFIG_EXAMPLE_FILE"
}

create_config_if_missing() {
    if [[ -f "$CONFIG_FILE" ]]; then
        log "config.ini already exists. Preserving current values."
    elif [[ -f "$APP_DIR/config.ini.example" ]]; then
        log "Creating starter config.ini from config.ini.example..."
        cp -f "$APP_DIR/config.ini.example" "$CONFIG_FILE"
        quiet_detail "Created starter config: $CONFIG_FILE"
    else
        fail "Neither $CONFIG_FILE nor $APP_DIR/config.ini.example exists."
    fi

    chmod 0640 "$CONFIG_FILE"
    chown root:"$WEB_GROUP" "$CONFIG_FILE"
}

create_favorites_if_missing() {
    if [[ ! -f "$FAVORITES_FILE" ]]; then
        log "Creating shared favorites file..."
        cat > "$FAVORITES_FILE" <<'EOF'
9990|Parrot|TGIF Parrot|TGIF
9050|East Coast Reflector|East Coast TGIF|TGIF
23510|CQ-UK World Wide|CQ-World Wide TGIF|TGIF
parrot.ysfreflector.de:42020|Fusion Parrot|YSF Parrot|YSF
EOF
    else
        log "favorites.txt already exists. Preserving current contents."
    fi

    chmod 0664 "$FAVORITES_FILE"
    chown "$WEB_USER":"$WEB_GROUP" "$FAVORITES_FILE"
}

config_value_or_empty() {
    local key="$1"
    [[ -f "$CONFIG_FILE" ]] || return 0
    awk -F= -v key="$key" '
        $1 ~ "^[[:space:]]*" key "[[:space:]]*$" {
            value = $2
            sub(/^[[:space:]]+/, "", value)
            sub(/[[:space:]]+$/, "", value)
            gsub(/^"|"$/, "", value)
            print value
            exit
        }
    ' "$CONFIG_FILE"
}

ini_value_any_section() {
    local file="$1"
    local key="$2"
    [[ -f "$file" ]] || return 0
    awk -F= -v key="$key" '
        $1 ~ "^[[:space:]]*" key "[[:space:]]*$" {
            value = $2
            # Strip inline comments used in DVSwitch/MMDVM INI files.
            sub(/[[:space:]]*[;#].*$/, "", value)
            sub(/^[[:space:]]+/, "", value)
            sub(/[[:space:]]+$/, "", value)
            gsub(/^"|"$/, "", value)
            print value
            exit
        }
    ' "$file"
}

is_placeholder_value() {
    local value="$1"
    [[ -z "$value" ]] && return 0
    [[ "$value" =~ YOUR|Your|CHANGE_ME|PLACEHOLDER|YOURCALL|YOUR_DMR_ID|YOUR_HOTSPOT|YOUR_TGIF_SECURITY_KEY|000000000 ]] && return 0
    return 1
}

tgifd_ini_set_if_placeholder_or_missing() {
    local section="$1"
    local key="$2"
    local value="$3"

    [[ -n "$value" ]] || return 0
    is_placeholder_value "$value" && return 0

    python3 - "$TGIF_CONFIG_FILE" "$section" "$key" "$value" <<'PYTGIFDINI'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
section = sys.argv[2]
key = sys.argv[3]
value = sys.argv[4]

text = path.read_text() if path.exists() else ""
lines = text.splitlines()
changed = False

placeholder_tokens = (
    "", "YOUR", "Your", "CHANGE_ME", "PLACEHOLDER", "YOURCALL",
    "YOUR_DMR_ID", "YOUR_HOTSPOT", "YOUR_TGIF_SECURITY_KEY", "000000000"
)

def is_placeholder(v: str) -> bool:
    raw = v.strip().strip('"')
    if raw == "":
        return True
    return any(token in raw for token in placeholder_tokens if token)

quoted_placeholder_keys = {"security_key", "mynode", "private_node"}

def render_value(k: str, v: str) -> str:
    raw = v.strip().strip('"')
    if k.lower() in quoted_placeholder_keys:
        raw = raw.replace("\\", "\\\\").replace('"', '\\"')
        return f'"{raw}"'
    return v.strip()

def render_line(k: str, v: str) -> str:
    return f"{k}={render_value(k, v)}"

start = None
end = len(lines)
for i, line in enumerate(lines):
    if line.strip().lower() == f"[{section}]".lower():
        start = i
        for j in range(i + 1, len(lines)):
            if lines[j].strip().startswith("[") and lines[j].strip().endswith("]"):
                end = j
                break
        break

if start is None:
    if lines and lines[-1].strip():
        lines.append("")
    lines.extend([f"[{section}]", render_line(key, value)])
    changed = True
else:
    key_line = None
    for i in range(start + 1, end):
        if "=" not in lines[i]:
            continue
        left, right = lines[i].split("=", 1)
        if left.strip().lower() == key.lower():
            key_line = i
            if is_placeholder(right):
                lines[i] = render_line(key, value)
                changed = True
            break
    if key_line is None:
        lines.insert(end, render_line(key, value))
        changed = True

if changed:
    path.write_text("\n".join(lines).rstrip() + "\n")
PYTGIFDINI
}

create_tgifd_config_example_if_missing() {
    if [[ -f "$TGIF_CONFIG_EXAMPLE" ]]; then
        log "tgifd.ini.example already exists. Preserving repo example file."
        chmod 0644 "$TGIF_CONFIG_EXAMPLE"
        chown root:root "$TGIF_CONFIG_EXAMPLE"
        return
    fi

    log "Creating starter tgifd.ini.example..."
    mkdir -p "$TGIF_CONFIG_DIR"
    cat > "$TGIF_CONFIG_EXAMPLE" <<'EOFTGIFDINIEXAMPLE'
[general]
log_file=/var/www/html/alltune2/logs/tgifd.log

[identity]
callsign=YOURCALL
dmr_id=YOUR_GATEWAY_DMR_ID
hotspot_radio_id=YOUR_HOTSPOT_ID_PLUS_1

[tgif]
host=tgif.network
port=62031
security_key="YOUR_TGIF_SECURITY_KEY"
startup_tg=9990
options=StartRef=9990;RelinkTime=60

[behavior]
receive_timeout_ms=1000
keepalive_seconds=5
protocol_assert_seconds=30
soft_refresh_trigger_missed=2
max_missed=5
reconnect_delay_seconds=5

[network]
local_bind_port=0

[tlv]
rx_port=31103
tx_host=127.0.0.1
tx_port=31100
timeout_ms=500
inbound_slot=2

[private_node]
enabled=true
asterisk_bin=/usr/sbin/asterisk
mynode="YOUR_ALLSTAR_NODE"
private_node="YOUR_DVSWITCH_NODE"
autoload_mode=transceive

[mmdvm]
; TGIFD reads this section from tgifd.ini for TGIF metadata.
; It does not mean TGIFD depends on MMDVM_Bridge.ini at runtime.
; Keep slots=2 for the AllTune2/TLV audio path.
slots=2

EOFTGIFDINIEXAMPLE

    chmod 0644 "$TGIF_CONFIG_EXAMPLE"
    chown root:root "$TGIF_CONFIG_EXAMPLE"
}

create_tgifd_config_if_missing() {
    mkdir -p "$TGIF_CONFIG_DIR"
    TGIF_CONFIG_CREATED_THIS_RUN=0

    if [[ -f "$TGIF_CONFIG_FILE" ]]; then
        log "Live tgifd.ini already exists. Preserving current values."
    elif [[ -f "$TGIF_CONFIG_EXAMPLE" ]]; then
        log "Creating starter tgifd.ini from tgifd.ini.example..."
        cp -f "$TGIF_CONFIG_EXAMPLE" "$TGIF_CONFIG_FILE"
        TGIF_CONFIG_CREATED_THIS_RUN=1
        quiet_detail "Created starter TGIFD config: $TGIF_CONFIG_FILE"
    else
        fail "Neither $TGIF_CONFIG_FILE nor $TGIF_CONFIG_EXAMPLE exists."
    fi

    chmod 0640 "$TGIF_CONFIG_FILE"
    chown root:"$WEB_GROUP" "$TGIF_CONFIG_FILE"
}

sync_tgifd_config_from_system_if_safe() {
    if [[ "${TGIF_CONFIG_CREATED_THIS_RUN:-0}" != "1" ]]; then
        log "Existing tgifd.ini preserved; skipping TGIFD placeholder sync."
        return 0
    fi

    log "Syncing TGIFD config placeholders from existing AllTune2/DVSwitch settings when safe..."

    local mynode=""
    local dvswitch_node=""
    local tgif_key=""
    local gateway_dmr_id=""
    local repeater_id=""
    local hotspot_radio_id=""
    local callsign=""
    local rx_frequency=""
    local tx_frequency=""
    local latitude=""
    local longitude=""
    local height=""
    local location=""
    local description=""

    mynode="$(config_value_or_empty MYNODE || true)"
    dvswitch_node="$(config_value_or_empty DVSWITCH_NODE || true)"
    tgif_key="$(config_value_or_empty TGIF_HotspotSecurityKey || true)"
    gateway_dmr_id="$(ini_value_any_section "$ANALOG_BRIDGE_INI" gatewayDmrId || true)"
    repeater_id="$(ini_value_any_section "$ANALOG_BRIDGE_INI" repeaterID || true)"
    callsign="$(ini_value_any_section "$ANALOG_BRIDGE_INI" gatewayCallsign || true)"
    if [[ -z "$callsign" ]]; then
        callsign="$(ini_value_any_section "$DVSWITCH_INI" gatewayCallsign || true)"
    fi
    if [[ -z "$callsign" ]]; then
        callsign="$(ini_value_any_section "$DVSWITCH_INI" callsign || true)"
    fi
    if [[ -z "$callsign" ]]; then
        callsign="$(ini_value_any_section "$MMDVM_BRIDGE_INI" Callsign || true)"
    fi

    rx_frequency="$(ini_value_any_section "$MMDVM_BRIDGE_INI" RXFrequency || true)"
    tx_frequency="$(ini_value_any_section "$MMDVM_BRIDGE_INI" TXFrequency || true)"
    latitude="$(ini_value_any_section "$MMDVM_BRIDGE_INI" Latitude || true)"
    longitude="$(ini_value_any_section "$MMDVM_BRIDGE_INI" Longitude || true)"
    height="$(ini_value_any_section "$MMDVM_BRIDGE_INI" Height || true)"
    location="$(ini_value_any_section "$MMDVM_BRIDGE_INI" Location || true)"
    description="$(ini_value_any_section "$MMDVM_BRIDGE_INI" Description || true)"

    if [[ "$repeater_id" =~ ^[0-9]+$ ]]; then
        hotspot_radio_id="$((repeater_id + 1))"
    fi

    tgifd_ini_set_if_placeholder_or_missing "identity" "callsign" "$callsign"
    tgifd_ini_set_if_placeholder_or_missing "identity" "dmr_id" "$gateway_dmr_id"
    tgifd_ini_set_if_placeholder_or_missing "identity" "hotspot_radio_id" "$hotspot_radio_id"
    tgifd_ini_set_if_placeholder_or_missing "tgif" "security_key" "$tgif_key"
    tgifd_ini_set_if_placeholder_or_missing "private_node" "mynode" "$mynode"
    tgifd_ini_set_if_placeholder_or_missing "private_node" "private_node" "$dvswitch_node"

    # TGIFD does not depend on MMDVM_Bridge.ini at runtime.
    # Keep only the required AllTune2/TLV slot defaults in the generated starter config.
    tgifd_ini_set_if_placeholder_or_missing "mmdvm" "slots" "2"
    tgifd_ini_set_if_placeholder_or_missing "tlv" "inbound_slot" "2"

    chmod 0640 "$TGIF_CONFIG_FILE"
    chown root:"$WEB_GROUP" "$TGIF_CONFIG_FILE"
}

check_tgifd_config_content() {
    log "Checking TGIFD config content..."

    local placeholders_regex='YOUR|Your|YOURCALL|CHANGE_ME|PLACEHOLDER|000000000'

    TGIFD_CONFIG_HAS_PLACEHOLDERS=0
    if grep -Eq "$placeholders_regex" "$TGIF_CONFIG_FILE"; then
        TGIFD_CONFIG_HAS_PLACEHOLDERS=1
        quiet_detail "tgifd.ini still contains placeholder values. TGIFD may not work until it is reviewed."
    fi

    if ! grep -qE '^[[:space:]]*hotspot_radio_id[[:space:]]*=' "$TGIF_CONFIG_FILE"; then
        warn "tgifd.ini does not define hotspot_radio_id. TGIFD may not stay connected to TGIF."
    fi

    if ! grep -qE '^[[:space:]]*security_key[[:space:]]*=' "$TGIF_CONFIG_FILE"; then
        warn "tgifd.ini does not define security_key. TGIFD authentication may fail."
    fi

    if ! awk -F= '
        /^[[:space:]]*\[tlv\][[:space:]]*$/ {in_tlv=1; next}
        /^[[:space:]]*\[/ {in_tlv=0}
        in_tlv && $1 ~ /^[[:space:]]*inbound_slot[[:space:]]*$/ {v=$2; gsub(/[[:space:]]/, "", v); found=(v=="2")}
        END {exit(found ? 0 : 1)}
    ' "$TGIF_CONFIG_FILE"; then
        warn "tgifd.ini [tlv] inbound_slot is not 2. Inbound TGIF audio may be unreliable."
    fi

    if ! awk -F= '
        /^[[:space:]]*\[mmdvm\][[:space:]]*$/ {in_mmdvm=1; next}
        /^[[:space:]]*\[/ {in_mmdvm=0}
        in_mmdvm && $1 ~ /^[[:space:]]*slots[[:space:]]*$/ {v=$2; gsub(/[[:space:]]/, "", v); found=(v=="2")}
        END {exit(found ? 0 : 1)}
    ' "$TGIF_CONFIG_FILE"; then
        warn "tgifd.ini [mmdvm] slots is not 2. TGIFD may not work correctly."
    fi
}


create_bmtd_config_example_if_missing() {
    if [[ -f "$BMTD_CONFIG_EXAMPLE" ]]; then
        log "bmtd.ini.example already exists. Preserving repo example file."
        chmod 0644 "$BMTD_CONFIG_EXAMPLE"
        chown root:root "$BMTD_CONFIG_EXAMPLE"
        return
    fi

    log "Creating starter bmtd.ini.example..."
    mkdir -p "$BMTD_CONFIG_DIR"
    cat > "$BMTD_CONFIG_EXAMPLE" <<'EOFBMTDINIEXAMPLE'
; AllTune2 BMTD configuration example

[general]
log_file=/var/www/html/alltune2/logs/bmtd.log

[brandmeister]
host=3104.repeater.net
port=54006
password=YOUR_BRANDMEISTER_HOTSPOT_PASSWORD

[identity]
dmr_id=YOUR_DMR_ID
callsign=YOUR_CALLSIGN

[tlv]
rx_port=31103
tx_host=127.0.0.1
tx_port=31100
timeout_ms=1000

[behavior]
startup_tg=0
keepalive_seconds=5
rx_idle_end_ms=1500
reconnect_delay_seconds=3
max_missed=3

[private_node]
enabled=true
asterisk_bin=/usr/sbin/asterisk
mynode="YOUR_ALLSTAR_NODE"
private_node="YOUR_DVSWITCH_NODE"
autoload_mode=transceive

EOFBMTDINIEXAMPLE

    chmod 0644 "$BMTD_CONFIG_EXAMPLE"
    chown root:root "$BMTD_CONFIG_EXAMPLE"
}

create_bmtd_config_if_missing() {
    mkdir -p "$BMTD_CONFIG_DIR"
    BMTD_CONFIG_CREATED_THIS_RUN=0

    if [[ -f "$BMTD_CONFIG_FILE" ]]; then
        log "Live bmtd.ini already exists. Preserving current values."
    elif [[ -f "$BMTD_CONFIG_EXAMPLE" ]]; then
        log "Creating starter bmtd.ini from bmtd.ini.example..."
        cp -f "$BMTD_CONFIG_EXAMPLE" "$BMTD_CONFIG_FILE"
        BMTD_CONFIG_CREATED_THIS_RUN=1
        quiet_detail "Created starter BMTD config: $BMTD_CONFIG_FILE"
    else
        fail "Neither $BMTD_CONFIG_FILE nor $BMTD_CONFIG_EXAMPLE exists."
    fi

    chmod 0640 "$BMTD_CONFIG_FILE"
    chown root:"$WEB_GROUP" "$BMTD_CONFIG_FILE"
}

bmtd_ini_set_if_placeholder_or_missing() {
    local section="$1"
    local key="$2"
    local value="$3"

    [[ -n "$value" ]] || return 0
    is_placeholder_value "$value" && return 0

    python3 - "$BMTD_CONFIG_FILE" "$section" "$key" "$value" <<'PYBMTDINI'
import pathlib
import sys

path = pathlib.Path(sys.argv[1])
section = sys.argv[2]
key = sys.argv[3]
value = sys.argv[4]

text = path.read_text() if path.exists() else ""
lines = text.splitlines()
changed = False

placeholder_tokens = (
    "", "YOUR", "Your", "CHANGE_ME", "PLACEHOLDER", "YOURCALL",
    "YOUR_DMR_ID", "YOUR_CALLSIGN", "YOUR_BRANDMEISTER_HOTSPOT_PASSWORD",
    "YOUR_ALLSTAR_NODE", "YOUR_DVSWITCH_NODE", "000000000"
)

def is_placeholder(v: str) -> bool:
    raw = v.strip().strip('"')
    if raw == "":
        return True
    return any(token in raw for token in placeholder_tokens if token)

quoted_keys = {"password", "mynode", "private_node"}

def render_value(k: str, v: str) -> str:
    raw = v.strip().strip('"')
    if k.lower() in quoted_keys:
        raw = raw.replace("\\", "\\\\").replace('"', '\\"')
        return f'"{raw}"'
    return v.strip()

def render_line(k: str, v: str) -> str:
    return f"{k}={render_value(k, v)}"

start = None
end = len(lines)
for i, line in enumerate(lines):
    if line.strip().lower() == f"[{section}]".lower():
        start = i
        for j in range(i + 1, len(lines)):
            if lines[j].strip().startswith("[") and lines[j].strip().endswith("]"):
                end = j
                break
        break

if start is None:
    if lines and lines[-1].strip():
        lines.append("")
    lines.extend([f"[{section}]", render_line(key, value)])
    changed = True
else:
    key_line = None
    for i in range(start + 1, end):
        if "=" not in lines[i]:
            continue
        left, right = lines[i].split("=", 1)
        if left.strip().lower() == key.lower():
            key_line = i
            if is_placeholder(right):
                lines[i] = render_line(key, value)
                changed = True
            break
    if key_line is None:
        lines.insert(end, render_line(key, value))
        changed = True

if changed:
    path.write_text("\n".join(lines).rstrip() + "\n")
PYBMTDINI
}

sync_bmtd_config_from_system_if_safe() {
    if [[ "${BMTD_CONFIG_CREATED_THIS_RUN:-0}" != "1" ]]; then
        log "Existing bmtd.ini preserved; skipping BMTD placeholder sync."
        return 0
    fi

    log "Syncing BMTD config placeholders from existing AllTune2/Analog_Bridge settings when safe..."

    local mynode=""
    local dvswitch_node=""
    local bm_password=""
    local gateway_dmr_id=""
    local callsign=""

    mynode="$(config_value_or_empty MYNODE || true)"
    dvswitch_node="$(config_value_or_empty DVSWITCH_NODE || true)"
    bm_password="$(config_value_or_empty BM_SelfcarePassword || true)"
    gateway_dmr_id="$(ini_value_any_section "$ANALOG_BRIDGE_INI" gatewayDmrId || true)"
    if [[ -z "$gateway_dmr_id" ]]; then
        gateway_dmr_id="$(ini_value_any_section "$ANALOG_BRIDGE_INI" repeaterID || true)"
    fi
    callsign="$(ini_value_any_section "$ANALOG_BRIDGE_INI" gatewayCallsign || true)"
    if [[ -z "$callsign" ]]; then
        callsign="$(ini_value_any_section "$DVSWITCH_INI" gatewayCallsign || true)"
    fi
    if [[ -z "$callsign" ]]; then
        callsign="$(ini_value_any_section "$DVSWITCH_INI" callsign || true)"
    fi

    bmtd_ini_set_if_placeholder_or_missing "brandmeister" "password" "$bm_password"
    bmtd_ini_set_if_placeholder_or_missing "identity" "dmr_id" "$gateway_dmr_id"
    bmtd_ini_set_if_placeholder_or_missing "identity" "callsign" "$callsign"
    bmtd_ini_set_if_placeholder_or_missing "private_node" "mynode" "$mynode"
    bmtd_ini_set_if_placeholder_or_missing "private_node" "private_node" "$dvswitch_node"

    chmod 0640 "$BMTD_CONFIG_FILE"
    chown root:"$WEB_GROUP" "$BMTD_CONFIG_FILE"
}

check_bmtd_config_content() {
    log "Checking BMTD config content..."

    local placeholders_regex='YOUR|Your|YOURCALL|CHANGE_ME|PLACEHOLDER|000000000'

    BMTD_CONFIG_HAS_PLACEHOLDERS=0
    if grep -Eq "$placeholders_regex" "$BMTD_CONFIG_FILE"; then
        BMTD_CONFIG_HAS_PLACEHOLDERS=1
        quiet_detail "bmtd.ini still contains placeholder values. BMTD may not work until it is reviewed."
    fi

    if ! grep -qE '^[[:space:]]*password[[:space:]]*=' "$BMTD_CONFIG_FILE"; then
        warn "bmtd.ini does not define BrandMeister password. BMTD authentication will fail."
    fi

    if ! grep -qE '^[[:space:]]*dmr_id[[:space:]]*=' "$BMTD_CONFIG_FILE"; then
        warn "bmtd.ini does not define dmr_id. BMTD will not identify correctly."
    fi
}

prepare_bmtd_binary() {
    log "Checking shipped BMTD binary..."

    [[ -f "$BMTD_HELPER" ]] || fail "BMTD helper not found: $BMTD_HELPER"
    [[ -f "$BMTD_BINARY" ]] || fail "BMTD binary not found: $BMTD_BINARY"

    chmod 0755 "$BMTD_BINARY"
    chown root:root "$BMTD_BINARY"

    [[ -x "$BMTD_BINARY" ]] || fail "BMTD binary is not executable: $BMTD_BINARY"

    local arch
    arch="$(dpkg --print-architecture 2>/dev/null || uname -m 2>/dev/null || true)"
    if [[ "$arch" != "arm64" && "$arch" != "aarch64" ]]; then
        fail "BMTD binary is built for 64-bit ARM. This system reports architecture: ${arch:-unknown}"
    fi

    if command -v ldd >/dev/null 2>&1; then
        if ldd "$BMTD_BINARY" 2>/dev/null | grep -q "not found"; then
            ldd "$BMTD_BINARY" 2>/dev/null || true
            fail "BMTD binary has missing runtime libraries. Install the missing runtime packages and rerun setup."
        fi
    fi
}

check_bmtd_binary() {
    [[ -x "$BMTD_BINARY" ]] || fail "BMTD binary missing or not executable: $BMTD_BINARY"
    log "BMTD binary exists: $BMTD_BINARY"
}

stop_existing_bmtd_if_running() {
    log "Stopping any active BMTD instance before runtime/config update..."

    if [[ -x "$BMTD_HELPER" ]]; then
        "$BMTD_HELPER" stop >/dev/null 2>&1 || true
    fi

    pkill -f "$BMTD_BINARY" >/dev/null 2>&1 || true
    sleep 1

    if pgrep -f "$BMTD_BINARY" >/dev/null 2>&1; then
        fail "BMTD process is still running after stop attempt. Stop it before continuing."
    fi

    log "No active BMTD process remains."
}

remove_legacy_stfu_runtime() {
    log "Removing legacy STFU runtime path and sudo/logrotate rules..."

    if [[ -x "$LEGACY_BM_RECEIVE_HELPER" ]]; then
        "$LEGACY_BM_RECEIVE_HELPER" stop >/dev/null 2>&1 || true
    fi

    pkill -f "$LEGACY_LOCAL_STFU_BIN" >/dev/null 2>&1 || true
    rm -f "$LEGACY_BM_RECEIVE_SUDOERS_FILE" >/dev/null 2>&1 || true
    rm -f "$LEGACY_BM_RECEIVE_LOGROTATE_FILE" /etc/logrotate.d/alltune2-stfu >/dev/null 2>&1 || true
    rm -rf "$LEGACY_STFU_DIR" >/dev/null 2>&1 || true
    rm -f "$LEGACY_BM_RECEIVE_HELPER" >/dev/null 2>&1 || true

    quiet_detail "Legacy STFU runtime/helper path removed if present. Old STFU log files are preserved."
}


prepare_tgifd_binary() {
    log "Checking shipped TGIFD binary..."

    [[ -f "$TGIF_HELPER" ]] || fail "TGIFD helper not found: $TGIF_HELPER"
    [[ -f "$TGIF_BINARY" ]] || fail "TGIFD binary not found: $TGIF_BINARY"

    chmod 0755 "$TGIF_BINARY"
    chown root:root "$TGIF_BINARY"

    [[ -x "$TGIF_BINARY" ]] || fail "TGIFD binary is not executable: $TGIF_BINARY"

    local arch
    arch="$(dpkg --print-architecture 2>/dev/null || uname -m 2>/dev/null || true)"
    if [[ "$arch" != "arm64" && "$arch" != "aarch64" ]]; then
        fail "TGIFD binary is built for 64-bit ARM. This system reports architecture: ${arch:-unknown}"
    fi

    if command -v ldd >/dev/null 2>&1; then
        if ldd "$TGIF_BINARY" 2>/dev/null | grep -q "not found"; then
            ldd "$TGIF_BINARY" 2>/dev/null || true
            fail "TGIFD binary has missing runtime libraries. Install the missing runtime packages and rerun setup."
        fi
    fi
}

check_tgifd_binary() {
    [[ -x "$TGIF_BINARY" ]] || fail "TGIFD binary missing or not executable: $TGIF_BINARY"
    log "TGIFD binary exists: $TGIF_BINARY"
}

stop_existing_tgifd_if_running() {
    log "Stopping any active TGIFD instance before rebuild/config update..."

    if [[ -x "$TGIF_HELPER" ]]; then
        "$TGIF_HELPER" stop >/dev/null 2>&1 || true
    fi

    pkill -f "$TGIF_BINARY" >/dev/null 2>&1 || true
    sleep 1

    if pgrep -f "$TGIF_BINARY" >/dev/null 2>&1; then
        fail "TGIFD process is still running after stop attempt. Stop it before continuing."
    fi

    log "No active TGIFD process remains."
}



check_required_repo_files() {
    log "Checking required repo files..."

    local required_files=(
        "$APP_DIR/README.md"
        "$APP_DIR/VERSION"
        "$APP_DIR/.gitignore"
        "$APP_DIR/setup_alltune2.sh"
        "$BMTD_HELPER"
        "$BMTD_BINARY"
        "$BMTD_CONFIG_EXAMPLE"
        "$APP_DIR/app/Support/Config.php"
        "$APP_DIR/api/connect.php"
        "$APP_DIR/api/status.php"
        "$APP_DIR/api/direct_link.php"
        "$APP_DIR/public/index.php"
        "$APP_DIR/public/favorites.php"
        "$APP_DIR/public/alltune2_ribbon_bar.php"
        "$APP_DIR/public/assets/js/app.js"
        "$APP_DIR/public/assets/css/style.css"
        "$CONFIG_EXAMPLE_FILE"
        "$TGIF_HELPER"
        "$TGIF_BINARY"
        "$TGIF_CONFIG_EXAMPLE"
        "$TGIF_COPYRIGHT_NOTICE"
    )

    local missing=0
    local file

    for file in "${required_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            warn "Missing required file: $file"
            missing=1
        fi
    done

    if [[ "$missing" -ne 0 ]]; then
        fail "Required AllTune2 repo files are missing."
    fi

    log "Required repo files look present."
}

check_optional_files() {
    log "Checking optional scaffold files..."

    local optional_files=(
        "$APP_DIR/tree.txt"
        "$APP_DIR/app/State/StatusMapper.php"
        "$APP_DIR/app/Actions/AllStarAction.php"
        "$APP_DIR/app/Actions/BrandMeisterAction.php"
        "$APP_DIR/app/Actions/TGIFAction.php"
        "$APP_DIR/app/Actions/YSFAction.php"
    )

    local file
    for file in "${optional_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            quiet_detail "Optional file not found: $file"
        fi
    done
}

check_dvswitch_dependencies() {
    log "Checking DVSwitch/Analog_Bridge system dependency hints..."

    if [[ ! -x "$DVSWITCH_SH" ]]; then
        quiet_detail "DVSwitch helper not found or not executable: $DVSWITCH_SH. MMDVM-backed modes may need manual configuration."
    fi
    if [[ ! -f "$DVSWITCH_INI" ]]; then
        quiet_detail "DVSwitch.ini not found: $DVSWITCH_INI. Starter configs will keep placeholders where needed."
    fi
    if [[ ! -f "$MMDVM_BRIDGE_INI" ]]; then
        quiet_detail "MMDVM_Bridge.ini not found: $MMDVM_BRIDGE_INI. MMDVM-backed modes may need manual configuration."
    fi
    if [[ ! -f "$ANALOG_BRIDGE_INI" ]]; then
        quiet_detail "Analog_Bridge.ini not found: $ANALOG_BRIDGE_INI. BMTD/TGIFD identity placeholders may need manual editing."
    fi

    log "DVSwitch/Analog_Bridge dependency hints checked."
}

check_bmtd_helper_local_paths() {
    log "Checking BMTD helper local paths..."

    if grep -qE '/usr/local/bin/STFU|/opt/STFU|/var/www/html/alltune2/stfu|build/bmtd' "$BMTD_HELPER"; then
        fail "BMTD helper still references a legacy STFU path or build/bmtd instead of bmtd/bin/bmtd."
    fi

    if ! grep -q 'bmtd/bin/bmtd' "$BMTD_HELPER" && ! grep -q '\$BMTD_DIR/bin/bmtd' "$BMTD_HELPER"; then
        quiet_detail "BMTD helper does not visibly reference bmtd/bin/bmtd. Verify BIN_FILE before release."
    fi

    log "BMTD helper path check completed."
}

set_tree_mode_and_owner() {
    local base_dir="$1"
    local dir_mode="$2"
    local file_mode="$3"
    local owner="$4"
    local group="$5"
    local exclude_dir="${6:-}"

    [[ -d "$base_dir" ]] || return 0

    if [[ -n "$exclude_dir" && -d "$exclude_dir" ]]; then
        find "$base_dir" -path "$exclude_dir" -prune -o -type d -exec chmod "$dir_mode" {} +
        find "$base_dir" -path "$exclude_dir" -prune -o -type f -exec chmod "$file_mode" {} +
        find "$base_dir" -path "$exclude_dir" -prune -o -exec chown "$owner:$group" {} +
    else
        find "$base_dir" -type d -exec chmod "$dir_mode" {} +
        find "$base_dir" -type f -exec chmod "$file_mode" {} +
        chown -R "$owner:$group" "$base_dir"
    fi
}

set_permissions() {
    log "Setting ownership and permissions..."

    local readonly_dirs=(
        "$APP_CODE_DIR"
        "$API_DIR"
        "$PUBLIC_DIR"
        "$DOCS_DIR"
        "$TOOLS_DIR"
        "$BMTD_DIR"
    )
    local dir
    for dir in "${readonly_dirs[@]}"; do
        set_tree_mode_and_owner "$dir" 0755 0644 root root
    done

    if [[ -d "$TGIF_DIR" ]]; then
        set_tree_mode_and_owner "$TGIF_DIR" 0755 0644 root root
    fi

    local top_level_files=(
        "$APP_DIR/README.md"
        "$APP_DIR/VERSION"
        "$APP_DIR/.gitignore"
        "$APP_DIR/tree.txt"
        "$APP_DIR/screenshot.png"
    )
    local file
    for file in "${top_level_files[@]}"; do
        if [[ -f "$file" ]]; then
            chmod 0644 "$file"
            chown root:root "$file"
        fi
    done

    chmod 0755 "$SCRIPT_PATH"
    chown root:root "$SCRIPT_PATH"

    chmod 0755 "$BMTD_HELPER"
    chown root:root "$BMTD_HELPER"

    if [[ -f "$BMTD_BINARY" ]]; then
        chmod 0755 "$BMTD_BINARY"
        chown root:root "$BMTD_BINARY"
    fi

    chmod 0755 "$TGIF_HELPER"
    chown root:root "$TGIF_HELPER"

    if [[ -f "$TGIF_BINARY" ]]; then
        chmod 0755 "$TGIF_BINARY"
        chown root:root "$TGIF_BINARY"
    fi

    if [[ -f "$TOOLS_DIR/alltune2_set_admin_password.php" ]]; then
        chmod 0750 "$TOOLS_DIR/alltune2_set_admin_password.php"
        chown root:root "$TOOLS_DIR/alltune2_set_admin_password.php"
    fi

    chmod 0775 "$DATA_DIR"
    chown "$WEB_USER":"$WEB_GROUP" "$DATA_DIR"

    chmod 0775 "$LOGS_DIR"
    chown "$WEB_USER":"$WEB_GROUP" "$LOGS_DIR"

    chmod 0775 "$RUN_DIR"
    chown "$WEB_USER":"$WEB_GROUP" "$RUN_DIR"

    chmod 0664 "$FAVORITES_FILE"
    chown "$WEB_USER":"$WEB_GROUP" "$FAVORITES_FILE"

    chmod 0640 "$CONFIG_FILE"
    chown root:"$WEB_GROUP" "$CONFIG_FILE"

    chmod 0644 "$CONFIG_EXAMPLE_FILE"
    chown root:root "$CONFIG_EXAMPLE_FILE"

    chmod 0755 "$BMTD_CONFIG_DIR"
    chown root:"$WEB_GROUP" "$BMTD_CONFIG_DIR"

    chmod 0640 "$BMTD_CONFIG_FILE"
    chown root:"$WEB_GROUP" "$BMTD_CONFIG_FILE"

    chmod 0644 "$BMTD_CONFIG_EXAMPLE"
    chown root:root "$BMTD_CONFIG_EXAMPLE"

    chmod 0755 "$TGIF_CONFIG_DIR"
    chown root:"$WEB_GROUP" "$TGIF_CONFIG_DIR"

    chmod 0640 "$TGIF_CONFIG_FILE"
    chown root:"$WEB_GROUP" "$TGIF_CONFIG_FILE"

    chmod 0644 "$TGIF_CONFIG_EXAMPLE"
    chown root:root "$TGIF_CONFIG_EXAMPLE"

    if [[ -f "$TGIF_COPYRIGHT_NOTICE" ]]; then
        chmod 0644 "$TGIF_COPYRIGHT_NOTICE"
        chown root:root "$TGIF_COPYRIGHT_NOTICE"
    fi
}

install_validated_sudoers_file() {
    local target_file="$1"
    local rule_line="$2"
    local temp_file

    temp_file="$(mktemp)"
    printf '%s\n' "$rule_line" > "$temp_file"
    chmod 0440 "$temp_file"

    visudo -cf "$temp_file" >/dev/null || {
        rm -f "$temp_file"
        fail "visudo validation failed for generated sudoers file: $target_file"
    }

    if [[ -f "$target_file" ]] && cmp -s "$temp_file" "$target_file"; then
        rm -f "$temp_file"
        log "Sudoers file already up to date: $target_file"
        return
    fi

    install -o root -g root -m 0440 "$temp_file" "$target_file"
    rm -f "$temp_file"
    log "Installed sudoers file: $target_file"
}

create_or_update_sudoers_files() {
    log "Ensuring required sudoers rules exist..."

    [[ -x "$ASTERISK_BIN" ]] || fail "Asterisk binary not found at $ASTERISK_BIN"
    [[ -x "$BMTD_HELPER" ]] || fail "BMTD helper is not executable: $BMTD_HELPER"
    [[ -x "$TGIF_HELPER" ]] || fail "TGIF helper is not executable: $TGIF_HELPER"
    [[ -x "$SYSTEMCTL_BIN" ]] || fail "systemctl not found at $SYSTEMCTL_BIN"

    install_validated_sudoers_file "$ASTERISK_SUDOERS_FILE" "$EXPECTED_ASTERISK_SUDOERS_RULE"
    install_validated_sudoers_file "$BMTD_HELPER_SUDOERS_FILE" "$EXPECTED_BMTD_HELPER_SUDOERS_RULE"
    install_validated_sudoers_file "$TGIF_HELPER_SUDOERS_FILE" "$EXPECTED_TGIF_HELPER_SUDOERS_RULE"
    install_validated_sudoers_file "$MMDVM_BRIDGE_SUDOERS_FILE" "$EXPECTED_MMDVM_BRIDGE_SUDOERS_RULE"
    install_validated_sudoers_file "$ASTERISK_SERVICE_SUDOERS_FILE" "$EXPECTED_ASTERISK_SERVICE_SUDOERS_RULE"
    install_validated_sudoers_file "$YSFGATEWAY_SUDOERS_FILE" "$EXPECTED_YSFGATEWAY_SUDOERS_RULE"

}

check_php_syntax() {
    log "Running PHP syntax checks..."

    local php_files=(
        "$APP_DIR/app/Support/Config.php"
        "$APP_DIR/api/connect.php"
        "$APP_DIR/api/status.php"
        "$APP_DIR/api/direct_link.php"
        "$APP_DIR/public/index.php"
        "$APP_DIR/public/favorites.php"
        "$APP_DIR/public/alltune2_ribbon_bar.php"
    )

    local file
    for file in "${php_files[@]}"; do
        if [[ -f "$file" ]]; then
            php -l "$file" >/dev/null || fail "PHP syntax check failed: $file"
        fi
    done

    log "PHP syntax checks passed."
}

check_shell_syntax() {
    log "Running shell syntax checks..."

    bash -n "$SCRIPT_PATH" || fail "Shell syntax check failed: $SCRIPT_PATH"
    bash -n "$BMTD_HELPER" || fail "Shell syntax check failed: $BMTD_HELPER"
    bash -n "$TGIF_HELPER" || fail "Shell syntax check failed: $TGIF_HELPER"

    log "Shell syntax checks passed."
}

check_tgifd_runtime_files() {
    log "Checking TGIFD runtime files..."

    [[ -x "$TGIF_BINARY" ]] || fail "TGIFD binary missing or not executable: $TGIF_BINARY"
    [[ -f "$TGIF_HELPER" ]] || fail "Missing TGIFD helper: $TGIF_HELPER"
    [[ -f "$TGIF_CONFIG_EXAMPLE" ]] || fail "Missing TGIFD config example: $TGIF_CONFIG_EXAMPLE"
    [[ -f "$TGIF_COPYRIGHT_NOTICE" ]] || fail "Missing TGIFD copyright notice: $TGIF_COPYRIGHT_NOTICE"

    log "TGIFD runtime checks passed."
}


check_bmtd_runtime_files() {
    log "Checking BMTD runtime files..."

    [[ -x "$BMTD_BINARY" ]] || fail "BMTD binary missing or not executable: $BMTD_BINARY"
    [[ -f "$BMTD_HELPER" ]] || fail "Missing BMTD helper: $BMTD_HELPER"
    [[ -f "$BMTD_CONFIG_EXAMPLE" ]] || fail "Missing BMTD config example: $BMTD_CONFIG_EXAMPLE"

    log "BMTD runtime checks passed."
}

check_config_content() {
    log "Checking config.ini keys..."

    local required_keys=(
        "MYNODE"
        "DVSWITCH_NODE"
        "BM_SelfcarePassword"
        "TGIF_HotspotSecurityKey"
    )

    local missing=0
    local key

    for key in "${required_keys[@]}"; do
        if ! grep -qE "^[[:space:]]*${key}[[:space:]]*=" "$CONFIG_FILE"; then
            warn "Missing config key in $CONFIG_FILE: $key"
            missing=1
        fi
    done

    if [[ "$missing" -eq 0 ]]; then
        log "Required config keys appear present."
    else
        warn "config.ini is missing one or more required keys."
    fi
}

warn_if_placeholder_values_remain() {
    log "Checking for placeholder values..."

    local placeholders_regex='YOUR NODE|YOUR DVSWITCH NODE|CHANGE_ME|YOUR_REAL_PASSWORD|YOUR_REAL_KEY|YOUR PASSWORD|YOUR KEY'

    if grep -Eq "$placeholders_regex" "$CONFIG_FILE"; then
        quiet_detail "config.ini still contains placeholder values. Review before first use."
    fi

    if grep -Eq "$placeholders_regex" "$TGIF_CONFIG_FILE"; then
        quiet_detail "tgifd.ini still contains placeholder values. TGIFD may not work until it is reviewed."
    fi

    if grep -Eq "$placeholders_regex" "$BMTD_CONFIG_FILE"; then
        quiet_detail "bmtd.ini still contains placeholder values. BMTD may not work until it is reviewed."
    fi

    if ! grep -Eq 'MYNODE[[:space:]]*=' "$CONFIG_FILE"; then
        warn "config.ini does not define MYNODE."
    fi

    if ! grep -Eq 'DVSWITCH_NODE[[:space:]]*=' "$CONFIG_FILE"; then
        warn "config.ini does not define DVSWITCH_NODE."
    fi
}

check_external_config_hints() {
    log "Checking external system config hints..."

    if ! grep -qE '^[[:space:]]*gatewayDmrId[[:space:]]*=' "$ANALOG_BRIDGE_INI"; then
        quiet_detail "Analog_Bridge.ini does not contain gatewayDmrId. Local TG generation may fail."
    fi

    if ! grep -qE '^[[:space:]]*txTg[[:space:]]*=' "$ANALOG_BRIDGE_INI"; then
        quiet_detail "Analog_Bridge.ini does not contain txTg. Local TG fallback may fail."
    fi

    if ! grep -qE '^[[:space:]]*BMPassword[[:space:]]*=' "$DVSWITCH_INI"; then
        quiet_detail "DVSwitch.ini does not contain BMPassword. BM receive mode may not work."
    fi

    quiet_detail "TGIFD reminder: if TGIF does not connect, review $TGIF_CONFIG_FILE and the Analog_Bridge identity/TLV settings."
}


logrotate_external_owners() {
    local target_file="$1"
    local log_path="$2"
    local owner=""
    local target_real=""
    local owner_real=""

    target_real="$(readlink -f "$target_file" 2>/dev/null || printf '%s' "$target_file")"

    while IFS= read -r owner; do
        [[ -n "$owner" ]] || continue
        owner_real="$(readlink -f "$owner" 2>/dev/null || printf '%s' "$owner")"
        if [[ "$owner_real" != "$target_real" ]]; then
            printf '%s\n' "$owner"
        fi
    done < <(grep -RIlF -- "$log_path" /etc/logrotate.conf /etc/logrotate.d 2>/dev/null || true)
}

ensure_log_file_if_missing() {
    local file="$1"

    if [[ ! -e "$file" ]]; then
        touch "$file"
        quiet_detail "Created missing log file: $file"
    else
        quiet_detail "Existing log file preserved: $file"
    fi

    chmod 0664 "$file"
    chown "$WEB_USER":"$WEB_GROUP" "$file"
    quiet_detail "Runtime log file permissions ready: $file"
}

install_logrotate_rule_without_duplicates() {
    local target_file="$1"
    local rule_body="$2"
    shift 2

    local requested_paths=("$@")
    local managed_paths=()
    local log_path=""
    local owners=""
    local conflict=0

    for log_path in "${requested_paths[@]}"; do
        owners="$(logrotate_external_owners "$target_file" "$log_path" || true)"
        if [[ -n "$owners" ]]; then
            conflict=1
            quiet_detail "Existing logrotate rule already manages $log_path. Preserving existing owner(s): $(echo "$owners" | paste -sd ',' -)"
        else
            managed_paths+=("$log_path")
        fi
    done

    # If another/manual rule owns some paths, AllTune2 must not duplicate them.
    # If no paths remain for this AllTune2 file, remove only the AllTune2-owned file.
    if [[ "$conflict" -eq 1 ]]; then
        if [[ "${#managed_paths[@]}" -eq 0 ]]; then
            if [[ -f "$target_file" ]]; then
                rm -f -- "$target_file"
                quiet_detail "Removed AllTune2 logrotate file to avoid duplicate log ownership: $target_file"
            fi
            return 0
        fi

        {
            printf '%s {\n' "${managed_paths[*]}"
            printf '%s\n' "$rule_body"
            printf '}\n'
        } > "$target_file"

        chmod 0644 "$target_file"
        chown root:root "$target_file"
        quiet_detail "Adjusted AllTune2 logrotate file to avoid duplicate log ownership: $target_file"
        return 0
    fi

    # If AllTune2 already owns the file and no external duplicate exists,
    # preserve it. Users may have changed policy values such as rotate/compress.
    if [[ -f "$target_file" ]]; then
        chmod 0644 "$target_file"
        chown root:root "$target_file"
        quiet_detail "Existing AllTune2 logrotate file preserved: $target_file"
        return 0
    fi

    {
        printf '%s {\n' "${requested_paths[*]}"
        printf '%s\n' "$rule_body"
        printf '}\n'
    } > "$target_file"

    chmod 0644 "$target_file"
    chown root:root "$target_file"
    quiet_detail "Installed new AllTune2 logrotate file: $target_file"
}



normalize_tgifd_log_path() {
    local current_tgifd_log_path=""

    mkdir -p "$LOGS_DIR"

    # Keep TGIFD on the current AllTune2 log path for fresh installs and updates.
    if [[ -f "$TGIF_CONFIG_FILE" ]]; then
        current_tgifd_log_path="$(grep -E '^[[:space:]]*log_file[[:space:]]*=' "$TGIF_CONFIG_FILE" 2>/dev/null | tail -n 1 | sed -E 's/^[^=]+=//; s/^[[:space:]]*//; s/[[:space:]]*$//; s/^"//; s/"$//' || true)"
        if [[ "$current_tgifd_log_path" == "./tgifd.log" || "$current_tgifd_log_path" == "$TGIF_DIR/tgifd.log" || "$current_tgifd_log_path" == "/var/www/html/alltune2/tgif/tgifd.log" ]]; then
            sed -i "s|^[[:space:]]*log_file[[:space:]]*=.*|log_file=$TGIF_LOG_FILE|" "$TGIF_CONFIG_FILE"
        fi
    fi

    # If an older AllTune2 logrotate rule points at old TGIFD paths, remove only
    # the stale rule. The normal logrotate installer below recreates the current rule.
    if [[ -f "$TGIF_LOGROTATE_FILE" ]] && grep -Eq 'tgifd-helper\.log|/var/www/html/alltune2/tgif/tgifd\.log' "$TGIF_LOGROTATE_FILE"; then
        rm -f "$TGIF_LOGROTATE_FILE" >/dev/null 2>&1 || true
    fi
}

create_or_update_logrotate_files() {
    log "Ensuring AllTune2 log rotation exists without overwriting existing user rules..."

    local before_ok=0
    if command -v logrotate >/dev/null 2>&1; then
        if logrotate -d /etc/logrotate.conf >/dev/null 2>&1; then
            before_ok=1
        else
            :
        fi
    fi

    mkdir -p "$LOGS_DIR" "$TGIF_DIR"

    # Current logs are $LOGS_DIR/bmtd.log and $LOGS_DIR/tgifd.log.

    rm -f /etc/logrotate.d/alltune2-stfu "$LEGACY_BM_RECEIVE_LOGROTATE_FILE" >/dev/null 2>&1 || true

    ensure_log_file_if_missing "$BMTD_LOG_FILE"
    ensure_log_file_if_missing "$TGIF_LOG_FILE"
    normalize_tgifd_log_path

    local default_one_day_rule
    default_one_day_rule='    daily
    rotate 1
    maxage 1
    missingok
    notifempty
    nocompress
    copytruncate
    su root root'

    install_logrotate_rule_without_duplicates "$BMTD_LOGROTATE_FILE" "$default_one_day_rule" \
        "$BMTD_LOG_FILE"

    install_logrotate_rule_without_duplicates "$TGIF_LOGROTATE_FILE" "$default_one_day_rule" \
        "$TGIF_LOG_FILE"

    if command -v logrotate >/dev/null 2>&1; then
        if logrotate -d /etc/logrotate.conf >/dev/null 2>&1; then
            log "Full logrotate configuration validation passed."
        elif [[ "$before_ok" -eq 1 ]]; then
            fail "Full logrotate validation failed after AllTune2 logrotate setup. Review /etc/logrotate.d/alltune2-* and /etc/logrotate.conf."
        else
            :
        fi
    else
        quiet_detail "logrotate command not found. Rotation cannot run until logrotate is installed."
    fi

    log "AllTune2 logrotate checks completed. Existing user/system rules were preserved."
}


create_or_update_apache_security_conf() {
    log "Ensuring Apache security hardening exists..."

    if ! command -v apache2ctl >/dev/null 2>&1; then
        quiet_detail "apache2ctl not found. Skipping Apache security config install."
        return
    fi

    if ! command -v a2enconf >/dev/null 2>&1; then
        quiet_detail "a2enconf not found. Skipping Apache security config install."
        return
    fi

    mkdir -p /etc/apache2/conf-available

    cat > "$APACHE_SECURITY_CONF_FILE" <<EOF
# AllTune2 security hardening
# Blocks direct web access to local config, runtime, helper, git, log, and data files.
# PHP can still read these files locally from the filesystem.

<Directory "$APP_DIR">
    Options -Indexes

    <FilesMatch "(^\.|^VERSION$|^README\.md$|^tree\.txt$|\.ini(\.example)?$|\.cfg(\.example)?$|\.json$|\.log$|\.bak$|\.pid$|\.state$|\.out$|\.lock$|\.db$|\.sqlite$|\.env$|\.yml$|\.yaml$|\.sh$|\.py$|composer\.(json|lock)$)">
        Require all denied
    </FilesMatch>
</Directory>

<DirectoryMatch "^$APP_DIR/(\.git|app|data|docs|logs|run|tools|tgif|bmtd)(/|$)">
    Require all denied
</DirectoryMatch>
EOF

    chmod 0644 "$APACHE_SECURITY_CONF_FILE"
    chown root:root "$APACHE_SECURITY_CONF_FILE"

    a2enconf "$APACHE_SECURITY_CONF_NAME" >/dev/null || fail "Failed to enable Apache security conf: $APACHE_SECURITY_CONF_NAME"
    apache2ctl configtest >/dev/null 2>&1 || fail "Apache configtest failed after installing $APACHE_SECURITY_CONF_FILE"

    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet apache2; then
        systemctl reload apache2 || fail "Failed to reload apache2 after installing $APACHE_SECURITY_CONF_FILE"
    else
        quiet_detail "Apache service is not active or systemctl is unavailable. Installed $APACHE_SECURITY_CONF_FILE, but Apache was not reloaded automatically."
    fi

    log "Installed Apache security conf: $APACHE_SECURITY_CONF_FILE"
}

create_or_update_apache_accesslog_filter() {
    log "Ensuring Apache access log filter exists for AllTune2 polling URLs..."

    if ! command -v apache2ctl >/dev/null 2>&1; then
        quiet_detail "apache2ctl not found. Skipping Apache access log filter install."
        return
    fi

    if [[ ! -d /etc/apache2/sites-available && ! -d /etc/apache2/sites-enabled ]]; then
        quiet_detail "Apache site directories not found. Skipping Apache access log filter install."
        return
    fi

    local restore_file
    restore_file="$(mktemp "${TMPDIR:-/tmp}/alltune2-apache-accesslog-restore.XXXXXX.json")" || {
        quiet_detail "Could not create temporary restore file for Apache access log filter."
        return
    }
    local patch_output

    if ! patch_output="$(python3 - "$restore_file" <<'PYAPACHE'
import json
import os
import pathlib
import re
import sys

restore_file = pathlib.Path(sys.argv[1])
restore_items = {}

# The filter suppresses only AllTune2's high-frequency browser polling URLs
# from Apache access.log. It does not block the requests.
expr_line = 'CustomLog ${APACHE_LOG_DIR}/access.log combined "expr=!(%{REQUEST_URI} =~ m#^/alltune2/(api/status\\.php|public/alltune2_ribbon_bar\\.php)#)"'

plain_customlog_re = re.compile(r'^(\s*)CustomLog\s+\$\{APACHE_LOG_DIR\}/access\.log\s+combined\s*$')
old_env_customlog_re = re.compile(r'^(\s*)CustomLog\s+\$\{APACHE_LOG_DIR\}/access\.log\s+combined\s+env=!dontlog_alltune2_polling\s*$')

def line_has_alltune_filter(line: str) -> bool:
    normalized = line.replace('\\', '')
    return (
        'CustomLog' in line
        and 'access.log' in line
        and 'expr=' in line
        and '/alltune2/' in normalized
        and 'api/status.php' in normalized
        and 'public/alltune2_ribbon_bar.php' in normalized
    )

def replacement_for_line(line: str):
    if 'CustomLog' not in line or 'access.log' not in line:
        return None

    if line_has_alltune_filter(line):
        return None

    old_env_match = old_env_customlog_re.match(line.rstrip('\r\n'))
    if old_env_match:
        return old_env_match.group(1) + expr_line

    plain_match = plain_customlog_re.match(line.rstrip('\r\n'))
    if plain_match:
        return plain_match.group(1) + expr_line

    return None

site_available = pathlib.Path(os.environ.get('ALLTUNE2_APACHE_SITES_AVAILABLE', '/etc/apache2/sites-available'))
site_enabled = pathlib.Path(os.environ.get('ALLTUNE2_APACHE_SITES_ENABLED', '/etc/apache2/sites-enabled'))

candidate_paths = [
    site_available / '000-default.conf',
    site_available / 'default-ssl.conf',
]

if site_enabled.exists():
    for item in site_enabled.glob('*.conf'):
        try:
            candidate_paths.append(item.resolve())
        except Exception:
            continue

seen = set()
paths = []
for path in candidate_paths:
    key = str(path)
    if key in seen:
        continue
    seen.add(key)
    if path.exists() and path.is_file():
        paths.append(path)

changed = []
skipped = []

for path in paths:
    try:
        text = path.read_text()
    except Exception as exc:
        skipped.append(f'{path}: could not read file: {exc}')
        continue

    lines = text.splitlines(keepends=True)
    new_lines = []
    file_changed = False

    for line in lines:
        if 'CustomLog' in line and 'access.log' in line:
            if line_has_alltune_filter(line):
                new_lines.append(line)
                continue

            replacement = replacement_for_line(line)
            if replacement is not None:
                ending = '\n' if line.endswith('\n') else ''
                new_lines.append(replacement + ending)
                file_changed = True
                continue

            if 'expr=' in line:
                skipped.append(f'{path}: CustomLog access.log already has a custom expr= clause; left unchanged')
            else:
                skipped.append(f'{path}: CustomLog access.log format not recognized; left unchanged')

        new_lines.append(line)

    if not file_changed:
        continue

    restore_items[str(path)] = text
    path.write_text(''.join(new_lines))
    changed.append(str(path))

if restore_items:
    restore_file.write_text(json.dumps(restore_items))

for item in skipped:
    print(f'SKIPPED {item}')
for item in changed:
    print(f'CHANGED {item}')
if not changed:
    print('NO_CHANGES')
PYAPACHE
)"; then
        quiet_detail "Apache access log filter patch helper failed. Leaving Apache config unchanged."
        rm -f "$restore_file"
        return
    fi

    if [[ "$patch_output" == *"SKIPPED"* ]]; then
        while IFS= read -r line; do
            [[ "$line" == SKIPPED* ]] && quiet_detail "${line#SKIPPED }"
        done <<< "$patch_output"
    fi

    if [[ "$patch_output" != *"CHANGED"* ]]; then
        rm -f "$restore_file"
        log "Apache access log filter already installed or no standard access.log CustomLog lines were found."
        return
    fi

    if ! apache2ctl configtest >/dev/null 2>&1; then
        warn "Apache configtest failed after installing AllTune2 access log filter. Restoring Apache site files."
        if [[ -f "$restore_file" ]]; then
            python3 - "$restore_file" <<'PYRESTORE'
import json
import pathlib
import sys

restore_file = pathlib.Path(sys.argv[1])
restore_items = json.loads(restore_file.read_text())
for original, content in restore_items.items():
    pathlib.Path(original).write_text(content)
restore_file.unlink(missing_ok=True)
PYRESTORE
        fi

        if apache2ctl configtest >/dev/null 2>&1; then
            quiet_detail "Restored Apache site files. Access log filter was not installed."
            rm -f "$restore_file"
            return
        fi

        rm -f "$restore_file"
        fail "Apache configtest still fails after restoring access log filter changes. Manual Apache review is required."
    fi

    rm -f "$restore_file"

    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet apache2; then
        systemctl reload apache2 || fail "Failed to reload apache2 after installing AllTune2 access log filter."
    else
        quiet_detail "Apache service is not active or systemctl is unavailable. Access log filter installed, but Apache was not reloaded automatically."
    fi

    log "Installed Apache access log filter for AllTune2 status/ribbon polling URLs."
}


check_web_user_sudo_command() {
    local description="$1"
    shift

    if sudo -u "$WEB_USER" sudo -n "$@" >/dev/null 2>&1; then
        log "Web-user sudo check passed: $description"
    else
        fail "Web-user sudo check failed for $description. Command: sudo -u $WEB_USER sudo -n $*"
    fi
}

check_web_user_sudo_allowed() {
    local description="$1"
    shift

    if sudo -u "$WEB_USER" sudo -n -l "$@" >/dev/null 2>&1; then
        log "Web-user sudo permission check passed: $description"
    else
        fail "Web-user sudo permission check failed for $description. Command: sudo -u $WEB_USER sudo -n -l $*"
    fi
}

check_sudoers_requirement() {
    log "Checking installed sudoers files..."

    grep -qF "$EXPECTED_ASTERISK_SUDOERS_RULE" "$ASTERISK_SUDOERS_FILE"         || fail "Expected Asterisk sudoers rule not found in $ASTERISK_SUDOERS_FILE"

    grep -qF "$EXPECTED_BMTD_HELPER_SUDOERS_RULE" "$BMTD_HELPER_SUDOERS_FILE"         || fail "Expected BMTD sudoers rule not found in $BMTD_HELPER_SUDOERS_FILE"

    grep -qF "$EXPECTED_TGIF_HELPER_SUDOERS_RULE" "$TGIF_HELPER_SUDOERS_FILE"         || fail "Expected TGIF helper sudoers rule not found in $TGIF_HELPER_SUDOERS_FILE"

    grep -qF "$EXPECTED_MMDVM_BRIDGE_SUDOERS_RULE" "$MMDVM_BRIDGE_SUDOERS_FILE"         || fail "Expected MMDVM_Bridge sudoers rule not found in $MMDVM_BRIDGE_SUDOERS_FILE"

    grep -qF "$EXPECTED_ASTERISK_SERVICE_SUDOERS_RULE" "$ASTERISK_SERVICE_SUDOERS_FILE"         || fail "Expected Asterisk service sudoers rule not found in $ASTERISK_SERVICE_SUDOERS_FILE"

    grep -qF "$EXPECTED_YSFGATEWAY_SUDOERS_RULE" "$YSFGATEWAY_SUDOERS_FILE"         || fail "Expected YSFGateway sudoers rule not found in $YSFGATEWAY_SUDOERS_FILE"

    visudo -cf "$ASTERISK_SUDOERS_FILE" >/dev/null || fail "Sudoers file failed validation: $ASTERISK_SUDOERS_FILE"
    visudo -cf "$BMTD_HELPER_SUDOERS_FILE" >/dev/null || fail "Sudoers file failed validation: $BMTD_HELPER_SUDOERS_FILE"
    visudo -cf "$TGIF_HELPER_SUDOERS_FILE" >/dev/null || fail "Sudoers file failed validation: $TGIF_HELPER_SUDOERS_FILE"
    visudo -cf "$MMDVM_BRIDGE_SUDOERS_FILE" >/dev/null || fail "Sudoers file failed validation: $MMDVM_BRIDGE_SUDOERS_FILE"
    visudo -cf "$ASTERISK_SERVICE_SUDOERS_FILE" >/dev/null || fail "Sudoers file failed validation: $ASTERISK_SERVICE_SUDOERS_FILE"
    visudo -cf "$YSFGATEWAY_SUDOERS_FILE" >/dev/null || fail "Sudoers file failed validation: $YSFGATEWAY_SUDOERS_FILE"

    check_web_user_sudo_allowed "Asterisk CLI permission" "$ASTERISK_BIN"
    check_web_user_sudo_allowed "BMTD helper permission" "$BMTD_HELPER"
    check_web_user_sudo_allowed "TGIFD helper permission" "$TGIF_HELPER"
    check_web_user_sudo_allowed "MMDVM_Bridge start" "$SYSTEMCTL_BIN" start mmdvm_bridge.service
    check_web_user_sudo_allowed "YSFGateway start" "$SYSTEMCTL_BIN" start ysfgateway.service
    check_web_user_sudo_allowed "YSFGateway stop" "$SYSTEMCTL_BIN" stop ysfgateway.service
    check_web_user_sudo_allowed "Asterisk service restart permission" "$SYSTEMCTL_BIN" restart asterisk.service


    log "Installed sudoers files look correct, MMDVM_Bridge/YSFGateway control is available, and web-user sudo checks passed."
}

check_status_endpoint_cli() {
    log "Checking status endpoint through CLI..."

    if php "$APP_DIR/api/status.php" >/dev/null 2>&1; then
        log "CLI execution of api/status.php succeeded."
    else
        quiet_detail "CLI execution of api/status.php returned a non-zero status."
    fi
}

helper_status_output_has_action_status() {
    local output="${1:-}"
    [[ "$output" =~ \"action\"[[:space:]]*:[[:space:]]*\"status\" ]]
}

check_tgif_helper_cli() {
    log "Checking TGIFD helper through CLI as root and web user..."

    local output
    if output="$(sudo "$TGIF_HELPER" status 2>&1)"; then
        if helper_status_output_has_action_status "$output"; then
            log "TGIFD helper root CLI status check returned JSON."
        else
            quiet_detail "TGIFD helper root CLI status check returned unexpected output."
            quiet_detail "$output"
        fi
    else
        quiet_detail "TGIFD helper root CLI status check returned a non-zero status."
        quiet_detail "$output"
    fi

    if output="$(sudo -u "$WEB_USER" sudo -n "$TGIF_HELPER" status 2>&1)"; then
        if helper_status_output_has_action_status "$output"; then
            log "TGIFD helper web-user sudo status check returned JSON."
        else
            fail "TGIFD helper web-user sudo check returned unexpected output: $output"
        fi
    else
        fail "TGIFD helper web-user sudo check failed. Verify $TGIF_HELPER_SUDOERS_FILE. Output: $output"
    fi
}


check_bmtd_helper_cli() {
    log "Checking BMTD helper through CLI as root and web user..."

    local output
    if output="$(sudo "$BMTD_HELPER" status 2>&1)"; then
        if helper_status_output_has_action_status "$output"; then
            log "BMTD helper root CLI status check returned JSON."
        else
            quiet_detail "BMTD helper root CLI status check returned unexpected output."
            quiet_detail "$output"
        fi
    else
        quiet_detail "BMTD helper root CLI status check returned a non-zero status."
        quiet_detail "$output"
    fi

    if output="$(sudo -u "$WEB_USER" sudo -n "$BMTD_HELPER" status 2>&1)"; then
        if helper_status_output_has_action_status "$output"; then
            log "BMTD helper web-user sudo status check returned JSON."
        else
            fail "BMTD helper web-user sudo check returned unexpected output: $output"
        fi
    else
        fail "BMTD helper web-user sudo check failed. Verify $BMTD_HELPER_SUDOERS_FILE. Output: $output"
    fi
}

check_git_hygiene_warnings() {
    log "Checking for common local-only files that must not be committed..."

    local local_paths=(
        "$CONFIG_FILE"
        "$FAVORITES_FILE"
        "$BMTD_CONFIG_FILE"
        "$TGIF_CONFIG_FILE"
        "$LOGS_DIR"
        "$RUN_DIR"
    )

    local path
    for path in "${local_paths[@]}"; do
        if [[ -e "$path" ]]; then
            log "Local-only path present, confirm .gitignore protects it before release: $path"
        fi
    done
}


show_summary() {
    local version="unknown"
    local web_login="Disabled"
    local apache_security="Not installed"
    local auth_enabled=""
    local auth_hash=""

    if [[ -f "$VERSION_FILE" ]]; then
        version="$(tr -d '
' < "$VERSION_FILE")"
    fi

    if [[ -f "$CONFIG_FILE" ]]; then
        auth_enabled="$(grep -E '^[[:space:]]*ALLTUNE2_AUTH_ENABLED[[:space:]]*=' "$CONFIG_FILE" 2>/dev/null | tail -n 1 | sed -E 's/^[^=]+=//; s/[[:space:]]//g; s/"//g' || true)"
        auth_hash="$(grep -E '^[[:space:]]*ALLTUNE2_ADMIN_PASSWORD_HASH[[:space:]]*=' "$CONFIG_FILE" 2>/dev/null | tail -n 1 | sed -E 's/^[^=]+=//; s/^[[:space:]]*//; s/[[:space:]]*$//; s/^"//; s/"$//' || true)"

        if [[ "$auth_enabled" == "1" && -n "$auth_hash" ]]; then
            web_login="Enabled"
        fi
    fi

    if [[ -f "$APACHE_SECURITY_CONF_FILE" ]]; then
        apache_security="Installed"
    fi

    echo
    echo "========================================"
    echo "[OK] $APP_NAME setup completed successfully."
    echo "========================================"
    echo "Version:         ${version}"
    echo "Dashboard:       /alltune2/public/"
    echo "Install path:    $APP_DIR"
    echo "Config:          $CONFIG_FILE"
    echo "Favorites:       $FAVORITES_FILE"
    echo "Web login:       $web_login"
    echo "Apache security: $apache_security"
    echo "BM backend:      BMTD"
    echo "TGIF backend:    TGIFD"
    if [[ "$INSTALLER_MODE" != "verbose" ]]; then
        echo "Installer log:   $QUIET_COMMAND_LOG"
    fi
    if [[ "${BMTD_CONFIG_HAS_PLACEHOLDERS:-0}" == "1" ]]; then
        echo "BMTD config:     Review before first BM connect ($BMTD_CONFIG_FILE)"
    else
        echo "BMTD config:     Checked"
    fi
    if [[ "${TGIFD_CONFIG_HAS_PLACEHOLDERS:-0}" == "1" ]]; then
        echo "TGIFD config:    Review before first TGIF connect ($TGIF_CONFIG_FILE)"
    else
        echo "TGIFD config:    Checked"
    fi
    echo

    echo "Notes:"
    echo "- Existing config.ini, favorites.txt, and web login settings are preserved."
    echo "- New installs should review config files if placeholders are shown above."
    echo "- Open /alltune2/public/ and test your enabled modes."
    echo
}

main() {
    require_root
    require_app_dir
    validate_installer_mode
    init_quiet_command_log

    if [[ "$AUTH_ACTION" == "set-password" ]]; then
        check_auth_runtime_tools
        check_web_user
        make_dirs
        create_config_example
        create_config_if_missing
        ensure_auth_config_defaults
        run_auth_password_setup
        exit 0
    fi

    if [[ "$AUTH_ACTION" == "disable-auth" ]]; then
        check_web_user
        make_dirs
        create_config_example
        create_config_if_missing
        ensure_auth_config_defaults
        run_auth_disable
        exit 0
    fi

    step "Installing/checking minimum runtime prerequisites..."
    install_minimum_packages_if_possible
    check_runtime_tools
    check_web_user

    step "Checking repo and system dependencies..."
    check_required_repo_files
    check_optional_files
    check_dvswitch_dependencies
    check_bmtd_helper_local_paths

    step "Checking BMTD/TGIFD binaries..."
    prepare_bmtd_binary
    prepare_tgifd_binary

    step "Preparing application files..."
    make_dirs
    create_config_example
    create_config_if_missing
    ensure_auth_config_defaults
    create_favorites_if_missing

    step "Preparing BMTD configuration..."
    create_bmtd_config_example_if_missing
    create_bmtd_config_if_missing
    sync_bmtd_config_from_system_if_safe

    step "Preparing TGIFD configuration..."
    create_tgifd_config_example_if_missing
    create_tgifd_config_if_missing
    sync_tgifd_config_from_system_if_safe

    step "Stopping any active BMTD/TGIFD after preflight checks..."
    stop_existing_bmtd_if_running
    stop_existing_tgifd_if_running

    step "Removing legacy STFU runtime path..."
    remove_legacy_stfu_runtime

    step "Applying permissions and sudoers..."
    set_permissions
    create_or_update_sudoers_files
    create_or_update_logrotate_files
    create_or_update_apache_security_conf
    create_or_update_apache_accesslog_filter

    step "Running installer self-checks..."
    check_php_syntax
    check_shell_syntax
    check_bmtd_runtime_files
    check_bmtd_binary
    check_tgifd_runtime_files
    check_tgifd_binary
    check_config_content
    check_bmtd_config_content
    check_tgifd_config_content
    warn_if_placeholder_values_remain
    check_external_config_hints
    check_sudoers_requirement
    check_status_endpoint_cli
    check_bmtd_helper_cli
    check_tgif_helper_cli
    check_git_hygiene_warnings

    SETUP_COMPLETED=1
    show_summary
}

main "$@"
