<?php
// Integration tests: real `composer` against throwaway path-repo projects. Run: php tests/run.php

const OLD_HOOK = 'PKGS=$(jq -r \'(.extra.patches // {}) | keys | join(" ")\' composer.json); if [ -n "$PKGS" ]; then HASH=$(jq -r \'._hash\' patches.lock.json 2>/dev/null); if [ -z "$HASH" ] || [ "$(cat .patches_applied 2>/dev/null)" != "$HASH" ]; then echo "Patches hash changed!" && echo "$HASH" > .patches_applied && composer reinstall $PKGS || rm -f .patches_applied; fi; fi';
const REAPPLY = 'Patches changed: re-applying';

$failures = 0;

function composer(string $dir, string $args): array
{
    exec('cd ' . escapeshellarg($dir) . ' && composer --no-interaction --no-ansi ' . $args . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

function patchText(string $from, string $to): string
{
    return "--- a/file.txt\n+++ b/file.txt\n@@ -1 +1 @@\n-$from\n+$to\n";
}

/**
 * A project with acme/demo (file.txt = "hello"), optionally patched, optionally with this plugin, locked + installed.
 * 'other' adds acme/other, patched to that text.
 */
function makeProject(array $o = []): string
{
    $o += ['patch' => 'patched', 'other' => null, 'plugin' => true, 'pluginDir' => dirname(__DIR__), 'scripts' => null];
    $dir = sys_get_temp_dir() . '/cpr-' . bin2hex(random_bytes(4));
    foreach ($o['other'] === null ? ['demo'] : ['demo', 'other'] as $pkg) {
        mkdir("$dir/pkgs/$pkg", 0777, true);
        file_put_contents("$dir/pkgs/$pkg/composer.json", '{"name":"acme/' . $pkg . '","version":"1.0.0"}');
        file_put_contents("$dir/pkgs/$pkg/file.txt", "hello\n");
    }

    $json = [
        'name' => 'test/project',
        'version' => '1.0.0',
        'require' => ['acme/demo' => '1.0.0', 'cweagans/composer-patches' => '^2.0'],
        'repositories' => [
            // symlink:false or cweagans would patch the fixture source itself.
            ['type' => 'path', 'url' => './pkgs/demo', 'options' => ['symlink' => false]],
            ['type' => 'path', 'url' => $o['pluginDir']],
        ],
        'config' => ['allow-plugins' => ['cweagans/composer-patches' => true, 'saucal/composer-patches' => true]],
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
    ];
    if ($o['other'] !== null) {
        $json['require']['acme/other'] = '1.0.0';
        $json['repositories'][] = ['type' => 'path', 'url' => './pkgs/other', 'options' => ['symlink' => false]];
        @mkdir("$dir/patches");
        file_put_contents("$dir/patches/other.patch", patchText('hello', $o['other']));
        $json['extra']['patches']['acme/other'] = [['description' => 'other', 'url' => './patches/other.patch', 'depth' => 1]];
    }
    if ($o['plugin']) {
        $json['require']['saucal/composer-patches'] = '*';
    }
    if ($o['patch'] !== null) {
        @mkdir("$dir/patches");
        file_put_contents("$dir/patches/demo.patch", patchText('hello', $o['patch']));
        $json['extra']['patches']['acme/demo'] = [['description' => 'demo', 'url' => './patches/demo.patch', 'depth' => 1]];
    }
    if ($o['scripts']) {
        $json['scripts'] = $o['scripts'];
    }
    file_put_contents("$dir/composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    [$code, $out] = composer($dir, 'update');
    check($code === 0, "fixture update ($dir)", $out);
    if ($o['patch'] !== null || $o['other'] !== null) {
        [$code, $out] = composer($dir, 'patches-relock');
        check($code === 0, 'fixture relock', $out);
    }
    return $dir;
}

function relock(string $dir, string $to): void
{
    file_put_contents("$dir/patches/demo.patch", patchText('hello', $to));
    [$code, $out] = composer($dir, 'patches-relock');
    check($code === 0, 'relock', $out);
}

/** Drops $pkg from extra.patches (all of them when null) and relocks, as a developer removing a patch would. */
function removePatch(string $dir, ?string $pkg = null): void
{
    $json = json_decode(file_get_contents("$dir/composer.json"), true);
    if ($pkg === null) {
        unset($json['extra']['patches']);
    } else {
        unset($json['extra']['patches'][$pkg]);
    }
    file_put_contents("$dir/composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    [$code, $out] = composer($dir, 'patches-relock');
    check($code === 0, 'relock after removing a patch', $out);
}

function demo(string $dir, string $pkg = 'demo'): string
{
    return trim((string) @file_get_contents("$dir/vendor/acme/$pkg/file.txt"));
}

/** The patches fingerprint recorded on $pkg's installed.json entry, null when none. */
function applied(string $dir, string $pkg = 'demo'): ?string
{
    foreach (json_decode((string) @file_get_contents("$dir/vendor/composer/installed.json"), true)['packages'] ?? [] as $p) {
        if ($p['name'] === "acme/$pkg") {
            return $p['extra']['saucal-patches-applied'] ?? null;
        }
    }
    return null;
}

/** A build restored from a previous one that kept only installed.json and the package dirs of vendor/. */
function restoreBuild(string $dir): void
{
    $keep = "$dir.keep";
    exec('mkdir -p ' . escapeshellarg($keep) . ' && mv ' . escapeshellarg("$dir/vendor/composer/installed.json") . ' ' . escapeshellarg("$dir/vendor/acme") . ' ' . escapeshellarg($keep)
        . ' && rm -rf ' . escapeshellarg("$dir/vendor") . ' && mkdir -p ' . escapeshellarg("$dir/vendor/composer")
        . ' && mv ' . escapeshellarg("$keep/installed.json") . ' ' . escapeshellarg("$dir/vendor/composer/") . ' && mv ' . escapeshellarg("$keep/acme") . ' ' . escapeshellarg("$dir/vendor/")
        . ' && rmdir ' . escapeshellarg($keep));
}

/** This plugin as tagged v1.0.0, extracted to a temp dir (once). */
function v1Dir(): string
{
    static $v1;
    if ($v1 === null) {
        $v1 = sys_get_temp_dir() . '/cpr-v1-' . bin2hex(random_bytes(4));
        mkdir($v1);
        // f363543 is the commit tagged v1.0.0, for clones without tags.
        exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' archive f363543 | tar -x -C ' . escapeshellarg($v1));
    }
    return $v1;
}

function check(bool $ok, string $what, string $out = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) {
        $failures++;
        echo "     ---\n     " . str_replace("\n", "\n     ", $out) . "\n";
    }
}

function scenario(string $name): void
{
    echo "\n# $name\n";
}

scenario('baseline: cweagans alone, fresh install from lock applies the patch');
$dir = makeProject(['plugin' => false]);
exec('rm -rf ' . escapeshellarg("$dir/vendor"));
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched', 'fresh install patched by cweagans alone', $out);

scenario('baseline: cweagans alone misses a changed patch when vendor/ is present');
relock($dir, 'patched v2');
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched', 'still old patch after relock + install (the gap this plugin closes)', $out);

scenario('fresh install applies the patch once');
$dir = makeProject();
exec('rm -rf ' . escapeshellarg("$dir/vendor"));
[$code, $out] = composer($dir, 'install');
check($code === 0, 'exit 0', $out);
check(demo($dir) === 'patched', 'file patched', $out);
check(substr_count($out, 'Patching acme/demo') === 1 && strpos($out, REAPPLY) === false, 'patched by cweagans only, no reinstall', $out);
check(applied($dir) !== null, 'fingerprint recorded in installed.json', $out);
check(!file_exists("$dir/.patches_applied") && !file_exists("$dir/vendor/composer/patches-reapply.hash"), 'no marker files', $out);

scenario('second install does not re-apply');
$before = applied($dir);
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched', 'still patched', $out);
check(strpos($out, REAPPLY) === false && strpos($out, 'Patching') === false, 'silent: no re-apply', $out);
check(applied($dir) === $before, 'fingerprint unchanged', $out);

scenario('changed patch (relock) re-applies on install');
relock($dir, 'patched v2');
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched v2', 'new patch applied', $out);
check(strpos($out, REAPPLY . ' patches to acme/demo') !== false, 'one-line notice names the package', $out);
check(applied($dir) !== $before && applied($dir) !== null, 'new fingerprint recorded', $out);

scenario('changed patch (relock) re-applies on update');
relock($dir, 'patched v3');
[$code, $out] = composer($dir, 'update --lock');
check($code === 0 && demo($dir) === 'patched v3', 'new patch applied by update', $out);

scenario('failing patch fails the install and is retried');
$before = applied($dir);
file_put_contents("$dir/patches/demo.patch", patchText('no such line', 'x'));
[$code, $out] = composer($dir, 'patches-relock');
check($code === 0, 'relock of a broken patch succeeds (relock does not apply)', $out);
[$code, $out] = composer($dir, 'install');
check($code !== 0, "install exits non-zero (got $code)", $out);
check(applied($dir) === $before, 'installed.json keeps the old fingerprint', $out);
[$code, $out] = composer($dir, 'install');
check($code !== 0, 'still failing on the next run (retried, not skipped)', $out);
relock($dir, 'patched v4');
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched v4', 'next install with a fixed patch re-applies', $out);

scenario('no patches: silent no-op');
$dir = makeProject(['patch' => null]);
exec('rm -rf ' . escapeshellarg("$dir/vendor"));
[$code, $out] = composer($dir, 'install');
check($code === 0, 'exit 0', $out);
check(strpos($out, REAPPLY) === false, 'no re-apply output', $out);
check(applied($dir) === null, 'no fingerprint', $out);

scenario('one of two patches removed: only its package is reinstalled, unpatched');
$dir = makeProject(['other' => 'other patched']);
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched' && demo($dir, 'other') === 'other patched', 'both patched', $out);
check(applied($dir) !== null && applied($dir, 'other') !== null, 'both fingerprinted', $out);
$before = applied($dir);
removePatch($dir, 'acme/other');
[$code, $out] = composer($dir, 'install');
check($code === 0, 'exit 0', $out);
check(demo($dir, 'other') === 'hello', 'removed patch reverted', $out);
check(demo($dir) === 'patched', 'remaining patch still applied', $out);
check(preg_match('/' . REAPPLY . ' patches to acme\/other$/m', $out) === 1, 'notice names only acme/other (acme/demo untouched)', $out);
check(applied($dir, 'other') === null && applied($dir) === $before, 'acme/other fingerprint cleared, acme/demo unchanged', $out);
[$code, $out] = composer($dir, 'install');
check($code === 0 && strpos($out, REAPPLY) === false && demo($dir, 'other') === 'hello', 'next install silent', $out);

scenario('patch removed in the same change as a failing patch: reverted on the retry');
$dir = makeProject(['other' => 'other patched']);
file_put_contents("$dir/patches/demo.patch", patchText('no such line', 'x'));
removePatch($dir, 'acme/other');
[$code, $out] = composer($dir, 'install');
check($code !== 0, "install exits non-zero (got $code)", $out);
relock($dir, 'patched fixed');
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched fixed' && demo($dir, 'other') === 'hello', 'retry applies the fixed patch and reverts the removed one', $out);

scenario('last patch removed: its package is still reverted');
removePatch($dir);
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'hello', 'last removed patch reverted', $out);
check(strpos($out, REAPPLY . ' patches to acme/demo') !== false, 'notice names the package', $out);
check(applied($dir) === null, 'fingerprint cleared', $out);
[$code, $out] = composer($dir, 'install');
check($code === 0 && strpos($out, REAPPLY) === false, 'next install silent', $out);

scenario('version bump: patched once by cweagans, no extra reinstall');
$dir = makeProject();
file_put_contents("$dir/pkgs/demo/composer.json", '{"name":"acme/demo","version":"1.0.1"}');
$json = json_decode(file_get_contents("$dir/composer.json"), true);
$json['require']['acme/demo'] = '1.0.1';
file_put_contents("$dir/composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
[$code, $out] = composer($dir, 'update acme/demo');
check($code === 0 && demo($dir) === 'patched' && strpos($out, 'Upgrading acme/demo') !== false, 'upgraded and patched', $out);
check(substr_count($out, 'Patching acme/demo') === 1 && strpos($out, REAPPLY) === false && applied($dir) !== null, 'patched once, fingerprint recorded, no reinstall', $out);

scenario('restored build (only installed.json + package dirs carried over)');
$dir = makeProject(['other' => 'other patched']);
composer($dir, 'install');
restoreBuild($dir);
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched' && demo($dir, 'other') === 'other patched', 'unchanged patches: still patched', $out);
check(strpos($out, REAPPLY) === false && strpos($out, 'Patching acme') === false, 'unchanged patches: nothing repatched', $out);
removePatch($dir, 'acme/other');
restoreBuild($dir);
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir, 'other') === 'hello' && demo($dir) === 'patched', 'removed patch reverted, other kept', $out);
check(preg_match('/' . REAPPLY . ' patches to acme\/other$/m', $out) === 1, 'only the changed package reinstalled', $out);

