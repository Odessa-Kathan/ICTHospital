<?php

// Run only on the existing deployment after its private backup has been taken.
// No passwords or private configuration are printed.
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if (! is_file('/etc/letsencrypt/live/shreeji-clinic-ip/fullchain.pem')) {
    throw new RuntimeException('Issue the production TLS certificate before this maintenance-window cutover.');
}
if ($db->table('institute')->count() !== 1) {
    throw new RuntimeException('Expected exactly one institute row; configuration was not changed.');
}
$oldName = (string) $db->table('institute')->value('name');
$oldMask = (string) (config('clinic.sms_sender_mask') ?: substr(preg_replace('/[^A-Za-z0-9]/', '', $oldName), 0, 11));
$path = $app->environmentFilePath();
$original = file_get_contents($path);
if ($original === false) {
    throw new RuntimeException('Cannot read environment configuration.');
}
$permissions = stat($path);
if ($permissions === false) {
    throw new RuntimeException('Cannot inspect environment ownership.');
}
$contents = $original;
foreach ([
    'CLINIC_NAME' => 'Shreeji Clinic',
    'SMS_SENDER_MASK' => $oldMask,
    'APP_URL' => 'https://129.225.76.84',
    'SESSION_SECURE_COOKIE' => 'true',
    'MAIL_FROM_NAME' => 'Shreeji Clinic',
] as $key => $value) {
    $line = $key.'="'.addcslashes($value, "\\\"\r\n").'"';
    $pattern = '/^'.preg_quote($key, '/').'=[^\r\n]*/m';
    $contents = preg_match($pattern, $contents)
        ? preg_replace_callback($pattern, fn () => $line, $contents)
        : rtrim($contents).PHP_EOL.$line.PHP_EOL;
}
$atomicWrite = function (string $text) use ($path, $permissions): void {
    $temp = tempnam(dirname($path), '.env-release-');
    if ($temp === false) {
        throw new RuntimeException('Unable to prepare environment configuration.');
    }
    try {
        if (file_put_contents($temp, $text) !== strlen($text)
            || ! chmod($temp, $permissions['mode'] & 0777)
            || (fileowner($temp) !== $permissions['uid'] && ! chown($temp, $permissions['uid']))
            || (filegroup($temp) !== $permissions['gid'] && ! chgrp($temp, $permissions['gid']))
            || ! rename($temp, $path)) {
            throw new RuntimeException('Unable to replace configuration while preserving its ownership.');
        }
    } finally {
        if (is_file($temp)) {
            unlink($temp);
        }
    }
};
$replaced = false;
try {
    $db->transaction(function () use ($db, $oldName, $atomicWrite, $contents, &$replaced) {
        $rows = $db->table('institute')->lockForUpdate()->get();
        if ($rows->count() !== 1 || $rows->first()->name !== $oldName) {
            throw new RuntimeException('Institute information changed; cutover was not applied.');
        }
        $db->table('institute')->where('name', $oldName)->update(['name' => 'Shreeji Clinic']);
        if ($db->table('institute')->value('name') !== 'Shreeji Clinic') {
            throw new RuntimeException('Configuration update failed.');
        }
        $atomicWrite($contents);
        $replaced = true;
    });
} catch (Throwable $e) {
    if ($replaced) {
        $atomicWrite($original);
    }
    throw $e;
}
echo 'Clinic branding and HTTPS environment configured; SMS identity preserved.', PHP_EOL;
