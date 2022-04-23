<?php

namespace Radic\ComposerExcludeClassmapPlugin;

use Composer\Composer;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use UnexpectedValueException;

class ExcludeClassmapPlugin implements PluginInterface, EventSubscriberInterface
{

    protected $composer;

    protected $io;

    protected $logger;

    protected $vendorDir;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;
        $this->logger   = new Logger('exclude-classmap', $io);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public function excludePackageClassmaps()
    {
        $this->vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $locker          = $this->composer->getLocker();
        $data            = $locker->getLockData();
        $repo            = $locker->getLockedRepository();
        if ($plugin = $this->getWikimediaPlugin()) {

            $ref      = new \ReflectionClass($plugin);
            $stateRef = $ref->getProperty('state');
            $stateRef->setAccessible(true);
            /** @var \Wikimedia\Composer\Merge\V2\PluginState $state */
            $state = $stateRef->getValue($plugin);
            $state->loadSettings();;
            $includes = $state->getIncludes();
            $requires = $state->getRequires();
            foreach ($this->getFilePathsFromGlobs($includes) as $paths) {
                foreach ($paths as $path) {
                    $json    = $this->readPackageJson($path);
                    $package = $this->loadPackage($json);
                    $this->addPackageExcludeFromClassmap($package, $path);
                    continue;
                }
            }
        }
        foreach ($repo->getPackages() as $package) {
            $this->addPackageExcludeFromClassmap($package);
//            $autoload = $package->getAutoload();
//            if ( ! isset($autoload[ 'exclude-from-classmap' ])) {
//                return;
//            }
            continue;
        }
        return;
    }

    protected function addPackageExcludeFromClassmap($package, $composerPath = null)
    {

        if ($package instanceof CompletePackage == false) {
            return;
        }
        $extra     = $package->getExtra();
        $overrides = [];

        $autoload = $package->getAutoload();
        if (isset($extra[ 'exclude' ])) {
            $overrides = ['exclude' => $extra[ 'exclude' ]];
        }
        if (isset($overrides[ 'exclude' ])) {
            $overrides[ 'exclude' ] = array_map(function ($path) {
                $path2 = preg_replace('/^(.*?)\/vendor\/(.*?)$/', "{$this->vendorDir}/$2", $path);
                return $path2;
            }, $overrides[ 'exclude' ]);

            $this->mergeRootComposerAutoload([
                'exclude-from-classmap' => $overrides[ 'exclude' ],
            ]);
            $count = count($overrides[ 'exclude' ]);
            $this->io->write("Added ($count) exclude-from-classmap's to root composer by {$package->getName()}");
            $this->io->write("excluded:", true, IOInterface::VERBOSE);
            $this->io->write("- " . implode("\n- ", $overrides[ 'exclude' ]), true, IOInterface::VERBOSE);
        }
    }

    protected function mergeRootComposerAutoload(array $autoload)
    {
        $result = array_merge_recursive($this->composer->getPackage()->getAutoload(), $autoload);
        $this->composer->getPackage()->setAutoload($result);
    }

    protected function isValidPath($path)
    {
        return is_dir($path) || is_file($path);
    }

    /**
     * @return \Wikimedia\Composer\Merge\V2\MergePlugin|null
     */
    protected function getWikimediaPlugin()
    {
        foreach ($this->composer->getPluginManager()->getPlugins() as $plugin) {
            if(get_class($plugin) === 'Wikimedia\\Composer\\Merge\\V2\\MergePlugin'){
                return $plugin;
            }
        }
        return null;
    }

    protected function getFilePathsFromGlobs($patterns, $required = false)
    {
        return array_map(
            function ($files, $pattern) use ($required) {
                if ($required && ! $files) {
                    throw new \RuntimeException(
                        "exclude-classmap-plugin: No files matched required '{$pattern}'"
                    );
                }
                return $files;
            },
            array_map('glob', $patterns),
            $patterns
        );
    }

    /**
     * Read the contents of a composer.json style file into an array.
     *
     * The package contents are fixed up to be usable to create a Package
     * object by providing dummy "name" and "version" values if they have not
     * been provided in the file. This is consistent with the default root
     * package loading behavior of Composer.
     *
     * @param string $path
     * @return array
     */
    protected function readPackageJson($path)
    {
        $file = new JsonFile($path);
        $json = $file->read();
        if ( ! isset($json[ 'name' ])) {
            $json[ 'name' ] = 'merge-plugin/' .
                strtr($path, DIRECTORY_SEPARATOR, '-');
        }
        if ( ! isset($json[ 'version' ])) {
            $json[ 'version' ] = '1.0.0';
        }
        return $json;
    }

    /**
     * @param array $json
     * @return CompletePackage
     */
    protected function loadPackage(array $json)
    {
        $loader  = new ArrayLoader();
        $package = $loader->load($json);
        // @codeCoverageIgnoreStart
        if ( ! $package instanceof CompletePackage) {
            throw new UnexpectedValueException(
                'Expected instance of CompletePackage, got ' .
                get_class($package)
            );
        }
        // @codeCoverageIgnoreEnd
        return $package;
    }

    public function onCommand(CommandEvent $event)
    {

        $this->io->write('exclude-class-map onCommand');
    }

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => [
                [ 'preAutoloadDump', 0 ],
            ],
        ];
    }

    public function preAutoloadDump(Event $event)
    {
        $this->excludePackageClassmaps();
    }

}
