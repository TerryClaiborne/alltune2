#!/usr/bin/env bash
set -euo pipefail

APP_NAME="AllTune2"
APP_DIR="/var/www/html/alltune2"
PUBLIC_DIR="$APP_DIR/public"
ASSETS_DIR="$PUBLIC_DIR/assets"
CSS_DIR="$ASSETS_DIR/css"
JS_DIR="$ASSETS_DIR/js"
API_DIR="$APP_DIR/api"
APP_CODE_DIR="$APP_DIR/app"
DATA_DIR="$APP_DIR/data"
DOCS_DIR="$APP_DIR/docs"
LOGS_DIR="$APP_DIR/logs"
LOCAL_STFU_DIR="$APP_DIR/stfu"

CONFIG_FILE="$APP_DIR/config.ini"
CONFIG_EXAMPLE_FILE="$APP_DIR/config.ini.example"
FAVORITES_FILE="$DATA_DIR/favorites.txt"
VERSION_FILE="$APP_DIR/VERSION"

BM_RECEIVE_HELPER="$APP_DIR/alltune2-bm-receive.sh"
LOCAL_STFU_BIN="$LOCAL_STFU_DIR/STFU"

WEB_USER="www-data"
WEB_GROUP="www-data"

ASTERISK_BIN="/usr/sbin/asterisk"
DVSWITCH_SH="/opt/MMDVM_Bridge/dvswitch.sh"
DVSWITCH_INI="/opt/MMDVM_Bridge/DVSwitch.ini"

ASTERISK_SUDOERS_FILE="/etc/sudoers.d/alltune2-asterisk"
BM_RECEIVE_SUDOERS_FILE="/etc/sudoers.d/alltune2-bm-receive"

EXPECTED_ASTERISK_SUDOERS_RULE="${WEB_USER} ALL=(root) NOPASSWD: ${ASTERISK_BIN}"
EXPECTED_BM_RECEIVE_SUDOERS_RULE="${WEB_USER} ALL=(root) NOPASSWD: ${BM_RECEIVE_HELPER}"

log() {
    echo "[INFO] $*"
}

warn() {
    echo "[WARN] $*" >&2
}

fail() {
    echo "[ERROR] $*" >&2
    exit 1
}

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

check_runtime_tools() {
    log "Checking runtime tools..."
    command -v php >/dev/null 2>&1 || fail "php is not installed or not in PATH."
    command -v sudo >/dev/null 2>&1 || fail "sudo is not installed or not in PATH."
    command -v visudo >/dev/null 2>&1 || fail "visudo is not installed or not in PATH."

    if command -v apache2ctl >/dev/null 2>&1; then
        log "apache2ctl found."
    else
        warn "apache2ctl not found in PATH."
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
    mkdir -p "$DATA_DIR" "$DOCS_DIR" "$LOGS_DIR" "$LOCAL_STFU_DIR"
}

create_config_example() {
    if [[ ! -f "$CONFIG_EXAMPLE_FILE" ]]; then
        log "Creating config.ini.example..."
        cat > "$CONFIG_EXAMPLE_FILE" <<'EOF'
MYNODE="YOUR NODE"
DVSWITCH_NODE="YOUR DVSWITCH NODE"
BM_SelfcarePassword="CHANGE_ME"
TGIF_HotspotSecurityKey="CHANGE_ME"
EOF
    else
        log "config.ini.example already exists."
    fi

    chmod 0644 "$CONFIG_EXAMPLE_FILE"
    chown root:root "$CONFIG_EXAMPLE_FILE"
}

create_config_if_missing() {
    if [[ ! -f "$CONFIG_FILE" ]]; then
        log "config.ini not found. Creating starter config.ini..."
        cat > "$CONFIG_FILE" <<'EOF'
MYNODE="YOUR NODE"
DVSWITCH_NODE="YOUR DVSWITCH NODE"
BM_SelfcarePassword="CHANGE_ME"
TGIF_HotspotSecurityKey="CHANGE_ME"
EOF
        warn "Created $CONFIG_FILE with placeholder values. Edit it before using AllTune2."
    else
        log "config.ini already exists. Preserving current values."
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
311630|AA9JR Repeater Link|Morning Net|TGIF
19570|Example TGIF|Example Favorite|TGIF
3220008|Example BM|Example Favorite|BM
68064|Example AllStar|Example AllStar Node|ASL
parrot.ysfreflector.de:42020|Fusion|Parrot For Fusion|YSF
EOF
    else
        log "favorites.txt already exists. Preserving current contents."
    fi

    chmod 0664 "$FAVORITES_FILE"
    chown "$WEB_USER":"$WEB_GROUP" "$FAVORITES_FILE"
}

