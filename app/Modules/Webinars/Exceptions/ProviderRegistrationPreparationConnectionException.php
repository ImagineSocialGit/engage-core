<?php

namespace App\Modules\Webinars\Exceptions;

use RuntimeException;

class ProviderRegistrationPreparationConnectionException extends RuntimeException
{
    // The provider registration request was never submitted, so retry is safe.
}