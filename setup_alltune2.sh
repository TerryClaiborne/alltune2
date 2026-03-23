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

CONFIG_FILE="$APP_DIR/config.ini"
CONFIG_EXAMPLE_FILE="$APP_DIR/config.ini.example"
FAVORITES_FILE="$DATA_DIR/favorites.txt"

WEB_USER="www-data"
WEB_GROUP="www-data"

ASTERISK_BIN="/usr/sbin/asterisk"
SUDOERS_FILE="/etc/sudoers.d/alltune2-asterisk"
EXPECTED_SUDOERS_RULE="${WEB_USER} ALL=(ALL) NOPASSWD: ${ASTERISK_BIN}"

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
    command -v sudo >/dev/null 2>&1 || warn "sudo not found in PATH."
    command -v apache2ctl >/dev/null 2>&1 || warn "apache2ctl not found in PATH."
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
    mkdir -p "$APP_DIR"
    mkdir -p "$PUBLIC_DIR"
    mkdir -p "$ASSETS_DIR"
    mkdir -p "$CSS_DIR"
    mkdir -p "$JS_DIR"
    mkdir -p "$API_DIR"
    mkdir -p "$APP_CODE_DIR"
    mkdir -p "$DATA_DIR"
}

create_config_example() {
    if [[ ! -f "$CONFIG_EXAMPLE_FILE" ]]; then
        log "Creating config.ini.example..."
        cat > "$CONFIG_EXAMPLE_FILE" <<'EOF'
MYNODE="67040"
DVSWITCH_NODE="1957"
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
MYNODE="67040"
DVSWITCH_NODE="1957"
BM_SelfcarePassword="CHANGE_ME"
TGIF_HotspotSecurityKey="CHANGE_ME"
EOF
        warn "Created $CONFIG_FILE with placeholder values. Edit it before using AllTune2."
    else
        log "config.ini already exists."
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
19570|KC3KMV|TGIF Network|TGIF
3220008|KC3KMV|Brandmeister|BM
68064|KD4HNZ|Allstar Node 68064|ASL
parrot.ysfreflector.de:42020|Fusion|Parrot For Fusion|YSF
686590|KD4HZN|Doug Mac Jr. New Allstar Node|ASL
3147762|KF4JOZ|Outlaw Mike Larry|BM
EOF
    else
        log "favorites.txt already exists."
    fi

    chmod 0664 "$FAVORITES_FILE"
    chown "$WEB_USER":"$WEB_GROUP" "$FAVORITES_FILE"
}

set_permissions() {
    log "Setting ownership and permissions..."

    find "$APP_DIR" -type d -exec chmod 0755 {} \;
    find "$APP_DIR" -type f -exec chmod 0644 {} \;

    chown -R root:root "$APP_DIR"

    if [[ -d "$DATA_DIR" ]]; then
        chown "$WEB_USER":"$WEB_GROUP" "$DATA_DIR"
        chmod 0775 "$DATA_DIR"
    fi

    if [[ -f "$FAVORITES_FILE" ]]; then
        chown "$WEB_USER":"$WEB_GROUP" "$FAVORITES_FILE"
        chmod 0664 "$FAVORITES_FILE"
    fi

    if [[ -f "$CONFIG_FILE" ]]; then
        chown root:"$WEB_GROUP" "$CONFIG_FILE"
        chmod 0640 "$CONFIG_FILE"
    fi

    if [[ -f "$CONFIG_EXAMPLE_FILE" ]]; then
        chown root:root "$CONFIG_EXAMPLE_FILE"
        chmod 0644 "$CONFIG_EXAMPLE_FILE"
    fi

    if [[ -f "$APP_DIR/setup_alltune2.sh" ]]; then
        chown root:root "$APP_DIR/setup_alltune2.sh"
        chmod 0755 "$APP_DIR/setup_alltune2.sh"
    fi
}

check_required_files() {
    log "Checking required project files..."

    local required_files=(
        "$APP_DIR/README.md"
        "$APP_DIR/tree.txt"
        "$APP_DIR/.gitignore"
        "$APP_DIR/setup_alltune2.sh"
        "$APP_DIR/app/Support/Config.php"
        "$APP_DIR/app/State/StatusMapper.php"
        "$APP_DIR/api/connect.php"
        "$APP_DIR/api/status.php"
        "$APP_DIR/public/index.php"
        "$APP_DIR/public/favorites.php"
        "$APP_DIR/public/assets/js/app.js"
        "$APP_DIR/public/assets/css/style.css"
        "$CONFIG_EXAMPLE_FILE"
    )

    local missing=0

    for file in "${required_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            warn "Missing required file: $file"
            missing=1
        fi
    done

    if [[ "$missing" -ne 0 ]]; then
        warn "One or more required project files are missing."
    else
        log "Required project files look present."
    fi
}

