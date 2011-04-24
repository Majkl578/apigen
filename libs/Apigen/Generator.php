<?php

/**
 * API Generator.
 *
 * Copyright (c) 2010 David Grudl (http://davidgrudl.com)
 * Copyright (c) 2011 Ondřej Nešpor (http://andrewsville.cz)
 *
 * This source file is subject to the "Nette license", and/or
 * GPL license. For more information please see http://nette.org
 */

namespace Apigen;

use NetteX;
use Apigen\Reflection as ApiReflection;
use TokenReflection\IReflectionClass as ReflectionClass, TokenReflection\IReflectionProperty as ReflectionProperty, TokenReflection\IReflectionMethod as ReflectionMethod, TokenReflection\IReflectionConstant as ReflectionConstant;
use TokenReflection\ReflectionAnnotation;


/**
 * Generates a HTML API documentation based on the model.
 *
 * @author David Grudl
 * @author Ondřej Nešpor
 */
class Generator extends NetteX\Object
{
	/**
	 * Library version.
	 *
	 * @var float
	 */
	const VERSION = 2.0;

	/**
	 * Model instance.
	 *
	 * @var Model
	 */
	private $model;

	/**
	 * Configuration.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Progressbar
	 *
	 * @var \Console_ProgressBar
	 */
	private $progressBar;

	/**
	 * Output directory.
	 *
	 * @var string
	 */
	private $outputDir;

	/**
	 * Constructor.
	 *
	 * @param Model $model Generator model
	 */
	public function __construct(Model $model)
	{
		$this->model = $model;
	}

