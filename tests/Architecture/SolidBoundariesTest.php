<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Architecture\Support\SourceTree;
use PHPUnit\Framework\TestCase;

final class SolidBoundariesTest extends TestCase
{
    private const ALLOWED_CONTROLLER_DEPS = [
        'MessageBusInterface',
        'MessengerQuery',
        'RateLimiterFactoryInterface',
        'RateLimitChecker',
        'Security',
        'LoggerInterface',
        'ClockInterface',
    ];

    private const SERVICE_SUFFIXES = [
        'Controller', 'Handler', 'Subscriber', 'Adapter', 'Store',
        'Repository', 'Finder', 'Indexer', 'Checker', 'Guard',
        'Policy', 'Service',
    ];

    private static function tree(): SourceTree
    {
        return new SourceTree();
    }

    private static function isScalarOrArray(string $type): bool
    {
        $t = ltrim($type, '?');
        $scalars = ['int', 'string', 'bool', 'float', 'array', 'mixed', 'callable', 'iterable', 'void', 'null', 'object', 'false', 'true', ''];
        if (in_array(strtolower($t), $scalars, true)) {
            return true;
        }
        if (str_contains($t, '|') || str_contains($t, '&')) {
            $parts = preg_split('/[|&]/', $t);
            if (false === $parts) {
                return false;
            }
            foreach ($parts as $part) {
                $part = trim($part);
                if ('' === $part || in_array(strtolower($part), $scalars, true)) {
                    continue;
                }

                return false;
            }

            return true;
        }

        return false;
    }

    private static function shortName(string $type): string
    {
        $type = ltrim($type, '?');
        if (str_contains($type, '|')) {
            $type = explode('|', $type)[0];
        }
        $parts = explode('\\', $type);

        return end($parts);
    }

