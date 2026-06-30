# Client Request Intake Template

Use this prompt when asking a new thread to convert a client request into Engage Core config files.

```text
We are generating Engage Core client config files.

Rules:
- Views remain in resources/views.
- Modules live under app/Modules.
- Integrations live under app/Integrations.
- Use lead/leads, not borrower/borrowers, in CRM/client-facing text unless explicitly told otherwise.
- Do not preserve old behavior by default.
- Do not invent columns, runtime features, or undocumented tokens.
- Messaging configs own reusable message copy and delivery templates.
- Campaign presets own journey/order/timing/template references.
- Campaign presets do not own payload/copy and do not override payload/copy.
- Campaign preset steps reference Messaging templates with first-class channel, purpose, and scope keys.
- Do not use meta.message for new Campaign preset step message references.
- Campaign messages resolve by:
  messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}
- FlowRoute presets own automation/control-flow routing.
- Webinar post-event config owns provider orchestration, not message copy.
- Default webinar configs should be vertical-neutral.
- Vertical-specific copy belongs in vertical-specific scopes such as marketing:mortgage_homebuyer_nurture.
- Email first. SMS can mirror after email passes.
- Use purpose/scope pairs:
  - transactional:webinar for confirmations/reminders/replay follow-ups.
  - marketing:webinar_nurture for attended/missed webinar nurture campaigns.
  - marketing:webinar_waitlist for waitlist notices.
  - marketing:mortgage_homebuyer_nurture for mortgage-specific long-term homebuyer nurture.
- Use dispatch keys:
  - registration_created for webinar registration confirmation/reminders.
  - consent_granted for opt-in messages.
  - webinar_ended for transactional post-webinar replay/follow-ups.
  - campaign_step_due for campaign step messages.
- Do not use marketing_message_sent for new configs.

Attached reference templates:
- README.md
- TOKEN_REFERENCE.md
- messaging-email-template.php
- messaging-sms-template.php
- campaign-presets-template.php
- contact-status-presets-template.php
- task-presets-template.php
- flow-routes-template.php
- webinar-post-event-template.php
- presets-root-template.php
- modules-template.php

Client request:
[PASTE CLIENT REQUEST HERE]

Return complete config files only, using the same structural shapes as the templates.
List any recommended new keys/tokens separately.
```