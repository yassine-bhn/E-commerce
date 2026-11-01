<?php

declare(strict_types=1);

namespace Agents\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * ISP-001 — Domain/Application port interfaces have at most 3 methods.
 *
 * @implements Rule<InClassNode>
 */
final class InterfaceMethodLimitRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof InClassNode) {
            return [];
        }

        $original = $node->getOriginalNode();
        if (!$original instanceof Interface_) {
            return [];
        }

        $className = $node->getClassReflection()->getName();
        if (1 !== preg_match('/^App\\\\[a-zA-Z0-9_]+\\\\(?:Application|Domain)(\\\\|$)/', $className)) {
            return [];
        }

        $methods = 0;
        foreach ($original->stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
                ++$methods;
            }
        }

        if ($methods <= 3) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'ISP-001: port interface may not have more than 3 methods.',
            )
                ->identifier('agents.isp001.methodLimit')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
