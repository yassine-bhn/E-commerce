<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Support;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\ParserFactory;

final class SourceTree
{
    private string $srcRoot;
    /** @var array<string, array<int, Node>> */
    private array $asts = [];
    /** @var array<int, string> */
    private array $fileList = [];

    public function __construct(?string $srcRoot = null)
    {
        $this->srcRoot = $srcRoot ?? dirname(__DIR__, 3).'/src';
        $factory = new ParserFactory();
        $parser = $factory->createForHostVersion();
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->srcRoot));
        foreach ($rii as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = ltrim(str_replace($this->srcRoot, '', $path), '/');
            $relative = str_replace('\\', '/', $relative);
            $code = file_get_contents($path);
            if (false === $code) {
                continue;
            }
            try {
                $ast = $parser->parse($code) ?? [];
            } catch (\Throwable $e) {
                $ast = [];
            }
            $this->asts[$relative] = $ast;
            $this->fileList[] = $relative;
        }
        sort($this->fileList);
    }

    /** @return string[] */
    public function files(): array
    {
        return $this->fileList;
    }

    /** @return array<int, array<string,mixed>> */
    public function classes(): array
    {
        $result = [];
        foreach ($this->fileList as $file) {
            $namespace = '';
            $imports = $this->imports($file);
            foreach ($this->asts[$file] as $stmt) {
                if ($stmt instanceof Namespace_) {
                    $namespace = $stmt->name?->toString() ?? '';
                    foreach ($stmt->stmts as $inner) {
                        if ($inner instanceof Class_ && null !== $inner->name) {
                            $result[] = $this->buildClass($file, $namespace, $inner, $imports);
                        }
                    }
                }
                if ($stmt instanceof Class_ && null !== $stmt->name) {
                    $result[] = $this->buildClass($file, $namespace, $stmt, $imports);
                }
            }
        }

        return $result;
    }

    /** @return array<int, array<string,mixed>> */
    public function interfaces(): array
    {
        $result = [];
        foreach ($this->fileList as $file) {
            $namespace = '';
            $imports = $this->imports($file);
            foreach ($this->asts[$file] as $stmt) {
                if ($stmt instanceof Namespace_) {
                    $namespace = $stmt->name?->toString() ?? '';
                    foreach ($stmt->stmts as $inner) {
                        if ($inner instanceof Interface_ && null !== $inner->name) {
                            $result[] = $this->buildInterface($file, $namespace, $inner, $imports);
                        }
                    }
                }
                if ($stmt instanceof Interface_ && null !== $stmt->name) {
                    $result[] = $this->buildInterface($file, $namespace, $stmt, $imports);
                }
            }
        }

        return $result;
    }

    /** @return string[] */
    public function imports(string $file): array
    {
        $imports = [];
        foreach ($this->asts[$file] ?? [] as $stmt) {
            $target = $stmt instanceof Namespace_ ? $stmt->stmts : [$stmt];
            foreach ($target as $inner) {
                if ($inner instanceof Use_) {
                    foreach ($inner->uses as $use) {
                        $imports[] = $use->name->toString();
                    }
                }
            }
        }

        return array_values(array_unique($imports));
    }

    /** @return array<int, array<string,mixed>> */
    public function constructorParams(): array
    {
        $result = [];
        foreach ($this->classes() as $class) {
            $result[] = [
                'file' => $class['file'],
                'class' => $class['fqcn'],
                'name' => $class['name'],
                'params' => $class['constructorParams'],
            ];
        }

        return $result;
    }

    /** @return array<int, array<string,mixed>> */
    public function methods(): array
    {
        $result = [];
        foreach ($this->classes() as $class) {
            foreach ($class['methods'] as $method) {
                $result[] = [
                    'file' => $class['file'],
                    'class' => $class['fqcn'],
                    'method' => $method,
                ];
            }
        }
        foreach ($this->interfaces() as $interface) {
            foreach ($interface['methods'] as $method) {
                $result[] = [
                    'file' => $interface['file'],
                    'class' => $interface['fqcn'],
                    'method' => $method,
                ];
            }
        }

        return $result;
    }

    /** @return array<int, array<string,mixed>> */
    public function attributes(): array
    {
        $result = [];
        foreach ($this->classes() as $class) {
            foreach ($class['attributes'] as $attr) {
                $result[] = [
                    'file' => $class['file'],
                    'class' => $class['fqcn'],
                    'attribute' => $attr,
                ];
            }
            // method-level attributes are not captured in class attributes; parse separately below
        }
        // Add method-level attributes by traversing AST
        foreach ($this->fileList as $file) {
            $namespace = '';
            foreach ($this->asts[$file] as $stmt) {
                $containers = $stmt instanceof Namespace_ ? $stmt->stmts : [$stmt];
                foreach ($containers as $c) {
                    if (!($c instanceof Class_) || null === $c->name) {
                        continue;
                    }
                    $ns = $stmt instanceof Namespace_ ? ($stmt->name?->toString() ?? '') : $namespace;
                    $fqcn = '' !== $ns ? $ns.'\\'.$c->name->toString() : $c->name->toString();
                    foreach ($c->stmts as $m) {
                        if ($m instanceof Node\Stmt\ClassMethod) {
                            foreach ($m->attrGroups as $group) {
                                foreach ($group->attrs as $attr) {
                                    $result[] = [
                                        'file' => $file,
                                        'class' => $fqcn,
                                        'attribute' => $attr->name->toString(),
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    public function hasSqlCall(string $file): bool
    {
        $code = $this->read($file);
        $patterns = [
            '->fetch(', '->fetchOne(', '->fetchAllAssociative(', '->fetchAssociative(',
            '->fetchFirstColumn(', '->fetchNumeric(', '->executeStatement(',
            '->beginTransaction(', '->commit(', '->rollBack(',
        ];
        foreach ($patterns as $pattern) {
            if (false !== strpos($code, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function read(string $file): string
    {
        $content = file_get_contents($this->srcRoot.'/'.ltrim($file, '/'));

        return false === $content ? '' : $content;
    }

    /** @return array<int,Node\Stmt\ClassMethod> */
    private function classMethods(Class_ $node): array
    {
        $methods = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[] = $stmt;
            }
        }

        return $methods;
    }

    /**
     * @param string[] $imports
     *
     * @return array<string, mixed>
     */
    private function buildClass(string $file, string $namespace, Class_ $node, array $imports): array
    {
        $name = null === $node->name ? '' : $node->name->toString();
        $fqcn = '' !== $namespace ? $namespace.'\\'.$name : $name;
        $methods = [];
        $constructorParams = [];
        $attributes = [];
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $attributes[] = $attr->name->toString();
            }
        }
        foreach ($this->classMethods($node) as $method) {
            $methods[] = $method->name->toString();
            if ('__construct' === $method->name->toString()) {
                foreach ($method->params as $param) {
                    $constructorParams[] = [
                        'name' => $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : '',
                        'type' => $this->typeToString($param->type),
                        'byRef' => $param->byRef,
                        'variadic' => $param->variadic,
                    ];
                }
            }
        }
        $propertyTypes = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                $propertyTypes[] = $this->typeToString($stmt->type);
            }
        }

        return [
            'file' => $file,
            'namespace' => $namespace,
            'name' => $name,
            'fqcn' => $fqcn,
            'node' => $node,
            'imports' => $imports,
            'methods' => $methods,
            'constructorParams' => $constructorParams,
            'attributes' => $attributes,
            'propertyTypes' => $propertyTypes,
        ];
    }

    /**
     * @param string[] $imports
     *
     * @return array<string, mixed>
     */
    private function buildInterface(string $file, string $namespace, Interface_ $node, array $imports): array
    {
        $name = null === $node->name ? '' : $node->name->toString();
        $fqcn = '' !== $namespace ? $namespace.'\\'.$name : $name;
        $methods = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[] = $stmt->name->toString();
            }
        }
        $attributes = [];
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $attributes[] = $attr->name->toString();
            }
        }

        return [
            'file' => $file,
            'namespace' => $namespace,
            'name' => $name,
            'fqcn' => $fqcn,
            'node' => $node,
            'imports' => $imports,
            'methods' => $methods,
            'constructorParams' => [],
            'attributes' => $attributes,
            'propertyTypes' => [],
        ];
    }

    private function typeToString(?Node $type): string
    {
        if (null === $type) {
            return '';
        }
        if ($type instanceof Node\NullableType) {
            return '?'.$this->typeToString($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map([$this, 'typeToString'], $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map([$this, 'typeToString'], $type->types));
        }
        if ($type instanceof Name) {
            return $type->toString();
        }
        if ($type instanceof Node\Identifier) {
            return $type->name;
        }

        throw new \LogicException(sprintf('Unsupported type node %s', $type::class));
    }
}
