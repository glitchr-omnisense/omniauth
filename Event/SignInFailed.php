<?php

namespace Omniauth\Event;

use Omniauth\Context;
use Omniauth\Factor;
use Omniauth\Failure;

/** A verification failed: logged with the address and the factor, never with the secret; SignInGuard counts it. */
final class SignInFailed
{
    public function __construct(
        public readonly Factor $factor,
        public readonly Failure $failure,
        public readonly Context $context,
        public readonly ?string $identifier = null,
        public readonly ?object $account = null,
        public readonly ?string $reason = null,
    ) {
    }
}
