<?php

namespace App\Modules\Iam\Exceptions;

use Exception;

class InvalidCredentialException extends Exception
{
    public function __construct()
    {
        parent::__construct('Invalid login credentials.');
    }
}
