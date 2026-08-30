<?php

declare(strict_types=1);

/*
 * Prints the score Infection just measured. Infection itself is run with
 * -q: a mutation diff containing angle brackets makes Symfony's console
 * formatter parse it as markup and abort, and the escaped mutants are in
 * infection.log anyway.
 */

$path = __DIR__ . '/../infection-summary.json';
$raw = @file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "no summary at $path\n");
    exit(1);
}
$stats = json_decode($raw, true)['stats'] ?? null;
if (!is_array($stats)) {
    fwrite(STDERR, "unreadable summary at $path\n");
    exit(1);
}
printf(
    "MSI %.2f%%, covered %.2f%%, killed %d of %d, escaped %d (see infection.log)\n",
    $stats['msi'],
    $stats['coveredCodeMsi'],
    $stats['killedCount'],
    $stats['totalMutantsCount'],
    $stats['escapedCount']
);
