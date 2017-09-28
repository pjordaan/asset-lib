<?php

namespace Hostnet\Component\Resolver\Bundler\Pipeline;

use Hostnet\Component\Resolver\Bundler\ContentItem;
use Hostnet\Component\Resolver\Bundler\ContentState;
use Hostnet\Component\Resolver\Cache\Cache;
use Hostnet\Component\Resolver\ConfigInterface;
use Hostnet\Component\Resolver\Event\AssetEvent;
use Hostnet\Component\Resolver\Event\AssetEvents;
use Hostnet\Component\Resolver\File;
use Hostnet\Component\Resolver\Import\Dependency;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The content pipeline allows for pushing items through it to be assets. Once
 * pushed, it will go through various processors until it is written to disk as
 * the given output file.
 */
class ContentPipeline
{
    private $dispatcher;
    private $logger;
    private $config;

    /**
     * @var ContentProcessorInterface[]
     */
    private $processors;

    public function __construct(
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
        ConfigInterface $config
    ) {
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        $this->config = $config;
        $this->processors = [];
    }

    public function addProcessor(ContentProcessorInterface $processor): void
    {
        $this->processors[] = $processor;
    }

    /**
     * Peek an item through the content pipeline. This will return the
     * resulting file extension.
     *
     * @param File $input_file
     * @return string
     */
    public function peek(File $input_file): string
    {
        $item = new ContentState($input_file->extension);

        // Transition the item until it is in a ready state.
        while ($item->current() !== ContentState::READY) {
            $this->nextPeek($item);
        }

        return $item->extension();
    }

    /**
     * Push a bundled file on the pipeline with a list of dependencies.
     *
     * @param Dependency[]    $dependencies
     * @param File            $target_file
     * @param ReaderInterface $file_reader
     * @return string
     */
    public function push(array $dependencies, File $target_file, ReaderInterface $file_reader): string
    {
        $this->logger->debug(' * Compiling target {name}', ['name' => $target_file->path]);

        /* @var $items ContentItem[] */
        $items = array_map(function (Dependency $d) use ($file_reader) {
            $file = $d->getFile();
            $module_name = $file->getName();

            if (!empty($this->config->getSourceRoot())
                && false !== strpos($module_name, $this->config->getSourceRoot())
            ) {
                $base_dir = trim(substr($file->dir, strlen($this->config->getSourceRoot())), '/');

                if (strlen($base_dir) > 0) {
                    $base_dir .= '/';
                }

                $module_name = $base_dir . $file->getBaseName() . '.' . $file->extension;
            }

            return new ContentItem($file, $module_name, $file_reader);
        }, array_filter($dependencies, function (Dependency $d) {
            return !$d->isVirtual();
        }));

        $buffer = '';

        foreach ($items as $item) {
            $cache_key = Cache::createFileCacheKey($item->file);

            if ($this->config->isDev()
                && file_exists($this->config->getCacheDir() . '/' . $cache_key)
                && !$this->checkIfChanged($target_file, $item->file)
            ) {
                [$content, $extension] = unserialize(file_get_contents(
                    $this->config->getCacheDir() . '/' . $cache_key
                ), []);

                $item->transition(ContentState::READY, $content, $extension);

                $this->logger->debug('   - Emiting cached file for {name}', ['name' => $item->file->path]);
            } else {
                // Transition the item until it is in a ready state.
                while (!$item->getState()->isReady()) {
                    $this->next($item);
                }

                if ($this->config->isDev()) {
                    // cache the contents of the item
                    file_put_contents(
                        $this->config->getCacheDir() . '/' . $cache_key,
                        serialize([$item->getContent(), $item->getState()->extension()])
                    );
                }

                $this->logger->debug('   - Emiting compile file for {name}', ['name' => $item->file->path]);
            }

            // Write
            $buffer .= $item->getContent();
        }

        // Create an item for the file to write to disk.
        $item = new ContentItem($target_file, $target_file->getName(), new StringReader($buffer));

        $this->dispatcher->dispatch(AssetEvents::READY, new AssetEvent($item));

        return $item->getContent();
    }

    /**
     * Transition the item.
     *
     * @param ContentItem $item
     */
    private function next(ContentItem $item): void
    {
        $current_state = $item->getState()->current();

        foreach ($this->processors as $processor) {
            if ($processor->supports($item->getState())) {
                $this->dispatcher->dispatch(AssetEvents::PRE_PROCESS, new AssetEvent($item));

                $processor->transpile($this->config->cwd(), $item);

                $this->dispatcher->dispatch(AssetEvents::POST_PROCESS, new AssetEvent($item));

                break;
            }
        }

        try {
            $this->validateState($current_state, $item->getState()->current());
        } catch (\LogicException $e) {
            throw new \LogicException(sprintf('Failed to compile resource "%s".', $item->module_name), 0, $e);
        }
    }

    /**
     * Transition the item.
     *
     * @param ContentState $item
     */
    private function nextPeek(ContentState $item): void
    {
        $current_state = $item->current();

        foreach ($this->processors as $processor) {
            if ($processor->supports($item)) {
                $processor->peek($this->config->cwd(), $item);

                break;
            }
        }

        $this->validateState($current_state, $item->current());
    }

    private function validateState(string $old_state, string $new_state): void
    {
        // Make sure we did a transition. If no change was made, that means we are in an infinite loop.
        if ($old_state === $new_state) {
            throw new \LogicException('State did not change, transition must occur.');
        }
    }

    private function checkIfChanged(File $output_file, File $file)
    {
        $file_path = $this->config->cwd() . '/' . $output_file->path;
        $mtime = file_exists($file_path) ? filemtime($file_path) : -1;

        if ($mtime === -1) {
            return true;
        }

        return $mtime < filemtime($this->config->cwd() . '/' . $file->path);
    }
}