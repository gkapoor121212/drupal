<?php

namespace Drupal\Composer\Plugin\VendorHardening;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Script\Event;
use Composer\Installer\PackageEvents;
use Drupal\Component\FileSecurity\FileSecurity;

/**
 * A Composer plugin to clean out your project's vendor directory.
 *
 * This plugin will remove directory paths within installed packages. You might
 * use this in order to mitigate the security risks of having your vendor
 * directory within an HTTP server's docroot.
 *
 * @see https://www.drupal.org/docs/develop/using-composer/using-drupals-vendor-cleanup-composer-plugin
 */
class VendorHardeningPlugin implements PluginInterface, EventSubscriberInterface {

  /**
   * Composer object.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * IO object.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * Configuration.
   *
   * @var \Drupal\Composer\VendorHardening\Config
   */
  protected $config;

  /**
   * List of projects already cleaned
   *
   * @var string[]
   */
  protected $packagesAlreadyCleaned = [];

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;

    // Set up configuration.
    $this->config = new Config($this->composer->getPackage());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
      ScriptEvents::POST_UPDATE_CMD => 'onPostCmd',
      ScriptEvents::POST_INSTALL_CMD => 'onPostCmd',
      PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
      PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageUpdate',
    ];
  }

  /**
   * POST_AUTOLOAD_DUMP event handler.
   *
   * @param \Composer\Script\Event $event
   *   The Composer event.
   */
  public function onPostAutoloadDump(Event $event) {
    $this->writeAccessRestrictionFiles($this->composer->getConfig()->get('vendor-dir'));
  }

  /**
   * POST_UPDATE_CMD and POST_INSTALL_CMD event handler.
   *
   * @param \Composer\Script\Event $event
   *   The Composer event.
   */
  public function onPostCmd(Event $event) {
    $this->cleanAllPackages($this->composer->getConfig()->get('vendor-dir'));
  }

  /**
   * POST_PACKAGE_INSTALL event handler.
   *
   * @param \Composer\Installer\PackageEvent $event
   */
  public function onPostPackageInstall(PackageEvent $event) {
    /** @var \Composer\Package\CompletePackage $package */
    $package = $event->getOperation()->getPackage();
    $package_name = $package->getName();
    $this->cleanPackage($this->composer->getConfig()->get('vendor-dir'), $package_name);
  }

  /**
   * POST_PACKAGE_UPDATE event handler.
   *
   * @param \Composer\Installer\PackageEvent $event
   */
  public function onPostPackageUpdate(PackageEvent $event) {
    /** @var \Composer\Package\CompletePackage $package */
    $package = $event->getOperation()->getTargetPackage();
    $package_name = $package->getName();
    $this->cleanPackage($this->composer->getConfig()->get('vendor-dir'), $package_name);
  }

  /**
   * Gets a list of all installed packages from Composer.
   *
   * @return \Composer\Package\PackageInterface[]
   *   The list of installed packages.
   */
  protected function getInstalledPackages() {
    return $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
  }

  /**
   * Clean all configured packages.
   *
   * This applies in the context of a post-command event.
   *
   * @param string $vendor_dir
   *   Path to vendor directory
   */
  public function cleanAllPackages($vendor_dir) {
    // Get a list of all the packages available after the update or install
    // command.
    $installed_packages = [];
    foreach ($this->getInstalledPackages() as $package) {
      // Normalize package names to lower case.
      $installed_packages[strtolower($package->getName())] = $package;
    }

    // Get all the packages that we should clean up but haven't already.
    $cleanup_packages = array_diff_key($this->config->getAllCleanupPaths(), $this->packagesAlreadyCleaned);

    // Get all the packages that are installed that we should clean up.
    $packages_to_be_cleaned = array_intersect_key($cleanup_packages, $installed_packages);

    if (!$packages_to_be_cleaned) {
      $this->io->writeError('<info>Vendor directory already clean.</info>');
      return;
    }
    $this->io->writeError('<info>Cleaning vendor directory.</info>');

    foreach ($packages_to_be_cleaned as $package_name => $paths_for_package) {
      $this->cleanPathsForPackage($vendor_dir, $package_name, $paths_for_package);
    }
  }

  /**
   * Clean a single package.
   *
   * This applies in the context of a package post-install or post-update event.
   *
   * @param string $vendor_dir
   *   Path to vendor directory
   * @param string $package_name
   *   Name of the package to clean
   */
  public function cleanPackage($vendor_dir, $package_name) {
    // Normalize package names to lower case.
    $package_name = strtolower($package_name);
    if (isset($this->packagesAlreadyCleaned[$package_name])) {
      $this->io->writeError(sprintf('%s<info>%s</info> already cleaned.', str_repeat(' ', 4), $package_name), TRUE, IOInterface::VERY_VERBOSE);
      return;
    }

    $paths_for_package = $this->config->getPathsForPackage($package_name);
    if ($paths_for_package) {
      $this->io->writeError(sprintf('%sCleaning: <info>%s</info>', str_repeat(' ', 4), $package_name));
      $this->cleanPathsForPackage($vendor_dir, $package_name, $paths_for_package);
    }
  }

  /**
   * Clean the installed directories for a named package.
   *
   * @param string $vendor_dir
   *   Path to vendor directory.
   * @param string $package_name
   *   Name of package to sanitize.
   * @param string $paths_for_package
   *   List of directories in $package_name to remove
   */
  protected function cleanPathsForPackage($vendor_dir, $package_name, $paths_for_package) {
    // Whatever happens here, this package counts as cleaned so that we don't
    // process it more than once.
    $this->packagesAlreadyCleaned[$package_name] = TRUE;

    $package_dir = $vendor_dir . '/' . $package_name;
    if (!is_dir($package_dir)) {
      return;
    }

    $this->io->writeError(sprintf('%sCleaning directories in <comment>%s</comment>', str_repeat(' ', 4), $package_name), TRUE, IOInterface::VERY_VERBOSE);
    $fs = new Filesystem();
    foreach ($paths_for_package as $cleanup_item) {
      $cleanup_path = $package_dir . '/' . $cleanup_item;
      if (!is_dir($cleanup_path)) {
        // If the package has changed or the --prefer-dist version does not
        // include the directory. This is not an error.
        $this->io->writeError(sprintf("%s<comment>Directory '%s' does not exist.</comment>", str_repeat(' ', 6), $cleanup_path), TRUE, IOInterface::VERY_VERBOSE);
        continue;
      }

      if (!$fs->removeDirectory($cleanup_path)) {
        // Always display a message if this fails as it means something
        // has gone wrong. Therefore the message has to include the
        // package name as the first informational message might not
        // exist.
        $this->io->writeError(sprintf("%s<error>Failure removing directory '%s'</error> in package <comment>%s</comment>.", str_repeat(' ', 6), $cleanup_item, $package_name), TRUE, IOInterface::NORMAL);
        continue;
      }

      $this->io->writeError(sprintf("%sRemoving directory <info>'%s'</info>", str_repeat(' ', 4), $cleanup_item), TRUE, IOInterface::VERBOSE);
    }
  }

  /**
   * Place .htaccess and web.config files into the vendor directory.
   *
   * @param string $vendor_dir
   *   Path to vendor directory.
   */
  public function writeAccessRestrictionFiles($vendor_dir) {
    $this->io->writeError('<info>Hardening vendor directory with .htaccess and web.config files.</info>');
    // Prevent access to vendor directory on Apache servers.
    FileSecurity::writeHtaccess($vendor_dir, TRUE);

    // Prevent access to vendor directory on IIS servers.
    FileSecurity::writeWebConfig($vendor_dir);
  }

}
