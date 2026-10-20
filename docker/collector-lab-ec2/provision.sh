#!/usr/bin/env bash
# Provisions the synthetic Moodle 4.5.10 source on an EC2 laboratory instance.
# Run as root from the extracted bundle directory (see send-via-ssm.sh).
# Idempotent: a second run keeps the database, secrets and data. Prints only
# closed stage markers, never credentials or Moodle output.
set -euo pipefail
umask 022

readonly ROOT=/opt/moodle-lab
readonly MOODLE_TAG=v4.5.10
readonly MOODLE_COMMIT=b2c2f2a0c5f9a141896df6c322a0b6b29bb77646
readonly BUNDLE=$(cd "$(dirname "$0")" && pwd)
stage() { printf 'STAGE %s\n' "$1"; }
trap 'printf "FAILED %s\n" "${CURRENT:-unknown}"' ERR

CURRENT=packages; stage "$CURRENT"
if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq >/dev/null
    apt-get install -y -qq docker.io git ca-certificates >/dev/null
    # The Compose plugin is packaged under different names across Ubuntu releases.
    apt-get install -y -qq docker-compose-v2 >/dev/null 2>&1 || apt-get install -y -qq docker-compose-plugin >/dev/null
fi
systemctl enable --now docker >/dev/null 2>&1

CURRENT=layout; stage "$CURRENT"
install -d -m 0755 "$ROOT" "$ROOT/runtime"
install -d -m 0700 -o 33 -g 33 "$ROOT/data"
install -d -m 0700 -o root -g root "$ROOT/secrets"
install -m 0644 "$BUNDLE/compose.yaml" "$BUNDLE/php.Dockerfile" "$ROOT/runtime/"
install -m 0644 "$BUNDLE/seed-moodle.php" "$ROOT/runtime/seed-moodle.php"

CURRENT=code; stage "$CURRENT"
if [[ ! -f $ROOT/code/version.php ]]; then
    rm -rf "$ROOT/code.tmp"
    git -c http.version=HTTP/1.1 clone --quiet --depth 1 --branch "$MOODLE_TAG" https://github.com/moodle/moodle.git "$ROOT/code.tmp"
    [[ $(git -C "$ROOT/code.tmp" rev-parse HEAD) == "$MOODLE_COMMIT" ]] || { CURRENT=code-commit; false; }
    rm -rf "$ROOT/code.tmp/.git"
    mv "$ROOT/code.tmp" "$ROOT/code"
fi

CURRENT=secrets; stage "$CURRENT"
if [[ ! -f $ROOT/secrets/moodle-lab-db ]]; then
    # The postgres image reads the secret as uid 70; Moodle reads config.php as uid 33.
    head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' >"$ROOT/secrets/moodle-lab-db"
fi
chown 70:70 "$ROOT/secrets/moodle-lab-db"
chmod 0400 "$ROOT/secrets/moodle-lab-db"
if [[ ! -f $ROOT/.moodle-toolkit-synthetic-lab.json ]]; then
    printf '{"schema_version":"synthetic-moodle.v1","fixture_id":"%s","moodle_commit":"%s"}\n' \
        "$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')" "$MOODLE_COMMIT" >"$ROOT/.moodle-toolkit-synthetic-lab.json"
    chmod 0644 "$ROOT/.moodle-toolkit-synthetic-lab.json"
fi

CURRENT=config; stage "$CURRENT"
# A real config.php, as on an institutional source. The platform never reads it;
# the Collector receives only its path.
if [[ ! -f $ROOT/code/config.php ]]; then
    password=$(cat "$ROOT/secrets/moodle-lab-db")
    cat >"$ROOT/code/config.php" <<PHP
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();
\$CFG->dbtype = 'pgsql';
\$CFG->dblibrary = 'native';
\$CFG->dbhost = 'moodle-lab-db';
\$CFG->dbname = 'moodle_lab';
\$CFG->dbuser = 'moodle_lab';
\$CFG->dbpass = '$password';
\$CFG->prefix = 'mdl_';
\$CFG->dboptions = ['dbpersist' => 0, 'dbport' => 5432];
\$CFG->wwwroot = 'http://moodle-lab.test';
\$CFG->dataroot = '/opt/moodle-lab/data';
\$CFG->admin = 'admin';
\$CFG->directorypermissions = 02777;
require_once(__DIR__ . '/lib/setup.php');
PHP
    unset password
fi
chown root:33 "$ROOT/code/config.php"
chmod 0640 "$ROOT/code/config.php"

CURRENT=services; stage "$CURRENT"
cd "$ROOT/runtime"
docker compose up -d --wait moodle-lab-db >/dev/null 2>&1
docker compose --profile tools build php >/dev/null 2>&1

CURRENT=seed; stage "$CURRENT"
# seed-moodle.php only accepts runtime files under /tmp/toolkit-synthetic-fixture;
# this one requires the canonical config.php so later require_once calls are no-ops.
docker compose --profile tools run --rm --no-deps -T php sh -c '
    install -d -m 0700 /tmp/toolkit-synthetic-fixture
    printf "%s\n" "<?php" "require \"/opt/moodle-lab/code/config.php\";" >/tmp/toolkit-synthetic-fixture/moodle-runtime.php
    php /opt/moodle-lab/runtime/seed-moodle.php /tmp/toolkit-synthetic-fixture/moodle-runtime.php >/dev/null 2>&1
    status=$?
    [ "$status" -eq 0 ] || cat /tmp/toolkit-synthetic-fixture/diagnostic.json
    exit "$status"
'

CURRENT=source-profile; stage "$CURRENT"
# Administrator-owned profile that mwt-agent reads for collector.742. The
# platform only names it ("synthetic-lab"); paths never come from the web.
install -d -m 0755 -o root -g root /etc/mwt-agent /etc/mwt-agent/sources
cat >/etc/mwt-agent/sources/synthetic-lab.conf <<'CONF'
# Synthetic Moodle source for the EC2 laboratory (docker/collector-lab-ec2).
MOODLE_CONFIG=/opt/moodle-lab/code/config.php
RUNTIME=compose
RUN_AS=www-data
COMPOSE_DIR=/opt/moodle-lab/runtime
COMPOSE_SERVICE=php
COMPOSE_PROFILE=tools
CONF
chown root:root /etc/mwt-agent/sources/synthetic-lab.conf
chmod 0644 /etc/mwt-agent/sources/synthetic-lab.conf

CURRENT=done; stage "$CURRENT"
echo 'SYNTHETIC_MOODLE_EC2_READY'
