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

/** A project with acme/demo (file.txt = "hello"), optionally patched, optionally with this plugin, locked + installed. */
function makeProject(array $o = []): string
{
    $o += ['patch' => 'patched', 'plugin' => true, 'scripts' => null];
    $dir = sys_get_temp_dir() . '/cpr-' . bin2hex(random_bytes(4));
    mkdir("$dir/pkgs/demo", 0777, true);
    file_put_contents("$dir/pkgs/demo/composer.json", '{"name":"acme/demo","version":"1.0.0"}');
    file_put_contents("$dir/pkgs/demo/file.txt", "hello\n");

    $json = [
        'name' => 'test/project',
        'version' => '1.0.0',
        'require' => ['acme/demo' => '1.0.0', 'cweagans/composer-patches' => '^2.0'],
        'repositories' => [
            // symlink:false or cweagans would patch the fixture source itself.
            ['type' => 'path', 'url' => './pkgs/demo', 'options' => ['symlink' => false]],
            ['type' => 'path', 'url' => dirname(__DIR__)],
        ],
        'config' => ['allow-plugins' => ['cweagans/composer-patches' => true, 'saucal/composer-patches' => true]],
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
    ];
    if ($o['plugin']) {
        $json['require']['saucal/composer-patches'] = '*';
    }
    if ($o['patch'] !== null) {
        mkdir("$dir/patches");
        file_put_contents("$dir/patches/demo.patch", patchText('hello', $o['patch']));
        $json['extra']['patches']['acme/demo'] = [['description' => 'demo', 'url' => './patches/demo.patch', 'depth' => 1]];
    }
    if ($o['scripts']) {
        $json['scripts'] = $o['scripts'];
    }
    file_put_contents("$dir/composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    [$code, $out] = composer($dir, 'update');
    check($code === 0, "fixture update ($dir)", $out);
    if ($o['patch'] !== null) {
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

function demo(string $dir): string
{
    return trim((string) @file_get_contents("$dir/vendor/acme/demo/file.txt"));
}

function marker(string $dir): string
{
    return "$dir/vendor/composer/patches-reapply.hash";
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

scenario('fresh install applies the patch');
$dir = makeProject();
exec('rm -rf ' . escapeshellarg("$dir/vendor"));
[$code, $out] = composer($dir, 'install');
check($code === 0, 'exit 0', $out);
check(demo($dir) === 'patched', 'file patched', $out);
check(substr_count($out, REAPPLY) === 1, 'one re-apply line (no marker on a fresh vendor/)', $out);
check(trim((string) @file_get_contents(marker($dir))) === json_decode(file_get_contents("$dir/patches.lock.json"), true)['_hash'], 'marker holds the lock hash', $out);
check(!file_exists("$dir/.patches_applied"), 'nothing written to the repo root', $out);

scenario('second install does not re-apply');
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched', 'still patched', $out);
check(strpos($out, REAPPLY) === false && strpos($out, 'Patching') === false, 'silent: no re-apply', $out);

scenario('changed patch (relock) re-applies on install');
relock($dir, 'patched v2');
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched v2', 'new patch applied', $out);
check(strpos($out, REAPPLY . ' patches to acme/demo') !== false, 'one-line notice names the package', $out);

scenario('changed patch (relock) re-applies on update');
relock($dir, 'patched v3');
[$code, $out] = composer($dir, 'update --lock');
check($code === 0 && demo($dir) === 'patched v3', 'new patch applied by update', $out);

scenario('failing patch clears the marker and fails the install');
file_put_contents("$dir/patches/demo.patch", patchText('no such line', 'x'));
[$code, $out] = composer($dir, 'patches-relock');
check($code === 0, 'relock of a broken patch succeeds (relock does not apply)', $out);
[$code, $out] = composer($dir, 'install');
check($code !== 0, "install exits non-zero (got $code)", $out);
check(!file_exists(marker($dir)), 'marker cleared', $out);
relock($dir, 'patched v4');
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched v4', 'next install with a fixed patch re-applies', $out);

scenario('no patches: silent no-op');
$dir = makeProject(['patch' => null]);
exec('rm -rf ' . escapeshellarg("$dir/vendor"));
[$code, $out] = composer($dir, 'install');
check($code === 0, 'exit 0', $out);
check(strpos($out, REAPPLY) === false, 'no re-apply output', $out);
check(!file_exists(marker($dir)), 'no marker', $out);

scenario('project still carrying the old jq post-install-cmd hook');
$dir = makeProject(['scripts' => ['post-install-cmd' => [OLD_HOOK]]]);
exec('rm -rf ' . escapeshellarg("$dir/vendor") . ' ' . escapeshellarg("$dir/.patches_applied"));
[$code, $out] = composer($dir, 'install');
check($code === 0 && demo($dir) === 'patched', 'fresh install: exit 0, patched', $out);
check(substr_count($out, REAPPLY) === 1 && preg_match('/^Patches hash changed!$/m', $out), 'plugin re-applies, then the old hook reinstalls again (redundant, harmless)', $out);
$fresh = $out;
[$code, $out] = composer($dir, 'install');
check($code === 0 && strpos($out, REAPPLY) === false && !preg_match('/^Patches hash changed!$/m', $out), 'second install: both silent', $out);
echo "     (old-hook fresh-install output, for the README)\n     " . str_replace("\n", "\n     ", $fresh) . "\n";

echo "\n" . ($failures ? "$failures FAILED" : 'all passed') . "\n";
exit($failures ? 1 : 0);
