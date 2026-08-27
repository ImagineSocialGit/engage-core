<?php

namespace App\Modules\Forms\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Services\FormSubmissionReadService;
use Illuminate\Contracts\View\View;

final class FormSubmissionController extends Controller
{
    public function index(
        FormDefinition $formDefinition,
        FormSubmissionReadService $submissions,
    ): View {
        return view('crm.forms.submissions.index', [
            'title' => 'Form submissions',
            'heading' => $formDefinition->name,
            'subheading' => 'Review recent submissions received for this form.',
            'form' => $submissions->formSummary($formDefinition),
            'submissions' => $submissions->forForm($formDefinition),
        ]);
    }

    public function show(
        FormSubmission $formSubmission,
        FormSubmissionReadService $submissions,
    ): View {
        return view('crm.forms.submissions.show', [
            'title' => 'Form submission',
            'heading' => 'Form submission',
            'subheading' => 'Review the submitted answers and the outcome evidence stored by Forms.',
            'submission' => $submissions->detail($formSubmission),
        ]);
    }
}