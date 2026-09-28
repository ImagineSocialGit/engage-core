<?php

namespace App\Modules\InboundMessaging\Contracts;

use App\Modules\InboundMessaging\Data\ReplySemanticAssessment;

interface ReplySemanticAssessmentProvider
{
    public function assess(string $body): ReplySemanticAssessment;
}