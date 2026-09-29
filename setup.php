<?php
/**
 * Keeps one copy of each package version in a shared folder for all your Composer projects (see README.md).
 *
 * Usage: php setup.php [project-path] [options] [-- extra "composer install" options]
 *   -p, --path PATH       Project folder (default: current folder)
 *   -d, --dry             Preview only: the site and the store are not changed
 *   --dev                 Also install dev packages (default: --no-dev)
 *   --wait SECONDS        How long to wait for another deploy to finish (default: 600)
 *   --composer COMMAND    How to run Composer (default: composer), e.g. "php ~/composer.phar"
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    fwrite(STDERR, "setup.php needs PHP 8.0 or newer\n");
    exit(1);
}

const IS_WINDOWS = PHP_OS_FAMILY === 'Windows';
const REPLAYED_EVENTS = ['pre-install-cmd', 'pre-autoload-dump', 'post-autoload-dump', 'post-install-cmd'];
const BUILD_EVENTS = ['pre-operations-exec', 'pre-package-install', 'post-package-install'];
// A fresh vendor/ only sees installs; Laravel's own uninstall hook (dev only) has nothing to do then
const NEVER_FIRED_EVENTS = ['pre-package-update', 'post-package-update', 'pre-package-uninstall', 'post-package-uninstall'];
const IGNORED_SCRIPTS = ['Illuminate\\Foundation\\ComposerScripts::prePackageUninstall'];
const BUILD_COMPOSER = 'composer.setup-build';
const META_FORMAT = 3;
const REMEMBERED_EDITS = 50;

$store = norm(realpath(__DIR__) ?: __DIR__) . '/vendors';
$opts = parseArgs($argv);

$project = realpath($opts['path']);
if ($project === false || !is_file("$project/composer.json")) {
    fail("No composer.json found in {$opts['path']}");
}
$project = norm($project);
if (!is_file("$project/composer.lock")) {
    fail("composer.lock not found in $project");
}

$composerJson = readJson("$project/composer.json");
$scripts = $composerJson['scripts'] ?? [];
$vendorRel = getenv('COMPOSER_VENDOR_DIR') ?: ($composerJson['config']['vendor-dir'] ?? 'vendor');
$vendor = absolute($vendorRel, $project);
$binRel = getenv('COMPOSER_BIN_DIR') ?: str_replace('{$vendor-dir}', $vendorRel, $composerJson['config']['bin-dir'] ?? '{$vendor-dir}/bin');
$bin = absolute($binRel, $project);
$binInsideVendor = str_starts_with("$bin/", "$vendor/");
$next = "$vendor.next";
$old = "$vendor.old";
$declared = array_values(array_filter((array) ($composerJson['extra']['non-shared-vendors'] ?? []), 'is_string'));
$devFlag = $opts['dev'] ? '--dev' : '--no-dev';
$composer = fn (array $args, array $env = []): int => runComposer($opts['composer'], $project, $args, $env);

if ($opts['dry']) {
    say('=== DRY RUN - nothing will be changed, neither in the project nor in the shared folder ===', 'yellow');
}
say("Project:       $project", 'cyan');
say("Vendor folder: $vendor", 'cyan');
say("Shared folder: $store", 'cyan');
say();

if (!is_dir($store) && !@mkdir($store, 0755, true)) {
    fail("Cannot create the shared folder $store");
}
$lock = acquireLock($store, basename($project), $opts['wait']);

if ($opts['dry']) {
    removeTree($next);
} else {
    if (!is_dir($vendor) && is_dir($old)) {
        moveFolder($old, $vendor);
        say('Restored vendor/ left behind by an interrupted deploy', 'yellow');
    }
    foreach ([$next, $old, "$vendor.failed"] as $leftover) {
        removeTree($leftover);
    }
    foreach (glob("$store/*/*.tmp-*", GLOB_NOSORT) ?: [] as $leftover) {
        removeTree($leftover);
    }
}
removeBuildComposer($project);

foreach (NEVER_FIRED_EVENTS as $event) {
    if (array_diff((array) ($scripts[$event] ?? []), IGNORED_SCRIPTS)) {
        say("Note: your \"$event\" scripts will never run: each deploy installs every package fresh into an empty folder, so nothing is ever updated or uninstalled" . (str_contains($event, 'update') ? '. Move that work to post-package-install' : ''), 'yellow');
    }
}
$buildEvents = array_values(array_filter(BUILD_EVENTS, fn (string $event): bool => !empty($scripts[$event])));
foreach ($buildEvents as $event) {
    if (array_filter((array) $scripts[$event], fn ($entry): bool => !is_string($entry) || str_contains($entry, ' ') || !str_contains($entry, '::'))) {
        say("Note: commands in your \"$event\" scripts run while the old vendor/ is still in use, so they see the old packages. Scripts written as PHP class methods get the new ones", 'yellow');
    }
}

$cacheFiles = norm(composerOutput($opts['composer'], $project, ['config', 'cache-files-dir', '--no-plugins']));

