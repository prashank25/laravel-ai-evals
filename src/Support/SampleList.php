<?php

namespace Prashank\AiEvals\Support;

use PHPUnit\Framework\Assert;
use Prashank\AiEvals\Sample;

/**
 * Normalises what an assertion was given, one sample or many, into a list that is never empty.
 */
final class SampleList
{
    /**
     * @param  Sample|array<int, Sample>  $samples
     * @return array<int, Sample>
     */
    public static function from(Sample|array $samples): array
    {
        $samples = array_values($samples instanceof Sample ? [$samples] : $samples);

        Assert::assertNotEmpty($samples, 'No samples to evaluate.');

        return $samples;
    }
}
