<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Splicewire\Beam\MultiTenancy\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('.');
