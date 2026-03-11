<?php

declare(strict_types=1);

namespace arabcoders\database\Scanner;

use Attribute;
use Closure;
use InvalidArgumentException;
use Iterator;
use PhpToken;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use SplHeap;
use Throwable;

class Attributes implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Scan for attributes in given directories.
     *
     * @param array<int,string|array{dir:string,filter?:Closure}> $dirs List of directories to scan for PHP files.
     * @param bool $allowNonInvokable Allow non-invokable classes to be listed.
     *
     * @return self
     */
    public static function scan(array $dirs, bool $allowNonInvokable = false, ?ContainerInterface $container = null): self
    {
        return new self($dirs, $allowNonInvokable, $container);
    }

    /**
     * @var array<int,array{dir:string,filter?:Closure}>
     */
    private readonly array $dirs;

    private function __construct(
        array $dirs,
        private bool $allowNonInvokable,
        private ?ContainerInterface $container = null,
    ) {
        $this->dirs = $this->normalizeDirs($dirs);
    }

    /**
     * Scan for attributes.
     *
     * @param Object|class-string $attribute Attribute to search for.
     * @param Closure|null $filter Filter to apply on returned data.
     *
     * @return array<array-key,Item> List of attributes found. Empty array if none found.
     * @throws \ReflectionException
     */
    public function for(object|string $attribute, ?Closure $filter = null): array
    {
        $references = [];

        $class = new ReflectionClass($attribute);
        $hasAttributes = $class->getAttributes(Attribute::class);
        if (empty($hasAttributes)) {
            throw new InvalidArgumentException(sprintf("The given class '%s' isn't a attribute.", $attribute));
        }

        if (is_string($attribute)) {
            if (!$class->isInstantiable()) {
                throw new InvalidArgumentException(sprintf("Class '%s' is not instantiable.", $attribute));
            }
            $attribute = $class->newInstanceWithoutConstructor();
        }

        foreach ($this->dirs as $entry) {
            $path = $entry['dir'];
            $fileFilter = $entry['filter'];
            $this->logger?->debug("Scanning '{dir}' for '{attr}' attributes.", [
                'dir' => $path,
                'attr' => $attribute::class,
            ]);
            array_push($references, ...$this->lookup($path, $attribute, $filter, $fileFilter));
        }

        return $references;
    }

    /**
     * Lookup for attributes in given directory.
     *
     * @param string $dir Directory to scan.
     * @param Object $attribute Attribute to search for.
     * @param Closure|null $filter Filter to apply on returned data.
     * @param Closure|null $fileFilter Filter to apply on file info.
     *
     * @return array<array-key,array{callable:string}> List of attributes found. Empty array if none found.
     */
    private function lookup(string $dir, object $attribute, ?Closure $filter = null, ?Closure $fileFilter = null): array
    {
        $classes = $callables = [];

        $it = $this->getSorter(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)));

        foreach ($it as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $filePath = $file->getRealPath() ?: $file->getPathname();
            if (null !== $fileFilter && false === $fileFilter($file)) {
                continue;
            }

            $class = $this->parse($filePath);

            if (empty($class)) {
                continue;
            }

            array_push($classes, ...$class);
        }

        foreach ($classes as $className) {
            if (!class_exists($className)) {
                $this->logger?->warning(sprintf("Class '%s' not found.", $className));
                continue;
            }

            array_push($callables, ...$this->find(new ReflectionClass($className), $attribute, $filter));
        }

        return $callables;
    }

    /**
     * Find attributes in class.
     *
     * @param ReflectionClass $class Class to search.
     * @param Object $attribute Attribute to search for.
     * @param Closure|null $filter Filter to apply on returned data.
     *
     * @return array<array-key,array{callable:string}> List of attributes found. Empty array if none found.
     */
    private function find(ReflectionClass $class, object $attribute, ?Closure $filter = null): array
    {
        $routes = [];

        $attributes = $class->getAttributes($attribute::class, ReflectionAttribute::IS_INSTANCEOF);

        $invokable = false;

        foreach ($class->getMethods() as $method) {
            if ($method->getName() !== '__invoke') {
                continue;
            }

            $invokable = true;
        }

        // -- for invokable classes.
        foreach ($attributes as $attrRef) {
            try {
                $attributeClass = $attrRef->newInstance();
            } catch (Throwable) {
                continue;
            }

            if (!$attributeClass instanceof $attribute) {
                continue;
            }

            if (false === $invokable && !$this->allowNonInvokable) {
                throw new InvalidArgumentException(sprintf(
                    "Found attribute '%s' on non-invokable class. '%s'.",
                    $attributeClass->pattern,
                    $class->getName(),
                ));
            }

            $item = [
                'target' => Target::IS_CLASS,
                'attribute' => $attributeClass::class,
                'callable' => $class->getName(),
                'data' => [],
            ];

            if (null !== $filter) {
                $filtered = $filter($attributeClass, $item);
                if (!empty($filtered)) {
                    $item['data'] = $filtered;
                }
            } else {
                $item['data'] = get_object_vars($attributeClass);
            }

            $item['container'] = $this->container;
            $routes[] = new Item(...$item);
        }

        foreach ($class->getMethods() as $method) {
            $attributes = $method->getAttributes($attribute::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attrRef) {
                try {
                    $attributeClass = $attrRef->newInstance();
                } catch (Throwable) {
                    continue;
                }

                if (!$attributeClass instanceof $attribute) {
                    continue;
                }

                $call = '__invoke' === $method->getName() ? $class->getName() : [$class->getName(), $method->getName()];

                $item = [
                    'target' => Target::IS_METHOD,
                    'attribute' => $attributeClass::class,
                    'callable' => $call,
                    'data' => [],
                ];

                if (null !== $filter) {
                    $filtered = $filter($attributeClass, $item);
                    if (!empty($filtered)) {
                        $item['data'] = $filtered;
                    }
                } else {
                    $item['data'] = get_object_vars($attributeClass);
                }

                $item['container'] = $this->container;
                $routes[] = new Item(...$item);
            }
        }

        return $routes;
    }

    /**
     * Parse file for classes.
     *
     * @param string|StreamInterface $file File to parse.
     *
     * @return array List of classes. Empty array if none found.
     */
    public function parse(string|StreamInterface $file): array
    {
        $classes = [];
        $namespace = [];

        $refName = is_string($file) ? $file : 'stream';
        try {
            if (true === $file instanceof StreamInterface) {
                if ($file->isSeekable()) {
                    $file->seek(0);
                }
                $contents = (string) $file;
            } else {
                $contents = file_get_contents($file);
            }

            $tokens = PhpToken::tokenize($contents, TOKEN_PARSE);
            $count = count($tokens);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(sprintf("Unable to read/parse '%s'. %s", $refName, $e->getMessage()));
        }

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                for ($j = $index + 1; $j < $count; $j++) {
                    if ($tokens[$j]->is(T_STRING)) {
                        $namespace = $tokens[$j]->text;
                        break;
                    }

                    if ($tokens[$j]->is(T_NAME_QUALIFIED)) {
                        $namespace = $tokens[$j]->text;
                        break;
                    }

                    if (';' === $tokens[$j]->getTokenName()) {
                        break;
                    }
                }
            }

            if ($token->is(T_CLASS)) {
                for ($j = $index + 1; $j < $count; $j++) {
                    if ($tokens[$j]->is(T_WHITESPACE)) {
                        continue;
                    }

                    if ($tokens[$j]->is(T_STRING)) {
                        $classes[] = $namespace . '\\' . $tokens[$j]->text;
                    } else {
                        break;
                    }
                }
            }
        }

        return count($classes) >= 1 ? $classes : [];
    }

    /**
     * Get sorter for given iterator.
     *
     * @param Iterator $it Iterator to sort.
     *
     * @return SplHeap<RecursiveDirectoryIterator> Sorted iterator.
     */
    private function getSorter(Iterator $it): SplHeap
    {
        return new class($it) extends SplHeap {
            public function __construct(Iterator $iterator)
            {
                foreach ($iterator as $item) {
                    $this->insert($item);
                }
            }

            public function compare($value1, $value2): int
            {
                return strcmp($value2->getRealpath(), $value1->getRealpath());
            }
        };
    }

    /**
     * @param array<int,string|array{dir:string,filter?:Closure}> $dirs
     * @return array<int,array{dir:string,filter:Closure|null}>
     */
    private function normalizeDirs(array $dirs): array
    {
        $normalized = [];

        foreach ($dirs as $dir) {
            if (is_string($dir)) {
                $normalized[] = ['dir' => $dir, 'filter' => null];
                continue;
            }

            if (!is_array($dir)) {
                throw new InvalidArgumentException('Scanner directory must be a string or an array with a dir key.');
            }

            $path = $dir['dir'] ?? null;
            if (!is_string($path) || '' === $path) {
                throw new InvalidArgumentException('Scanner directory must be a non-empty string.');
            }

            $filter = $dir['filter'] ?? null;
            if (null !== $filter && !$filter instanceof Closure) {
                throw new InvalidArgumentException('Scanner directory filter must be a Closure.');
            }

            $normalized[] = ['dir' => $path, 'filter' => $filter];
        }

        return $normalized;
    }
}
