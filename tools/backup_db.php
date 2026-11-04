<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';

function backup_profile(array $config, string $name): array {
    $profile = $config['db_profiles'][$name] ?? null;
    if (is_array($profile)) return $profile;
    if ($name === 'demo' && isset($config['db_name'])) {
        return ['db_host'=>$config['db_host']??'127.0.0.1','db_name'=>$config['db_name'],'db_user'=>$config['db_user']??'root','db_pass'=>$config['db_pass']??''];
    }
    throw new RuntimeException("Database profile '$name' is not configured.");
}

function unique_backup_dir(string $root): string {
    $base = date('Ymd_His');
    for ($i=0; $i<1000; $i++) {
        $name = $base . ($i ? sprintf('_%03d', $i) : '');
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (@mkdir($path, 0700)) return $path;
    }
    throw new RuntimeException('Could not create a unique backup directory.');
}

$profileName = 'live';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--profile=')) $profileName = substr($arg, 10);
    else {fwrite(STDERR,"Usage: php tools/backup_db.php [--profile=demo|live]\n");exit(2);}
}
if (!in_array($profileName, ['demo','live'], true)) {fwrite(STDERR,"Profile must be demo or live.\n");exit(2);}

try {
    $profile = backup_profile(config(), $profileName);
    $dbName = (string)($profile['db_name'] ?? '');
    if ($dbName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) throw new RuntimeException('Configured database name is invalid.');
    $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    if (!is_file($mysqldump)) throw new RuntimeException('XAMPP mysqldump.exe was not found at the expected local path.');

    $backupRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true) && !is_dir($backupRoot)) throw new RuntimeException('Could not create backups directory.');
    $dir = unique_backup_dir($backupRoot);
    $dumpPath = $dir . DIRECTORY_SEPARATOR . $dbName . '.sql';
    putenv('MYSQL_PWD=' . (string)($profile['db_pass'] ?? ''));
    $command = [$mysqldump, '--host=' . (string)($profile['db_host'] ?? '127.0.0.1'), '--user=' . (string)($profile['db_user'] ?? 'root'), '--single-transaction', '--routines', '--triggers', '--databases', $dbName, '--result-file=' . $dumpPath];
    $pipes = [];
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Could not start mysqldump.');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);
    putenv('MYSQL_PWD');
    if ($exit !== 0 || !is_file($dumpPath) || filesize($dumpPath) === 0) {
        @unlink($dumpPath);
        throw new RuntimeException('Database backup failed; mysqldump returned an error.');
    }
    echo "Backup complete: backups/" . basename($dir) . "/$dbName.sql\n";
} catch (Throwable $e) {
    putenv('MYSQL_PWD');
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