check_required_repo_files() {
    log "Checking required repo files for AllTune2 1.20.0..."

    local required_files=(
        "$APP_DIR/README.md"
        "$APP_DIR/VERSION"
        "$APP_DIR/tree.txt"
        "$APP_DIR/.gitignore"
        "$APP_DIR/setup_alltune2.sh"
        "$APP_DIR/alltune2-bm-receive.sh"
        "$APP_DIR/app/Support/Config.php"
        "$APP_DIR/api/connect.php"
        "$APP_DIR/api/status.php"
        "$APP_DIR/public/index.php"
        "$APP_DIR/public/favorites.php"
        "$APP_DIR/public/alltune2_ribbon_bar.php"
        "$APP_DIR/public/assets/js/app.js"
        "$APP_DIR/public/assets/css/style.css"
        "$CONFIG_EXAMPLE_FILE"
        "$LOCAL_STFU_BIN"
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
        fail "Required AllTune2 repo files are missing. This install expects the needed helper and local STFU binary to already be in the AllTune2 repo."
    fi

    log "Required repo files look present."
}

check_optional_files() {
    log "Checking optional scaffold files..."

    local optional_files=(
        "$APP_DIR/app/State/StatusMapper.php"
        "$APP_DIR/app/Actions/AllStarAction.php"
        "$APP_DIR/app/Actions/BrandMeisterAction.php"
        "$APP_DIR/app/Actions/TGIFAction.php"
        "$APP_DIR/app/Actions/YSFAction.php"
    )

    local file
    for file in "${optional_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            warn "Optional file not found: $file"
        fi
    done
}

check_dvswitch_dependencies() {
    log "Checking DVSwitch system dependencies..."

    [[ -x "$DVSWITCH_SH" ]] || fail "Required DVSwitch helper not found or not executable: $DVSWITCH_SH"
    [[ -f "$DVSWITCH_INI" ]] || fail "Required DVSwitch.ini not found: $DVSWITCH_INI"

    log "DVSwitch dependencies look present."
}

check_helper_local_paths() {
    log "Checking BM receive helper local paths..."

    grep -q '^STFU_DIR="/var/www/html/alltune2/stfu"$' "$BM_RECEIVE_HELPER" \
        || fail "alltune2-bm-receive.sh is not pointed at the AllTune2-local STFU directory."

    grep -q '^STFU_BIN="/var/www/html/alltune2/stfu/STFU"$' "$BM_RECEIVE_HELPER" \
        || fail "alltune2-bm-receive.sh is not pointed at the AllTune2-local STFU binary."

    if grep -q '/usr/local/bin/STFU' "$BM_RECEIVE_HELPER"; then
        fail "alltune2-bm-receive.sh still references /usr/local/bin/STFU. Update the helper before installing."
    fi

    if grep -q '/opt/STFU' "$BM_RECEIVE_HELPER"; then
        fail "alltune2-bm-receive.sh still references /opt/STFU. Update the helper before installing."
    fi

    log "BM receive helper local STFU paths look correct."
}

set_permissions() {
    log "Setting ownership and permissions..."

    find "$APP_DIR" -type d -exec chmod 0755 {} \;
    find "$APP_DIR" -type f -exec chmod 0644 {} \;

    chown -R root:root "$APP_DIR"

    chmod 0755 "$APP_DIR/setup_alltune2.sh"
    chmod 0755 "$BM_RECEIVE_HELPER"
    chmod 0755 "$LOCAL_STFU_BIN"

    chown root:root "$APP_DIR/setup_alltune2.sh"
    chown root:root "$BM_RECEIVE_HELPER"
    chown root:root "$LOCAL_STFU_BIN"

    chmod 0775 "$DATA_DIR"
    chown "$WEB_USER":"$WEB_GROUP" "$DATA_DIR"

    chmod 0775 "$LOGS_DIR"
    chown "$WEB_USER":"$WEB_GROUP" "$LOGS_DIR"

    chmod 0664 "$FAVORITES_FILE"
    chown "$WEB_USER":"$WEB_GROUP" "$FAVORITES_FILE"

    chmod 0640 "$CONFIG_FILE"
    chown root:"$WEB_GROUP" "$CONFIG_FILE"

    chmod 0644 "$CONFIG_EXAMPLE_FILE"
    chown root:root "$CONFIG_EXAMPLE_FILE"
}

create_or_update_sudoers_files() {
    log "Ensuring required sudoers rules exist..."

    [[ -x "$ASTERISK_BIN" ]] || fail "Asterisk binary not found at $ASTERISK_BIN"
    [[ -x "$BM_RECEIVE_HELPER" ]] || fail "BM receive helper is not executable: $BM_RECEIVE_HELPER"

    cat > "$ASTERISK_SUDOERS_FILE" <<EOF
$EXPECTED_ASTERISK_SUDOERS_RULE
EOF

    cat > "$BM_RECEIVE_SUDOERS_FILE" <<EOF
$EXPECTED_BM_RECEIVE_SUDOERS_RULE
EOF

    chmod 0440 "$ASTERISK_SUDOERS_FILE" "$BM_RECEIVE_SUDOERS_FILE"

    visudo -cf "$ASTERISK_SUDOERS_FILE" >/dev/null \
        || fail "visudo validation failed for $ASTERISK_SUDOERS_FILE"

    visudo -cf "$BM_RECEIVE_SUDOERS_FILE" >/dev/null \
        || fail "visudo validation failed for $BM_RECEIVE_SUDOERS_FILE"

    log "Sudoers files created and validated."
}

check_php_syntax() {
    log "Running PHP syntax checks..."

    local php_files=(
        "$APP_DIR/app/Support/Config.php"
        "$APP_DIR/api/connect.php"
        "$APP_DIR/api/status.php"
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

    bash -n "$APP_DIR/setup_alltune2.sh" || fail "Shell syntax check failed: $APP_DIR/setup_alltune2.sh"
    bash -n "$BM_RECEIVE_HELPER" || fail "Shell syntax check failed: $BM_RECEIVE_HELPER"

    log "Shell syntax checks passed."
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

check_sudoers_requirement() {
    log "Checking installed sudoers files..."

    grep -qF "$EXPECTED_ASTERISK_SUDOERS_RULE" "$ASTERISK_SUDOERS_FILE" \
        || fail "Expected Asterisk sudoers rule not found in $ASTERISK_SUDOERS_FILE"

    grep -qF "$EXPECTED_BM_RECEIVE_SUDOERS_RULE" "$BM_RECEIVE_SUDOERS_FILE" \
        || fail "Expected BM receive sudoers rule not found in $BM_RECEIVE_SUDOERS_FILE"

    visudo -cf "$ASTERISK_SUDOERS_FILE" >/dev/null \
        || fail "Sudoers file failed validation: $ASTERISK_SUDOERS_FILE"

    visudo -cf "$BM_RECEIVE_SUDOERS_FILE" >/dev/null \
        || fail "Sudoers file failed validation: $BM_RECEIVE_SUDOERS_FILE"

    log "Installed sudoers files look correct."
}

check_status_endpoint_cli() {
    log "Checking status endpoint through CLI..."

    if php "$APP_DIR/api/status.php" >/dev/null 2>&1; then
        log "CLI execution of api/status.php succeeded."
    else
        warn "CLI execution of api/status.php returned a non-zero status."
    fi
}

show_summary() {
    local version="unknown"

    if [[ -f "$VERSION_FILE" ]]; then
        version="$(tr -d '\r\n' < "$VERSION_FILE")"
    fi

    echo
    echo "========================================"
    echo "$APP_NAME setup summary"
    echo "========================================"
    echo "Version:              ${version}"
    echo "App directory:        $APP_DIR"
    echo "Config file:          $CONFIG_FILE"
    echo "Config example:       $CONFIG_EXAMPLE_FILE"
    echo "Favorites file:       $FAVORITES_FILE"
    echo "BM helper:            $BM_RECEIVE_HELPER"
    echo "Local STFU binary:    $LOCAL_STFU_BIN"
    echo "Web user/group:       $WEB_USER:$WEB_GROUP"
    echo "Asterisk sudoers:     $ASTERISK_SUDOERS_FILE"
    echo "BM helper sudoers:    $BM_RECEIVE_SUDOERS_FILE"
    echo

    echo "Notes:"
    echo "- Existing config.ini and favorites.txt are preserved."
    echo "- BM is one-step and uses the AllTune2-local BM receive helper."
    echo "- TGIF is one-step."
    echo "- The installer expects stfu/STFU to already be present in the AllTune2 repo."
    echo "- DVSwitch system files must already exist on the target system."
    echo

    echo "Next steps:"
    echo "1. Edit $CONFIG_FILE and set real values if needed."
    echo "2. Open /alltune2/public/ in the browser."
    echo "3. Test BM, TGIF, YSF, AllStarLink, EchoLink, Disconnect DVSwitch, and Disconnect All."
    echo "4. Confirm BM/TGIF one-step behavior and mixed-link operation."
    echo
}

main() {
    require_root
    require_app_dir
    check_runtime_tools
    check_web_user
    make_dirs
    create_config_example
    create_config_if_missing
    create_favorites_if_missing
    check_required_repo_files
    check_dvswitch_dependencies
    check_helper_local_paths
    set_permissions
    create_or_update_sudoers_files
    check_optional_files
    check_php_syntax
    check_shell_syntax
    check_config_content
    check_sudoers_requirement
    check_status_endpoint_cli
    show_summary
}

main "$@"
