<?php

namespace Prashank\AiEvals\Scorers;

use Prashank\AiEvals\Sample;

interface Scorer
{
    public const float DEFAULT_THRESHOLD = 0.7;

    /**
     * Score a sample between 0.0 and 1.0.
     */
    public function score(Sample $sample, ?string $expected = null): ScorerResult;
}
