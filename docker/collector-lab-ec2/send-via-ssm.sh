#!/usr/bin/env bash
# Sends the provisioning bundle to an EC2 laboratory instance over SSM and waits
# for it. Usage: send-via-ssm.sh <instance-id> [aws-profile] [region] [provision.sh|verify.sh]
# Needs only the AWS CLI with ssm:SendCommand/GetCommandInvocation; no SSH.
set -euo pipefail

instance=${1:?instance id required}
profile=${2:-personal}
region=${3:-us-east-2}
entry=${4:-provision.sh}
[[ $entry == provision.sh || $entry == verify.sh ]] || { echo 'entry must be provision.sh or verify.sh' >&2; exit 2; }
here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

cp "$here/provision.sh" "$here/verify.sh" "$here/compose.yaml" "$here/php.Dockerfile" "$here/../collector-lab/seed-moodle.php" "$work/"
bundle=$(tar -C "$work" -czf - provision.sh verify.sh compose.yaml php.Dockerfile seed-moodle.php | base64 | tr -d '\n')
# The bundle travels inside the command; only base64 characters, so plain quotes suffice.
cat >"$work/params.json" <<JSON
{"commands":["set -e","d=\$(mktemp -d /root/moodle-lab-provision.XXXXXX)","printf %s '$bundle' | base64 -d | tar -xz -C \"\$d\"","bash \"\$d/$entry\"","rm -rf \"\$d\""],"executionTimeout":["2400"]}
JSON

params=$work/params.json
# A Windows AWS CLI under Git Bash cannot read POSIX temp paths.
command -v cygpath >/dev/null 2>&1 && params=$(cygpath -m "$params")
aws=(aws --profile "$profile" --region "$region")
command_id=$("${aws[@]}" ssm send-command --instance-ids "$instance" --document-name AWS-RunShellScript \
    --comment "MoodleWebToolkit: synthetic Moodle source (C0, $entry)" --timeout-seconds 60 \
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
