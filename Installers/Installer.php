<?php

namespace Pixelion\Composer\Installers;

use Composer\Composer;
use Composer\Installer\BinaryInstaller;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;

class Installer extends LibraryInstaller
{
    const EXTRA_BOOTSTRAP = \yii\composer\Installer::EXTRA_BOOTSTRAP;
    const EXTENSION_FILE = \yii\composer\Installer::EXTENSION_FILE;
    /**
     * Package types to installer class map
     *
     * @var array
     */
    private $supportedTypes = array(
        'pixelion' => 'PixelionInstaller',
    );

    /**
     * Installer constructor.
     *
     * Disables installers specified in main composer extra installer-disable
     * list
     *
     * @param IOInterface $io
     * @param Composer $composer
     * @param string $type
     * @param Filesystem|null $filesystem
     * @param BinaryInstaller|null $binaryInstaller
     */
    public function __construct(
        IOInterface $io,
        Composer $composer,
        $type = 'library',
        Filesystem $filesystem = null,
        BinaryInstaller $binaryInstaller = null
    )
    {
        parent::__construct($io, $composer, $type, $filesystem,
            $binaryInstaller);
        $this->removeDisabledInstallers();
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        $type = $package->getType();
        $frameworkType = $this->findFrameworkType($type);

        if ($frameworkType === false) {
            throw new \InvalidArgumentException(
                'Sorry the package type of this package is not yet supported.'
            );
        }

        $class = 'Pixelion\\Composer\\Installers\\' . $this->supportedTypes[$frameworkType];
        $installer = new $class($package, $this->composer, $this->getIO());

        return $installer->getInstallPath($package, $frameworkType);
    }

    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $afterInstall = function () use ($package) {
            // add the package to yiisoft/extensions.php
            $this->addPackage($package);
            // ensure the yii2-dev package also provides Yii.php in the same place as yii2 does
            //if ($package->getName() == 'yiisoft/yii2-dev') {
            //    $this->linkBaseYiiFiles();
            //}
        };

        // install the package the normal composer way
        $promise = parent::install($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($afterInstall);
        }