    private static function isForbiddenType(string $type): bool
    {
        $type = ltrim($type, '?');
        foreach (['Doctrine\\DBAL\\', 'Doctrine\\ORM\\', 'Doctrine\\Persistence\\', 'Symfony\\Component\\HttpFoundation\\'] as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }
        if (preg_match('#^App\\\\[^\\\\]+\\\\Infrastructure\\\\#', $type)) {
            return true;
        }

        return false;
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function applicationForbiddenTypeProvider(): array
    {
        $cases = [];
        foreach (self::tree()->classes() as $class) {
            if (!preg_match('#^[^/]+/Application/#', $class['file'])) {
                continue;
            }
            $found = null;
            foreach ($class['imports'] as $import) {
                if (self::isForbiddenType($import)) {
                    $found = $import;
                    break;
                }
            }
            if (null === $found) {
                foreach ($class['constructorParams'] as $param) {
                    if (self::isForbiddenType($param['type'])) {
                        $found = $param['type'];
                        break;
                    }
                }
            }
            if (null === $found) {
                foreach ($class['propertyTypes'] as $type) {
                    if (self::isForbiddenType($type)) {
                        $found = $type;
                        break;
                    }
                }
            }
            if (null !== $found) {
                $cases[$class['file']] = [$class['file'], $found];
            }
        }

        if ([] === $cases) {
            $cases['__none__'] = ['__none__', 'none'];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('applicationForbiddenTypeProvider')]
    public function itKeepsApplicationFreeOfForbiddenTypes(string $file, string $type): void
    {
        if ('__none__' === $file) {
            self::assertSame('none', $type, 'no Application class imports or type-hints a forbidden type');

            return;
        }
        self::fail(sprintf('%s uses forbidden type %s', $file, $type));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function thinControllerProvider(): array
    {
        $cases = [];
        foreach (self::tree()->classes() as $class) {
            if (!preg_match('#^[^/]+/Infrastructure/Http/#', $class['file'])) {
                continue;
            }
            if (!str_ends_with($class['name'], 'Controller')) {
                continue;
            }
            $bad = null;
            foreach ($class['constructorParams'] as $param) {
                $type = $param['type'];
                if (self::isScalarOrArray($type)) {
                    continue;
                }
                $short = self::shortName($type);
                if (!in_array($short, self::ALLOWED_CONTROLLER_DEPS, true)) {
                    $bad = $type;
                    break;
                }
            }
            if (null !== $bad) {
                $cases[$class['file']] = [$class['file'], $bad];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('thinControllerProvider')]
    public function itKeepsControllersThin(string $file, string $type): void
    {
        self::fail(sprintf('%s constructs with disallowed dependency %s', $file, $type));
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function oneHandlerProvider(): array
    {
        $cases = [];
        $methodCounts = [];
        foreach (self::tree()->attributes() as $a) {
            if (str_contains($a['attribute'], 'AsMessageHandler')) {
                $methodCounts[$a['class']] = ($methodCounts[$a['class']] ?? 0) + 1;
            }
        }
        foreach (self::tree()->classes() as $class) {
            $mc = $methodCounts[$class['fqcn']] ?? 0;
            if ($mc > 1) {
                $cases[$class['file'].'#'.$class['name']] = [$class['file'], $mc];
            }
        }
        if ([] === $cases) {
            $cases['__none__'] = ['__none__', 0];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('oneHandlerProvider')]
    public function itAllowsOneHandlerPerClass(string $file, int $count): void
    {
        if ('__none__' === $file) {
            self::assertSame(0, $count, 'no class carries more than one AsMessageHandler');

            return;
        }
        self::fail(sprintf('%s has %d AsMessageHandler attributes', $file, $count));
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function portMethodLimitProvider(): array
    {
        $cases = [];
        foreach (self::tree()->interfaces() as $iface) {
            if (!preg_match('#^[^/]+/(Domain|Application)/#', $iface['file'])) {
                continue;
            }
            if (count($iface['methods']) > 3) {
                $cases[$iface['file'].'#'.$iface['name']] = [$iface['file'], count($iface['methods'])];
            }
        }
        if ([] === $cases) {
            $cases['__none__'] = ['__none__', 0];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('portMethodLimitProvider')]
    public function itLimitsPortsToThreeMethods(string $file, int $count): void
    {
        if ('__none__' === $file) {
            self::assertSame(0, $count, 'no Domain/Application port exceeds 3 methods');

            return;
        }
        self::fail(sprintf('%s has %d methods', $file, $count));
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function constructorDependencyProvider(): array
    {
        $cases = [];
        foreach (self::tree()->classes() as $class) {
            $suffixMatch = false;
            foreach (self::SERVICE_SUFFIXES as $suffix) {
                if (str_ends_with($class['name'], $suffix)) {
                    $suffixMatch = true;
                    break;
                }
            }
            if (!$suffixMatch) {
                continue;
            }
            $classTyped = 0;
            foreach ($class['constructorParams'] as $param) {
                if (!self::isScalarOrArray($param['type']) && '' !== $param['type']) {
                    ++$classTyped;
                }
            }
            if ($classTyped > 5) {
                $cases[$class['file'].'#'.$class['name']] = [$class['file'], $classTyped];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('constructorDependencyProvider')]
    public function itLimitsConstructorDependencies(string $file, int $count): void
    {
        self::fail(sprintf('%s has %d class-typed constructor params', $file, $count));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function portsInInnerLayersProvider(): array
    {
        $tree = self::tree();
        $classes = $tree->classes();
        $interfacesByFqcn = [];
        foreach ($tree->interfaces() as $iface) {
            $interfacesByFqcn[$iface['fqcn']] = $iface;
        }
        $cases = [];
        foreach ($classes as $class) {
            if (!preg_match('#^[^/]+/Application/#', $class['file'])) {
                continue;
            }
            foreach ($class['imports'] as $import) {
                if (isset($interfacesByFqcn[$import])) {
                    $iface = $interfacesByFqcn[$import];
                    if (preg_match('#^[^/]+/Infrastructure/#', $iface['file'])) {
                        $cases[$class['file'].'::'.$import] = [$class['file'], $import];
                    }
                }
            }
        }
        foreach ($classes as $class) {
            if (!preg_match('#^[^/]+/(Domain|Application)/#', $class['file'])) {
                continue;
            }
            foreach ($class['node']->implements ?? [] as $impl) {
                $implName = $impl instanceof \PhpParser\Node\Name ? $impl->toString() : (string) $impl;
                foreach ($interfacesByFqcn as $fqcn => $iface) {
                    if (str_ends_with($fqcn, '\\'.$implName) || $fqcn === $implName) {
                        if (preg_match('#^[^/]+/(Domain|Application)/#', $iface['file'])) {
                            $cases[$class['file'].'::implements '.$fqcn] = [$class['file'], $fqcn];
                        }
                    }
                }
            }
        }
        if (empty($cases)) {
            $cases['__none__'] = [null, null];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('portsInInnerLayersProvider')]
    public function itKeepsPortsInInnerLayers(?string $file, ?string $type): void
    {
        if (null === $file) {
            self::assertNull($type);

            return;
        }
        self::fail(sprintf('%s violates port layering with %s', $file, $type));
    }

    /** @return array<string, array{0:?string, 1:?string}> */
    public static function serviceLocationProvider(): array
    {
        $cases = [];
        foreach (self::tree()->files() as $file) {
            if (str_contains($file, '/Kernel/')) {
                continue;
            }
            if (str_contains($file, 'Compiler') || str_contains($file, 'DependencyInjection')) {
                continue;
            }
            $code = self::tree()->read($file);
            $reason = null;
            if (str_contains($code, 'ContainerInterface')) {
                $reason = 'ContainerInterface';
            } elseif (str_contains($code, 'static::getContainer()')) {
                $reason = 'static::getContainer()';
            } elseif (preg_match('/[Cc]ontainer[^;]{0,40}->get\s*\(/', $code)) {
                $reason = 'container->get(';
            }
            if (null !== $reason) {
                $cases[$file] = [$file, $reason];
            }
        }
        if (empty($cases)) {
            $cases['__none__'] = [null, null];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('serviceLocationProvider')]
    public function itForbidsServiceLocation(?string $file, ?string $reason): void
    {
        if (null === $file) {
            self::assertNull($reason);

            return;
        }
        self::fail(sprintf('%s uses service location (%s)', $file, $reason));
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function matchTrueProvider(): array
    {
        $cases = [];
        foreach (self::tree()->files() as $file) {
            if (!preg_match('#^[^/]+/(Domain|Application)/#', $file)) {
                continue;
            }
            $code = self::tree()->read($file);
            if (str_contains($code, 'match (true)') || str_contains($code, 'match(true)')) {
                $cases[$file] = [$file, 1];
            }
        }
        if ([] === $cases) {
            $cases['__none__'] = ['__none__', 0];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('matchTrueProvider')]
    public function itForbidsMatchTrueInApplicationAndDomain(string $file, int $violations): void
    {
        if ('__none__' === $file) {
            self::assertSame(0, $violations, 'no Domain/Application code uses match (true)');

            return;
        }
        self::fail(sprintf('%s uses match (true)', $file));
    }

    /** @return array<string, array{0:string}> */
    public static function sqlInHttpProvider(): array
    {
        $cases = [];
        foreach (self::tree()->files() as $file) {
            if (!preg_match('#^[^/]+/Infrastructure/Http/#', $file)) {
                continue;
            }
            if (self::tree()->hasSqlCall($file)) {
                $cases[$file] = [$file];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('sqlInHttpProvider')]
    public function itForbidsSqlInHttp(string $file): void
    {
        self::fail(sprintf('%s contains SQL calls in HTTP layer', $file));
    }
}
