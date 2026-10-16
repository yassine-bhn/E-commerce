<?php

declare(strict_types=1);

namespace Agents\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * DIP-003 — Application handlers/sagas depend on ports, not Doctrine or Infrastructure concretes.
 *
 * @implements Rule<ClassMethod>
 */
final class NoInfrastructureInHandlerRule implements Rule
{
    private const FORBIDDEN_TYPES = [
        'EntityManagerInterface',
        'EntityManager',
        'DocumentManager',
    ];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod || $node->name->toString() !== '__construct') {
            return [];
        }

        $class = $scope->getClassReflection();
        if ($class === null || !self::isApplicationHandler($class->getName())) {
            return [];
        }

        $errors = [];
        foreach ($node->params as $param) {
            if (!$param instanceof Param) {
                continue;
            }
            $type = $param->type?->toString() ?? '';
            if ($type === '') {
                continue;
            }
            if (self::isForbiddenType($type) || self::isInfrastructureConcrete($type)) {
                $errors[] = RuleErrorBuilder::message(
                    'DIP-003: handler constructor must type-hint domain/application ports, not infrastructure or ORM',
                )
                    ->identifier('agents.dip003.noInfrastructure')
                    ->line($param->getStartLine())
                    ->build();
            }
        }

        return $errors;
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

        return false;
    }

    private static function isInfrastructureConcrete(string $type): bool
    {
        return str_contains($type, '\\Infrastructure\\');
    }
}
