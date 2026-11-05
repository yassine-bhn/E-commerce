<?php

declare(strict_types=1);

namespace Agents\PhpStan;

use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType as NodeTypeUnion;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * DIP-003 — Application must not import or type-hint Doctrine, HttpFoundation or Infrastructure.
 *
 * @implements Rule<Node>
 */
final class NoDoctrineInApplicationRule implements Rule
{
    private const FORBIDDEN_PREFIXES = [
        'Doctrine\DBAL\\',
        'Doctrine\ORM\\',
        'Doctrine\Persistence\\',
        'Symfony\Component\HttpFoundation\\',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = $scope->getNamespace() ?? '';
        if (!self::isApplicationNamespace($namespace)) {
            return [];
        }

        if ($node instanceof Use_) {
            return self::useErrors($node);
        }

        if ($node instanceof Param) {
            return self::typeErrors(self::typeNames($node->type), $node->getStartLine());
        }

        if ($node instanceof ClassMethod) {
            return self::typeErrors(self::typeNames($node->returnType), $node->getStartLine());
        }

        if ($node instanceof Property) {
            return self::typeErrors(self::typeNames($node->type), $node->getStartLine());
        }

        return [];
    }

    private static function useErrors(Use_ $node): array
    {
        if (Use_::TYPE_NORMAL !== $node->type) {
            return [];
        }

        $errors = [];
        foreach ($node->uses as $use) {
            $name = $use->name->toString();
            if (self::isForbidden($name)) {
                $errors[] = self::error($name, $use->getStartLine());
            }
        }

        return $errors;
    }

    /**
     * @param string[] $names
     *
     * @return RuleError[]
     */
    private static function typeErrors(array $names, int $line): array
    {
        $errors = [];
        foreach ($names as $name) {
            if (self::isForbidden($name)) {
                $errors[] = self::error($name, $line);
            }
        }

        return $errors;
    }

    private static function error(string $type, int $line): RuleError
    {
        return RuleErrorBuilder::message(
            \sprintf('DIP-003: application may not depend on %s.', $type),
        )
            ->identifier('agents.dip003.application')
            ->line($line)
            ->build();
    }

    private static function isForbidden(string $type): bool
    {
        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return 1 === preg_match('/^App\\\\(?:[a-zA-Z0-9_]+\\\\)*Infrastructure\\\\/', $type);
    }

    private static function isApplicationNamespace(string $namespace): bool
    {
        return 1 === preg_match('/^App\\\\[a-zA-Z0-9_]+\\\\Application(\\\\|$)/', $namespace);
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
