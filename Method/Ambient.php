<?php

namespace Omniauth\Method;

use Omniauth\Context;

/**
 * A second factor the visitor does not present: it is read from the context
 * (the trusted-device cookie). The conductor tries it by itself when a second
 * factor is required, and asks it what to leave on the browser when the
 * visitor asked to be trusted after passing another one.
 */
interface Ambient extends Method
{
    /** What the host keeps on the browser (a cookie's value) so that this factor passes next time. */
    public function remember(object $account, Context $context): string;
}
