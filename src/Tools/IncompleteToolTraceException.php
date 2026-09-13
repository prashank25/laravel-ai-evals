<?php

namespace Prashank\AiEvals\Tools;

use RuntimeException;

/**
 * Thrown when an assertion needs tool call details the provider did not return.
 */
final class IncompleteToolTraceException extends RuntimeException
{
    //
}
