<?php

declare(strict_types=1);

namespace Agents\PhpStan;

use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType as NodeTypeUnion;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * DIP-003 — Application handlers/sagas depend on ports, not Doctrine or Infrastructure concretes.
 *
 * @implements Rule<Node>
 */
final class NoInfrastructureInHandlerRule implements Rule
{
    private const FORBIDDEN_TYPES = [
        'EntityManagerInterface',
        'EntityManager',
        'DocumentManager',
    ];

    private const FORBIDDEN_PREFIXES = [
        'Doctrine\DBAL\\',
        'Doctrine\Persistence\\',
        'Doctrine\ORM\\',
        'Symfony\Component\HttpFoundation\\',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection();
        if (null === $class || !self::isApplicationHandler($class->getName())) {
            return [];
        }

        if ($node instanceof Param) {
            return self::paramErrors($node);
        }

        if ($node instanceof Property) {
            return self::typeErrors(self::typeNames($node->type), $node->getStartLine());
        }

        return [];
    }

    private static function paramErrors(Param $param): array
    {
        $errors = [];
        foreach (self::typeNames($param->type) as $type) {
            if (self::isForbiddenType($type) || self::isInfrastructureConcrete($type)) {
                $errors[] = self::error($param->getStartLine());
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
        foreach ($names as $type) {
            if (self::isForbiddenType($type) || self::isInfrastructureConcrete($type)) {
                $errors[] = self::error($line);
            }
        }

        return $errors;
    }

    private static function error(int $line): RuleError
    {
        return RuleErrorBuilder::message(
            'DIP-003: handler constructor must type-hint domain/application ports, not infrastructure or ORM',
        )
            ->identifier('agents.dip003.noInfrastructure')
            ->line($line)
            ->build();
    }

    private static function isApplicationHandler(string $className): bool
    {
        return str_contains($className, '\\Application\\CommandHandler\\')
            || str_contains($className, '\\Application\\Saga\\')
            || str_contains($className, '\\Application\\QueryHandler\\');
    }

    private static function isForbiddenType(string $type): bool
    {
        foreach (self::FORBIDDEN_TYPES as $forbidden) {
            if (str_ends_with($type, $forbidden)) {
                return true;
            }
        }

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isInfrastructureConcrete(string $type): bool
    {
        return str_contains($type, '\\Infrastructure\\');
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
