<?php

namespace Prashank\AiEvals\Tools;

/**
 * A tool the agent made available to the model, identified by the class that does the work.
 */
final readonly class ExposedTool
{
    /**
     * @param  class-string  $class  The class the tool resolves to once wrappers are removed.
     * @param  string|null  $name  The name sent to the model, null for provider tools whose name is provider-specific.
     */
    public function __construct(
        public string $class,
        public ?string $name,
        public bool $fromProvider = false,
    ) {
        //
    }
}
