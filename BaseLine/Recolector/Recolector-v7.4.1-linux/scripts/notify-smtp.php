<?php
// Notificación SMTP opcional y no bloqueante para exportación y validación.

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

function smtp_arg(string $name, string $default = ''): string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with((string)$argument, $prefix)) {
            return substr((string)$argument, strlen($prefix));
        }
    }
    return $default;
}

function smtp_warning(string $message): never {
    fwrite(STDERR, 'SMTP_WARNING ' . $message . PHP_EOL);
    exit(0);
}

function smtp_load_phpmailer(string $moodleconfig): void {
    $moodleroot = dirname((string)(realpath($moodleconfig) ?: $moodleconfig));
    $autoloaders = [
        __DIR__ . '/../vendor/autoload.php',
        $moodleroot . '/vendor/autoload.php',
    ];
    foreach ($autoloaders as $autoloader) {
        if (is_readable($autoloader)) {
            require_once $autoloader;
            if (class_exists(PHPMailer::class)) {
                return;
            }
        }
    }

    $library = $moodleroot . '/lib/phpmailer/src';
    foreach (['Exception.php', 'SMTP.php', 'PHPMailer.php'] as $filename) {
        $path = $library . '/' . $filename;
        if (!is_readable($path)) {
            smtp_warning(
                'No se encontró PHPMailer dentro de Moodle: ' . $library . '.'
            );
        }
        require_once $path;
    }
    if (!class_exists(PHPMailer::class)) {
        smtp_warning('PHPMailer no quedó disponible después de cargar Moodle.');
    }
}

