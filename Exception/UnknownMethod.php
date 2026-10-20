<?php

namespace Omniauth\Exception;

use Omniauth\Factor;

/** A factor no method was mounted for: a host's mistake, not a visitor's. */
final class UnknownMethod extends \InvalidArgumentException implements OmniauthException
{
    /** @param list<string> $mounted */
    public static function for(Factor|string $factor, array $mounted): self
    {
        return new self(\sprintf('No method is mounted for "%s"; mounted: %s.', $factor instanceof Factor ? $factor->value : $factor, $mounted ? implode(', ', $mounted) : 'none'));
    }
}
