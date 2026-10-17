# saucal/composer-patches

A Composer plugin. When `patches.lock.json` has changed since the patches were last applied, it re-applies the [cweagans/composer-patches](https://github.com/cweagans/composer-patches) v2 patches on `composer install` / `composer update`. It needs no shell and no `jq`, and it writes no marker to the repo root.

On `post-install-cmd` and `post-update-cmd`, if `extra.patches` names any packages and `patches.lock.json`'s `_hash` differs from the hash stored at `vendor/composer/patches-reapply.hash`, it reinstalls exactly those packages in-process. cweagans then re-applies their patches, and the plugin stores the new hash.
- **No patches:** it does nothing and prints nothing.
- **When it re-applies:** it prints one line, `Patches changed: re-applying patches to <pkgs>`.
- **If re-applying fails:** it deletes the marker and Composer exits non-zero, so CI notices and the next run retries.

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

**If the old hook is left in place:** everything still works, just redundantly. Plugin listeners run before root scripts on the same event. So on a fresh install, cweagans patches, then this plugin re-applies, then the old hook runs `composer reinstall` once more. The final state is correct and the exit code is 0. Later installs are silent from both.

## Behaviour details

- **Marker location:** `<vendor-dir>/composer/patches-reapply.hash`. Fresh checkouts never have one, so they always apply, and there's nothing to gitignore.
- **A fresh install reinstalls the patched packages once more:** cweagans patches during install, then the plugin reinstalls because there's no marker yet. It's a clean uninstall + install, so the patch is never applied twice to the same files.
- **Why post-update too:** `composer update` / `composer require` only re-patch packages whose version changed. When nothing changed, the check costs one file read.
- **Mechanism:** the plugin does what `composer reinstall` does, in-process: an `UninstallOperation` + `InstallOperation` per package through `InstallationManager::execute()`. That fires package events, so cweagans' listeners load the locked patches and apply them. It fires no script events, so the plugin doesn't re-enter itself. There's no shelling out to `composer`.
- **Failure:** cweagans throws when a patch doesn't apply. The marker was already deleted, so Composer exits 1 and the next run retries.
- **`--no-scripts`:** on Composer 2.9.5 the plugin still runs, because `--no-scripts` only disables root `scripts` listeners, not plugin listeners or package events. Older 2.x releases haven't been checked.
- **Lock path:** taken from cweagans' `Patches::getPatchesLockFilePath()`, so `COMPOSER=other.json` (giving `other-patches.lock.json`) agrees with cweagans.

## Limitations

The plugin is meant for patching ordinary packages such as WordPress plugins and themes, not for working around cweagans' own edge cases.

- **Composer plugins** (`composer/installers`, cweagans itself): patching these isn't supported. The reinstall doesn't put plugins first, so packages they install can land in the wrong place.
- **Path repos with `symlink: true`:** cweagans patches the source through the symlink, so the re-apply fails on every run. Use `symlink: false`.
- **`--download-only`:** still reinstalls the patched packages.
- **PHP:** the plugin code is PHP 7.4-compatible, but cweagans v2 requires **PHP >= 8.0**.
- **Patch sources:** only `extra.patches` is read. cweagans' `patches-file` and dependency-provided patches aren't covered.
- **Autoloader:** not re-dumped after the reinstall, since the package version and files are unchanged.
- **`--no-plugins`:** disables this plugin, as it disables cweagans.

## Tests

```
php tests/run.php
```

The tests run real Composer. You need `composer` on `PATH` and network or cache access to Packagist. Each scenario builds a throwaway project in the system temp dir with these parts:
- `acme/demo`: a path repo with `symlink: false`, whose `file.txt` reads `hello`;
- a patch in `./patches`, listed in `extra.patches`;
- `patches.lock.json`, written by `composer patches-relock`;
- this plugin, installed from a path repo that points at this checkout.

| Scenario | Asserts |
|---|---|
| baseline: cweagans alone, fresh install | patched without the plugin |
| baseline: cweagans alone, relock + install with `vendor/` | **not** re-patched (the gap) |
| fresh install | patched, one re-apply line, marker = lock `_hash`, nothing in repo root |
| second install | silent, still patched |
| relock + install | new patch applied, notice names the package |
| relock + `update --lock` | new patch applied |
| failing patch | exit 1, marker removed; fixed patch + install then re-applies |
| no patches | exit 0, no output, no marker |
| old script hook still present | exit 0, patched, redundant reinstall, later runs silent |

Tested on Composer 2.9.5 with PHP 8.2. The re-apply was also checked on Composer 2.0.14 and 2.2.25.
