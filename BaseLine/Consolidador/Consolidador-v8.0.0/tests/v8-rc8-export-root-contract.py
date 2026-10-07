from pathlib import Path

launcher = Path("moodle-consolidation.sh").read_text(
    encoding="utf-8"
)

oauth = Path("scripts/oauth2-validate.ps1").read_text(
    encoding="utf-8-sig"
)

common = Path("scripts/Common.ps1").read_text(
    encoding="utf-8-sig"
)

# ------------------------------------------------------------
# Root /exports: contrato que faltaba en RC7.
# ------------------------------------------------------------

required_launcher = [
    'local export_owner_uid="${ASSISTANT_UID:-$(id -u)}"',
    "chmod 2770 /exports",
    "test -x /exports && test -w /exports",
    "EXPORT_TRANSPORT_OK",
]

for value in required_launcher:
    assert value in launcher, (
        f"RC8_EXPORT_ROOT_CONTRACT_MISSING value={value}"
    )

# No abrir permisos globalmente.
assert "chmod 777 /exports" not in launcher
assert "chmod 0777 /exports" not in launcher

# ------------------------------------------------------------
# OAuth ya tenía correctamente Grant/Restore en RC7.
# No debe duplicarse.
# ------------------------------------------------------------

assert oauth.count("Grant-ContainerExportWrite") == 1, (
    "RC8_OAUTH_GRANT_DUPLICATED_OR_MISSING"
)

assert oauth.count("Restore-AssistantExportOwnership") == 1, (
    "RC8_OAUTH_RESTORE_DUPLICATED_OR_MISSING"
)

assert "RC8_OAUTH_EXPORT_TRANSPORT" not in oauth, (
    "RC8_OAUTH_DUPLICATED_PATCH_STILL_PRESENT"
)

grant = oauth.index("Grant-ContainerExportWrite")
php = oauth.index("php /opt/consolidator/oauth2-validate.php")
restore = oauth.index("Restore-AssistantExportOwnership")

assert grant < php < restore, (
    "RC8_OAUTH_TRANSPORT_ORDER_INVALID"
)

# ------------------------------------------------------------
# Common.ps1 conserva la política de hijos.
# ------------------------------------------------------------

assert "chown -R $($ownership.Uid):www-data" in common
assert "chmod -R u=rwX,g=rwX,o=" in common
assert "find '$ContainerPath' -type d -exec chmod g+s {} +" in common

print(
    "V8_RC8_EXPORT_ROOT_CONTRACT_OK "
    "root=2770 oauth_original=1 grant_restore=1"
)
