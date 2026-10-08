from pathlib import Path

root = Path(__file__).resolve().parents[1]

def text(path):
    return (root / path).read_text(encoding='utf-8-sig')

def require(condition, message):
    if not condition:
        raise SystemExit('V8_RC1_STATIC_FAILED: ' + message)

assistant = text('docker/assistant.Dockerfile')
require('DEBIAN_FRONTEND=noninteractive TZ=Etc/UTC' in assistant,
        'assistant Dockerfile must be noninteractive')

phase4 = text('scripts/phase4-apply.ps1')
require("-ExportName 'oauth2-live-pre-phase4'" in phase4,
        'phase4 must use separate live OAuth artifact')
require("exports/oauth2-live-pre-phase4/validation.json" in phase4,
        'phase4 must read separate live OAuth artifact')

oauth = text('scripts/oauth2-validate.ps1')
require("[string]$ExportName = ''" in oauth,
        'oauth validator must expose ExportName')

common = text('scripts/Common.ps1')
require('readiness_sha256 = $readinessHash' in common,
        'destination write lock must seal readiness hash')
require("workflow_floor = 'phase4-users'" in common,
        'destination write lock must declare workflow floor')

wizard = text('scripts/consolidation-wizard.ps1')
require('function Assert-PostWriteAnchor' in wizard,
        'wizard must validate post-write anchor')
require('$postWriteStarted = Test-Path -LiteralPath $DestinationWriteLockPath' in wizard,
        'wizard must detect post-write state')
for needle in [
    '-not $postWriteStarted -and\n            (-not (Test-PluginAudit)',
    '-not $postWriteStarted -and -not (Test-OAuth2LiveReady)',
    '-not $postWriteStarted -and -not (Test-IdentityReconciliation)',
    '-not $postWriteStarted -and -not (Test-V8Readiness)',
]:
    require(needle in wizard, 'missing monotonic guard: ' + needle)
require("$workflowRestartRequired = $message.StartsWith(" in wizard,
        'identity review must force workflow restart')

review = text('scripts/v8-identity-review.ps1')
require('V8_IDENTITY_REVIEW_POST_WRITE_BLOCKED' in review,
        'identity review must be blocked after writes')
require("Remove-Item -LiteralPath $readiness -Force" in review,
        'identity review must invalidate stale pre-write readiness')

plugins = text('scripts/v8-plugin-catalog.php')
require("V8_PLUGIN_REQUIRED_DISABLED" in plugins,
        'plugin seal must reject disabled required activity')
require("function v8c_normalize_plugin_path" in plugins,
        'nested plugins support missing')
require("foreach ($lock['plugins'] as &$selection)" in plugins,
        'seal must mutate real lock array')

resolver = text('scripts/v8-plugin-resolver.ps1')
require('V8_PLUGIN_ADOPT_PENDING' in resolver,
        'unknown plugin adoption must return to intervention instead of crashing')

audit = text('scripts/audit-package-plugins.ps1')
require('Enable-RequiredDisabledActivityModules $Needs' in audit,
        'plugin revalidation must enable required disabled modules')
require('PLUGIN_REQUIRED_DISABLED' in audit,
        'technical validation must reject remaining disabled required modules')

print('V8_RC1_STATIC_OK')
