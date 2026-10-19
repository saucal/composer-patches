# saucal/composer-patches

A Composer plugin. When a package's locked patches differ from the ones last applied to it, it re-applies the [cweagans/composer-patches](https://github.com/cweagans/composer-patches) v2 patches on `composer install` / `composer update`. It needs no shell and no `jq`, and it writes no marker files: what was applied is recorded in `vendor/composer/installed.json`.

Whenever cweagans patches a package during an install, the plugin records a fingerprint of that package's patches (sha256 of the locked patch entries) on its `installed.json` entry, under `extra.saucal-patches-applied`. On `post-install-cmd` and `post-update-cmd` it compares each installed package's recorded fingerprint with `patches.lock.json`, and reinstalls in-process every package that differs. cweagans then applies the current patches.
- **No patches:** it does nothing and prints nothing.
- **A patch changed or added:** only that package is reinstalled, not every patched package.
- **A patch removed (including the last one):** its package is reinstalled once, without the patch, and its fingerprint is cleared.
- **When it re-applies:** it prints one line, `Patches changed: re-applying patches to <pkgs>`.
- **If re-applying fails:** the old fingerprint stays in `installed.json` and Composer exits non-zero, so CI notices and the next run retries.

## Install

```json
"require": {
    "saucal/composer-patches": "^1.0"
},
"config": {
    "allow-plugins": {
        "cweagans/composer-patches": true,
        "saucal/composer-patches": true
    }
}
```

cweagans/composer-patches `^2.0` comes in as a dependency, so a direct `require` on it can be dropped. Both plugins must still be in `allow-plugins`, because Composer allows plugins by name whether they're required directly or not. If one is missing, Composer itself refuses a non-interactive install (CI). Note that `composer remove cweagans/composer-patches` also deletes its `allow-plugins` entry, so add that entry back afterwards.

## Why is this needed?

cweagans v2 applies patches from `patches.lock.json` on a fresh install. It misses one case: **the patch set changed, but the patched package is already installed.**

| Case | cweagans v2 alone |
|---|---|
| Fresh checkout, `composer install` from `composer.lock` + `patches.lock.json` (no `vendor/`) | **Patched** |
| Plugin activation order: is a patched package ever installed before cweagans is active? | **No.** cweagans declares `plugin-modifies-downloads: true`, so Composer installs it (and its dependency) before every other package, and it activates as soon as it's installed. |
| `vendor/` present, patch edited + `composer patches-relock`, then `composer install` | **Not patched.** "Nothing to install, update or remove": no package events fire, so cweagans never runs. |
| Same, but `composer update` | **Not patched.** Update only touches packages whose version changed. |

That gap shows up:
- in a developer's checkout after pulling a patch change;
- on any persistent build directory or deploy target that keeps `vendor/` (or an installer-paths directory such as `plugins/`);
- in CI that caches `vendor/` keyed on `composer.lock`. A patch change doesn't touch `composer.lock`, so the cache is restored and the new patch is never applied.

## Migrating from a `post-install-cmd` script hook

A common workaround is a shell hook like this one, with `.patches_applied` gitignored:

```sh
PKGS=$(jq -r '(.extra.patches // {}) | keys | join(" ")' composer.json); if [ -n "$PKGS" ]; then HASH=$(jq -r '._hash' patches.lock.json 2>/dev/null); if [ -z "$HASH" ] || [ "$(cat .patches_applied 2>/dev/null)" != "$HASH" ]; then echo "Patches hash changed!" && echo "$HASH" > .patches_applied && composer reinstall $PKGS || rm -f .patches_applied; fi; fi
```

Its weaknesses:
- It needs `jq`.
- If `.patches_applied` is committed by mistake, fresh builds match the hash and never apply the patch.
- It exits 0 when a patch fails, so CI goes green.

To migrate:
1. Remove the hook from `scripts.post-install-cmd`, and the whole key if nothing else is in it.
2. `composer require saucal/composer-patches`, add it to `allow-plugins`, and optionally drop the direct cweagans `require` (see Install).
3. The `.patches_applied` line in `.gitignore` can stay or go. Nothing writes that file any more. Delete any leftover `.patches_applied` file.

**If the old hook is left in place:** everything still works, just redundantly. Plugin listeners run before root scripts on the same event. So on a fresh install, cweagans patches (the plugin records it and stays silent), then the old hook runs `composer reinstall` once more. The final state is correct and the exit code is 0. Later installs are silent from both.

## Behaviour details