section(1, $opts['dry'] ? 2 : 5, 'Building a fresh vendor/ next to the live one');
foreach (['pre-install-cmd', 'pre-autoload-dump'] as $event) {
    if (!empty($scripts[$event]) && !$opts['dry'] && $composer(['run-script', $event, '--no-interaction', $devFlag]) !== 0) {
        fail("The project's \"$event\" script failed; nothing was changed");
    }
}
$nextEnv = str_starts_with($next, "$project/") ? substr($next, strlen($project) + 1) : $next;
$env = ['COMPOSER_VENDOR_DIR' => $nextEnv];
if (!$binInsideVendor) {
    $env['COMPOSER_BIN_DIR'] = "$nextEnv/.bin-build";
}
$installArgs = array_merge(['install', '--no-interaction', '--no-progress'], $opts['dev'] ? [] : ['--no-dev'], $opts['extra']);
if ($buildEvents && !$opts['dry']) {
    writeBuildComposer($project);
    $env['COMPOSER'] = BUILD_COMPOSER . '.json';
} else {
    $installArgs[] = '--no-scripts';
}
$buildExit = $composer($installArgs, $env);
removeBuildComposer($project);
if ($buildExit !== 0) {
    removeTree($next);
    fail('composer install failed; the live vendor/ was not touched');
}
if ($cacheFiles === '' || !is_dir($cacheFiles)) {
    removeTree($next);
    fail("Composer's download cache ($cacheFiles) is not available. Packages are compared with their downloads, so the cache must be enabled and kept in a permanent folder.");
}

$installed = readInstalled($next);
$packages = [];
foreach ($installed as $pkg) {
    $name = $pkg['name'];
    if (str_starts_with($name, 'composer/')
        || norm("$next/composer/" . ($pkg['install-path'] ?? "../$name")) !== "$next/$name"
        || !is_dir("$next/$name")) {
        continue;
    }
    $packages[$name] = ['pkg' => $pkg, 'reason' => localReason($pkg, $declared)];
}
$ctx = [
    'store' => $store,
    'cache' => $cacheFiles,
    'project' => $project,
    'dry' => $opts['dry'],
    'patched' => patchedPackages($project, $composerJson, $installed),
];

if ($opts['dry']) {
    section(2, 2, 'Comparing packages with the originals Composer downloaded');
    $results = sharePackages($packages, $next, $ctx);
    removeTree($next);
    report($results, $declared, array_column($installed, 'name'));
    $skipped = array_values(array_filter(array_merge(REPLAYED_EVENTS, BUILD_EVENTS), fn (string $event): bool => !empty($scripts[$event])));
    if ($skipped) {
        say('Not run in a dry run, as they could change the site: ' . implode(', ', $skipped) . '. Changes they would make to packages are not shown.', 'yellow');
    }
    say('Dry run: nothing was changed.', 'yellow');
    exit(0);
}

section(2, 5, 'Swapping in the new vendor/');
$hadVendor = is_dir($vendor);
if ($hadVendor && !moveFolder($vendor, $old)) {
    removeTree($next);
    fail("Could not move $vendor aside (are files in use?); nothing was changed");
}
if (!moveFolder($next, $vendor)) {
    if ($hadVendor) {
        moveFolder($old, $vendor);
    }
    removeTree($next);
    fail('Could not switch to the new vendor/; nothing was changed');
}

$rollback = function (string $why) use ($hadVendor, $vendor, $old): void {
    say("Error: $why", 'red');
    if (!$hadVendor) {
        say('There was no previous vendor/ to roll back to; the new one stays in place', 'yellow');
    } elseif (moveFolder($vendor, "$vendor.failed") && moveFolder($old, $vendor)) {
        removeTree("$vendor.failed");
        say('Rolled back to the previous vendor/', 'yellow');
    } else {
        say("Could not roll back automatically; the previous vendor/ is in $old", 'red');
    }
    exit(1);
};

if (!$binInsideVendor) {
    removeTree("$vendor/.bin-build");
    if ($hadVendor) {
        foreach (binNames(readInstalled($old)) as $binName) {
            foreach ([$binName, "$binName.bat"] as $file) {
                if (is_file("$bin/$file")) {
                    @unlink("$bin/$file");
                }
            }
        }
    }
    say("Recreating the small scripts in $binRel that start the command-line tools");
    if ($composer(array_merge(['install', '--no-interaction', '--no-progress', '--no-scripts'], $opts['dev'] ? [] : ['--no-dev'], $opts['extra'])) !== 0) {
        $rollback('Composer could not recreate the scripts that start the command-line tools');
    }
}

section(3, 5, "Running the project's Composer scripts");
$ranScripts = false;
foreach (['post-autoload-dump', 'post-install-cmd'] as $event) {
    if (empty($scripts[$event])) {
        continue;
    }
    $ranScripts = true;
    if ($composer(['run-script', $event, '--no-interaction', $devFlag]) !== 0) {
        $rollback("The project's \"$event\" script failed");
    }
}
if (!$ranScripts) {
    say('None defined', 'gray');
}

section(4, 5, 'Linking packages to the shared folder');
$results = sharePackages($packages, $vendor, $ctx);

section(5, 5, 'Cleaning up');
removeTree($old);

report($results, $declared, array_column($installed, 'name'));
exit(0);


// Sharing

function sharePackages(array $packages, string $vendor, array $ctx): array
{
    $results = [];
    foreach ($packages as $name => ['pkg' => $pkg, 'reason' => $reason]) {
        $result = ['name' => $name, 'version' => $pkg['version'], 'status' => 'local', 'detail' => $reason, 'notes' => [], 'files' => 0, 'warnings' => [], 'partial' => false, 'edited' => false];
        if ($reason === null) {
            $result = array_merge($result, sharePackage($pkg, "$vendor/$name", $ctx));
        }
        printResult($result);
        $results[] = $result;
    }

    return $results;
}

