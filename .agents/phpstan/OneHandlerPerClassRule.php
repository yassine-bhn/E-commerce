<?php

declare(strict_types=1);

namespace Agents\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * SRP-001 — at most one #[AsMessageHandler] attribute per class.
 *
 * @implements Rule<InClassNode>
 */
final class OneHandlerPerClassRule implements Rule
{
    private const HANDLER_ATTRIBUTE = 'Symfony\Component\Messenger\Attribute\AsMessageHandler';

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
        if (!$original instanceof Class_) {
            return [];
        }

        $count = self::countAttributes($original->attrGroups);
        foreach ($original->stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
                $count += self::countAttributes($stmt->attrGroups);
            }
        }

        if ($count <= 1) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'SRP-001: class may declare at most one #[AsMessageHandler] attribute.',
            )
                ->identifier('agents.srp001.oneHandler')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private static function countAttributes(array $attrGroups): int
    {
        $count = 0;
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (self::HANDLER_ATTRIBUTE === $attr->name->toString()) {
                    ++$count;
                }
            }
        }

        return $count;
    }
}
