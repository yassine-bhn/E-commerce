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
 * EVT-002 — command handlers and sagas must not depend on an event bus; use the outbox.
 *
 * @implements Rule<ClassMethod>
 */
final class NoEventBusInCommandHandlerRule implements Rule
{
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
        if ($class === null || !self::isWriteOrchestrator($class->getName())) {
            return [];
        }

        $errors = [];
        foreach ($node->params as $param) {
            if (!$param instanceof Param) {
                continue;
            }
            $type = $param->type?->toString() ?? '';
            if (self::isEventBusType($type)) {
                $errors[] = RuleErrorBuilder::message(
                    'EVT-002: inject Outbox (or aggregate recording), not EventBusInterface; relay publishes from outbox',
                )
                    ->identifier('agents.evt002.noEventBus')
                    ->line($param->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }

    private static function isWriteOrchestrator(string $className): bool
    {
        return str_contains($className, '\\Application\\CommandHandler\\')
            || str_contains($className, '\\Application\\Saga\\');
    }

    private static function isEventBusType(string $type): bool
    {
        return str_contains($type, 'EventBus');
    }
}
