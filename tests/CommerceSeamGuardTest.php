<?php

use Rushing\Graphine\Testing\SeamGuard;

/**
 * The cycle guard, asserted here rather than left to a consuming app's topology test.
 *
 * `laravel-beam-commerce` REQUIRES `laravel-beam-tenancy`. A commerce symbol in this package's
 * `src/` is therefore that cycle expressed in source, whether or not composer.json ever declares
 * the edge — and a composer.json that stays clean while the source reaches across is the exact
 * shape of the drift this prefactor exists to close.
 *
 * This is the same AST scan the declared `extra.package-topology.sourceNeverReferences` rule runs
 * in a consuming app: `use` imports, group-uses and fully-qualified name references, ignoring
 * strings and comments. Prose in a docblock may still SAY "beam-commerce" — describing the seam
 * is not crossing it.
 */
it('names no beam-commerce symbol anywhere in src', function () {
    $offenders = (new SeamGuard(['Splicewire\Beam\Commerce']))->scan(__DIR__.'/../src');

    expect($offenders)->toBe([]);
});
