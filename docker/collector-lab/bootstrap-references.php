<?php

// One-shot LAB initializer. Values are generated locally and never printed.
umask(0077);
$references = '/run/secrets/moodle-toolkit-lab';
$root = '/opt/moodle-lab';
foreach ([$references, $root.'/data'] as $directory) {
    if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0700, true))) {
        exit(1);
    }
    chmod($directory, 0700);
    chown($directory, 33);
    chgrp($directory, 33);
}
$path = $references.'/moodle-lab-db.1';
if (! file_exists($path)) {
    $handle = fopen($path, 'xb');
    if ($handle === false) {
        exit(1);
    }
    fwrite($handle, bin2hex(random_bytes(32)));
    fclose($handle);
    chmod($path, 0600);
    chown($path, 33);
    chgrp($path, 33);
}
clearstatcache();
$stat = lstat($path);
if ($stat === false || is_link($path) || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || $stat['uid'] !== 33) {
    exit(1);
}
$databaseReference = '/run/secrets/moodle-lab-database';
if (is_link($databaseReference) || (! is_dir($databaseReference) && ! mkdir($databaseReference, 0700, true))) {
    exit(1);
}
chmod($databaseReference, 0700);
chown($databaseReference, 70);
chgrp($databaseReference, 70);
$databasePath = $databaseReference.'/password';
if (! file_exists($databasePath)) {
    $handle = fopen($databasePath, 'xb');
    if ($handle === false) {
        exit(1);
    }
    fwrite($handle, file_get_contents($path));
    fclose($handle);
    chmod($databasePath, 0600);
    chown($databasePath, 70);
    chgrp($databasePath, 70);
}
clearstatcache();
$stat = lstat($databasePath);
if ($stat === false || is_link($databasePath) || ($stat['mode'] & 0777) !== 0600 || $stat['nlink'] !== 1 || $stat['uid'] !== 70
    || ! hash_equals(hash_file('sha256', $path), hash_file('sha256', $databasePath))) {
    exit(1);
}
$marker = $root.'/.moodle-toolkit-synthetic-lab.json';
if (! file_exists($marker)) {
    file_put_contents($marker, json_encode(['schema_version' => 'synthetic-moodle.v1', 'fixture_id' => bin2hex(random_bytes(16)),
        'moodle_commit' => 'b2c2f2a0c5f9a141896df6c322a0b6b29bb77646'], JSON_THROW_ON_ERROR));
    chmod($marker, 0644);
}
if (is_link($marker)) {
    exit(1);
}
echo "LAB_REFERENCES_READY\n";
