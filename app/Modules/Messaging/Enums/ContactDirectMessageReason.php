<?php

namespace App\Modules\Messaging\Enums;

enum ContactDirectMessageReason: string
{
    case InquiryFollowUp = 'inquiry_follow_up';
    case AppointmentScheduling = 'appointment_scheduling';
    case ExistingRelationship = 'existing_relationship';
    case RequestedInformation = 'requested_information';
    case ServiceFollowUp = 'service_follow_up';
    case MarketingOutreach = 'marketing_outreach';

    public function label(): string
    {
        return match ($this) {
            self::InquiryFollowUp => 'Follow up on an inquiry',
            self::AppointmentScheduling => 'Appointment or scheduling',
            self::ExistingRelationship => 'Existing customer/client conversation',
            self::RequestedInformation => 'Send requested information',
            self::ServiceFollowUp => 'Other service/business follow-up',
            self::MarketingOutreach => 'Marketing or promotional outreach',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::InquiryFollowUp => 'Continue a conversation the contact started or respond to their request.',
            self::AppointmentScheduling => 'Coordinate, confirm, or follow up about an appointment.',
            self::ExistingRelationship => 'Continue an expected one-to-one conversation with an existing customer or client.',
            self::RequestedInformation => 'Send information, documents, or details the contact asked to receive.',
            self::ServiceFollowUp => 'Use for another expected one-to-one business or service conversation.',
            self::MarketingOutreach => 'Use for promotional outreach that is not a direct response to the contact.',
        };
    }

    public function purpose(): MessagePurpose
    {
        return $this === self::MarketingOutreach
            ? MessagePurpose::Marketing
            : MessagePurpose::Transactional;
    }

    public static function defaultForPurpose(MessagePurpose|string $purpose): self
    {
        $purpose = $purpose instanceof MessagePurpose
            ? $purpose
            : MessagePurpose::from(str_replace('-', '_', strtolower(trim($purpose))));

        return $purpose === MessagePurpose::Marketing
            ? self::MarketingOutreach
            : self::ServiceFollowUp;
    }
}