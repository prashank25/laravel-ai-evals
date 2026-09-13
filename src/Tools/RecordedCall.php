<?php

namespace Prashank\AiEvals\Tools;

/**
 * One tool invocation made during a run, in the order the model made it.
 */
final readonly class RecordedCall
{
    /**
     * @param  class-string|null  $class  Null when the call could not be matched to an exposed tool.
     * @param  array<string, mixed>|null  $arguments  Null when the provider did not report arguments.
     */
    public function __construct(
        public string $name,
        public ?string $class,
        public ?array $arguments,
        public int $position,
        public bool $fromProvider = false,
    ) {
        //
    }

    public function matches(string $nameOrClass): bool
    {
        return class_exists($nameOrClass)
            ? $this->class === $nameOrClass
            : $this->name === $nameOrClass;
    }
}