function sharePackage(array $pkg, string $dir, array $ctx): array
{
    $actual = fingerprint($dir);
    [$pristine, $original, $why] = compareWithDownload($pkg, $actual, $ctx['cache']);
    $ref = packageReference($pkg);
    [$base, $alt] = storeCandidates($ctx['store'], $pkg['name'], $pkg['version'], $ref);
    if ($base === null) {
        return ['detail' => 'development version without a commit reference, so different builds of it can\'t be told apart'];
    }

    if ($pristine !== false) {
        foreach ([$base, $alt] as $candidate) {
            $meta = $candidate !== null && is_dir($candidate) ? readMeta($candidate) : null;
            if ($meta === null || ($meta['reference'] ?? null) !== $ref || !isset($meta['files'])) {
                continue;
            }
            if ($pristine === true || $meta['files'] === $actual) {
                return shareCopy('link', $candidate, $pkg, $dir, $ctx, $meta, count($actual));
            }
            [$pristine, $original] = [false, $meta['files']];
            break;
        }
    }
    if ($pristine === null) {
        return ['detail' => $why];
    }
    if ($pristine === false) {
        return shareEdited($pkg, $dir, $base, $original, $actual, $ctx);
    }

    $notes = [];
    foreach ([$base, $alt] as $candidate) {
        if ($candidate === null) {
            continue;
        }
        if (!file_exists($candidate)) {
            return shareCopy('new', $candidate, $pkg, $dir, $ctx, ['files' => $actual], count($actual)) + ['notes' => $notes];
        }
        if (is_dir($candidate) && readMeta($candidate) === null) {
            if (fingerprint($candidate) === $actual) {
                return shareCopy('adopt', $candidate, $pkg, $dir, $ctx, ['files' => $actual], count($actual)) + ['notes' => $notes];
            }
            $notes[] = 'the copy named ' . basename($candidate) . ' in the shared folder differs from the original, so it was not used';
        }
    }

    return ['detail' => array_shift($notes) ?? 'no free name for it in the shared folder', 'notes' => $notes];
}

// Edited copies are stored as <base>-edit-<code> once a second project produces exactly the same content
function shareEdited(array $pkg, string $dir, string $base, ?array $original, array $actual, array $ctx): array
{
    $label = (isset($ctx['patched'][$pkg['name']]) ? 'patched by composer-patches' : 'changed after download')
        . ($original !== null ? ' (' . describeDiff($original, $actual) . ')' : '');
    $fingerprint = strongFingerprint($dir);
    $code = substr($fingerprint, 0, 8);
    $target = "$base-edit-$code";
    $details = ['fingerprint' => $fingerprint, 'edited' => $label];
    $shared = fn (array $result): array => $result['status'] === 'fail' ? $result : ['edited' => true, 'detail' => "shared changed version $code, $label"] + $result;

    if (is_dir($target)) {
        $meta = readMeta($target);
        if ($meta !== null && ($meta['fingerprint'] ?? null) === $fingerprint) {
            return $shared(shareCopy('link', $target, $pkg, $dir, $ctx, $meta, count($actual)));
        }
        if ($meta === null && strongFingerprint($target) === $fingerprint) {
            return $shared(shareCopy('adopt', $target, $pkg, $dir, $ctx, $details, count($actual)));
        }

        return ['detail' => "$label; its name in the shared folder is taken by a different change"];
    }

    $seenElsewhere = array_diff(array_keys(readJson("$base.edits.json")[$fingerprint] ?? []), [$ctx['project']]);
    if (!$ctx['dry']) {
        rememberEdit($base, $fingerprint, $ctx['project']);
    }

    return $seenElsewhere
        ? $shared(shareCopy('new', $target, $pkg, $dir, $ctx, $details, count($actual)))
        : ['detail' => "$label; it will be shared as soon as another project makes the exact same change"];
}

function shareCopy(string $action, string $target, array $pkg, string $dir, array $ctx, array $details, int $files): array
{
    if ($action !== 'link') {
        $details += scanPackage($action === 'new' ? $dir : $target, $pkg['bin'] ?? []);
    }
    $error = $ctx['dry'] ? null : storeAndLink($action, $target, $dir, $pkg, $details, basename($ctx['project']));
    if ($error !== null) {
        return ['status' => 'fail', 'detail' => $error];
    }

    $private = $details['writable'] ?? [];

    return [
        'status' => $action === 'new' ? 'new' : 'link',
        'detail' => $private ? 'has its own writable folder' . (count($private) > 1 ? 's' : '') . ' in this project: ' . implode(', ', $private) : null,
        'files' => $files,
        'warnings' => $details['warnings'] ?? [],
        'partial' => !empty($details['real']) || $private !== [],
    ];
}

function storeAndLink(string $action, string $target, string $dir, array $pkg, array $details, string $projectName): ?string
{
    [$real, $writable] = [$details['real'] ?? [], $details['writable'] ?? []];
    if ($action === 'new') {
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
            return 'could not create ' . dirname($target);
        }
        $sameDrive = !IS_WINDOWS || strcasecmp(substr($dir, 0, 2), substr($target, 0, 2)) === 0;
        if ($sameDrive && moveFolder($dir, $target)) {
            if (!makeLink($target, $dir)) {
                copyTree($target, $dir);

                return 'could not create the link';
            }
        } else {
            $tmp = "$target.tmp-" . getmypid();
            if (!copyTree($dir, $tmp) || !moveFolder($tmp, $target)) {
                removeTree($tmp);

                return 'could not copy it into the shared folder';
            }
        }
    }
    if ($action !== 'link') {
        writeMeta($target, $pkg, $details, $projectName);
        makeReadOnly($target);
    }
    if ($action === 'new' && isLink($dir) && !$real && !$writable) {
        return null;
    }

    return replaceWithLink($target, $dir, $real, $writable);
}

