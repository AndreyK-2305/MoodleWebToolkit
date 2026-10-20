#!/usr/bin/env bash
# Verifies the synthetic Moodle source on the EC2 instance. Prints one JSON
# document with counts, versions and permissions; never credentials.
set -euo pipefail
readonly ROOT=/opt/moodle-lab
cd "$ROOT/runtime"

perm() { stat -c '%U:%G %a' "$1"; }
db_state=$(docker inspect -f '{{.State.Health.Status}}' moodle-lab-moodle-lab-db-1 2>/dev/null || echo missing)
db_bind=$(docker port moodle-lab-moodle-lab-db-1 5432/tcp 2>/dev/null | paste -sd, - || echo none)

moodle=$(docker compose --profile tools run --rm --no-deps -T php php -r '
    define("CLI_SCRIPT", true);
    require "/opt/moodle-lab/code/config.php";
    global $DB, $CFG;
    echo json_encode([
        "release" => $CFG->release,
        "php" => PHP_VERSION,
        "courses" => $DB->count_records_select("course", "id <> ?", [SITEID]),
        "users" => $DB->count_records_select("user", "deleted = 0 AND username NOT IN (?, ?)", ["guest", "fixture-admin"]),
        "modules" => $DB->count_records("course_modules"),
        "files" => $DB->count_records_select("files", "filename <> ? AND component = ?", [".", "mod_folder"]),
        "oauth_issuers" => $DB->count_records("oauth2_issuer"),
        "oauth_linked_logins" => $DB->count_records("auth_oauth2_linked_login"),
        "course_themes" => array_values(array_filter($DB->get_fieldset_select("course", "theme", "id <> ?", [SITEID]))),
        "site_theme" => get_config("core", "theme"),
        "fixture_id_matches" => get_config(null, "local_toolkit_fixture_id")
            === json_decode(file_get_contents("/opt/moodle-lab/.moodle-toolkit-synthetic-lab.json"), true)["fixture_id"],
    ]);
' 2>/dev/null || echo null)

printf '{"db_health":"%s","db_ports":"%s","config_php":"%s","dataroot":"%s","db_secret":"%s","moodle":%s}\n' \
    "$db_state" "$db_bind" "$(perm "$ROOT/code/config.php")" "$(perm "$ROOT/data")" "$(perm "$ROOT/secrets/moodle-lab-db")" "$moodle"
