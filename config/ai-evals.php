<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Judge
    |--------------------------------------------------------------------------
    |
    | The provider and model used by the LLM-as-judge scorers (judge,
    | relevance and factuality). Any provider configured in laravel/ai works.
    |
    */

    'judge' => [
        'provider' => env('AI_EVALS_JUDGE_PROVIDER', 'openai'),
        'model' => env('AI_EVALS_JUDGE_MODEL', 'gpt-5.6-luna'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | The provider and model used by the semantic similarity scorer.
    |
    */

    'embeddings' => [
        'provider' => env('AI_EVALS_EMBEDDINGS_PROVIDER', 'openai'),
        'model' => env('AI_EVALS_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verbose Output
    |--------------------------------------------------------------------------
    |
    | Print every score, threshold and the judge's reasoning while evals run.
    |
    */

    'verbose' => (bool) env('AI_EVALS_VERBOSE', false),

];