function replaceWithLink(string $target, string $dir, array $realFiles, array $writable): ?string
{
    [$new, $aside] = ["$dir.setup-new", "$dir.setup-old"];
    removeTree($new);
    removeTree($aside);
    if (!linkPackage($target, $new, $realFiles, $writable)) {
        removeTree($new);

        return 'could not create the link';
    }
    if (!moveFolder($dir, $aside)) {
        removeTree($new);

        return 'could not move it aside';
    }
    if (!moveFolder($new, $dir)) {
        moveFolder($aside, $dir);
        removeTree($new);

        return 'could not put the link in place';
    }
    removeTree($aside);

    return null;
}

// Files that find other files relative to their own location sit at the project's address, and the places a
// package writes to become real, writable folders (or writable copies of the files it overwrites)
function linkPackage(string $target, string $link, array $realFiles, array $writable): bool
{
    if (!$realFiles && !$writable) {
        return makeLink($target, $link);
    }
    [$realDirs, $copies] = [[], []];
    foreach (array_merge($realFiles, $writable) as $path) {
        for ($dir = dirname($path); $dir !== '.' && $dir !== ''; $dir = dirname($dir)) {
            $realDirs[$dir] = true;
        }
    }
    foreach ($writable as $path) {
        if (is_dir("$target/$path")) {
            $realDirs[$path] = true;
        } elseif (is_file("$target/$path")) {
            $copies[$path] = true;
        }
    }

    return buildTree($target, $link, '', $realDirs, $copies);
}

function buildTree(string $target, string $link, string $rel, array $realDirs, array $copies): bool
{
    if (!@mkdir($rel === '' ? $link : "$link/$rel")) {
        return false;
    }
    foreach (scandir($rel === '' ? $target : "$target/$rel") ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $rel === '' ? $item : "$rel/$item";
        if (is_dir("$target/$path")) {
            $ok = isset($realDirs[$path]) ? buildTree($target, $link, $path, $realDirs, $copies) : makeLink("$target/$path", "$link/$path");
        } elseif (isset($copies[$path])) {
            $ok = @copy("$target/$path", "$link/$path") && @chmod("$link/$path", 0644);
        } else {
            $ok = linkFile("$target/$path", "$link/$path");
        }
        if (!$ok) {
            return false;
        }
    }

    return true;
}

// A hard link is a second name for the same file: no extra inode, and PHP sees it at the project's address
function linkFile(string $source, string $destination): bool
{
    if (!IS_WINDOWS && @link($source, $destination)) {
        return true;
    }
    if (!@copy($source, $destination)) {
        return false;
    }
    @chmod($destination, (@fileperms($source) ?: 0644) & 0777);

    return true;
}

function storeCandidates(string $store, string $name, string $version, ?string $ref): array
{
    [$namespace, $short] = explode('/', $name, 2);
    $version = preg_replace('/^v/', '', $version);
    $safeVersion = preg_replace('~[^A-Za-z0-9._+-]~', '-', $version);
    $ref8 = $ref !== null ? preg_replace('~[^A-Za-z0-9]~', '', substr($ref, 0, 8)) : '';
    if (str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) {
        return $ref8 === '' ? [null, null] : ["$store/$namespace/{$short}_$safeVersion-$ref8", null];
    }
    $base = "$store/$namespace/{$short}_$safeVersion";

    return [$base, $ref8 === '' ? null : "$base-$ref8"];
}

