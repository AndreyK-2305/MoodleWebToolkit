#!/usr/bin/env bash
# Runs seed-lab.php on one synthetic EC2 source over SSM and waits for it.
# Usage: send-seed-via-ssm.sh <instance-id> <pregrado|posgrado> [seed|reset|remove|status] [aws-profile] [region]
# Needs only the AWS CLI with ssm:SendCommand/GetCommandInvocation; no SSH.
# The instance must be running and provisioned by ../provision.sh (/opt/moodle-lab).
set -euo pipefail

instance=${1:?instance id required}
variant=${2:?variant required: pregrado or posgrado}
mode=${3:-seed}
profile=${4:-personal}
region=${5:-us-east-2}
[[ $variant == pregrado || $variant == posgrado ]] || { echo 'variant must be pregrado or posgrado' >&2; exit 2; }
case $mode in
    seed) flag='' ;;
    reset | remove | status) flag="--$mode" ;;
    *) echo 'mode must be seed, reset, remove or status' >&2; exit 2 ;;
esac
here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

script=$(base64 <"$here/seed-lab.php" | tr -d '\n')
# The script travels inside the command; only base64 characters, so plain quotes suffice.
# It runs in the source's own php tools container as www-data, like the Collector.
cat >"$work/params.json" <<JSON
{"commands":["set -e","[ -f /opt/moodle-lab/runtime/compose.yaml ] || { echo NOT_A_SYNTHETIC_SOURCE; exit 2; }","cd /opt/moodle-lab/runtime","docker compose up -d --wait moodle-lab-db >/dev/null 2>&1","install -m 0644 /dev/null seed-lab.php","printf %s '$script' | base64 -d >seed-lab.php","status=0; docker compose --profile tools run --rm --no-deps -T php php /opt/moodle-lab/runtime/seed-lab.php --variant=$variant $flag || status=\$?","rm -f seed-lab.php","exit \$status"],"executionTimeout":["1800"]}
JSON

params=$work/params.json
# A Windows AWS CLI under Git Bash cannot read POSIX temp paths.
command -v cygpath >/dev/null 2>&1 && params=$(cygpath -m "$params")
aws=(aws --profile "$profile" --region "$region")
command_id=$("${aws[@]}" ssm send-command --instance-ids "$instance" --document-name AWS-RunShellScript \
    --comment "MoodleWebToolkit: synthetic lab seed ($variant, $mode)" --timeout-seconds 60 \
    --parameters "file://$params" --query Command.CommandId --output text)
echo "command=$command_id"

while :; do
    sleep 15
    status=$("${aws[@]}" ssm get-command-invocation --command-id "$command_id" --instance-id "$instance" --query Status --output text 2>/dev/null || echo Pending)
    case $status in
        Pending | InProgress | Delayed) ;;
        *) break ;;
    esac
done
"${aws[@]}" ssm get-command-invocation --command-id "$command_id" --instance-id "$instance" \
    --query '[Status,ResponseCode,StandardOutputContent,StandardErrorContent]' --output text
[[ $status == Success ]]