        // If not, execute the code right away as parent::install executed synchronously (composer v1, or v2 without async)
        $afterInstall();
    }

    protected function addPackage(PackageInterface $package)
    {

        $extension = [
            'name' => $package->getName(),
            'version' => $package->getVersion(),
            'type' => $package->getType(),
        ];

        $alias = $this->generateDefaultAlias($package);

        if (!empty($alias)) {
            $extension['alias'] = $alias;
        }
        $extra = $package->getExtra();
        if (isset($extra[self::EXTRA_BOOTSTRAP])) {
            $extension['bootstrap'] = $extra[self::EXTRA_BOOTSTRAP];
        }

        $extensions = $this->loadExtensions();
        $extensions[$package->getName()] = $extension;
        $this->saveExtensions($extensions);
    }

    protected function removePackage(PackageInterface $package)
    {
        $packages = $this->loadExtensions();
        unset($packages[$package->getName()]);
        $this->saveExtensions($packages);
    }

    protected function loadExtensions()
    {
        $file = $this->vendorDir . '/' . static::EXTENSION_FILE;
        if (!is_file($file)) {
            return [];
        }
        // invalidate opcache of extensions.php if exists
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        $extensions = require($file);

        $vendorDir = str_replace('\\', '/', $this->vendorDir);
        $n = strlen($vendorDir);

        foreach ($extensions as &$extension) {
            if (isset($extension['alias'])) {
                foreach ($extension['alias'] as $alias => $path) {
                    $path = str_replace('\\', '/', $path);
                    if (strpos($path . '/', $vendorDir . '/') === 0) {
                        $extension['alias'][$alias] = '<vendor-dir>' . substr($path, $n);
                    }
                }
            }
        }

        return $extensions;
    }

    protected function generateDefaultAlias(PackageInterface $package)
    {
        $fs = new Filesystem;
        $vendorDir = $fs->normalizePath($this->vendorDir);
        $autoload = $package->getAutoload();

        $aliases = [];

        if (!empty($autoload['psr-0'])) {
            foreach ($autoload['psr-0'] as $name => $path) {
                $name = str_replace('\\', '/', trim($name, '\\'));
                if (!$fs->isAbsolutePath($path)) {
                    $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
                }
                $path = $fs->normalizePath($path);
                if (strpos($path . '/', $vendorDir . '/') === 0) {
                    $aliases["@$name"] = '<vendor-dir>' . substr($path, strlen($vendorDir)) . '/' . $name;
                } else {
                    $aliases["@$name"] = $path . '/' . $name;
                }
            }
        }

        if (!empty($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $name => $path) {
                if (is_array($path)) {
                    // ignore psr-4 autoload specifications with multiple search paths
                    // we can not convert them into aliases as they are ambiguous
                    continue;
                }
                $name = str_replace('\\', '/', trim($name, '\\'));
                if (!$fs->isAbsolutePath($path)) {
                    $path = $this->vendorDir . '/' . $package->getPrettyName() . '/' . $path;
                }
                $path = $fs->normalizePath($path);
                if (strpos($path . '/', $vendorDir . '/') === 0) {
                    $aliases["@$name"] = '<vendor-dir>' . substr($path, strlen($vendorDir));
                } else {
                    $aliases["@$name"] = $path;
                }
            }
        }

        return $aliases;
    }

    protected function saveExtensions(array $extensions)
    {
        $file = $this->vendorDir . '/' . static::EXTENSION_FILE;
        if (!file_exists(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        $array = str_replace("'<vendor-dir>", '$vendorDir . \'', var_export($extensions, true));
        file_put_contents($file, "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn $array;\n");
        // invalidate opcache of extensions.php if exists
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $afterUpdate = function () use ($initial, $target) {
            $this->removePackage($initial);
            $this->addPackage($target);
        };

        // update the package the normal composer way
        $promise = parent::update($repo, $initial, $target);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($afterUpdate);
        }

        // If not, execute the code right away as parent::update executed synchronously (composer v1, or v2 without async)
        $afterUpdate();
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {

        $afterUninstall = function () use ($package) {
            // remove the package from yiisoft/extensions.php
            $this->removePackage($package);
        };


        $installPath = $this->getPackageBasePath($package);
        $io = $this->io;
        $outputStatus = function () use ($io, $installPath) {
            $io->write(sprintf('Deleting %s - %s', $installPath, !file_exists($installPath) ? '<comment>deleted</comment>' : '<error>not deleted</error>'));
        };

        $promise = parent::uninstall($repo, $package);

        // Composer v2 might return a promise here
        if ($promise instanceof PromiseInterface) {
            return $promise->then($outputStatus);
        }

        // If not, execute the code right away as parent::uninstall executed synchronously (composer v1, or v2 without async)
        $outputStatus();
        $afterUninstall();
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        $frameworkType = $this->findFrameworkType($packageType);

        if ($frameworkType === false) {
            return false;
        }

        $locationPattern = $this->getLocationPattern($frameworkType);

        return preg_match('#' . $frameworkType . '-' . $locationPattern . '#', $packageType, $matches) === 1;
    }

    /**
     * Finds a supported framework type if it exists and returns it
     *
     * @param string $type
     * @return string|false
     */
    protected function findFrameworkType($type)
    {
        krsort($this->supportedTypes);

        foreach ($this->supportedTypes as $key => $val) {
            if ($key === substr($type, 0, strlen($key))) {
                return substr($type, 0, strlen($key));
            }
        }

        return false;
    }

    /**
     * Get the second part of the regular expression to check for support of a
     * package type
     *
     * @param string $frameworkType
     * @return string
     */
    protected function getLocationPattern($frameworkType)
    {
        $pattern = false;
        if (!empty($this->supportedTypes[$frameworkType])) {
            $frameworkClass = 'Pixelion\\Composer\\Installers\\' . $this->supportedTypes[$frameworkType];
            /** @var BaseInstaller $framework */
            $framework = new $frameworkClass(null, $this->composer, $this->getIO());
            $locations = array_keys($framework->getLocations());
            $pattern = $locations ? '(' . implode('|', $locations) . ')' : false;
        }

        return $pattern ?: '(\w+)';
    }

    /**
     * Get I/O object
     *
     * @return IOInterface
     */
    private function getIO()
    {
        return $this->io;
    }

    /**
     * Look for installers set to be disabled in composer's extra config and
     * remove them from the list of supported installers.
     *
     * Globals:
     *  - true, "all", and "*" - disable all installers.
     *  - false - enable all installers (useful with
     *     wikimedia/composer-merge-plugin or similar)
     *
     * @return void
     */
    protected function removeDisabledInstallers()
    {
        $extra = $this->composer->getPackage()->getExtra();

        if (!isset($extra['installer-disable']) || $extra['installer-disable'] === false) {
            // No installers are disabled
            return;
        }

        // Get installers to disable
        $disable = $extra['installer-disable'];

        // Ensure $disabled is an array
        if (!is_array($disable)) {
            $disable = array($disable);
        }

        // Check which installers should be disabled
        $all = array(true, "all", "*");
        $intersect = array_intersect($all, $disable);
        if (!empty($intersect)) {
            // Disable all installers
            $this->supportedTypes = array();
        } else {
            // Disable specified installers
            foreach ($disable as $key => $installer) {
                if (is_string($installer) && key_exists($installer, $this->supportedTypes)) {
                    unset($this->supportedTypes[$installer]);
                }
            }
        }
    }
    
    
        /**
     * Special method to run tasks defined in `[extra][panix\composer\Installer::postCreateProject]` key in `composer.json`
     *
     * @param Event $event
     */
    public static function postCreateProject($event)
    {
        static::runCommands($event, __METHOD__);
    }

    /**
     * Special method to run tasks defined in `[extra][$extraKey]` key in `composer.json`
     *
     * @param Event $event
     * @param string $extraKey
     */
    protected static function runCommands($event, $extraKey)
    {
        $params = $event->getComposer()->getPackage()->getExtra();
        if (isset($params[$extraKey]) && is_array($params[$extraKey])) {
            foreach ($params[$extraKey] as $method => $args) {
                call_user_func_array([__CLASS__, $method], (array)$args);
            }
        }
    }

    /**
     * Create directory and permissions for those listed in the additional section.
     * @param array $paths the paths (keys) and the corresponding permission octal strings (values)
     */
    public static function createDir(array $paths)
    {
        foreach ($paths as $path => $permission) {
            if (!file_exists($path)) {
                echo "mkdir('$path', $permission)...";
                try {
                    mkdir($path, $permission);
                    echo "done.\n";
                } catch (\Exception $e) {
                    echo $e->getMessage() . "\n";
                }
            } else {
                echo "dir already exist.\n";
            }
        }
    }
    
    
    
    public static function settingsDb(array $configPaths)
    {

        echo "Settings database configure?: say \e[36m\"yes\"\e[0m for continue.\n";


        $handle = fopen("php://stdin", "r");
        $dbComfirm = trim(fgets($handle));
        if ($dbComfirm != 'yes') {
            echo "\e[31mSettings database canceled!\e[0m\n";
            exit;
        }

        echo "Database driver: (default \"mysql\"): Press \e[36m\"enter\"\e[0m for default setting.\n";
        $dbDriver = trim(fgets($handle));
        if ($dbDriver == '' || empty($dbDriver)) {
            $dbDriver = 'mysql';
            //echo "Set port {$dbDriver}!\n";
        }
        if (!in_array($dbDriver, array('mysql', 'sqlite', 'pgsql', 'mssql', 'oci'))) {
            echo "\e[31mDatabase driver: \"{$dbDriver}\" Error!\e[0m\n";
            exit;
        }


        echo "Database host: (default \"localhost\"): Press \e[36m\"enter\"\e[0m for default setting.\n";
        $dbHost = trim(fgets($handle));
        if ($dbHost == '' || empty($dbHost)) {
            $dbHost = 'localhost';
            //echo "Set host {$dbHost}!\n";
        }

        if (!in_array($dbDriver, array('oci', 'sqlite'))) {
            echo "Database post: (default \"3306\"): Press \e[36m\"enter\"\e[0m for default setting.\n";
            $dbPort = trim(fgets($handle));
            if ($dbPort == '' || empty($dbPort)) {
                $dbPort = '3306';
                //echo "Set port {$dbPort}!\n";
            }
        }

        echo "Database name:\n";
        $dbName = trim(fgets($handle));
        while ($dbName == '' || empty($dbName)) {
            $dbName = trim(fgets($handle));
            if ($dbName == '' || empty($dbName)) {
                echo "\e[31mDatabase name cannot be empty!\e[0m\n";
            }

        }

        echo "Database user:\n";
        $dbUser = trim(fgets($handle));
        while ($dbUser == '' || empty($dbUser)) {
            $dbUser = trim(fgets($handle));
            if ($dbUser == '' || empty($dbUser)) {
                echo "\e[31mDatabase user cannot be empty!\e[0m\n";
            }
        }
        if (!in_array($dbDriver, array('oci', 'sqlite'))) {
            echo "Database password:\n";
            $dbPwd = trim(fgets($handle));
        }


        if (in_array($dbDriver, array('mysql', 'pgsql'))) {
            $dsn = strtr('{driver}:host={host};port={port};dbname={dbname}', array(
                '{host}' => $dbHost,
                '{dbname}' => $dbName,
                '{driver}' => $dbDriver,
                '{port}' => $dbPort
            ));
        } elseif (in_array($dbDriver, array('oci', 'sqlite'))) {
            $dsn = strtr('{driver}:dbname={host}/{db_name}', array(
                '{host}' => $dbHost,
                '{dbname}' => $dbName,
                '{driver}' => $dbDriver,
            ));
        }
        $connStatus = false;
        try {
            $conn = new \PDO($dsn, $dbUser, $dbPwd);
            $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $connStatus = $conn;
        } catch (\Exception $e) {
            $connStatus = false;
        }

        if (!$connStatus) {
            echo "\e[31mFailed to connect to database \"$dbName\"!\e[0m\n";
            exit;
        } else {
            echo "\e[32mСonnect to database \"$dbName\" successfully!\e[0m\n";
        }


        echo "Database tables prefix: (default \"Generate RAND(4)\"): Press \e[32m\"enter\"\e[0m for default setting.\n";
        $dbPrefix = trim(fgets($handle));
        if ($dbPrefix == '' || empty($dbPrefix)) {
            $dbPrefix = \panix\engine\CMS::gen(4);
            //echo "Set tables prefix: {$dbPrefix}!\n";
        }


        
        foreach ($configPaths as $file) {
            $content = file_get_contents($file);
            $content = preg_replace("/\'dsn\'\s*\=\>\s*\'.*\'/", "'dsn'=>'{$dsn}'", $content);
            $content = preg_replace("/\'username\'\s*\=\>\s*\'.*\'/", "'username'=>'{$dbUser}'", $content);
            $content = preg_replace("/\'password\'\s*\=\>\s*\'.*\'/", "'password'=>'{$dbPwd}'", $content);
            $content = preg_replace("/\'tablePrefix\'\s*\=\>\s*\'.*\'/", "'tablePrefix'=>'{$dbPrefix}_'", $content);
            file_put_contents($file, $content);
        }
        echo "Database config:\n";
        echo "Connection driver: \e[36m{$dbDriver} / {$dbHost}:{$dbPort}\e[0m\n";
        echo "DB Name: \e[36m{$dbName}\e[0m\n";
        echo "DB User: \e[36m{$dbUser}\e[0m\n";
        echo "DB Password: \e[36m{$dbPwd}\e[0m\n";
        echo "Tables prefix: \e[36m{$dbPrefix}\e[0m\n";
        echo "\e[32mConfigure done.\e[0m\n";


        fclose($handle);
        echo "\n";
        echo "\e[36mThank you.\e[0m\n";

    }
}