function rememberEdit(string $base, string $fingerprint, string $project): void
{
    $edits = readJson("$base.edits.json");
    $edits[$fingerprint][$project] = date('c');
    uasort($edits, fn (array $a, array $b): int => max($b) <=> max($a));
    if (!is_dir(dirname($base))) {
        @mkdir(dirname($base), 0755, true);
    }
    file_put_contents("$base.edits.json", json_encode(array_slice($edits, 0, REMEMBERED_EDITS, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function localReason(array $pkg, array $declared): ?string
{
    foreach ($declared as $pattern) {
        if (fnmatch(strtolower($pattern), $pkg['name'])) {
            return 'listed in non-shared-vendors';
        }
    }
    if (($pkg['installation-source'] ?? 'dist') === 'source') {
        return 'installed from a git repository, so its files can change without a new version';
    }
    if (($pkg['dist']['type'] ?? '') === 'path') {
        return 'installed from a local folder (path repository)';
    }

    return null;
}

function patchedPackages(string $project, array $composerJson, array $installed): array
{
    $names = [];
    foreach ($installed as $pkg) {
        if (!empty($pkg['extra']['patches_applied'])) {
            $names[$pkg['name']] = true;
        }
    }
    $extra = $composerJson['extra'] ?? [];
    $sources = [
        readJson("$project/patches.lock.json")['patches'] ?? [],
        is_array($extra['patches'] ?? null) ? $extra['patches'] : [],
    ];
    $patchesFile = $extra['patches-file'] ?? $extra['composer-patches']['patches-file'] ?? null;
    if (is_string($patchesFile)) {
        $sources[] = readJson(absolute($patchesFile, $project))['patches'] ?? [];
    }
    foreach ($sources as $patches) {
        foreach (is_array($patches) ? array_keys($patches) : [] as $name) {
            $names[strtolower((string) $name)] = true;
        }
    }

    return $names;
}

function packageReference(array $pkg): ?string
{
    return $pkg['dist']['reference'] ?? $pkg['source']['reference'] ?? null;
}


// Fingerprints and scanning

function filesIn(string $dir): Generator
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));
    foreach ($items as $item) {
        if ($item->isFile()) {
            yield substr($item->getPathname(), strlen($dir) + 1);
        }
    }
}

// crc32 per file: the checksum zip files store, so a folder can be compared with Composer's download
function fingerprint(string $dir): array
{
    $files = [];
    foreach (filesIn($dir) as $file) {
        $files[$file] = hash_file('crc32b', "$dir/$file");
    }
    ksort($files, SORT_STRING);

    return $files;
}

function strongFingerprint(string $dir): string
{
    $files = [];
    foreach (filesIn($dir) as $file) {
        $files[$file] = hash_file('sha1', "$dir/$file");
    }
    ksort($files, SORT_STRING);

    return sha1((string) json_encode($files));
}

// Returns [identical?, the download's fingerprint, why unknown]; null means there is no zip to compare with
function compareWithDownload(array $pkg, array $actual, string $cacheFiles): array
{
    if (($pkg['dist']['type'] ?? null) !== 'zip' || empty($pkg['dist']['url'])) {
        return [null, null, 'not downloaded as a zip, so there is no original to compare it with'];
    }
    $folder = "$cacheFiles/{$pkg['name']}";
    $expectedZip = "$folder/" . sha1($pkg['dist']['url']) . '.zip';
    if (is_file($expectedZip) && ($expected = zipFingerprint($expectedZip)) !== null) {
        return [$expected === $actual, $expected, null];
    }
    foreach (glob("$folder/*.zip", GLOB_NOSORT) ?: [] as $zip) {
        if (zipFingerprint($zip) === $actual) {
            return [true, $actual, null];
        }
    }

    return [null, null, 'its original zip is not in Composer\'s cache, so it can\'t be compared'];
}

function zipFingerprint(string $zipPath): ?array
{
    $size = @filesize($zipPath);
    $handle = @fopen($zipPath, 'rb');
    if (!$size || !$handle) {
        return null;
    }
    $tailLength = min($size, 65557);
    fseek($handle, $size - $tailLength);
    $tail = (string) fread($handle, $tailLength);
    $pos = strrpos($tail, "PK\x05\x06");
    if ($pos === false || strlen($tail) < $pos + 22) {
        fclose($handle);

        return null;
    }
    $end = unpack('vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset', substr($tail, $pos + 4, 16));
    if ($end['entries'] === 0xFFFF || $end['cdOffset'] === 0xFFFFFFFF) {
        fclose($handle);

        return null;
    }
    fseek($handle, $end['cdOffset']);
    $directory = (string) fread($handle, $end['cdSize']);
    fclose($handle);

    $entries = [];
    $offset = 0;
    for ($i = 0; $i < $end['entries']; $i++) {
        if (substr($directory, $offset, 4) !== "PK\x01\x02") {
            return null;
        }
        $header = unpack('Vcrc/VcompressedSize/Vsize/vnameLength/vextraLength/vcommentLength', substr($directory, $offset + 16, 18));
        $entries[substr($directory, $offset + 46, $header['nameLength'])] = sprintf('%08x', $header['crc']);
        $offset += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
    }

    // Composer strips a single top-level folder, ignoring .DS_Store
    $topLevel = [];
    foreach (array_keys($entries) as $entry) {
        $first = explode('/', $entry, 2)[0];
        if ($first !== '.DS_Store') {
            $topLevel[$first] = ($topLevel[$first] ?? false) || str_contains($entry, '/');
        }
    }
    $prefix = count($topLevel) === 1 && reset($topLevel) === true ? key($topLevel) . '/' : '';

    $files = [];
    foreach ($entries as $entry => $crc) {
        if (str_ends_with($entry, '/') || ($prefix !== '' && !str_starts_with($entry, $prefix))) {
            continue;
        }
        $files[substr($entry, strlen($prefix))] = $crc;
    }
    ksort($files, SORT_STRING);

    return $files;
}

function describeDiff(array $expected, array $actual): string
{
    $changes = [
        'changed' => array_keys(array_diff_assoc(array_intersect_key($actual, $expected), $expected)),
        'added' => array_keys(array_diff_key($actual, $expected)),
        'removed' => array_keys(array_diff_key($expected, $actual)),
    ];
    $parts = [];
    foreach ($changes as $kind => $files) {
        if ($files) {
            $parts[] = "$kind: " . implode(', ', array_slice($files, 0, 3)) . (count($files) > 3 ? ' and ' . (count($files) - 3) . ' more' : '');
        }
    }

    return implode(', ', $parts);
}

// Files that must sit at the project's address (tools, code climbing out of the package, code writing into it),
// the places it writes to (each project gets them as private writable folders), and writes that can't be located
function scanPackage(string $dir, array $binFiles): array
{
    [$real, $writable, $warnings] = [[], [], []];
    foreach ($binFiles as $file) {
        if (is_file("$dir/" . norm($file))) {
            $real[norm($file)] = true;
        }
    }
    foreach (filesIn($dir) as $file) {
        if (!str_ends_with($file, '.php') || preg_match('~(^|/)(tests?|fixtures?|vendor)/~i', $file)) {
            continue;
        }
        $source = (string) file_get_contents("$dir/$file");
        if (climbsOut($source, substr_count($file, '/')) !== null) {
            $real[$file] = true;
        }
        foreach (writesIntoItself($source, $file) as [$offset, $target]) {
            if ($target === '..') {
                continue; // outside the package: climbsOut already puts the file at the project's address
            }
            if ($target !== null) {
                $real[$file] = true;
                $writable[$target] = true;
            } elseif (!$warnings) {
                $warnings[] = ['reason' => 'writes into its own folder, at a path the tool can\'t work out', 'file' => $file, 'line' => lineOf($source, $offset)];
            }
        }
    }
    ksort($real, SORT_STRING);
    ksort($writable, SORT_STRING);

    return ['real' => array_keys($real), 'writable' => array_keys($writable), 'warnings' => $warnings];
}

// A file $depth folders deep can climb $depth levels and still be inside its package
function climbsOut(string $source, int $depth): ?int
{
    $patterns = [
        '~__DIR__\s*\.\s*[\'"]/((?:\.\./)*\.\.)(?=[/\'"])~' => fn (string $m): int => substr_count($m, '..'),
        '~dirname\(\s*__DIR__\s*,\s*(\d+)\s*\)~' => fn (string $m): int => (int) $m,
        '~dirname\(\s*__FILE__\s*,\s*(\d+)\s*\)~' => fn (string $m): int => (int) $m - 1,
        '~((?:\\\\?dirname\(\s*)+)__DIR__\s*\)~' => fn (string $m): int => substr_count($m, 'dirname'),
        '~((?:\\\\?dirname\(\s*)+)__FILE__\s*\)~' => fn (string $m): int => substr_count($m, 'dirname') - 1,
    ];
    $first = null;
    foreach ($patterns as $pattern => $levels) {
        preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $i => [$captured]) {
            if ($levels($captured) > $depth) {
                $first = min($first ?? PHP_INT_MAX, $matches[0][$i][1]);
                break;
            }
        }
    }

    return $first;
}

