<?php

namespace Prashank\AiEvals\Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Prashank\AiEvals\AiEvalsServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, AiEvalsServiceProvider::class];
    }
}
