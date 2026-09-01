function Test-ConsolidationNotificationsEnabled {
    return [string]$env:CONSOLIDATION_EMAIL_ENABLED -eq "1"
}

function Send-ConsolidationNotification {
    param(
        [Parameter(Mandatory = $true)][string]$Stage,
        [Parameter(Mandatory = $true)][string]$Status,
        [Parameter(Mandatory = $true)][string]$Message,
        [string[]]$ReviewPaths = @()
    )

    if (-not (Test-ConsolidationNotificationsEnabled)) {
        return
    }

    try {
        $smtpHost = [string]$env:CONSOLIDATION_SMTP_HOST
        $smtpPort = 0
        if ([string]::IsNullOrWhiteSpace($smtpHost) -or
                -not [int]::TryParse(
                    [string]$env:CONSOLIDATION_SMTP_PORT,
                    [ref]$smtpPort
                ) -or $smtpPort -lt 1 -or $smtpPort -gt 65535) {
            throw "La configuración SMTP está incompleta."
        }

        $from = [string]$env:CONSOLIDATION_EMAIL_FROM
        $toValues = @(
            ([string]$env:CONSOLIDATION_EMAIL_TO).Split(
                [char[]]@(",", ";"),
                [System.StringSplitOptions]::RemoveEmptyEntries
            ) |
                ForEach-Object { $_.Trim() } |
                Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
        )
        if ([string]::IsNullOrWhiteSpace($from) -or $toValues.Count -lt 1) {
            throw "Falta el remitente o destinatario de notificaciones."
        }

        $subject = "[Consolidador Moodle] $Status - $Stage"
        $targetUrl = [string]$env:MOODLE_PUBLIC_URL
        $bodyLines = @(
            "Consolidador Moodle 7.3.0-linux",
            "",
            "Etapa: $Stage",
            "Estado: $Status",
            "Detalle: $Message",
            "Fecha UTC: $([DateTime]::UtcNow.ToString('o'))",
            "Destino: $targetUrl"
        )
        if ($ReviewPaths.Count -gt 0) {
            $bodyLines += ""
            $bodyLines += "Archivos o rutas para revisión:"
            foreach ($relative in $ReviewPaths) {
                $bodyLines += "  $relative"
            }
        }
        $bodyLines += ""
        $bodyLines += (
            "Consulte ./ESTADO.sh y reports/asistente-consolidacion.log " +
            "en el servidor destino."
        )

        $mail = New-Object System.Net.Mail.MailMessage
        $client = New-Object System.Net.Mail.SmtpClient($smtpHost, $smtpPort)
        try {
            $displayName = [string]$env:CONSOLIDATION_EMAIL_FROM_NAME
            if ([string]::IsNullOrWhiteSpace($displayName)) {
                $displayName = "Consolidador Moodle"
            }
            $mail.From = New-Object System.Net.Mail.MailAddress($from, $displayName)
            foreach ($recipient in $toValues) {
                [void]$mail.To.Add($recipient)
            }
            $mail.Subject = $subject
            $mail.Body = $bodyLines -join [Environment]::NewLine
            $mail.IsBodyHtml = $false

            $client.EnableSsl = (
                [string]$env:CONSOLIDATION_SMTP_USE_TLS -ne "0"
            )
            $client.Timeout = 15000
            $smtpUser = ""
            $usernameBase64 = [string]$env:CONSOLIDATION_SMTP_USERNAME_BASE64
            if (-not [string]::IsNullOrWhiteSpace($usernameBase64)) {
                $smtpUser = [System.Text.Encoding]::UTF8.GetString(
                    [Convert]::FromBase64String($usernameBase64)
                )
            }
            $passwordBase64 = [string]$env:CONSOLIDATION_SMTP_PASSWORD_BASE64
            if (-not [string]::IsNullOrWhiteSpace($smtpUser)) {
                $smtpPassword = ""
                if (-not [string]::IsNullOrWhiteSpace($passwordBase64)) {
                    $smtpPassword = [System.Text.Encoding]::UTF8.GetString(
                        [Convert]::FromBase64String($passwordBase64)
                    )
                }
                $client.UseDefaultCredentials = $false
                $client.Credentials = New-Object System.Net.NetworkCredential(
                    $smtpUser,
                    $smtpPassword
                )
            }
            $client.Send($mail)
            Write-Host "CORREO_ESTADO_ENVIADO etapa=$Stage estado=$Status" `
                -ForegroundColor DarkGray
        } finally {
            $mail.Dispose()
            $client.Dispose()
        }
    } catch {
        Write-Warning (
            "No fue posible enviar la notificación de '$Stage'. " +
            "La consolidación no se bloqueará por correo: " +
            $_.Exception.Message
        )
    }
}