// Writes into the package's own folder: [offset, target relative to the package root, or null when built at runtime]
function writesIntoItself(string $source, string $file): array
{
    $own = '((?:\\\\?dirname\s*\(\s*)*(?:__DIR__|__FILE__)(?:\s*,\s*\d+\s*)?\)*)';
    $literal = '(?:\s*\.\s*[\'"]([^\'"]*)[\'"])?';
    // [pattern, whether the path is complete: yes, no, or when group 3 (the end of the argument) matched]
    $patterns = [
        ['~\b(?:file_put_contents|mkdir|touch|tempnam)\s*\(\s*' . $own . $literal . '(\s*[,)])?~', 'end'],
        ['~\bfopen\s*\(\s*' . $own . '[^;]*?,\s*[\'"][waxc]~', 'no'],
        ['~\bfopen\s*\(\s*' . $own . $literal . '\s*,\s*[\'"][waxc]~', 'yes'],
        // a path to a temp/cache folder, e.g. mPDF's default: __DIR__ . '/../../tmp'
        ['~' . $own . '\s*\.\s*[\'"]((?:/[^\'"]*)?/\.?(?:tmp|temp|cache|caches|logs?|storage)(?:/[^\'"./]+)*/?)[\'"]~i', 'yes'],
    ];
    $hits = [];
    foreach ($patterns as [$pattern, $complete]) {
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $offset = $match[0][1];
            $lineStart = (int) strrpos(substr($source, 0, $offset), "\n");
            if (preg_match('~\b(require|include)(_once)?\b~', substr($source, $lineStart, $offset - $lineStart))) {
                continue;
            }
            $known = $complete === 'yes' || ($complete === 'end' && ($match[3][1] ?? -1) >= 0);
            $hits[$offset] = [$offset, $known ? resolveOwnPath($match[1][0], $match[2][0] ?? '', $file) : null];
        }
    }
    ksort($hits);

    return array_values($hits);
}

function resolveOwnPath(string $own, string $literal, string $file): ?string
{
    $cut = strcspn($literal, '${');
    if ($cut < strlen($literal)) {
        $slash = strrpos(substr($literal, 0, $cut), '/');
        $literal = $slash === false ? '' : substr($literal, 0, $slash);
    }
    $levels = substr_count($own, 'dirname') + (preg_match('~,\s*(\d+)~', $own, $m) ? (int) $m[1] - 1 : 0);
    if (str_contains($own, '__FILE__')) {
        if ($levels === 0) {
            return norm($file . $literal);
        }
        $levels--;
    }
    $segments = array_slice(explode('/', $file), 0, -1);
    if ($levels > count($segments)) {
        return '..';
    }
    $target = norm(ltrim(implode('/', array_slice($segments, 0, count($segments) - $levels)) . '/' . $literal, '/'));

    return $target === '..' || str_starts_with($target, '../') ? '..' : $target;
}

function lineOf(string $source, int $offset): int
{
    return substr_count($source, "\n", 0, $offset) + 1;
}


// Store metadata: <store>/<vendor>/<package>_<version>.json next to each stored copy

function readMeta(string $target): ?array
{
    $meta = readJson("$target.json");

    return (int) ($meta['format'] ?? 0) >= META_FORMAT ? $meta : null;
}

