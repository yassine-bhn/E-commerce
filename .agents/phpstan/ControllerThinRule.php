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
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * SRP-002 — Controllers may only inject the HTTP↔bus translation collaborators.
 *
 * @implements Rule<Node>
 */
final class ControllerThinRule implements Rule
{
    private const ALLOWED_TYPES = [
        'Symfony\Component\Messenger\MessageBusInterface',
        'App\Shared\Infrastructure\Http\MessengerQuery',
        'Symfony\Component\RateLimiter\RateLimiterFactoryInterface',
        'App\Shared\Infrastructure\Http\RateLimitChecker',
        'Symfony\Bundle\SecurityBundle\Security',
        'Psr\Log\LoggerInterface',
        'Symfony\Component\Clock\ClockInterface',
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
        if (null === $class || !str_ends_with($class->getName(), 'Controller')) {
            return [];
        }

        if (1 !== preg_match('/^App\\\\[a-zA-Z0-9_]+\\\\Infrastructure\\\\Http(\\\\|$)/', $class->getName())) {
            return [];
        }

        $errors = [];
        foreach ($node->params as $param) {
            foreach (self::typeNames($param->type) as $name) {
                if (!\in_array($name, self::ALLOWED_TYPES, true)) {
                    $errors[] = self::error($name, $param->getStartLine());
                }
            }
        }

        return $errors;
    }

    private static function error(string $type, int $line): RuleError
    {
        return RuleErrorBuilder::message(
            \sprintf('SRP-002: controller may not depend on %s.', $type),
        )
            ->identifier('agents.srp002.controller')
            ->line($line)
            ->build();
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
