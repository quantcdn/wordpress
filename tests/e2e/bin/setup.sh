#!/usr/bin/env bash
# Start the e2e environment, install WordPress, activate the plugin, create fixtures.
# Idempotent: safe to run again on a running environment.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
E2E_DIR="$(cd "$HERE/.." && pwd)"
ROOT="$(cd "$E2E_DIR/../.." && pwd)"
COMPOSE="$ROOT/docker-compose.e2e.yml"
SITE_URL="${E2E_BASE_URL:-http://localhost:8088}"

# Set E2E_ALLOW_PLUGIN_ERRORS=1 to continue (with --skip-plugins=quant for fixtures)
# when the plugin has a PHP error. Default is strict: a broken plugin fails setup.
ALLOW_PLUGIN_ERRORS="${E2E_ALLOW_PLUGIN_ERRORS:-0}"
WP_EXTRA=()

dc() { docker compose -f "$COMPOSE" "$@"; }
wp() { dc exec -T wpcli wp "$@" "${WP_EXTRA[@]}" --path=/var/www/html; }
log() { printf '\033[1;34m[e2e]\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31m[e2e] %s\033[0m\n' "$*" >&2; exit 1; }

log "Starting containers"
dc up -d

log "Waiting for the web server at $SITE_URL"
for i in $(seq 1 90); do
  code="$(curl -s -o /dev/null -w '%{http_code}' "$SITE_URL/" || true)"
  if [[ "$code" =~ ^[2-5][0-9][0-9]$ ]]; then break; fi
  if [[ "$i" == "90" ]]; then dc logs --tail=50 wordpress; fail "Web server did not come up (last HTTP $code)"; fi
  sleep 2
done
log "Web server HTTP $code"

log "Waiting for mock API"
for i in $(seq 1 30); do
  if curl -fsS http://localhost:4000/_mock/health >/dev/null 2>&1; then break; fi
  [[ "$i" == "30" ]] && { dc logs --tail=50 mock-api; fail "mock API did not start"; }
  sleep 1
done

# wp-config.php is written by the wordpress image entrypoint on first boot.
for i in $(seq 1 30); do
  if dc exec -T wpcli test -f /var/www/html/wp-config.php; then break; fi
  [[ "$i" == "30" ]] && fail "wp-config.php was not created"
  sleep 2
done

plugin_fatal() { # prints the fatal error from a front page request, if any
  curl -s "$SITE_URL/" | grep -oE "Fatal error:.{0,250}" | head -1 || true
}

# If a previous run left a broken plugin active, wp-cli itself fatals. Detect and bypass.
wp_loads() { dc exec -T wpcli wp option get siteurl "$@" --path=/var/www/html >/dev/null 2>&1; }
if ! wp_loads; then
  if wp_loads --skip-plugins=quant; then
    log "WARNING: WordPress loads only with the quant plugin skipped"
    [[ "$ALLOW_PLUGIN_ERRORS" == "1" ]] || { echo "$(plugin_fatal)"; fail "quant plugin has a PHP error (set E2E_ALLOW_PLUGIN_ERRORS=1 to continue)"; }
    WP_EXTRA=(--skip-plugins=quant)
  else
    fail "wp-cli cannot load WordPress"
  fi
fi

if wp core is-installed >/dev/null 2>&1; then
  log "WordPress already installed"
else
  log "Installing WordPress"
  wp core install --url="$SITE_URL" --title="Quant E2E" --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email
fi

wp option update blogdescription 'E2E' >/dev/null
wp rewrite structure '/%postname%/' >/dev/null

if [[ -z "$(wp theme list --status=active --field=name)" ]]; then
  theme="$(wp theme list --status=inactive --field=name | head -1)"
  [[ -n "$theme" ]] && wp theme activate "$theme"
fi
log "Theme: $(wp theme list --status=active --field=name)"

log "Activating quant plugin"
if ! wp plugin activate quant; then
  echo "----- wp-content/debug.log -----"
  wp eval 'echo @file_get_contents("/var/www/html/wp-content/debug.log");' || true
  fail "quant plugin activation failed"
fi

code="$(curl -s -o /dev/null -w '%{http_code}' "$SITE_URL/" || true)"
if [[ "$code" != "200" ]]; then
  echo "----- front page HTTP $code with quant active -----"
  plugin_fatal
  if [[ "$ALLOW_PLUGIN_ERRORS" == "1" ]]; then
    log "WARNING: continuing with --skip-plugins=quant for fixture creation"
    WP_EXTRA=(--skip-plugins=quant)
  else
    fail "quant plugin breaks the site (set E2E_ALLOW_PLUGIN_ERRORS=1 to continue anyway)"
  fi