function writeMeta(string $target, array $pkg, array $details, string $projectName): void
{
    $meta = [
        'format' => META_FORMAT,
        'package' => $pkg['name'],
        'version' => $pkg['version'],
        'reference' => packageReference($pkg),
        'dist' => $pkg['dist']['url'] ?? null,
        'stored_by' => $projectName,
        'stored_at' => date('c'),
    ] + $details;
    removeTree("$target.json");
    file_put_contents("$target.json.tmp", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename("$target.json.tmp", "$target.json");
    @chmod("$target.json", 0444);
}


// Filesystem (Windows uses junctions and copies: symlinks need admin rights there)

function makeLink(string $target, string $link): bool
{
    if (IS_WINDOWS) {
        exec('mklink /J ' . escapeshellarg(str_replace('/', '\\', $link)) . ' ' . escapeshellarg(str_replace('/', '\\', $target)) . ' >NUL 2>&1', $output, $code);

        return $code === 0 && is_dir($link);
    }
    $from = realpath(dirname($link));

    return $from !== false && @symlink(relativePath(norm($from), $target), $link);
}

// Windows refuses to rename a folder while another program (antivirus, editor indexer) has a file in it open
function moveFolder(string $from, string $to): bool
{
    for ($attempt = 1; !@rename($from, $to); $attempt++) {
        if (!IS_WINDOWS || $attempt === 40) {
            return false;
        }
        if ($attempt === 1) {
            say('  Waiting for another program (antivirus, editor) to release files in ' . basename($from) . '...', 'gray');
        }
        usleep(250000);
    }

    return true;
}

function isLink(string $path): bool
{
    if (is_link($path)) {
        return true;
    }
    // is_link() misses junctions; readlink() sees them but returns plain files' own path
    $target = IS_WINDOWS ? @readlink($path) : false;

    return $target !== false && norm($target) !== norm((string) realpath(dirname($path)) . '/' . basename($path));
}

// Never follows links, so store copies are never touched
function removeTree(string $path): void
{
    if (isLink($path)) {
        if (!IS_WINDOWS || !@rmdir($path)) {
            @unlink($path);
        }

        return;
    }
    if (is_dir($path)) {
        @chmod($path, 0755);
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                removeTree("$path/$item");
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) && !@unlink($path) && (@stat($path)['nlink'] ?? 2) === 1) {
        @chmod($path, 0644);
        @unlink($path);
    }
}

function copyTree(string $from, string $to): bool
{
    if (!is_dir($to) && !@mkdir($to, 0755, true)) {
        return false;
    }
    foreach (scandir($from) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $source = "$from/$item";
        if (is_dir($source) && !isLink($source)) {
            if (!copyTree($source, "$to/$item")) {
                return false;
            }
        } elseif (!@copy($source, "$to/$item")) {
            return false;
        } else {
            @chmod("$to/$item", (@fileperms($source) ?: 0644) & 0777);
        }
    }

    return true;
}

function makeReadOnly(string $dir): void
{
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if (!$item->isLink()) {
            @chmod($item->getPathname(), $item->getPerms() & 0777 & ~0222);
        }
    }
    @chmod($dir, (@fileperms($dir) ?: 0755) & 0777 & ~0222);
}

function norm(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $prefix = preg_match('~^([A-Za-z]:)?/~', $path, $match) ? $match[0] : '';
    $parts = [];
    foreach (explode('/', substr($path, strlen($prefix))) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..' && $parts && end($parts) !== '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }

    return $prefix . implode('/', $parts);
}

function absolute(string $path, string $base): string
{
    return norm(preg_match('~^([A-Za-z]:)?[/\\\\]~', $path) ? $path : "$base/$path");
}

function relativePath(string $fromDir, string $to): string
{
    $from = explode('/', $fromDir);
    $target = explode('/', $to);
    while ($from && $target && $from[0] === $target[0]) {
        array_shift($from);
        array_shift($target);
    }

    return str_repeat('../', count($from)) . implode('/', $target);
}


// Composer, lock, input and output

// Output is relayed through this process: sharing the log's file handle garbles it on Windows
function runComposer(string $composer, string $cwd, array $args, array $env = []): int
{
    $args[] = stream_isatty(STDOUT) ? '--ansi' : '--no-ansi';
    $command = $composer . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $process = proc_open($command, [0 => STDIN, 1 => ['pipe', 'w']], $pipes, $cwd, $env ? array_merge(getenv(), $env) : null);
    if (!is_resource($process)) {
        return 1;
    }
    while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
        echo $chunk;
    }
    fclose($pipes[1]);

    return proc_close($process);
}

function composerOutput(string $composer, string $cwd, array $args): string
{
    $command = $composer . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', IS_WINDOWS ? 'NUL' : '/dev/null', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return '';
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    $lines = array_filter(array_map('trim', explode("\n", $output)));

    return (string) end($lines);
}

// The copy is decoded into objects so empty {} stay objects: composer.lock's content-hash still matches
function writeBuildComposer(string $project): void
{
    $data = json_decode((string) file_get_contents("$project/composer.json"));
    foreach (REPLAYED_EVENTS as $event) {
        if (isset($data->scripts->$event)) {
            unset($data->scripts->$event);
        }
    }
    file_put_contents("$project/" . BUILD_COMPOSER . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    copy("$project/composer.lock", "$project/" . BUILD_COMPOSER . '.lock');
    if (is_file("$project/patches.lock.json")) {
        copy("$project/patches.lock.json", "$project/" . BUILD_COMPOSER . '-patches.lock.json');
    }
}

function removeBuildComposer(string $project): void
{
    foreach (['.json', '.lock', '-patches.lock.json'] as $suffix) {
        removeTree("$project/" . BUILD_COMPOSER . $suffix);
    }
}

// The system releases the lock when this process ends, even on a crash
function acquireLock(string $store, string $projectName, int $wait)
{
    $handle = @fopen("$store/.lock", 'c');
    if ($handle === false) {
        fail("Cannot open $store/.lock");
    }
    if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
        // Windows never reports "would block"
        if (!IS_WINDOWS && !$wouldBlock) {
            fail('This filesystem does not support file locks, so deploys cannot be kept apart');
        }
        $owner = trim((string) @file_get_contents("$store/.lock-owner")) ?: 'another project';
        say("Waiting: $owner is deploying...", 'yellow');
        $deadline = time() + $wait;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fail("Gave up after waiting {$wait}s for: $owner");
            }
            sleep(2);
        }
    }
    file_put_contents("$store/.lock-owner", "$projectName (started " . date('H:i') . ')');

    return $handle;
}

function readInstalled(string $vendorDir): array
{
    $data = readJson("$vendorDir/composer/installed.json");

    return $data['packages'] ?? (isset($data[0]) ? $data : []);
}

