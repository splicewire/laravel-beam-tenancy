<?php

/**
 * analytics-area 25: a host keeps this package's PUBLISHED migrations after `composer remove` (laravel-beam-commerce
 * publishes four of them through its closure, and declares its removal result DATA RETAINED), so a stub's code may name
 * only classes the host keeps: Laravel's and `rushing/laravel-schema-convergence`'s (which laravel-beam requires).
 * grant_analytics_view_to_admin.php.stub named spatie/laravel-permission classes and failed commerce's
 * RemovalResultMigrationsTest; it now reaches spatie by class-name string, and only when spatie is installed.
 *
 * The same rule as that test: tokenize each stub (comments are not code) and refuse any qualified class name outside
 * `Illuminate\` and `Rushing\SchemaConvergence\`. Pinned here, so the package that ships the stubs catches it first.
 */
it('names only classes a Beam host keeps after composer remove, in every migration stub', function () {
    $root = dirname(__DIR__).'/database/migrations';
    $stubs = [];
    $foreign = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_contains($file->getFilename(), '.php')) {
            continue;
        }
        $stubs[] = $file->getPathname();

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = ltrim($token[1], '\\');
            if (! str_starts_with($name, 'Illuminate\\') && ! str_starts_with($name, 'Rushing\\SchemaConvergence\\')) {
                $foreign[] = basename($file->getPathname()).': '.$name;
            }
        }
    }

    // Not vacuous: the guard read the stubs, the analytics back-fill among them.
    expect($stubs)->not->toBeEmpty()
        ->and(array_map('basename', $stubs))->toContain('grant_analytics_view_to_admin.php.stub')
        ->and($foreign)->toBe([]);
});
