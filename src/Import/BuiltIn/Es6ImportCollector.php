<?php
/**
 * @copyright 2017 Hostnet B.V.
 */
declare(strict_types=1);

namespace Hostnet\Component\Resolver\Import\BuiltIn;

use Hostnet\Component\Resolver\File;
use Hostnet\Component\Resolver\Import\FileResolverInterface;
use Hostnet\Component\Resolver\Import\ImportCollection;
use Hostnet\Component\Resolver\Import\ImportCollectorInterface;

/**
 * Import resolver for JS and TS files for finding ES6 imports. These still needs to be transpiled to ES5
 */
final class Es6ImportCollector implements ImportCollectorInterface
{
    private $js_import_collector;
    private $nodejs_resolver;
    private $extensions;

    public function __construct(
        JsImportCollector $js_import_collector,
        FileResolverInterface $nodejs_resolver,
        array $extensions = ['ts']
    ) {
        $this->js_import_collector = $js_import_collector;
        $this->nodejs_resolver     = $nodejs_resolver;
        $this->extensions          = $extensions;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(File $file): bool
    {
        return in_array($file->extension, $this->extensions, true);
    }

    /**
     * {@inheritdoc}
     */
    public function collect(string $cwd, File $file, ImportCollection $imports): void
    {
        $content = file_get_contents(File::makeAbsolutePath($file->path, $cwd));
        $n       = preg_match_all('/import([^;\'"]*from)?\s+["\'](.*?)["\'];/', $content, $matches);

        $this->js_import_collector->collect($cwd, $file, $imports);

        for ($i = 0; $i < $n; $i++) {
            $path = $matches[2][$i];

            try {
                $import = $this->nodejs_resolver->asRequire($path, $file);
                $imports->addImport($import);
            } catch (\RuntimeException $e) {
                continue;
            }
        }
    }
}