function binNames(array $installed): array
{
    $names = [];
    foreach ($installed as $pkg) {
        foreach ($pkg['bin'] ?? [] as $binPath) {
            $names[] = basename($binPath);
        }
    }

    return $names;
}

function readJson(string $path): array
{
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    return is_array($data) ? $data : [];
}

function parseArgs(array $argv): array
{
    $opts = ['path' => getcwd(), 'dry' => false, 'dev' => false, 'wait' => 600, 'composer' => 'composer', 'extra' => []];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        [$flag, $value] = str_starts_with($arg, '-') && str_contains($arg, '=') ? explode('=', $arg, 2) : [$arg, null];
        $takeValue = function () use (&$i, $argv, $flag, $value): string {
            $value ??= $argv[++$i] ?? null;
            if ($value === null) {
                fail("Missing value for $flag");
            }

            return $value;
        };
        switch (strtolower($flag)) {
            case '--':
                $opts['extra'] = array_slice($argv, $i + 1);

                return $opts;
            case '-p':
            case '--path':
            case '-path':
                $opts['path'] = $takeValue();
                break;
            case '-d':
            case '--dry':
            case '-dry':
                $opts['dry'] = true;
                break;
            case '--dev':
                $opts['dev'] = true;
                break;
            case '--wait':
                $opts['wait'] = max(0, (int) $takeValue());
                break;
            case '--composer':
                $opts['composer'] = $takeValue();
                break;
            case '-h':
            case '--help':
                preg_match('~/\*\*(.*?)\*/~s', (string) file_get_contents(__FILE__), $doc);
                echo trim(preg_replace('~^\s*\* ?~m', '', $doc[1])), PHP_EOL;
                exit(0);
            default:
                if (str_starts_with($arg, '-')) {
                    fail("Unknown option $arg (see --help)");
                }
                $opts['path'] = $arg;
        }
    }

    return $opts;
}

function printResult(array $result): void
{
    [$label, $color] = ['link' => ['LINK', 'gray'], 'new' => ['NEW', 'green'], 'local' => ['LOCAL', 'yellow'], 'fail' => ['FAIL', 'red']][$result['status']];
    say(sprintf('  %-7s %s %s', "[$label]", $result['name'], $result['version']) . ($result['detail'] ? " - {$result['detail']}" : ''), $color);
    foreach ($result['notes'] as $note) {
        say("          $note", 'yellow');
    }
}

function report(array $results, array $declared, array $installedNames): void
{
    $counts = ['link' => 0, 'new' => 0, 'local' => 0, 'fail' => 0];
    [$files, $edited, $partial] = [0, 0, 0];
    $warned = [];
    foreach ($results as $result) {
        $counts[$result['status']]++;
        if (in_array($result['status'], ['link', 'new'], true)) {
            $files += $result['files'];
            $edited += (int) $result['edited'];
            $partial += (int) $result['partial'];
            if ($result['warnings']) {
                $warned[$result['name']] = $result['warnings'];
            }
        }
    }

    say();
    if ($warned) {
        say('These packages write files into their own folder, and the shared folder is read-only.', 'yellow');
        say('Set their temp or cache folder to your project\'s storage/ folder, or add them to "non-shared-vendors" in composer.json:', 'yellow');
        $width = max(array_map('strlen', array_keys($warned)));
        foreach ($warned as $name => $warnings) {
            foreach ($warnings as $i => $warning) {
                say(sprintf('  %-' . $width . 's  # %s (%s:%d)', $i === 0 ? $name : '', $warning['reason'], $warning['file'], $warning['line']), 'yellow');
            }
        }
        say();
    }
    foreach ($declared as $pattern) {
        if (!array_filter($installedNames, fn (string $name): bool => fnmatch(strtolower($pattern), $name))) {
            say("Note: non-shared-vendors lists \"$pattern\", which is not installed", 'gray');
        }
    }

    say('========================================', 'cyan');
    say('Summary: ' . count($results) . ' packages', 'cyan');
    $line = fn (string $label, string $value, string $color = '') => say(sprintf('  %-33s %s', "$label:", $value), $color);
    $line('Linked to the shared folder', (string) $counts['link']);
    $line('Added to the shared folder', (string) $counts['new'], $counts['new'] ? 'green' : '');
    $line('Kept their own copy', (string) $counts['local'], $counts['local'] ? 'yellow' : '');
    if ($counts['fail']) {
        $line('Could not be linked', (string) $counts['fail'], 'red');
    }
    if ($edited) {
        $line('Shared changed versions', (string) $edited);
    }
    if ($partial) {
        $line('With some files of their own', "$partial (tools, writable folders)");
    }
    $line('Files shared instead of copied', number_format($files));
    say('========================================', 'cyan');
}

function section(int $step, int $steps, string $title): void
{
    say();
    say("[$step/$steps] $title", 'cyan');
}

function say(string $text = '', string $color = ''): void
{
    static $colors = null;
    if ($colors === null) {
        $colors = function_exists('stream_isatty') && stream_isatty(STDOUT);
        if ($colors && IS_WINDOWS && function_exists('sapi_windows_vt100_support')) {
            $colors = sapi_windows_vt100_support(STDOUT, true);
        }
    }
    $codes = ['red' => 31, 'green' => 32, 'yellow' => 33, 'cyan' => 36, 'gray' => 90];
    echo $colors && isset($codes[$color]) ? "\e[{$codes[$color]}m$text\e[0m" : $text, PHP_EOL;
}

function fail(string $message)
{
    say("Error: $message", 'red');
    exit(1);
}
