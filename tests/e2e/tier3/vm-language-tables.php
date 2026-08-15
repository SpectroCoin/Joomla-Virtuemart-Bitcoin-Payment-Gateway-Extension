<?php
/**
 * Creates VirtueMart's per-language tables (`*_en_gb`).
 *
 * VirtueMart keeps translated columns in a side table per language and creates
 * them from its table classes' setTranslatable() declarations - but only when
 * the configuration is saved in the backend, and that path is not reachable
 * headlessly (the settings form is JS-rendered, and the CLI cannot run the
 * installer's redirect). Without these tables the callback dies the moment it
 * touches a payment method or a vendor.
 *
 * So this reads the same declarations VirtueMart reads and creates the tables
 * from them, rather than hard-coding a list that would drift with the version.
 * Columns are TEXT throughout: VirtueMart only reads them here, and guessing
 * each column's width per release would be its own source of breakage.
 */

declare(strict_types=1);

$tablesDir = '/var/www/html/administrator/components/com_virtuemart/tables';
$lang      = 'en_gb';

$mysqli = new mysqli('db', 'root', 'root', 'joomla');
if ($mysqli->connect_errno) {
    fwrite(STDERR, "cannot connect: {$mysqli->connect_error}\n");
    exit(1);
}

// The prefix Joomla actually installed with.
preg_match(
    "/dbprefix[^']*'([^']*)'/",
    (string) file_get_contents('/var/www/html/configuration.php'),
    $m
);
$prefix = $m[1] ?? 'joom_';

$created = 0;
foreach (glob($tablesDir . '/*.php') as $file) {
    $source = (string) file_get_contents($file);

    if (!preg_match('/setTranslatable\s*\(\s*array\s*\((.*?)\)\s*\)/s', $source, $tm)) {
        continue;
    }
    preg_match_all("/'([a-z0-9_]+)'/i", $tm[1], $fm);
    $fields = $fm[1] ?? [];
    if (!$fields) {
        continue;
    }

    $table = basename($file, '.php');                 // e.g. paymentmethods
    // VirtueMart's key column is usually virtuemart_<singular>_id, but a few
    // tables (manufacturercategories among them) keep the plural form as-is.
    $singular = preg_replace('/ies$/', 'y', $table);
    $singular = preg_replace('/s$/', '', $singular);
    $base     = $prefix . 'virtuemart_' . $table;
    $key      = null;
    foreach (['virtuemart_' . $singular . '_id', 'virtuemart_' . $table . '_id'] as $candidate) {
        $res = $mysqli->query("SHOW COLUMNS FROM `{$base}` LIKE '{$candidate}'");
        if ($res && $res->num_rows > 0) {
            $key = $candidate;
            break;
        }
    }
    if ($key === null) {
        continue;   // not a table keyed the way we expect - skip rather than guess
    }

    $cols = ["`{$key}` int(11) UNSIGNED NOT NULL"];
    foreach ($fields as $f) {
        $cols[] = "`{$f}` text";
    }
    $cols[] = "`slug` varchar(255) NOT NULL DEFAULT ''";
    $cols[] = "PRIMARY KEY (`{$key}`)";

    $sql = "CREATE TABLE IF NOT EXISTS `{$base}_{$lang}` (" . implode(', ', $cols)
         . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if ($mysqli->query($sql)) {
        $created++;
    } else {
        fwrite(STDERR, "  {$base}_{$lang}: {$mysqli->error}\n");
    }
}

echo "LANGTABLES={$created}\n";
