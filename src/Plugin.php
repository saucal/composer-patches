<?php

namespace Saucal\ComposerPatches;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use cweagans\Composer\PatchCollection;
use cweagans\Composer\Plugin\Patches;

/**
 * Records, on each package's installed.json entry, a fingerprint of the patches cweagans applied to it.
 * After install/update, reinstalls (so cweagans re-applies the locked patches) every package whose
 * recorded fingerprint differs from patches.lock.json: changed, added and removed patches alike.
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** Key in the installed.json package's "extra" holding the applied patches' fingerprint. */
    const EXTRA_KEY = 'saucal-patches-applied';

    /** cweagans never patches itself (see Patches::patchPackage). */
    const UNPATCHABLE = ['cweagans/composer-patches', 'cweagans/composer-configurable-plugin'];

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
    }

    public static function getSubscribedEvents(): array
    {
        // Update too: `composer update`/`require` only re-patch packages whose version changed.
        return [
            // Below cweagans' patchPackage (priority 10), which throws on a failed patch: recorded means applied.
            PackageEvents::POST_PACKAGE_INSTALL => ['record', -10],
            PackageEvents::POST_PACKAGE_UPDATE => ['record', -10],
            ScriptEvents::POST_INSTALL_CMD => 'reapply',
            ScriptEvents::POST_UPDATE_CMD => 'reapply',
        ];
    }

    public function record(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        $name = ($operation instanceof UpdateOperation ? $operation->getTargetPackage() : $operation->getPackage())->getName();
        $patches = $this->cweagans();
        if (!$patches || in_array($name, self::UNPATCHABLE, true)) {
            return;
        }
        try {
            $collection = $patches->getPatchCollection();
        } catch (\Error $e) {
            // Typed property not initialized yet: cweagans loaded no patches, so it patched nothing either.
            return;
        }
        // The installer adds a clone of the operation's package; installed.json is written from that one
        // at the end of InstallationManager::execute().
        foreach ($event->getLocalRepo()->getCanonicalPackages() as $package) {
            if ($package->getName() === $name && $package instanceof Package) {
                $extra = $package->getExtra();
                $fingerprint = $collection ? self::fingerprint($collection, $name) : null;
                if ($fingerprint === null) {
                    unset($extra[self::EXTRA_KEY]);
                } else {
                    $extra[self::EXTRA_KEY] = $fingerprint;
                }
                $package->setExtra($extra);
            }
        }
    }

    public function reapply(Event $event): void
    {
        $patches = $this->cweagans();
        if (!$patches) {
            return;
        }
        // Same as cweagans before any install: read patches.lock.json, creating it when missing.
        $patches->loadLockedPatches();
        $collection = $patches->getPatchCollection();

        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $packages = array_values(array_filter($localRepo->getCanonicalPackages(), function ($package) use ($collection) {
            $extra = $package->getExtra();
            return !in_array($package->getName(), self::UNPATCHABLE, true)
                && ($extra[self::EXTRA_KEY] ?? null) !== self::fingerprint($collection, $package->getName());
        }));
        if (!$packages) {
            return;
        }

        $this->io->write('<info>Patches changed: re-applying patches to ' . implode(', ', array_map(function ($package) {
            return $package->getName();
        }, $packages)) . '</info>');

        // Same as `composer reinstall`, in-process: the install operations fire cweagans' package events,
        // which re-apply the locked patches, and record() above. No script events, so this doesn't recurse.
        // A failed patch throws before installed.json gets the new fingerprint, so the next run retries.
        $uninstall = $install = [];
        foreach ($packages as $package) {
            $uninstall[] = new UninstallOperation($package);
            $install[] = new InstallOperation($package);
        }
        $installationManager = $this->composer->getInstallationManager();
        $installationManager->execute($localRepo, $uninstall, $event->isDevMode());
        $installationManager->execute($localRepo, $install, $event->isDevMode());
    }

    private function cweagans(): ?Patches
    {
        foreach ($this->composer->getPluginManager()->getPlugins() as $plugin) {
            if ($plugin instanceof Patches) {
                return $plugin;
            }
        }

        return null;
    }

    /** Null when the package has no patches. */
    private static function fingerprint(PatchCollection $collection, string $name): ?string
    {
        $patches = $collection->getPatchesForPackage($name);

        return $patches ? hash('sha256', json_encode($patches)) : null;
    }
}
