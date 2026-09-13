<?php

namespace Prashank\AiEvals;

use Illuminate\Support\ServiceProvider;

class AiEvalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-evals.php', 'ai-evals');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ai-evals.php' => config_path('ai-evals.php'),
        ], 'ai-evals-config');
    }
}
