#!/bin/bash
set -euo pipefail

TG="${1:-}"
RULES_DIR="${2:-/var/www/html/alltune2/tgif-hblink}"
TEMPLATE="${RULES_DIR}/rules.py.template"
RULES="${RULES_DIR}/rules.py"
HB_CFG="${RULES_DIR}/hblink.cfg"
ANALOG_INI="${ANALOG_INI:-/opt/Analog_Bridge/Analog_Bridge.ini}"
RELINK_TIME="${RELINK_TIME:-60}"

read_ini_value() {
    local key="$1"
    local file="$2"
    [[ -f "$file" ]] || return 0
    awk -F= -v k="$key" '
        $1 ~ "^[[:space:]]*" k "[[:space:]]*$" {
            v=$2
            sub(/;.*/, "", v)
            gsub(/\r/, "", v)
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", v)
            print v
            exit
        }
    ' "$file" 2>/dev/null
}

read_local_tg() {
    local tg
    tg="$(read_ini_value "gatewayDmrId" "$ANALOG_INI")"
    if [[ ! "$tg" =~ ^[0-9]+$ ]]; then
        tg="$(read_ini_value "txTg" "$ANALOG_INI")"
    fi
    printf '%s' "$tg"
}

[[ -n "$TG" ]] || { echo "Usage: $0 <talkgroup> [rules_dir]" >&2; exit 1; }
[[ "$TG" =~ ^[0-9]+$ ]] || { echo "Talkgroup must be numeric" >&2; exit 1; }
[[ -f "$TEMPLATE" ]] || { echo "Missing template: $TEMPLATE" >&2; exit 1; }
[[ -f "$HB_CFG" ]] || { echo "Missing hblink config: $HB_CFG" >&2; exit 1; }

LOCAL_TG="$(read_local_tg)"
[[ "$LOCAL_TG" =~ ^[0-9]+$ ]] || {
    echo "Failed to determine local TG from $ANALOG_INI" >&2
    exit 1
}

sed \
    -e "s/__LOCAL_TG__/${LOCAL_TG}/g" \
    -e "s/__TG__/${TG}/g" \
    "$TEMPLATE" > "$RULES"

echo "Wrote $RULES for upstream TG ${TG} and local TG ${LOCAL_TG}"

python3 - "$HB_CFG" "$TG" "$RELINK_TIME" <<'PY'
import pathlib
import re
import sys

cfg_path = pathlib.Path(sys.argv[1])
tg = sys.argv[2]
relink = sys.argv[3]
text = cfg_path.read_text()

section_pattern = re.compile(r'(?ms)^\[REPEATER-1\]\n(?P<body>.*?)(?=^\[|\Z)')
match = section_pattern.search(text)
if not match:
    raise SystemExit('Could not find [REPEATER-1] section in hblink.cfg')

body = match.group('body')

if re.search(r'(?m)^LOOSE:\s*', body):
    body = re.sub(r'(?m)^LOOSE:\s*.*$', 'LOOSE: True', body)
else:
    body = 'LOOSE: True\n' + body

new_options = f'OPTIONS: StartRef={tg};RelinkTime={relink}'
if re.search(r'(?m)^OPTIONS:\s*', body):
    body = re.sub(r'(?m)^OPTIONS:\s*.*$', new_options, body)
else:
    body = body.rstrip('\n') + '\n' + new_options + '\n'

if re.search(r'(?m)^USE_ACL:\s*', body):
    body = re.sub(r'(?m)^USE_ACL:\s*.*$', 'USE_ACL: True', body)
else:
    if re.search(r'(?m)^SUB_ACL:\s*', body):
        body = re.sub(r'(?m)^SUB_ACL:\s*.*$', 'USE_ACL: True\nSUB_ACL: PERMIT:ALL', body, count=1)
    else:
        body = body.rstrip('\n') + '\nUSE_ACL: True\nSUB_ACL: PERMIT:ALL\n'

text = text[:match.start('body')] + body + text[match.end('body'):]
cfg_path.write_text(text)
PY

echo "Updated ${HB_CFG} [REPEATER-1] LOOSE=True, USE_ACL=True, and OPTIONS to StartRef=${TG};RelinkTime=${RELINK_TIME}"
