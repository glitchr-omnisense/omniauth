<?php

namespace Omniauth\Event;

use Omniauth\Context;
use Omniauth\Factor;

/** A first factor passed and a second one is due. */
final class SecondFactorRequired
{
    /**
     * @param list<Factor> $passed
     * @param list<Factor> $offered
     */
    public function __construct(
        public readonly object $account,
        public readonly array $passed,
        public readonly array $offered,
        public readonly Context $context,
    ) {
    }
}