try {
    $smtpconfig = trim(smtp_arg('smtpconfig'));
    if ($smtpconfig === '' || !is_readable($smtpconfig)) {
        fwrite(STDOUT, 'SMTP_SKIPPED reason=config_not_readable' . PHP_EOL);
        exit(0);
    }
    if ((int)filesize($smtpconfig) > 65536) {
        smtp_warning('smtp-config.json supera el tamaño permitido.');
    }
    $config = json_decode(
        (string)file_get_contents($smtpconfig),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($config) || ($config['enabled'] ?? false) !== true) {
        fwrite(STDOUT, 'SMTP_SKIPPED reason=disabled' . PHP_EOL);
        exit(0);
    }

    $moodleconfig = trim(smtp_arg('moodleconfig'));
    $sourceid = trim(smtp_arg('sourceid'));
    $operation = strtolower(trim(smtp_arg('operation', 'export')));
    $result = strtolower(trim(smtp_arg('result')));
    $exitcode = (int)smtp_arg('exitcode', '1');
    $stage = trim(smtp_arg('stage', 'unknown'));
    $duration = max(0, (int)smtp_arg('duration', '0'));
    $outputzip = trim(smtp_arg('outputzip'));
    $reportfile = trim(smtp_arg('reportfile'));
    $logfile = trim(smtp_arg('logfile'));
    if (!is_readable($moodleconfig) ||
            !preg_match('/^[a-z][a-z0-9_-]{0,62}$/', $sourceid) ||
            !in_array($operation, ['export', 'validation'], true) ||
            !in_array($result, ['started', 'progress', 'success', 'error'], true) ||
            ($operation === 'validation' &&
                !in_array($result, ['success', 'error'], true))) {
        smtp_warning('Los datos de la notificación son inválidos.');
    }

    $host = trim((string)($config['host'] ?? ''));
    $port = (int)($config['port'] ?? 0);
    $encryption = strtolower(trim((string)($config['encryption'] ?? 'tls')));
    $authentication = ($config['auth'] ?? true) === true;
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');
    $fromemail = trim((string)($config['from_email'] ?? ''));
    $fromname = trim((string)($config['from_name'] ?? 'Recolector Moodle'));
    $timeout = (int)($config['timeout_seconds'] ?? 10);
    $recipients = $config['to'] ?? [];
    if (!is_array($recipients)) {
        $recipients = [$recipients];
    }
    if ($host === '' || $port < 1 || $port > 65535 ||
            !in_array($encryption, ['tls', 'ssl', 'none'], true) ||
            !filter_var($fromemail, FILTER_VALIDATE_EMAIL) ||
            $timeout < 3 || $timeout > 30 ||
            ($authentication && ($username === '' || $password === ''))) {
        smtp_warning('La configuración SMTP está incompleta o es inválida.');
    }

    $validrecipients = [];
    foreach ($recipients as $recipient) {
        $recipient = trim((string)$recipient);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $validrecipients[] = $recipient;
        }
    }
    if ($validrecipients === []) {
        smtp_warning('No hay destinatarios SMTP válidos.');
    }

    smtp_load_phpmailer($moodleconfig);
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->Timeout = $timeout;
    $mail->SMTPAuth = $authentication;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->CharSet = 'UTF-8';
    if ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    $mail->setFrom($fromemail, $fromname);
    foreach ($validrecipients as $recipient) {
        $mail->addAddress($recipient);
    }

    $completed = max(0, (int)smtp_arg('completed', '0'));
    $total = max(0, (int)smtp_arg('total', '0'));
    $created = max(0, (int)smtp_arg('created', '0'));
    $reused = max(0, (int)smtp_arg('reused', '0'));
    $resumed = max(0, (int)smtp_arg('resumed', '0'));
    $adopted = max(0, (int)smtp_arg('adopted', '0'));
    $failed = max(0, (int)smtp_arg('failed', '0'));
    $pending = max(0, (int)smtp_arg('pending', '0'));
    $active = max(0, (int)smtp_arg('active', '0'));
    $workers = max(0, (int)smtp_arg('workers', '0'));
    $eta = trim(smtp_arg('eta'));
    $details = trim(smtp_arg('details'));
    $progressfile = trim(smtp_arg('progressfile'));
    if ($result !== 'progress' && $progressfile !== '' && is_readable($progressfile)) {
        try {
            $savedprogress = json_decode(
                (string)file_get_contents($progressfile),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
            if (is_array($savedprogress)) {
                $completed = max(0, (int)($savedprogress['completed_courses'] ?? $completed));
                $total = max(0, (int)($savedprogress['total_courses'] ?? $total));
                $created = max(0, (int)($savedprogress['created_courses'] ?? $created));
                $reused = max(0, (int)($savedprogress['reused_courses'] ?? $reused));
                $resumed = max(0, (int)($savedprogress['resumed_courses'] ?? $resumed));
                $adopted = max(0, (int)($savedprogress['adopted_courses'] ?? $adopted));
                $failed = max(0, (int)($savedprogress['failed_courses'] ?? $failed));
                $pending = max(0, (int)($savedprogress['pending_courses'] ?? $pending));
                $active = max(0, (int)($savedprogress['active_workers'] ?? $active));
            }
        } catch (Throwable) {
            // El resumen es opcional; el correo conserva el resultado principal.
        }
    }

    if ($operation === 'validation') {
        $label = $result === 'success' ? 'VALIDACIÓN OK' : 'VALIDACIÓN ERROR';
        $operationlabel = 'Validación exhaustiva';
    } else {
        if ($result === 'started') {
            $label = 'INICIADO';
        } else if ($result === 'progress') {
            $label = 'PROGRESO ' . $completed . '/' . $total;
        } else {
            $label = $result === 'success' ? 'COMPLETADO' : 'ERROR';
        }
        $operationlabel = 'Exportación de origen';
    }
    $mail->Subject = '[Recolector Moodle] ' . $label . ' - ' . $sourceid;
    $mail->isHTML(false);
    $bodylines = [
        'Resultado: ' . $label,
        'Operación: ' . $operationlabel,
        'Origen: ' . $sourceid,
        'Etapa: ' . $stage,
        'Código de salida: ' . $exitcode,
        'Duración: ' . $duration . ' segundos',
        'ZIP: ' . $outputzip,
    ];
    if ($result === 'progress' ||
            ($operation === 'export' && $total > 0 && in_array($result, ['success', 'error'], true))) {
        $bodylines[] = 'Cursos completados: ' . $completed . ' de ' . $total;
        $bodylines[] = 'Creados: ' . $created;
        $bodylines[] = 'Reutilizados: ' . $reused;
        $bodylines[] = 'Reanudados por checkpoint: ' . $resumed;
        $bodylines[] = 'Respaldos existentes adoptados: ' . $adopted;
        $bodylines[] = 'Fallidos: ' . $failed;
        $bodylines[] = 'Pendientes: ' . $pending;
        $bodylines[] = 'Workers activos: ' . $active . ' de ' . $workers;
        if ($eta !== '' && preg_match('/^[0-9]+$/', $eta) === 1) {
            $bodylines[] = 'Tiempo restante estimado: ' . $eta . ' segundos';
        }
        if ($details !== '') {
            try {
                $workerstates = json_decode($details, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($workerstates)) {
                    foreach ($workerstates as $workerstate) {
                        if (!is_array($workerstate)) {
                            continue;
                        }
                        $bodylines[] = sprintf(
                            'Worker %d: curso %d, posición %d, fase %s, %d s',
                            (int)($workerstate['worker'] ?? 0),
                            (int)($workerstate['course_id'] ?? 0),
                            (int)($workerstate['position'] ?? 0),
                            (string)($workerstate['stage'] ?? 'unknown'),
                            (int)($workerstate['course_elapsed_seconds'] ?? 0)
                        );
                    }
                }
            } catch (Throwable) {
                $bodylines[] = 'Detalle de workers: no disponible';
            }
        }
    }
    if ($operation === 'validation' && $reportfile !== '') {
        $bodylines[] = 'Reporte: ' . $reportfile;
    }
    $bodylines[] = 'Log: ' . $logfile;
    $bodylines[] = 'Fecha UTC: ' . gmdate('c');
    $mail->Body = implode(PHP_EOL, $bodylines);
    $mail->send();
    $operationoutput = $operation === 'validation' ? ' operation=validation' : '';
    fwrite(
        STDOUT,
        'SMTP_OK' . $operationoutput . ' result=' . $result .
        ' source=' . $sourceid . PHP_EOL
    );
} catch (Throwable $error) {
    smtp_warning($error->getMessage());
}
