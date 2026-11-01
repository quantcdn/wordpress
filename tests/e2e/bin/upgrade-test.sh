#!/usr/bin/env bash
# Upgrade test: install the published 1.x plugin from WordPress.org, configure
# it the way customers do, update it in place with the 2.0 release zip, and
# check that settings migrate, the plugin stays active and pushes still work.
#
# Usage: tests/e2e/bin/upgrade-test.sh [1.x version]   (default 1.6.0)
# Env:   KEEP=1 leaves the containers running.
set -euo pipefail

FROM_VERSION="${1:-1.6.0}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
DIR="$HERE/upgrade"
export COMPOSE_PROJECT_NAME=quant-upgrade-test WP_PORT="${WP_PORT:-8089}" MOCK_PORT="${MOCK_PORT:-4001}"
DC="docker compose -f $DIR/compose.yml"
WP() { $DC exec -T wpcli wp --path=/var/www/html "$@"; }
MOCK="http://localhost:$MOCK_PORT"
fail() { echo "FAIL: $*" >&2; exit 1; }
check() { local label="$1" actual="$2" expected="$3"; [ "$actual" = "$expected" ] || fail "$label: expected '$expected', got '$actual'"; echo "ok   $label"; }

mkdir -p "$DIR/zips"
"$ROOT/plugins/quant/bin/build-zip.sh" "$DIR/zips" >/dev/null
TO_ZIP="$(ls -t "$DIR"/zips/quant-2*.zip | head -1)"
curl -fsSLo "$DIR/zips/quant-$FROM_VERSION.zip" "https://downloads.wordpress.org/plugin/quant.$FROM_VERSION.zip"

trap '[ "${KEEP:-0}" = 1 ] || $DC down -v >/dev/null 2>&1' EXIT
$DC down -v >/dev/null 2>&1 || true
$DC up -d --wait >/dev/null
for _ in $(seq 1 60); do code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$WP_PORT/") && [[ "$code" =~ ^(200|302)$ ]] && break; sleep 2; done

WP core install --url="http://localhost:$WP_PORT" --title=Upgrade --admin_user=admin --admin_password=password --admin_email=a@example.com --skip-email >/dev/null
WP rewrite structure '/%postname%/' >/dev/null
WP plugin install "/zips/quant-$FROM_VERSION.zip" --activate >/dev/null
WP option update wp_quant_settings "{\"enabled\":1,\"purge_on_save\":1,\"disable_tls_verify\":0,\"http_request_timeout\":20,\"webserver_url\":\"http://wordpress\",\"webserver_host\":\"localhost:$WP_PORT\",\"api_endpoint\":\"http://mock-api:4000\",\"api_account\":\"test-org\",\"api_project\":\"test-project\",\"api_token\":\"test-token-123\"}" --format=json >/dev/null
WP option update wp_quant_seed '{"404_route":"/404","custom_routes":"/robots.txt","domains_strip":"old.example.com"}' --format=json >/dev/null
WP option update wp_quant_cron_settings '{"cron_enabled":1,"cron_schedule":"daily","cron_home":1,"cron_posts":1,"cron_pages":0,"cron_categories":0,"cron_tags":0,"cron_custom_routes":""}' --format=json >/dev/null
POST_ID=$(WP post create --post_title="Before Upgrade" --post_status=publish --post_author=1 --porcelain)
pushed() { local body; body="$(curl -fs "$MOCK/_mock/requests?path=/v1&method=POST")"; [[ "$body" == *"$1"* ]]; }
if pushed before-upgrade; then echo "ok   $FROM_VERSION pushes content"; else echo "note $FROM_VERSION did not push on this PHP version (its own incompatibility); continuing with the migration checks"; fi

# Update the way wp-admin does: maintenance mode holds new requests while
# the files are replaced. Wait for requests already running the 1.x code
# (a cron loopback, for example), then start the log from a clean slate.
WP maintenance-mode activate >/dev/null
sleep 5
$DC exec -T wordpress sh -c ': > /var/www/html/wp-content/debug.log'
WP plugin install "/zips/$(basename "$TO_ZIP")" --force >/dev/null
WP maintenance-mode deactivate >/dev/null
curl -s -o /dev/null "http://localhost:$WP_PORT/"

check "plugin active after update" "$(WP plugin get quant --field=status)" active
check "version" "$(WP plugin get quant --field=version)" "$(grep -E '^ \* Version:' "$ROOT/plugins/quant/quant.php" | awk '{print $3}')"
check "migration complete" "$(WP option get quant_migration_complete)" 1
check "organization" "$(WP option pluck quant_credentials organization)" test-org
check "token" "$(WP option pluck quant_credentials token)" test-token-123
check "timeout" "$(WP option pluck quant_credentials http_request_timeout)" 20
check "auto sync" "$(WP option pluck quant_sync_settings auto_sync)" 1
check "webserver host" "$(WP option pluck quant_static_config webserver_host)" "localhost:$WP_PORT"
check "domain rewrite" "$(WP option pluck quant_static_config domain_rewrites 0)" old.example.com
check "schedule enabled" "$(WP option pluck quant_schedule enabled)" 1
check "1.x cron event removed" "$(WP cron event list --hook=quant_cronjob --format=count)" 0
check "queue table" "$(WP eval 'global $wpdb; echo (int) ($wpdb->get_var("SHOW TABLES LIKE \"{$wpdb->prefix}quant_sync_queue\"") !== null);')" 1

curl -fs -X DELETE "$MOCK/_mock/requests" >/dev/null
WP post update "$POST_ID" --post_title="After Upgrade" >/dev/null
WP cron event run quant_process_queue >/dev/null
pushed before-upgrade || fail "2.0 did not push the edited post through WP-Cron"
echo "ok   edit after update is pushed by WP-Cron"

WP quant sync --all --process >/dev/null
check "full push has no failures" "$(WP eval 'echo (new Quant\Sync\DatabaseQueue($GLOBALS["wpdb"]))->getStatus()->failed;')" 0

# Count only errors in files of the installed 2.x plugin. A request that
# loaded the 1.x code just before the update (a cron loopback, for example)
# can still log after the log was cleared; those files no longer exist.
PLUGIN_ERRORS="$($DC exec -T wordpress sh -c 'cd /var/www/html/wp-content && grep -E "PHP (Fatal|Warning|Notice|Deprecated)" debug.log | grep "plugins/quant/" | while IFS= read -r line; do f=$(echo "$line" | grep -o "plugins/quant/[^ ]*\.php" | head -1); [ -n "$f" ] && [ -f "$f" ] && echo "$line"; done; true')"
[ -z "$PLUGIN_ERRORS" ] || echo "$PLUGIN_ERRORS" >&2
LOG="$(printf '%s' "$PLUGIN_ERRORS" | grep -c . || true)"
check "no PHP errors from the plugin" "$LOG" 0

echo "PASS: $FROM_VERSION -> $(basename "$TO_ZIP")"
