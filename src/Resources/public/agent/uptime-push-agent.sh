#!/bin/sh
# uptime-push-agent — pushes this server's health to a NowoUptimeMonitorBundle push monitor (since 1.14).
#
# Reads /proc and df only, sends one HTTPS POST and exits; run it from a systemd timer (or cron) as an unprivileged
# user. Nothing the server answers is executed. The token and the signing secret never appear on a command line
# (curl reads its options from stdin, the HMAC is computed with sha256sum), so `ps` does not show them.
#
# Config file (default /etc/uptime-push-agent.conf, mode 0600 or 0640 root:<agent group>), KEY=value lines:
#   UPTIME_PUSH_URL=https://uptime.example.com/uptime-push/<key>
#   UPTIME_PUSH_TOKEN=upm_...
#   UPTIME_PUSH_SECRET=...      (only when the monitor requires signed pushes)
#   UPTIME_PUSH_MOUNTS=/ /var    (optional: only these mount points; default every real filesystem)
#
# Usage: uptime-push-agent.sh [config file]   ·   UPTIME_PUSH_DRY_RUN=1 prints the JSON instead of sending it.
set -eu

CONFIG=${1:-/etc/uptime-push-agent.conf}
URL=
TOKEN=
SECRET=
MOUNTS=

[ -r "$CONFIG" ] || { echo "uptime-push-agent: cannot read $CONFIG" >&2; exit 2; }
# Parsed, never sourced: the file holds values, not shell code.
while IFS='=' read -r key value; do
    case $key in
        UPTIME_PUSH_URL) URL=$value ;;
        UPTIME_PUSH_TOKEN) TOKEN=$value ;;
        UPTIME_PUSH_SECRET) SECRET=$value ;;
        UPTIME_PUSH_MOUNTS) MOUNTS=$value ;;
    esac
done < "$CONFIG"

case $URL in
    https://*) ;;
    *) echo "uptime-push-agent: UPTIME_PUSH_URL must be an https:// URL" >&2; exit 2 ;;
esac
[ -n "$TOKEN" ] || { echo "uptime-push-agent: UPTIME_PUSH_TOKEN is empty" >&2; exit 2; }

# CPU busy % over a 2-second window.
cpu_sample() { awk '/^cpu /{ idle=$5+$6; total=0; for (i=2; i<=NF; i++) total+=$i; print total, idle; exit }' /proc/stat; }
set -- $(cpu_sample)
t1=$1 i1=$2
sleep 2
set -- $(cpu_sample)
t2=$1 i2=$2
cpu=$(awk -v t="$((t2 - t1))" -v i="$((i2 - i1))" 'BEGIN { if (t > 0) printf "%.2f", (t - i) * 100 / t; else print 0 }')

mem=$(awk '/^MemTotal:/{t=$2} /^MemAvailable:/{a=$2} END { if (t > 0) printf "%.2f", (t - a) * 100 / t; else print 0 }' /proc/meminfo)
swap=$(awk '/^SwapTotal:/{t=$2} /^SwapFree:/{f=$2} END { if (t > 0) printf "%.2f", (t - f) * 100 / t; else print 0 }' /proc/meminfo)
set -- $(cat /proc/loadavg)
load1=$1 load5=$2 load15=$3
cores=$(grep -c '^processor' /proc/cpuinfo || echo 1)
uptime_s=$(awk '{ printf "%d", $1 }' /proc/uptime)

# Disks: used % and inodes % of every real filesystem (or UPTIME_PUSH_MOUNTS), as JSON objects.
disks=$(
    { df -P -x tmpfs -x devtmpfs -x overlay -x squashfs 2>/dev/null | awk 'NR > 1 { sub(/%/, "", $5); print "B", $6, $5 }'
      df -Pi -x tmpfs -x devtmpfs -x overlay -x squashfs 2>/dev/null | awk 'NR > 1 { sub(/%/, "", $5); print "I", $6, $5 }'; } |
    awk -v only="$MOUNTS" '
        BEGIN { n = split(only, list, " "); for (i = 1; i <= n; i++) keep[list[i]] = 1 }
        n > 0 && !($2 in keep) { next }
        $2 ~ /[^ -~]/ || $2 ~ /["\\]/ { next }
        $1 == "B" && $3 ~ /^[0-9]+$/ { used[$2] = $3; order[++count] = $2 }
        $1 == "I" && $3 ~ /^[0-9]+$/ { inodes[$2] = $3 }
        END {
            sep = ""
            for (i = 1; i <= count && i <= 32; i++) {
                m = order[i]
                printf "%s{\"mount\":\"%s\",\"used_pct\":%s", sep, m, used[m]
                if (m in inodes) printf ",\"inodes_pct\":%s", inodes[m]
                printf "}"
                sep = ","
            }
        }'
)

body=$(printf '{"cpu_pct":%s,"mem_pct":%s,"swap_pct":%s,"load1":%s,"load5":%s,"load15":%s,"cpu_count":%s,"uptime_s":%s,"disks":[%s]}' \
    "$cpu" "$mem" "$swap" "$load1" "$load5" "$load15" "$cores" "$uptime_s" "$disks")

if [ "${UPTIME_PUSH_DRY_RUN:-0}" = 1 ]; then
    printf '%s\n' "$body"
    exit 0
fi

# HMAC-SHA256 with the key read from the config, never passed as an argument (RFC 2104, key shorter than a block).
xor_key() {
    printf '%s' "$1" | od -An -v -tu1 | tr -s ' ' '\n' | sed '/^$/d' | {
        count=0
        while read -r byte; do printf "\\$(printf '%03o' $((byte ^ $2)))"; count=$((count + 1)); done
        while [ "$count" -lt 64 ]; do printf "\\$(printf '%03o' "$2")"; count=$((count + 1)); done
    }
}
hmac_sha256() {
    inner=$({ xor_key "$1" 54; printf '%s' "$2"; } | sha256sum | cut -d' ' -f1)
    { xor_key "$1" 92; printf '%s' "$inner" | sed 's/../& /g' | tr ' ' '\n' | sed '/^$/d' | while read -r h; do printf "\\$(printf '%03o' "0x$h")"; done; } | sha256sum | cut -d' ' -f1
}

signature_headers=
if [ -n "$SECRET" ]; then
    ts=$(date +%s)
    sig=$(hmac_sha256 "$SECRET" "$ts.$body")
    signature_headers="header = \"X-Uptime-Timestamp: $ts\"
header = \"X-Uptime-Signature: $sig\""
fi

status=$(printf 'url = "%s"\nheader = "Authorization: Bearer %s"\nheader = "Content-Type: application/json"\n%s\n' "$URL" "$TOKEN" "$signature_headers" |
    curl --config - --silent --show-error --proto '=https' --max-time 10 --request POST --data-binary "$body" \
        --output /dev/null --write-out '%{http_code}')

case $status in
    2??) exit 0 ;;
    *) echo "uptime-push-agent: push refused (HTTP $status)" >&2; exit 1 ;;
esac