- **Where the state lives:** `vendor/composer/installed.json`, the file Composer already keeps. Composer carries unknown `extra` keys through its own rewrites of that file (checked on install, update, remove and reinstall). A build that restores `installed.json` together with the installed package dirs from a previous build (for example a deploy pipeline that commits both) reinstalls nothing when the patches haven't changed, and reverts patches removed since that build. Nothing to gitignore; the fingerprint is written only through Composer's and cweagans' APIs (`Package::setExtra()`, the local repository's own write).
- **When it's recorded:** in `post-package-install` / `post-package-update`, after cweagans' own listener (which throws if a patch fails). So a fresh install patches each package once and records it; there is no extra reinstall.
- **Upgrading from v1.0.0:** no package has a fingerprint yet, so the first install or update after the upgrade reinstalls every patched package once. A patch removed in that same change is not reverted (nothing recorded which packages were patched). Run `composer install` once after upgrading, before removing patches. The old `vendor/composer/patches-reapply.hash` is no longer read and can be deleted.
- **Why post-update too:** `composer update` / `composer require` only re-patch packages whose version changed. When nothing changed, the check is one pass over the installed packages.
- **Mechanism:** the plugin does what `composer reinstall` does, in-process: an `UninstallOperation` + `InstallOperation` per package through `InstallationManager::execute()`. That fires package events, so cweagans' listeners load the locked patches and apply them, and the plugin records the new fingerprint. It fires no script events, so the plugin doesn't re-enter itself. There's no shelling out to `composer`.
- **Failure:** cweagans throws when a patch doesn't apply, before the new fingerprint is recorded. Composer exits 1 and the next run retries.
- **`--no-scripts`:** on Composer 2.9.5 the plugin still runs, because `--no-scripts` only disables root `scripts` listeners, not plugin listeners or package events. Older 2.x releases haven't been checked.
- **Lock path:** patches are read through cweagans (`Patches::loadLockedPatches()`), so `COMPOSER=other.json` (giving `other-patches.lock.json`) agrees with cweagans. Like cweagans before an install, this creates `patches.lock.json` when it's missing.

## Limitations

The plugin is meant for patching ordinary packages such as WordPress plugins and themes, not for working around cweagans' own edge cases.

- **Composer plugins** (`composer/installers`, cweagans itself): patching these isn't supported. The reinstall doesn't put plugins first, so packages they install can land in the wrong place.
- **Path repos with `symlink: true`:** cweagans patches the source through the symlink, so the re-apply fails on every run. Use `symlink: false`.
- **`--download-only`:** still reinstalls the patched packages.
- **PHP:** the plugin code is PHP 7.4-compatible, but cweagans v2 requires **PHP >= 8.0**.
- **Patch sources:** fingerprints come from cweagans' locked patch collection, so `patches-file` and dependency-provided patches should count too; only `extra.patches` is tested.
- **Autoloader:** not re-dumped after the reinstall, since the package version and files are unchanged.
- **Version bumps:** a package moved to a new version gets a fresh `installed.json` entry; the plugin records it as cweagans patches it, so no extra reinstall.
- **`--no-plugins`:** disables this plugin, as it disables cweagans.

## Tests

```
php tests/run.php
```

The tests run real Composer. You need `composer` on `PATH` and network or cache access to Packagist. Each scenario builds a throwaway project in the system temp dir with these parts:
- `acme/demo`: a path repo with `symlink: false`, whose `file.txt` reads `hello`;
- a patch in `./patches`, listed in `extra.patches`;
- for the removal scenarios, a second package `acme/other` with its own patch;
- `patches.lock.json`, written by `composer patches-relock`;
- this plugin, installed from a path repo that points at this checkout (v1.0.0 is extracted with `git archive` for the upgrade and parity scenarios).

| Scenario | Asserts |
|---|---|
| baseline: cweagans alone, fresh install | patched without the plugin |
| baseline: cweagans alone, relock + install with `vendor/` | **not** re-patched (the gap) |
| fresh install | patched once by cweagans, no reinstall, fingerprint in `installed.json`, no marker files |
| second install | silent, still patched, fingerprint unchanged |
| relock + install | new patch applied, notice names the package, new fingerprint |
| relock + `update --lock` | new patch applied |
| failing patch | exit 1, old fingerprint kept, next run fails again; fixed patch + install then re-applies |
| no patches | exit 0, no output, no fingerprint |
| one of two patches removed | removed one reverted, other untouched (notice names only the removed one), fingerprint cleared, next run silent |
| patch removed + another patch failing | exit 1; after fixing, the retry applies the fix and reverts the removed one |
| last patch removed | reverted, fingerprint cleared, next run silent |
| version bump | `update` to 1.0.1 patches once, records the fingerprint, no reinstall |
| restored build | `vendor/` dropped except `installed.json` + package dirs: unchanged patches repatch nothing; a removed patch reverts only its package |
| upgrade from v1.0.0 | patched packages reinstalled once and fingerprinted, next run silent, later removal reverts |
| parity: jq hook vs v1.0.0 vs current | the same lifecycle (fresh, silent, changed via install/update, failing, retried, one removed, last removed) runs three times; every step jq gets right is right in both plugin versions, and the jq/v1.0.0 wrong steps are pinned |
| old script hook still present | exit 0, patched, plugin silent, old hook's redundant reinstall, later runs silent |

Tested on Composer 2.9.5 with PHP 8.2. v1.0.0's re-apply was also checked on Composer 2.0.14 and 2.2.25; this version hasn't been run there.

## License

GPL-2.0-or-later. See `LICENSE`.
