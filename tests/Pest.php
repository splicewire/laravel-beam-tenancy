<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Splicewire\Beam\Tenancy\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('.');
