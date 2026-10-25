<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Support;

/**
 * Runs an operation that is expected to be refused and hands the failure back to the test.
 *
 * TDD-006 keeps `try/catch` out of test methods; a test still needs the throwable to assert its
 * class and to read the state the refused operation left behind, so the catch lives here. Nothing
 * is swallowed: a success is reported as a failure of the test that expected a refusal.
 */
final class ApprovalAttempt
{
    public static function failureOf(callable $attempt): \Throwable
    {
        try {
            $attempt();
        } catch (\Throwable $failure) {
            return $failure;
        }

        throw new \LogicException('The operation was expected to be refused, but it succeeded.');
    }
}
