<?php

return [
    // One name for public pages, navigation, verification and printed records.
    'name' => env('CLINIC_NAME') ?: 'Shreeji Clinic',
    // Keep an existing provider-approved SMS identity separate from display branding.
    'sms_sender_mask' => env('SMS_SENDER_MASK'),
];