scenario('upgrade from v1.0.0 (no fingerprints yet)');
$dir = makeProject(['pluginDir' => v1Dir()]);
composer($dir, 'install');
$json = json_decode(file_get_contents("$dir/composer.json"), true);
$json['repositories'][1]['url'] = dirname(__DIR__);
file_put_contents("$dir/composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
[$code, $out] = composer($dir, 'update saucal/composer-patches');
check($code === 0 && demo($dir) === 'patched', 'upgrade: exit 0, patched', $out);
check(strpos($out, REAPPLY . ' patches to acme/demo') !== false && applied($dir) !== null, 'patched packages reinstalled once and fingerprinted', $out);
[$code, $out] = composer($dir, 'install');
check($code === 0 && strpos($out, REAPPLY) === false, 'next install silent', $out);
removePatch($dir);
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'hello', 'a later removal is reverted', $out);

/**
 * Runs one patch lifecycle with only the old jq hook ('jq'), only this plugin ('plugin'), or only the plugin
 * at $pluginDir (v1.0.0); returns step => whether the vendor/ state (and exit code) came out right.
 */
function lifecycle(string $mode, ?string $pluginDir = null): array
{
    $jq = $mode === 'jq';
    $dir = makeProject(['other' => 'other patched', 'plugin' => !$jq, 'pluginDir' => $pluginDir ?? dirname(__DIR__), 'scripts' => $jq ? ['post-install-cmd' => [OLD_HOOK]] : null]);
    exec('rm -rf ' . escapeshellarg("$dir/vendor") . ' ' . escapeshellarg("$dir/.patches_applied"));
    $reapplied = function (string $out) use ($jq): bool {
        return $jq ? (bool) preg_match('/^Patches hash changed!$/m', $out) : strpos($out, REAPPLY) !== false;
    };
    $ok = [];

    [$code, $out] = composer($dir, 'install');
    $ok['fresh install'] = $code === 0 && demo($dir) === 'patched' && demo($dir, 'other') === 'other patched';
    [$code, $out] = composer($dir, 'install');
    $ok['second install is silent'] = $code === 0 && !$reapplied($out) && demo($dir) === 'patched';
    relock($dir, 'patched v2');
    [$code, $out] = composer($dir, 'install');
    $ok['changed patch, install'] = $code === 0 && demo($dir) === 'patched v2';
    relock($dir, 'patched v3');
    [$code, $out] = composer($dir, 'update --lock');
    $ok['changed patch, update'] = $code === 0 && demo($dir) === 'patched v3';
    file_put_contents("$dir/patches/demo.patch", patchText('no such line', 'x'));
    composer($dir, 'patches-relock');
    [$code, $out] = composer($dir, 'install');
    $ok['failing patch exits non-zero'] = $code !== 0;
    relock($dir, 'patched v4');
    [$code, $out] = composer($dir, 'install');
    $ok['fixed patch retried'] = $code === 0 && demo($dir) === 'patched v4';
    removePatch($dir, 'acme/other');
    [$code, $out] = composer($dir, 'install');
    $ok['one of two removed'] = $code === 0 && demo($dir, 'other') === 'hello' && demo($dir) === 'patched v4';
    removePatch($dir);
    [$code, $out] = composer($dir, 'install');
    $ok['last removed'] = $code === 0 && demo($dir) === 'hello';

    return $ok;
}

