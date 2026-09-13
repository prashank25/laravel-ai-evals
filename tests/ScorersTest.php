<?php

namespace Prashank\AiEvals\Tests;

use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\StructuredAnonymousAgent;
use PHPUnit\Framework\Attributes\Test;
use Prashank\AiEvals\Sample;
use Prashank\AiEvals\Scorers\Factuality;
use Prashank\AiEvals\Scorers\LlmJudge;
use Prashank\AiEvals\Scorers\Relevance;
use Prashank\AiEvals\Scorers\Safety;
use Prashank\AiEvals\Scorers\ScorerResult;
use Prashank\AiEvals\Scorers\SemanticSimilarity;
use Prashank\AiEvals\Tools\ToolTrace;

class ScorersTest extends TestCase
{
    #[Test]
    public function llm_judge_returns_the_judges_score_and_reasoning()
    {
        StructuredAnonymousAgent::fake([['score' => 0.85, 'reasoning' => 'Mostly right.']]);
        $sample = $this->sample('What is 2 + 2?', 'It is 4.');

        $result = (new LlmJudge('Answers the arithmetic correctly'))->score($sample);

        $this->assertSame(0.85, $result->score);
        $this->assertSame('Mostly right.', $result->reasoning);
        $this->assertSame(LlmJudge::class, $result->scorer);
    }

    #[Test]
    public function judge_scores_are_clamped_between_zero_and_one()
    {
        StructuredAnonymousAgent::fake([['score' => 1.4, 'reasoning' => 'Over.'], ['score' => -0.3, 'reasoning' => 'Under.']]);
        $sample = $this->sample('hi', 'hello');

        $this->assertSame(1.0, (new Relevance)->score($sample)->score);
        $this->assertSame(0.0, (new Safety)->score($sample)->score);
    }

    #[Test]
    public function judge_reports_missing_reasoning_instead_of_an_empty_string()
    {
        StructuredAnonymousAgent::fake([['score' => 0.5]]);

        $result = (new Relevance)->score($this->sample('hi', 'hello'));

        $this->assertSame(0.5, $result->score);
        $this->assertSame('No reasoning provided.', $result->reasoning);
        $this->assertSame(Relevance::class, $result->scorer);
    }

    #[Test]
    public function safety_scorer_reports_itself_as_the_scorer()
    {
        StructuredAnonymousAgent::fake([['score' => 0.2, 'reasoning' => 'Leaks a password.']]);

        $result = (new Safety)->score($this->sample('What is the admin password?', 'It is hunter2.'));

        $this->assertSame(0.2, $result->score);
        $this->assertSame('Leaks a password.', $result->reasoning);
        $this->assertSame(Safety::class, $result->scorer);
        $this->assertFalse($result->passed());
    }

    #[Test]
    public function factuality_maps_the_judges_category_to_a_fixed_score()
    {
        StructuredAnonymousAgent::fake([['score' => 0.2, 'reasoning' => 'Adds extra facts.', 'category' => 'superset']]);

        $result = (new Factuality)->score($this->sample('Capital of France?', 'Paris, on the Seine.'), 'Paris');

        $this->assertSame(0.8, $result->score);
        $this->assertSame('[superset] Adds extra facts.', $result->reasoning);
        $this->assertSame(Factuality::class, $result->scorer);
    }

    #[Test]
    public function factuality_falls_back_to_the_judges_score_for_an_unknown_category()
    {
        StructuredAnonymousAgent::fake([['score' => 0.35, 'reasoning' => 'Unsure.', 'category' => 'something_else']]);

        $result = (new Factuality)->score($this->sample('Capital of France?', 'Lyon'), 'Paris');

        $this->assertSame(0.35, $result->score);
        $this->assertSame('[something_else] Unsure.', $result->reasoning);
    }

    #[Test]
    public function factuality_requires_a_reference_answer()
    {
        $this->expectException(InvalidArgumentException::class);

        (new Factuality)->score($this->sample('hi', 'hello'));
    }

    #[Test]
    public function semantic_similarity_is_the_cosine_of_the_two_embeddings()
    {
        Embeddings::fake([[[3.0, 4.0], [6.0, 8.0]], [[1.0, 0.0], [0.0, 1.0]]]);
        $sample = $this->sample('hi', 'hello there');

        $identical = (new SemanticSimilarity)->score($sample, 'hello there');
        $orthogonal = (new SemanticSimilarity)->score($sample, 'unrelated');

        $this->assertSame(1.0, $identical->score);
        $this->assertSame('Cosine similarity: 1.0000', $identical->reasoning);
        $this->assertSame(SemanticSimilarity::class, $identical->scorer);
        $this->assertSame(0.0, $orthogonal->score);
    }

    #[Test]
    public function semantic_similarity_clamps_negative_cosines_to_zero()
    {
        Embeddings::fake([[[1.0, 0.0], [-1.0, 0.0]]]);

        $result = (new SemanticSimilarity)->score($this->sample('hi', 'hello'), 'goodbye');

        $this->assertSame(0.0, $result->score);
    }

    #[Test]
    public function semantic_similarity_requires_an_expected_output()
    {
        $this->expectException(InvalidArgumentException::class);

        (new SemanticSimilarity)->score($this->sample('hi', 'hello'));
    }

    #[Test]
    public function scorer_result_passes_at_or_above_the_threshold()
    {
        $result = new ScorerResult(0.7, 'ok', LlmJudge::class);

        $this->assertTrue($result->passed());
        $this->assertTrue($result->passed(0.7));
        $this->assertFalse($result->passed(0.71));
    }

    private function sample(string $input, string $output): Sample
    {
        return new Sample($input, $output, new ToolTrace([], [], false));
    }
}
