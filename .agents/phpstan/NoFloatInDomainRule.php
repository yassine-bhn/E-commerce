<?php

declare(strict_types=1);

namespace Agents\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * ECO-001 — no float for money in Domain or Application layers.
 *
 * @implements Rule<Class_>
 */
final class NoFloatInDomainRule implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Class_) {
            return [];
        }

        $className = $scope->getNamespace()?->name . '\\' . $node->name->toString();
        if (!self::isDomainOrApplication($className)) {
            return [];
        }

        $errors = [];
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof Property) {
                $type = $stmt->type?->toString();
                foreach ($stmt->props as $prop) {
                    $propType = $prop->type?->toString() ?? $type;
                    $errors = array_merge($errors, self::floatError($propType, $prop->getStartLine()));
                }
            }
        }

        foreach ($node->getMethods() as $method) {
            if (!$method instanceof ClassMethod) {
                continue;
            }
            foreach ($method->params as $param) {
                if ($param instanceof Param) {
                    $errors = array_merge($errors, self::floatError($param->type?->toString(), $param->getStartLine()));
                }
            }
        }

        return $errors;
    }

    /** @return array<int, \PHPStan\Rules\RuleError> */
    private static function floatError(?string $type, int $line): array
    {
        if ($type !== 'float') {
            return [];
        }

        return [
            RuleErrorBuilder::message('ECO-001: money must never be float; use Money (int minor units + currency)')
                ->identifier('agents.eco001.noFloat')
                ->line($line)
                ->build(),
        ];
    }

    private static function isDomainOrApplication(string $className): bool
    {
        return str_contains($className, '\\Domain\\')
            || str_contains($className, '\\Application\\');
    }
}
