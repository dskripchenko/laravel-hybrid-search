<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Tests\TestCase;
use Dskripchenko\HybridSearch\Tests\VectorTestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(VectorTestCase::class)->in('Vector');