	/**
	 * Wipes out the target directory.
	 *
	 * @param string target directory
	 * @param array configuration
	 * @return boolean
	 */
	public function wipeOutTarget($target, array $config)
	{
		// resources
		foreach ($config['resources'] as $dir) {
			$pathName = $target . '/' . $dir;
			if (is_dir($pathName)) {
				foreach (NetteX\Utils\Finder::findFiles('*')->from($pathName)->childFirst() as $item) {
					if ($item->isDir()) {
						if (!@rmdir($item)) {
							return false;
						}
					} elseif ($item->isFile()) {
						if (!@unlink($item)) {
							return false;
						}
					}
				}
				if (!@rmdir($pathName)) {
					return false;
				}
			}
		}

		// common files
		$filenames = array_keys($config['templates']['common']);
		foreach (NetteX\Utils\Finder::findFiles($filenames)->from($target) as $item) {
			if (!@unlink($item)) {
				return false;
			}
		}

		// output files
		$masks = array_map(function($mask) {
			return preg_replace('~%[^%]*?s~', '*', $mask);
		}, $config['filenames']);
		$filter = function($item) use($masks) {
			foreach ($masks as $mask) {
				if (fnmatch($mask, $item->getFilename())) {
					return true;
				}
			}

			return false;
		};

		foreach (NetteX\Utils\Finder::findFiles('*')->filter($filter)->from($target) as $item) {
			if (!@unlink($item)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generates API documentation.
	 *
	 * @param string output directory
	 * @param array configuration
	 */
	public function generate($output, array $config)
	{
		if (!is_dir($output)) {
			throw new \Exception("Directory $output doesn't exist.");
		}

		$this->config = $config;
		$this->outputDir = $output;

		// copy resources
		foreach ($config['resources'] as $source => $dest) {
			foreach ($iterator = NetteX\Utils\Finder::findFiles('*')->from($source)->getIterator() as $foo) {
				copy($iterator->getPathName(), self::forceDir("$output/$dest/" . $iterator->getSubPathName()));
			}
		}

		// categorize by namespaces
		$packages = array();
		$namespaces = array();
		$allClasses = array();
		foreach ($this->model->getClasses() as $class) {
			$packages[$class->getPackageName()]['classes'][$class->getName()] = $class;
			if ($class->inNamespace()) {
				$packages[$class->getPackageName()]['namespaces'][$class->getNamespaceName()] = true;
				$namespaces[$class->getNamespaceName()]['classes'][$class->getShortName()] = $class;
				$namespaces[$class->getNamespaceName()]['packages'][$class->getPackageName()] = true;
			}
			$allClasses[$class->getName()] = $class;
		}
		uksort($packages, 'strcasecmp');
		uksort($namespaces, 'strcasecmp');
		uksort($allClasses, 'strcasecmp');

		if ($config['settings']['progressbar']) {
			$this->prepareProgressBar(
				count($allClasses)
				+ count($namespaces)
				+ count($packages)
				+ count($config['templates']['common'])
				+ array_reduce($allClasses, function($count, ApiReflection $class) {
					if ($class->isUserDefined()) {
						$count++;
					}
					return $count;
				}, 0)
			);
		}

		$template = $this->createTemplate();
		$template->version = self::VERSION;
		$template->fileRoot = $this->model->getDirectory();
		foreach ($config['variables'] as $key => $value) {
			$template->$key = $value;
		}

		// generate summary files
		$template->namespaces = array_keys($namespaces);
		$template->packages = array_keys($packages);
		$template->classes = array_filter($allClasses, function($class) {
			return !$class->isInterface() && !$class->isException();
		});
		$template->interfaces = array_filter($allClasses, function($class) {
			return $class->isInterface() && !$class->isException();
		});
		$template->exceptions = array_filter($allClasses, function($class) {
			return $class->isException();
		});
		foreach ($config['templates']['common'] as $dest => $source) {
			$template->setFile($source)->save(self::forceDir("$output/$dest"));

			$this->incrementProgressBar();
		}

		$generatedFiles = array();
		$fshl = new \fshlParser('HTML_UTF8', P_TAB_INDENT | P_LINE_COUNTER);

		// generate namespace summary
		$template->package = null;
		foreach ($namespaces as $namespace => $definition) {
			$classes = isset($definition['classes']) ? $definition['classes'] : array();
			uksort($classes, 'strcasecmp');
			$nPackages = isset($definition['packages']) ? array_keys($definition['packages']) : array();
			usort($nPackages, 'strcasecmp');
			$template->package = 1 === count($nPackages) ? $nPackages[0] : null;
			$template->packages = $nPackages;
			$template->namespace = $namespace;
			$template->namespaces = array_filter(array_keys($namespaces), function($item) use($namespace) {
				return strpos($item, $namespace) === 0 || strpos($namespace, $item) === 0;
			});
			$template->classes = array_filter($classes, function($class) {
				return !$class->isInterface() && !$class->isException();
			});
			$template->interfaces = array_filter($classes, function($class) {
				return $class->isInterface() && !$class->isException();
			});
			$template->exceptions = array_filter($classes, function($class) {
				return $class->isException();
			});
			$template->setFile($config['templates']['namespace'])->save(self::forceDir($output . '/' . $this->formatNamespaceLink($namespace)));

			$this->incrementProgressBar();
		}

		// generate package summary
		$template->namespace = null;
		foreach ($packages as $package => $definition) {
			$classes = isset($definition['classes']) ? $definition['classes'] : array();
			uksort($classes, 'strcasecmp');
			$pNamespaces = isset($definition['namespaces']) ? array_keys($definition['namespaces']) : array();
			usort($pNamespaces, 'strcasecmp');
			$template->package = $package;
			$template->packages = array($package);
			$template->namespaces = $pNamespaces;
			$template->classes = array_filter($classes, function($class) {
				return !$class->isInterface() && !$class->isException();
			});
			$template->interfaces = array_filter($classes, function($class) {
				return $class->isInterface() && !$class->isException();
			});
			$template->exceptions = array_filter($classes, function($class) {
				return $class->isException();
			});
			$template->setFile($config['templates']['package'])->save(self::forceDir($output . '/' . $this->formatPackageLink($package)));

			$this->incrementProgressBar();
		}


		// generate class & interface files
		$template->classes = $allClasses;
		foreach ($allClasses as $class) {
			$template->package = $package = $class->getPackageName();
			$template->namespace = $namespace = $class->getNamespaceName();
			if ($namespace) {
				$template->namespaces = array_filter(array_keys($namespaces), function($item) use($namespace) {
					return strpos($item, $namespace) === 0 || strpos($namespace, $item) === 0;
				});
			} else {
				$template->namespaces = array();
			}
			$template->packages = array($package);
			$template->tree = array($class);
			while ($parent = $template->tree[0]->getParentClass()) {
				array_unshift($template->tree, $parent);
			}
			$template->classes = !$class->isInterface() && !$class->isException() ? array($class) : array();
			$template->interfaces = $class->isInterface() && !$class->isException() ? array($class) : array();
			$template->exceptions = $class->isException() ? array($class) : array();
			$template->subClasses = $this->model->getDirectSubClasses($class);
			uksort($template->subClasses, 'strcasecmp');
			$template->implementers = $this->model->getDirectImplementers($class);
			uksort($template->implementers, 'strcasecmp');
			$template->class = $class;
			$template->setFile($config['templates']['class'])->save(self::forceDir($output . '/' . $this->formatClassLink($class)));

			$this->incrementProgressBar();

			// generate source codes
			if ($class->isUserDefined() && !isset($generatedFiles[$class->getFileName()])) {
				$file = $class->getFileName();
				$template->source = $fshl->highlightString('PHP', file_get_contents($file));
				$template->fileName = substr($file, strlen($this->model->getDirectory()) + 1);
				$template->setFile($config['templates']['source'])->save(self::forceDir($output . '/' . $this->formatSourceLink($class, FALSE)));
				$generatedFiles[$file] = TRUE;

				$this->incrementProgressBar();
			}
		}
	}

	/**
	 * Returns a template instance with required helpers prepared.
	 *
	 * @return \NetteX\Templates\FileTemplate
	 */
	private function createTemplate()
	{
		$template = new NetteX\Templating\FileTemplate;
		$template->setCacheStorage(new NetteX\Caching\Storages\MemoryStorage);

		$latte = new NetteX\Latte\Engine;
		$latte->handler->macros['try'] = '<?php try { ?>';
		$latte->handler->macros['/try'] = '<?php } catch (\Exception $e) {} ?>';
		$template->registerFilter($latte);

		// common operations
		$template->registerHelperLoader('NetteX\Templating\DefaultHelpers::loader');
		$template->registerHelper('ucfirst', 'ucfirst');
		$template->registerHelper('values', 'array_values');
		$template->registerHelper('map', function($arr, $callback) {
			return array_map(create_function('$value', $callback), $arr);
		});
		$template->registerHelper('replaceRE', 'NetteX\StringUtils::replace');
		$template->registerHelper('replaceNS', function($name, $namespace) { // remove current namespace
			$name = ltrim($name, '\\');
			return (strpos($name, $namespace . '\\') === 0 && strpos($name, '\\', strlen($namespace) + 1) === FALSE)
				? substr($name, strlen($namespace) + 1) : $name;
		});

		// PHP source highlight
		$fshl = new \fshlParser('HTML_UTF8');
		$template->registerHelper('highlightPHP', function($source) use ($fshl) {
			return $fshl->highlightString('PHP', (string) $source);
		});

		// links
		$template->registerHelper('packageLink', callback($this, 'formatPackageLink'));
		$template->registerHelper('namespaceLink', callback($this, 'formatNamespaceLink'));
		$template->registerHelper('classLink', callback($this, 'formatClassLink'));
		$template->registerHelper('sourceLink', callback($this, 'formatSourceLink'));

		// types
		$model = $this->model;
		$template->registerHelper('getTypes', function($element, $position = NULL) use ($model) {
			$namespace = $element->getDeclaringClass()->getNamespaceName();
			$s = $position === NULL ? $element->getAnnotation($element->hasAnnotation('var') ? 'var' : 'return')
				: @$element->annotations['param'][$position];
			if (is_object($s)) {
				$s = get_class($s); // TODO
			}
			$res = array();
			foreach (preg_replace('#\s.*#', '', $s) as $s) {
				foreach (explode('|', $s) as $name) {
					$res[] = (object) array('name' => $name, 'class' => $model->resolveType($name, $namespace));
				}
			}
			return $res;
		});
		$template->registerHelper('resolveType', callback($model, 'resolveType'));
		$template->registerHelper('getType', function($variable) {
			return is_object($variable) ? get_class($variable) : gettype($variable);
		});

		// docblock
		$texy = new \TexyX;
		$texy->allowedTags = \TexyX::NONE;
		$texy->allowed['list/definition'] = FALSE;
		$texy->allowed['phrase/em-alt'] = FALSE;
		$texy->registerBlockPattern( // highlight <code>, <pre>
			function($parser, $matches, $name) use ($fshl) {
				$content = $matches[1] === 'code' ? $fshl->highlightString('PHP', $matches[2]) : htmlSpecialChars($matches[2]);
				$content = $parser->getTexy()->protect($content, \TexyX::CONTENT_BLOCK);
				return \TexyXHtml::el('pre', $content);
			},
			'#<(code|pre)>(.+?)</\1>#s',
			'codeBlockSyntax'
		);

		// Documentation formatting
		$template->registerHelper('docline', function($text) use ($texy) {
			return $texy->processLine($text);
		});
		$template->registerHelper('docblock', function($text) use ($texy) {
			return $texy->process(preg_replace('#([^\n])(\n)([^\n])#', '\1\2 \3', $text));
		});
		$template->registerHelper('doclabel', function($doc, $namespace) use ($template) {
			@list($names, $label) = preg_split('#\s+#', $doc, 2);
			$res = '';
			foreach (explode('|', $names) as $name) {
				$class = $template->resolveType($name, $namespace);
				$name = $template->replaceNS($name, $namespace);
				$res .= $class !== NULL ? sprintf('<a href="%s">%s</a>', $template->classLink($class), $template->escapeHtml($name)) : $template->escapeHtml($name);
				$res .= '|';
			}
			return rtrim($res, '|') . ' ' . $template->escapeHtml($label);
		});

		// Docblock descriptions
		$template->registerHelper('longDescription', function($element, $shortIfNone = false) {
			$short = $element->getAnnotation(ReflectionAnnotation::SHORT_DESCRIPTION);
			$long = $element->getAnnotation(ReflectionAnnotation::LONG_DESCRIPTION);

			if ($long) {
				$short .= "\n\n" . $long;
			}

			return $short;
		});
		$template->registerHelper('shortDescription', function($element) {
			return $element->getAnnotation(ReflectionAnnotation::SHORT_DESCRIPTION);
		});

		// static files versioning
		$outputDir = $this->outputDir;
		$template->registerHelper('staticFile', function($name, $line = null) use($outputDir) {
			static $versions = array();

			$filename = $outputDir . '/' . $name;
			if (!isset($versions[$filename]) && file_exists($filename)) {
				$versions[$filename] = sprintf('%u', crc32(file_get_contents($filename)));
			}
			if (isset($versions[$filename])) {
				$name .= '?' . $versions[$filename];
			}
			return $name;
		});


		return $template;
	}

	/**
	 * Generates a link to a namespace summary file.
	 *
	 * @param  string|\Apigen\Reflection|IReflectionNamespace
	 * @return string
	 */
	public function formatNamespaceLink($class)
	{
		if (!isset($this->config['filenames']['namespace'])) {
			throw new \Exception('Namespace output filename not defined.');
		}

		$namescape = ($class instanceof ApiReflection) ? $class->getNamespaceName() : $class;
		return sprintf($this->config['filenames']['namespace'], $namescape ? preg_replace('#[^a-z0-9_]#i', '.', $namescape) : 'None');
	}

	/**
	 * Generates a link to a package summary file.
	 *
	 * @param  string|\Apigen\Reflection
	 * @return string
	 */
	public function formatPackageLink($class)
	{
		if (!isset($this->config['filenames']['package'])) {
			throw new \Exception('Package output filename not defined.');
		}

		$package = ($class instanceof ApiReflection) ? $class->getPackageName() : $class;
		return sprintf($this->config['filenames']['package'], $package ? preg_replace('#[^a-z0-9_]#i', '.', $package) : 'None');
	}

	/**
	 * Generates a link to class summary file.
	 *
	 * @param  string|\Apigen\Reflection|IReflectionMethod|IReflectionProperty
	 * @return string
	 */
	public function formatClassLink($element)
	{
		if (!isset($this->config['filenames']['class'])) {
			throw new \Exception('Class output filename not defined.');
		}

		$id = '';
		if (is_string($element)) {
			$class = $element;
		} elseif ($element instanceof ApiReflection) {
			$class = $element->getName();
		} else {
			$class = $element->getDeclaringClass()->getName();
			if ($element instanceof ReflectionProperty) {
				$id = '#$' . $element->getName();
			} elseif ($element instanceof ReflectionMethod) {
				$id = '#_' . $element->getName();
			} elseif ($element instanceof ReflectionConstant) {
				$id = '#' . $element->getName();
			}
		}

		return sprintf($this->config['filenames']['class'], preg_replace('#[^a-z0-9_]#i', '.', $class)) . $id;
	}

	/**
	 * Generates a link to a class source code file.
	 *
	 * @param  \Apigen\Reflection|IReflectionMethod
	 * @return string|null
	 */
	public function formatSourceLink($element, $withLine = TRUE)
	{
		if (!isset($this->config['filenames']['source'])) {
			throw new \Exception('Source output filename not defined.');
		}

		$class = ($element instanceof ApiReflection) ? $element : $element->getDeclaringClass();
		if ($class->isInternal()) {
			if ($element instanceof ApiReflection) {
				return strtolower('http://php.net/manual/class.' . $class->getName() . '.php');
			} else {
				return strtolower('http://php.net/manual/' . $class->getName() . '.' . strtr(ltrim($element->getName(), '_'), '_', '-') . '.php');
			}
		} elseif ($class->isUserDefined()) {
			$file = substr($element->getFileName(), strlen($this->model->getDirectory()) + 1);
			$line = $withLine ? $element->getStartLine() : NULL;

			return sprintf($this->config['filenames']['source'], preg_replace('#[^a-z0-9_]#i', '.', $file)) . (isset($line) ? "#$line" : '');
		}
	}

	/**
	 * Prepares the progressbar.
	 *
	 * @param $maximum Maximum progressbar value
	 */
	private function prepareProgressBar($maximum = 1)
	{
		$this->progressBar = new \Console_ProgressBar(
			'[%bar%] %percent%',
			'=>',
			' ',
			80,
			$maximum
		);
	}

	/**
	 * Increments the progressbar by one.
	 */
	protected function incrementProgressBar()
	{
		if (null !== $this->progressBar) {
			$this->progressBar->update($this->progressBar->getProgress() + 1);
		}
	}

	/**
	 * Ensures a directory is created.
	 *
	 * @param string Directory path
	 * @return string
	 */
	public static function forceDir($path)
	{
		@mkdir(dirname($path), 0755, TRUE);
		return $path;
	}
}
