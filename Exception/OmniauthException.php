<?php

namespace Omniauth\Exception;

use Omnisense\Contracts\Exception\ExceptionInterface;

/**
 * Every exception omniauth throws. It extends omnisense/contracts' interface,
 * so that one catch holds the failures of every omni family.
 */
interface OmniauthException extends ExceptionInterface
{
}
