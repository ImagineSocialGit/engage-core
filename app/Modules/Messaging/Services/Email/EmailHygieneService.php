<?php

namespace App\Modules\Messaging\Services\Email;

use App\Modules\Messaging\Data\Email\EmailHygieneResult;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Services\MessageSuppressionService;

class EmailHygieneService
{
    public function __construct(
        private readonly EmailDomainHealthChecker $domainHealthChecker,
        private readonly MessageSuppressionService $messageSuppressionService,
    ) {}

    public function inspect(string $email): EmailHygieneResult
    {
        $email = strtolower(trim($email));

        if ($email === ''
            || mb_strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return EmailHygieneResult::invalid($email, 'invalid_format');
        }

        if ($this->messageSuppressionService->isSuppressed(
            MessageChannel::Email,
            $email,
        )) {
            return EmailHygieneResult::suppressed($email);
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        $mailRoute = $this->domainHealthChecker->hasMailRoute($domain);

        return match ($mailRoute) {
            true => EmailHygieneResult::valid($email),
            false => EmailHygieneResult::invalid($email, 'no_mail_route'),
            null => EmailHygieneResult::unknown($email),
        };
    }
}