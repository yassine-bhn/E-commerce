<?php

declare(strict_types=1);

namespace Agents\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * CMD-003 — command handlers must declare `: void` on `__invoke`.
 *
 * @implements Rule<ClassMethod>
 */
final class HandlerReturnVoidRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof ClassMethod || $node->name->toString() !== '__invoke') {
            return [];
        }

        $class = $scope->getClassReflection();
        if ($class === null || !self::isCommandHandler($class->getName())) {
            return [];
        }

        $returnType = $node->getReturnType();
        if ($returnType !== null && $returnType->toString() === 'void') {
            return [];
        }

        return [
            RuleErrorBuilder::message('CMD-003: command handler __invoke must return void')
                ->identifier('agents.cmd003.handlerReturnVoid')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private static function isCommandHandler(string $className): bool
    {
        return str_contains($className, '\\Application\\CommandHandler\\');
    }
}