check_optional_action_files() {
    log "Checking optional scaffold action files..."

    local optional_files=(
        "$APP_DIR/app/Actions/AllStarAction.php"
        "$APP_DIR/app/Actions/BrandMeisterAction.php"
        "$APP_DIR/app/Actions/TGIFAction.php"
        "$APP_DIR/app/Actions/YSFAction.php"
    )

    for file in "${optional_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            warn "Optional scaffold file not found: $file"
        fi
    done
}

check_php_syntax() {
    log "Running PHP syntax checks..."

    local php_files=(
        "$APP_DIR/app/Support/Config.php"
        "$APP_DIR/app/State/StatusMapper.php"
        "$APP_DIR/api/connect.php"
        "$APP_DIR/api/status.php"
        "$APP_DIR/public/index.php"
        "$APP_DIR/public/favorites.php"
    )

    for file in "${php_files[@]}"; do
        if [[ -f "$file" ]]; then
            php -l "$file" >/dev/null || fail "PHP syntax check failed: $file"
        fi
    done

    log "PHP syntax checks passed."
}

check_config_content() {
    log "Checking config.ini keys..."

    local required_keys=(
        "MYNODE"
        "DVSWITCH_NODE"
        "BM_SelfcarePassword"
        "TGIF_HotspotSecurityKey"
    )

    if [[ ! -f "$CONFIG_FILE" ]]; then
        warn "config.ini is missing: $CONFIG_FILE"
        return
    fi

    local missing=0
    for key in "${required_keys[@]}"; do
        if ! grep -qE "^[[:space:]]*${key}[[:space:]]*=" "$CONFIG_FILE"; then
            warn "Missing config key in $CONFIG_FILE: $key"
            missing=1
        fi
    done

    if [[ "$missing" -eq 0 ]]; then
        log "Required config keys appear present."
    fi
}

check_sudoers_requirement() {
    log "Checking Asterisk sudoers requirement..."

    if [[ ! -x "$ASTERISK_BIN" ]]; then
        warn "Asterisk binary not found at $ASTERISK_BIN"
        return
    fi

    if [[ -f "$SUDOERS_FILE" ]]; then
        if grep -qF "$EXPECTED_SUDOERS_RULE" "$SUDOERS_FILE"; then
            log "Sudoers file looks present: $SUDOERS_FILE"
        else
            warn "Sudoers file exists but does not contain the expected rule."
            warn "Expected:"
            warn "  $EXPECTED_SUDOERS_RULE"
        fi
    else
        warn "Missing sudoers file: $SUDOERS_FILE"
        warn "Create it with this exact line:"
        warn "  $EXPECTED_SUDOERS_RULE"
        warn "Then validate it with:"
        warn "  visudo -cf $SUDOERS_FILE"
    fi
}

check_status_endpoint_cli() {
    log "Checking status endpoint through CLI..."

    if [[ -f "$APP_DIR/api/status.php" ]]; then
        if ! php "$APP_DIR/api/status.php" >/dev/null; then
            warn "CLI execution of api/status.php returned a non-zero status."
        else
            log "CLI execution of api/status.php succeeded."
        fi
    fi
}

show_summary() {
    echo
    echo "========================================"
    echo "$APP_NAME setup summary"
    echo "========================================"
    echo "App directory:      $APP_DIR"
    echo "Config file:        $CONFIG_FILE"
    echo "Config example:     $CONFIG_EXAMPLE_FILE"
    echo "Favorites file:     $FAVORITES_FILE"
    echo "Web user/group:     $WEB_USER:$WEB_GROUP"
    echo "Sudoers file:       $SUDOERS_FILE"
    echo

    echo "Notes:"
    echo "- Dashboard and Status are the same main screen."
    echo "- Favorites uses one shared file: data/favorites.txt"
    echo "- AllTune2 uses its own config.ini in the app root."
    echo

    echo "Next steps:"
    echo "1. Edit $CONFIG_FILE and set real values."
    echo "2. Confirm $SUDOERS_FILE exists and is valid."
    echo "3. Open /alltune2/public/ in the browser."
    echo "4. Test BM, TGIF, YSF, AllStar, disconnects, and DVSwitch auto-load."
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
    set_permissions
    check_required_files
    check_optional_action_files
    check_php_syntax
    check_config_content
    check_sudoers_requirement
    check_status_endpoint_cli
    show_summary
}

main "$@"