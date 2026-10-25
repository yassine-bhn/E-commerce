<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Kernel;

/**
 * Boots the real `prod` container in a dedicated cache directory.
 *
 * A non-debug kernel never checks whether its compiled container still matches the configuration
 * (Symfony only re-checks freshness in debug mode), so reusing `var/cache/prod` could assert a
 * policy that is no longer the one on disk. The directory name therefore carries a fingerprint of
 * the configuration: different configuration contents compile a fresh container, identical
 * contents reuse the previous one.
 *
 * What the fingerprint covers: for every file under `config/` (the YAML packages and routes plus
 * `bundles.php`, `reference.php` and `preload.php`), its path relative to `config/` and its
 * `xxh128` content hash. It is keyed on contents, never on mtimes, so a `git checkout`, a
 * `composer install` or a copy that rewrites timestamps reuses the same directory instead of
 * compiling a new container.
 *
 * What it does not cover, by design:
 * - environment values: `.env*` files and real environment variables (`APP_SECRET`,
 *   `REMEMBER_ME_LIFETIME`, `DATABASE_URL`, ...) are never read, so changing them does not
 *   invalidate a cached container;
 * - anything outside `config/`: `src/`, `public/`, `translations/`, `templates/`, `migrations/`,
 *   `composer.json` / `composer.lock` and `vendor/`.
 */
final class FreshProductionKernel extends Kernel
{
    public function getCacheDir(): string
    {
        return sprintf(
            '%s/var/cache/remember-me-policy/%s-%s',
            $this->getProjectDir(),
            $this->getEnvironment(),
            $this->configurationFingerprint(),
        );
    }

    private function configurationFingerprint(): string
    {
        $configDir = $this->getProjectDir().'/config';
        $entries = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($configDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $entries[] = sprintf(
                '%s:%s',
                substr($file->getPathname(), \strlen($configDir)),
                hash_file('xxh128', $file->getPathname()),
            );
        }
        sort($entries);

        return hash('xxh128', implode('|', $entries));
    }
}
