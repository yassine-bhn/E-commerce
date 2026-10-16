<?php

declare(strict_types=1);

namespace Agents\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * QRY-001 — query/read-model code must not persist, flush, or dispatch.
 *
 * @implements Rule<MethodCall>
 */
final class NoWriteInQueryHandlerRule implements Rule
{
    private const FORBIDDEN = ['persist', 'flush', 'dispatch', 'executeStatement'];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof MethodCall) {
            return [];
        }

        $class = $scope->getClassReflection();
        if ($class === null || !self::isQuerySide($class->getName())) {
            return [];
        }

        $methodName = $node->name->toString();
        if (!in_array($methodName, self::FORBIDDEN, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'QRY-001: query side must never call %s(); use the write model or outbox instead',
                $methodName,
            ))
                ->identifier('agents.qry001.noWrite')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private static function isQuerySide(string $className): bool
    {
        return str_contains($className, '\\Infrastructure\\ReadModel\\')
            || str_contains($className, '\\Application\\QueryHandler\\');
    }
}
