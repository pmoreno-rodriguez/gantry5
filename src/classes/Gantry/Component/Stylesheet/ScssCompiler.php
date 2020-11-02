<?php

/**
 * @package   Gantry5
 * @author    RocketTheme http://www.rockettheme.com
 * @copyright Copyright (C) 2007 - 2020 RocketTheme, LLC
 * @license   Dual License: MIT or GNU/GPLv2 and later
 *
 * http://opensource.org/licenses/MIT
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Gantry Framework code that extends GPL code is considered GNU/GPLv2 and later
 */

namespace Gantry\Component\Stylesheet;

use Gantry\Component\Stylesheet\Scss\Compiler;
use Gantry\Component\Stylesheet\Scss\Functions;
use Gantry\Framework\Document;
use Gantry\Framework\Gantry;
use ScssPhp\ScssPhp\Exception\CompilerException;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\File\JsonFile;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use ScssPhp\ScssPhp\Formatter\Crunched;
use ScssPhp\ScssPhp\Formatter\Expanded;

/**
 * Class ScssCompiler
 * @package Gantry\Component\Stylesheet
 */
class ScssCompiler extends CssCompiler
{
    /** @var string */
    public $type = 'scss';
    /** @var string */
    public $name = 'SCSS';

    /** @var Compiler */
    protected $compiler;
    /** @var Functions */
    protected $functions;

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->compiler = new Compiler();
        $this->functions = new Functions($this->compiler);

        if ($this->production) {
            $this->compiler->setFormatter(Crunched::class);
        } else {
            $this->compiler->setFormatter(Expanded::class);
            // Work around bugs in SCSS compiler.
            // TODO: Pass our own SourceMapGenerator instance instead.
            $this->compiler->setSourceMap(Compiler::SOURCE_MAP_INLINE);
            $this->compiler->setSourceMapOptions([
                'sourceMapBasepath' => '/',
                'sourceRoot'        => '/',
            ]);
            $this->compiler->setLineNumberStyle(Compiler::LINE_COMMENTS);
        }
    }

    /**
     * @param string $in
     * @return string
     */
    public function compile($in)
    {
        return $this->compiler->compile($in);
    }

    /**
     * @return $this
     */
    public function reset()
    {
        $this->functions->reset();

        return $this;
    }

    public function resetCache()
    {
    }

    /**
     * @param string $in    Filename without path or extension.
     * @return bool         True if the output file was saved.
     * @throws \RuntimeException
     */
    public function compileFile($in)
    {
        // Buy some extra time as compilation may take a lot of time in shared environments.
        @set_time_limit(30);
        @set_time_limit(60);
        @set_time_limit(90);
        @set_time_limit(120);
        ob_start();

        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];

        $out = $this->getCssUrl($in);
        /** @var string $path */
        $path = $locator->findResource($out, true, true);
        $file = File::instance($path);

        // Attempt to lock the file for writing.
        try {
            $file->lock(false);
        } catch (\Exception $e) {
            // Another process has locked the file; we will check this in a bit.
        }

        if ($file->locked() === false) {
            // File was already locked by another process, lets avoid compiling the same file twice.
            return false;
        }

        // Set the lookup paths.
        $this->functions->setBasePath($path);
        $this->compiler->setImportPaths([[$this, 'findImport']]);

        // Run the compiler.
        $this->compiler->setVariables($this->getVariables());
        $scss = '@import "' . $in . '.scss"';
        try {
            $css = $this->compiler->compile($scss);
        } catch (CompilerException $e) {
            throw new \RuntimeException("CSS Compilation on file '{$in}.scss' failed on error: {$e->getMessage()}", 500, $e);
        }
        if (strpos($css, $scss) === 0) {
            $css = '/* ' . $scss . ' */';
        }

        // Extract map from css and save it as separate file.
        $pos = strrpos($css, '/*# sourceMappingURL=');
        if ($pos !== false) {
            $map = json_decode(urldecode(substr($css, $pos + 43, -3)), true);

            /** @var Document $document */
            $document = $gantry['document'];

            foreach ($map['sources'] as &$source) {
                $source = $document::url($source, false, -1);
            }
            unset($source);

            $mapFile = JsonFile::instance($path . '.map');
            $mapFile->save($map);
            $mapFile->free();

            $css = substr($css, 0, $pos) . '/*# sourceMappingURL=' . basename($out) . '.map */';
        }

        $warnings = trim(ob_get_clean() ?: '');
        if ($warnings) {
            $this->warnings[$in] = explode("\n", $warnings);
        }

        if (!$this->production) {
            $warning = <<<WARN
/* GANTRY5 DEVELOPMENT MODE ENABLED.
 *
 * WARNING: This file is automatically generated by Gantry5. Any modifications to this file will be lost!
 *
 * For more information on modifying CSS, please read:
 *
 * http://docs.gantry.org/gantry5/configure/styles
 * http://docs.gantry.org/gantry5/tutorials/adding-a-custom-style-sheet
 */
WARN;
            $css = $warning . "\n\n" . $css;
        } else {
            $css = "{$this->checksum()}\n{$css}";
        }

        $file->save($css);
        $file->unlock();
        $file->free();

        $this->createMeta($out, md5($css));
        $this->compiler->cleanParsedFiles();

        return true;
    }

    /**
     * @param string   $name       Name of function to register to the compiler.
     * @param callable $callback   Function to run when called by the compiler.
     * @return $this
     */
    public function registerFunction($name, callable $callback)
    {
        $this->compiler->registerFunction($name, $callback);

        return $this;
    }

    /**
     * @param string $name       Name of function to unregister.
     * @return $this
     */
    public function unregisterFunction($name)
    {
        $this->compiler->unregisterFunction($name);

        return $this;
    }

    /**
     * @param string $url
     * @return null|string
     * @internal
     */
    public function findImport($url)
    {
        $gantry = Gantry::instance();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];

        // Ignore vanilla css and external requests.
        if (preg_match('/\.css$|^https?:\/\//', $url)) {
            return null;
        }

        // Try both normal and the _partial filename.
        $files = [$url, preg_replace('/[^\/]+$/', '_\0', $url)];

        foreach ($this->paths as $base) {
            foreach ($files as $file) {
                if (!preg_match('|\.scss$|', $file)) {
                    $file .= '.scss';
                }
                if ($locator->findResource($base . '/' . $file)) {
                    return $base . '/' . $file;
                }
            }
        }

        return null;
    }

    /**
     * @param array $list
     */
    protected function doSetFonts(array $list)
    {
        $this->functions->setFonts($list);
    }
}
