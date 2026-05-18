<?php

declare(strict_types=1);

namespace PorscheConnect\Exception;

class PorscheCaptchaRequiredException extends PorscheException
{
    public function __construct(
        public readonly string $captcha,
        public readonly string $state,
    ) {
        parent::__construct('CAPTCHA_REQUIRED', 'Captcha verification required');
    }
}