else
  WP_EXTRA=()
  log "Front page HTTP 200 with quant active"
fi

if wp plugin is-installed redirection >/dev/null 2>&1; then
  wp plugin activate redirection >/dev/null 2>&1 || true
  log "Redirection plugin present"
else
  log "Installing Redirection plugin (network)"
  if ! wp plugin install redirection --activate; then
    log "WARNING: could not install Redirection; redirect fixtures will be skipped"
  fi
fi

# ---------------------------------------------------------------------------
# Fixtures (idempotent)
# ---------------------------------------------------------------------------

term_id() { # taxonomy slug name
  local id
  id="$(wp term list "$1" --slug="$2" --field=term_id)"
  if [[ -z "$id" ]]; then id="$(wp term create "$1" "$3" --slug="$2" --porcelain)"; fi
  echo "$id"
}

post_id() { # type slug
  wp post list --post_type="$1" --name="$2" --post_status=any --field=ID | head -1
}

create_post() { # type slug title content [extra wp args...]
  local type="$1" slug="$2" title="$3" content="$4"; shift 4
  local id
  id="$(post_id "$type" "$slug")"
  if [[ -z "$id" ]]; then
    id="$(wp post create --post_type="$type" --post_status=publish --post_name="$slug" --post_title="$title" --post_content="$content" --porcelain "$@")"
    log "Created $type $slug (#$id)"
  else
    log "Exists $type $slug (#$id)"
  fi
  echo "$id"
}

NEWS=$(term_id category news News)
TECH=$(term_id category tech Tech)
ALPHA=$(term_id post_tag alpha Alpha)
BETA=$(term_id post_tag beta Beta)
FICTION=$(term_id genre fiction Fiction)

HELLO=$(create_post post hello-quant "Hello Quant" "<p>First post for the Quant e2e suite.</p>" --post_category="$NEWS" --tags_input=alpha)
SECOND=$(create_post post second-post "Second Post" "<p>Second post content.</p>" --post_category="$TECH" --tags_input=beta)
THIRD=$(create_post post third-post "Third Post" "<p>Third post content.</p>" --post_category="$NEWS")
ABOUT=$(create_post page about "About" "<p>About this site.</p>")
CONTACT=$(create_post page contact "Contact" "<p>Contact us.</p>")
BOOK=$(create_post book my-book "My Book" "<p>A book about static sites.</p>")
wp post term set "$BOOK" genre fiction >/dev/null

# Remove the default "Hello world!" post so counts are predictable.
DEFAULT_ID="$(post_id post hello-world)"
if [[ -n "$DEFAULT_ID" ]]; then wp post delete "$DEFAULT_ID" --force >/dev/null && log "Removed default hello-world post"; fi

if [[ -z "$(wp post list --post_type=attachment --post_status=inherit --name=sample --field=ID | head -1)" ]]; then
  log "Importing sample.png"
  dc cp "$E2E_DIR/fixtures/sample.png" wpcli:/tmp/sample.png
  wp media import /tmp/sample.png --post_id="$HELLO" --title="Sample" --alt="Sample image" >/dev/null
else
  log "Exists attachment sample.png"
fi

if wp plugin is-active redirection >/dev/null 2>&1; then
  log "Ensuring Redirection fixture /old-page -> /about"
  wp redirection database install >/dev/null 2>&1 || true
  wp eval '
    if (!class_exists("Red_Item")) { echo "no Red_Item\n"; return; }
    $existing = Red_Item::get_for_url("/old-page");
    foreach ($existing as $item) { if ($item->get_url() === "/old-page") { echo "exists\n"; return; } }
    $groups = Red_Group::get_all();
    if (empty($groups)) { $g = Red_Group::create("Redirections", 1); $groups = Red_Group::get_all(); }
    $group_id = !empty($groups) ? (int) $groups[0]["id"] : 1;
    $r = Red_Item::create([
      "url" => "/old-page",
      "match_type" => "url",
      "action_type" => "url",
      "action_code" => 301,
      "action_data" => ["url" => "/about"],
      "group_id" => $group_id,
      "status" => "enabled",
      "regex" => false,
    ]);
    echo is_wp_error($r) ? "error: " . $r->get_error_message() . "\n" : "created\n";
  ' || log "WARNING: redirect fixture failed"
fi

wp cache flush >/dev/null 2>&1 || true

log "Summary"
wp post list --post_type=post,page,book --fields=ID,post_type,post_name,post_status
wp term list category --fields=term_id,slug,count
wp term list post_tag --fields=term_id,slug,count
wp term list genre --fields=term_id,slug,count
log "Site: $SITE_URL  admin / password   Mock API: http://localhost:4000"
