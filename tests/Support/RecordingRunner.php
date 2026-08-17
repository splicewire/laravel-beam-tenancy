<?php

namespace Splicewire\Beam\Tenancy\Tests\Support;

use Rushing\Popcorn\Contracts\Runner;
use Rushing\Popcorn\Runner\Grant;
use Rushing\Popcorn\Runner\Manifest;
use Rushing\Popcorn\Runner\Result;

/**
 * A Runner that records exactly what it was called with and returns a canned Result — no real
 * `tofu`. Mirrors `laravel-beam-provision`'s own test-support double of the same name/shape
 * (not shared across packages — cheap enough to duplicate rather than add a cross-package
 * test-only dependency for).
 */
class RecordingRunner implements Runner
{
    public ?Manifest $manifest = null;

    public ?Grant $grant = null;

    public ?array $input = null;

    public function __construct(private readonly Result $toReturn) {}

    public function run(Manifest $manifest, Grant $grant, array $input): Result
    {
        $this->manifest = $manifest;
        $this->grant = $grant;
        $this->input = $input;

        return $this->toReturn;
    }
}