scenario('parity: nothing the old jq hook got right regresses with v1.0.0 or the current plugin');
$jq = lifecycle('jq');
$old = lifecycle('plugin', v1Dir());
$plugin = lifecycle('plugin');
$word = function (bool $ok): string {
    return str_pad($ok ? 'right' : 'wrong', 8);
};
echo '     ' . str_pad('', 30) . "jq      v1.0.0  current\n";
foreach ($jq as $step => $jqOk) {
    echo '     ' . str_pad($step, 30) . $word($jqOk) . $word($old[$step]) . $word($plugin[$step]) . "\n";
    check(!$jqOk || ($old[$step] && $plugin[$step]), "$step: right with jq, so right with v1.0.0 and the current plugin");
}
check(array_keys(array_filter($old, function ($ok) {
    return !$ok;
})) === ['one of two removed', 'last removed'], 'v1.0.0 baseline: wrong only on removals (the bug fixed here)');
// Pins the jq baseline, so the parity checks above can't pass vacuously.
$jqWrong = array_keys(array_filter($jq, function ($ok) {
    return !$ok;
}));
check($jqWrong === ['changed patch, update', 'failing patch exits non-zero', 'one of two removed', 'last removed'], 'jq baseline: wrong exactly where expected (' . implode(', ', $jqWrong) . ')');
check(!in_array(false, $plugin, true), 'plugin: right on every step');

scenario('project still carrying the old jq post-install-cmd hook');
$dir = makeProject(['scripts' => ['post-install-cmd' => [OLD_HOOK]]]);
exec('rm -rf ' . escapeshellarg("$dir/vendor") . ' ' . escapeshellarg("$dir/.patches_applied"));
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched', 'fresh install: exit 0, patched', $out);
check(strpos($out, REAPPLY) === false && preg_match('/^Patches hash changed!$/m', $out), 'plugin silent (patched at install), the old hook reinstalls once more (redundant, harmless)', $out);
$fresh = $out;
[$code, $out] = composer($dir, 'install');
check($code === 0 && strpos($out, REAPPLY) === false && !preg_match('/^Patches hash changed!$/m', $out), 'second install: both silent', $out);
echo "     (old-hook fresh-install output, for the README)\n     " . str_replace("\n", "\n     ", $fresh) . "\n";

echo "\n" . ($failures ? "$failures FAILED" : 'all passed') . "\n";
exit($failures ? 1 : 0);
