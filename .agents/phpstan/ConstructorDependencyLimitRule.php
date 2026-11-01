<?php

declare(strict_types=1);

namespace Agents\PhpStan;

use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType as NodeTypeUnion;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * ISP-003 — service classes have at most 5 constructor dependencies.
 *
 * @implements Rule<Node>
 */
final class ConstructorDependencyLimitRule implements Rule
{
    private const SERVICE_SUFFIXES = [
        'Controller',
        'Handler',
        'Subscriber',
        'Adapter',
        'Store',
        'Repository',
        'Finder',
        'Indexer',
        'Checker',
        'Guard',
        'Policy',
        'Service',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod || '__construct' !== $node->name->toString()) {
            return [];
        }

        $class = $scope->getClassReflection();
        if (null === $class) {
            return [];
        }

        $shortName = substr($class->getName(), (int) strrpos($class->getName(), '\\') + 1);
        foreach (self::SERVICE_SUFFIXES as $suffix) {
            if (str_ends_with($shortName, $suffix)) {
                return self::errorsFor($node);
            }
        }

        return [];
    }

    private static function errorsFor(ClassMethod $node): array
    {
        $dependencies = 0;
        foreach ($node->params as $param) {
            if ([] !== self::typeNames($param->type)) {
                ++$dependencies;
            }
        }

        if ($dependencies <= 5) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'ISP-003: service constructor has more than 5 dependencies.',
            )
                ->identifier('agents.isp003.dependencyLimit')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /**
     * @return string[]
     */
    private static function typeNames(?Node $type): array
    {
        if (null === $type) {
            return [];
        }
        if ($type instanceof NullableType) {
            return self::typeNames($type->type);
        }
        if ($type instanceof NodeTypeUnion || $type instanceof IntersectionType) {
            $names = [];
            foreach ($type->types as $inner) {
                $names = [...$names, ...self::typeNames($inner)];
            }

            return $names;
        }
        if ($type instanceof Name) {
            return [$type->toString()];
        }

        return [];
    }
}
