<?php

namespace Saucal\ComposerPatches;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use cweagans\Composer\Plugin\Patches;

/**
 * Reinstalls the patched packages (so cweagans re-applies their patches) whenever
 * patches.lock.json's _hash differs from the hash last applied in this vendor dir.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer */
    private $composer;

    /** @var IOInterface */
    private $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        @unlink(self::markerPath($composer));
    }

    public static function getSubscribedEvents(): array
    {
        // Update too: `composer update`/`require` only re-patch packages whose version changed.
        return [
            ScriptEvents::POST_INSTALL_CMD => 'reapply',
            ScriptEvents::POST_UPDATE_CMD => 'reapply',
        ];
    }

    public function reapply(Event $event): void
    {
        // shortcut: only extra.patches is read (like the old hook), not cweagans' patches-file or
        // dependency patches; read patches.lock.json's "patches" keys instead if those are ever used.
        $extra = $this->composer->getPackage()->getExtra();
        $names = array_keys(isset($extra['patches']) && is_array($extra['patches']) ? $extra['patches'] : []);
        if (!$names) {
            return;
        }

        $marker = self::markerPath($this->composer);
        $hash = self::lockHash();
        if ($hash !== '' && is_file($marker) && trim((string) file_get_contents($marker)) === $hash) {
            return;
        }

        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $packages = array_values(array_filter($localRepo->getCanonicalPackages(), function ($package) use ($names) {
            return in_array($package->getName(), $names, true);
        }));
        if (!$packages) {
            return;
        }

        $this->io->write('<info>Patches changed: re-applying patches to ' . implode(', ', array_map(function ($package) {
            return $package->getName();
        }, $packages)) . '</info>');

        // Cleared first so a failed re-apply (cweagans throws, Composer exits non-zero) is retried next run.
        @unlink($marker);

        // Same as `composer reinstall`, in-process: the install operations fire cweagans' package events,
        // which re-apply the locked patches. No script events, so this handler doesn't recurse.
        $uninstall = $install = [];
        foreach ($packages as $package) {
            $uninstall[] = new UninstallOperation($package);
            $install[] = new InstallOperation($package);
        }
        $installationManager = $this->composer->getInstallationManager();
        $installationManager->execute($localRepo, $uninstall, $event->isDevMode());
        $installationManager->execute($localRepo, $install, $event->isDevMode());

        // Re-read: cweagans creates patches.lock.json during the reinstall when it was missing.
        $hash = self::lockHash();
        if ($hash !== '') {
            file_put_contents($marker, $hash . "\n");
        }
    }

    private static function lockHash(): string
    {
        $lock = json_decode((string) @file_get_contents(Patches::getPatchesLockFilePath()), true);

        return is_array($lock) && isset($lock['_hash']) && is_string($lock['_hash']) ? $lock['_hash'] : '';
    }

    private static function markerPath(Composer $composer): string
    {
        // In vendor/, not the repo root: nothing to gitignore, and a fresh checkout has no marker.
        return $composer->getConfig()->get('vendor-dir') . '/composer/patches-reapply.hash';
    }
}
