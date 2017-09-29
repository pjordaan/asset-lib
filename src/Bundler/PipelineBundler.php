<?php
declare(strict_types=1);

namespace Hostnet\Component\Resolver\Bundler;

use Hostnet\Component\Resolver\Bundler\Pipeline\ContentPipeline;
use Hostnet\Component\Resolver\Bundler\Pipeline\FileReader;
use Hostnet\Component\Resolver\Bundler\Pipeline\ReaderInterface;
use Hostnet\Component\Resolver\Cache\Cache;
use Hostnet\Component\Resolver\ConfigInterface;
use Hostnet\Component\Resolver\File;
use Hostnet\Component\Resolver\Import\Dependency;
use Hostnet\Component\Resolver\Import\ImportFinderInterface;
use Psr\Log\LoggerInterface;

class PipelineBundler
{
    private $finder;
    private $pipeline;
    private $logger;
    private $config;

    public function __construct(
        ImportFinderInterface $finder,
        ContentPipeline $pipeline,
        LoggerInterface $logger,
        ConfigInterface $config
    ) {
        $this->finder   = $finder;
        $this->pipeline = $pipeline;
        $this->logger   = $logger;
        $this->config   = $config;
    }

    /**
     * Execute the bundler. This will compile all the entry points and assets
     * defined in the config.
     */
    public function execute()
    {
        $output_folder = $this->config->getWebRoot() . '/' . $this->config->getOutputFolder();
        $source_dir = (!empty($this->config->getSourceRoot()) ? $this->config->getSourceRoot() . '/' : '');

        $require_file_name = 'require' . ($this->config->isDev() ? '' : '.min') . '.js';

        // put the require.js in the web folder
        $require_file = new File(File::clean(__DIR__ . '/../Resources/' . $require_file_name));
        $output_require_file = new File($output_folder . '/require.js');

        if ($this->checkIfAnyChanged($output_require_file, [new Dependency($require_file)])) {
            $this->logger->debug('Writing require.js file to {name}', ['name' => $output_require_file->path]);

            $path = $this->config->cwd() . '/' . $output_require_file->path;

            if (!file_exists(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            copy($require_file->path, $path);
        }

        $file_reader = new FileReader($this->config->cwd());

        // Entry points
        foreach ($this->config->getEntryPoints() as $file_name) {
            $file        = new File($source_dir . $file_name);
            $entry_point = new EntryPoint($this->finder->all($file));

            $this->logger->debug('Checking entry-point bundle file {name}', ['name' => $entry_point->getFile()->path]);

            // bundle
            $this->write($entry_point->getBundleFiles(), $entry_point->getBundleFile($output_folder), $file_reader);

            $this->logger->debug('Checking entry-point vendor file {name}', ['name' => $entry_point->getFile()->path]);

            // vendor
            $this->write($entry_point->getVendorFiles(), $entry_point->getVendorFile($output_folder), $file_reader);

            // assets
            foreach ($entry_point->getAssetFiles() as $file) {
                // peek for the extension... since we do not know it.
                $asset = new Asset($this->finder->all($file), $this->pipeline->peek($file));

                $this->logger->debug('Checking asset {name}', ['name' => $asset->getFile()->path]);

                $this->write($asset->getFiles(), $asset->getAssetFile($output_folder, $this->config->getSourceRoot()), $file_reader);
            }
        }

        // Assets
        foreach ($this->config->getAssetFiles() as $file_name) {
            $file  = new File($source_dir . $file_name);
            $asset = new Asset($this->finder->all($file), $this->pipeline->peek($file));

            $this->logger->debug('Checking asset {name}', ['name' => $asset->getFile()->path]);

            $this->write($asset->getFiles(), $asset->getAssetFile($output_folder, $this->config->getSourceRoot()), $file_reader);
        }
    }

    private function write(array $dependencies, File $target_file, ReaderInterface $file_reader): void
    {
        if ($this->config->isDev() && !$this->checkIfAnyChanged($target_file, $dependencies)) {
            $this->logger->debug(' * Target already up to date');
            return;
        }

        $this->logger->debug(' * Compiling target {name}', ['name' => $target_file->path]);

        $content = $this->pipeline->push($dependencies, $target_file, $file_reader);

        $path = $this->config->cwd() . '/' . $target_file->path;

        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $content);
    }

    /**
     * Check if the output file is newer than the input files.
     *
     * @param File         $output_file
     * @param Dependency[] $input_files
     * @return bool
     */
    private function checkIfAnyChanged(File $output_file, array $input_files): bool
    {
        // did the sources change?
        $sources_file = $this->config->getCacheDir() . '/' . Cache::createFileCacheKey($output_file) . '.sources';
        $input_sources = array_map(function (Dependency $d) {
            return $d->getFile()->path;
        }, $input_files);

        sort($input_sources);

        if (!file_exists($sources_file)) {
            // make sure the cache dir exists
            if (!is_dir(dirname($sources_file))) {
                mkdir(dirname($sources_file), 0777, true);
            }
            file_put_contents($sources_file, serialize($input_sources));

            return true;
        }

        $sources = unserialize(file_get_contents($sources_file), []);

        if (count(array_diff($sources, $input_sources)) > 0 || count(array_diff($input_sources, $sources)) > 0) {
            file_put_contents($sources_file, serialize($input_sources));

            return true;
        }

        // Did the files change?
        $file_path = $this->config->cwd() . '/' . $output_file->path;
        $mtime = file_exists($file_path) ? filemtime($file_path) : -1;

        if ($mtime === -1) {
            return true;
        }

        foreach ($input_files as $input_file) {
            $path = $input_file->getFile()->path;

            if (!File::isAbsolutePath($path)) {
                $path = $this->config->cwd() . '/' . $path;
            }

            if ($mtime < filemtime($path)) {
                return true;
            }
        }

        return false;
    }
}
