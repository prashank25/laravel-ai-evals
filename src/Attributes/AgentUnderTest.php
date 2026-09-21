<?php

namespace Prashank\AiEvals\Attributes;

use Attribute;
use Laravel\Ai\Contracts\Agent;

/**
 * Names the agent an eval class exercises, so the providerChain data provider can enumerate its failover chain.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AgentUnderTest
{
    /**
     * @param  class-string<Agent>  $agent
     */
    public function __construct(public string $agent)
    {
        //
    }
}
