<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;

$check = static function (bool $condition, string $message) use (&$failed): void {
    if ($condition) {
        echo "OK   {$message}".PHP_EOL;

        return;
    }

    echo "FAIL {$message}".PHP_EOL;
    $failed = true;
};

$path = static fn (string $relative): string => $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

$commandsPath = $path('src/Commands');
$providerPath = $path('src/PreviewServiceProvider.php');
$readmePath = $path('README.md');

$check(is_dir($commandsPath), 'src/Commands exists');
$check(is_file($providerPath), 'src/PreviewServiceProvider.php exists');
$check(is_file($readmePath), 'README.md exists');

/**
 * @return array<string, string>
 */
$commandSignatures = static function (string $commandsPath): array {
    $signatures = [];
    $files = glob($commandsPath.DIRECTORY_SEPARATOR.'*Command.php') ?: [];
    sort($files);

    foreach ($files as $file) {
        $contents = file_get_contents($file);

        if ($contents === false) {
            continue;
        }

        $class = basename($file, '.php');

        if (preg_match('/protected\s+\$signature\s*=\s*(["\'])(.*?)\1\s*;/s', $contents, $matches) !== 1) {
            continue;
        }

        $signature = trim($matches[2]);
        $name = preg_split('/\s+/', $signature, 2)[0] ?? '';

        if ($name !== '') {
            $signatures[$class] = $name;
        }
    }

    return $signatures;
};

/**
 * @return list<string>
 */
$registeredCommands = static function (string $providerPath): array {
    $contents = file_get_contents($providerPath);

    if ($contents === false) {
        return [];
    }

    if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)Command::class\b/', $contents, $matches) === false) {
        return [];
    }

    $registered = [];

    foreach ($matches[1] as $baseName) {
        $registered[] = $baseName.'Command';
    }

    $registered = array_values(array_unique($registered));
    sort($registered);

    return $registered;
};

$signatures = is_dir($commandsPath) ? $commandSignatures($commandsPath) : [];
$registered = is_file($providerPath) ? $registeredCommands($providerPath) : [];
$readme = is_file($readmePath) ? file_get_contents($readmePath) : false;

$commandFiles = is_dir($commandsPath) ? (glob($commandsPath.DIRECTORY_SEPARATOR.'*Command.php') ?: []) : [];
$commandClasses = array_map(static fn (string $file): string => basename($file, '.php'), $commandFiles);
sort($commandClasses);

$missingSignatures = array_values(array_diff($commandClasses, array_keys($signatures)));
$unregistered = array_values(array_diff(array_keys($signatures), $registered));
$registeredWithoutSignature = array_values(array_diff($registered, array_keys($signatures)));

$check($commandFiles !== [], 'command files were found');
$check($missingSignatures === [], 'each command file declares protected $signature');

foreach ($missingSignatures as $class) {
    $check(false, "{$class} declares protected \$signature");
}

$check($registered !== [], 'PreviewServiceProvider registers commands');
$check($registeredWithoutSignature === [], 'registered command classes have signatures');

foreach ($registeredWithoutSignature as $class) {
    $check(false, "{$class} is registered but no matching command signature was found");
}

$check($unregistered === [], 'signature-bearing command classes are registered');

foreach ($unregistered as $class) {
    $check(false, "{$class} has a signature but is not registered");
}

$commandNames = array_values(array_unique($signatures));
sort($commandNames);

$check(count($commandNames) === count($signatures), 'command names are unique');
$check($readme !== false, 'README.md is readable');

if ($readme !== false) {
    foreach ($commandNames as $commandName) {
        $check(str_contains($readme, $commandName), "README.md mentions {$commandName}");
    }
}

exit($failed ? 1 : 0);
