<?php

return [
    'title' => 'Legacy migration review',
    'subtitle' => 'Suggested English/Arabic provider pairs. Nothing is merged until an administrator approves it, and every approval can be undone.',
    'privacy_notice' => 'This page shows real provider data and is visible to administrators only. Do not copy it into tickets, chat or logs.',
    'filter_status' => 'Suggestion status',
    'loading' => 'Loading…',
    'empty' => 'There are no suggestions in this state.',
    'suggestion' => 'Suggestion',
    'missing_side' => 'This side has no provider data.',
    'conflict_title' => 'Values of the Arabic record that differ from the canonical (English) provider. They are preserved for review and used if the approval is undone.',
    'confirm_approve' => 'Merge these two records into one provider? The English values stay canonical and the Arabic record is archived. You can undo this.',
    'confirm_undo' => 'Undo this approval and restore the two separate providers?',
    'signal_count' => '{1} :count signal|[2,*] :count signals',

    'statuses' => ['suggested' => 'To review', 'approved' => 'Approved', 'rejected' => 'Rejected'],
    'actions' => ['approve' => 'Approve pair', 'reject' => 'Reject', 'undo' => 'Undo approval'],
    'side' => ['en' => 'English record', 'ar' => 'Arabic record'],
    'fields' => [
        'name' => 'Name', 'address' => 'Address', 'city' => 'City', 'district' => 'District',
        'type' => 'Type', 'phone' => 'Phone', 'map_url' => 'Map URL', 'source' => 'Source',
    ],
    'signals' => [
        'same_source_id' => 'Same legacy id',
        'same_phone_normalized' => 'Same phone number',
        'same_map_url' => 'Same map link',
        'same_coordinates' => 'Same coordinates',
    ],
    'notices' => [
        'approved' => 'The pair was merged into one provider.',
        'rejected' => 'The suggestion was rejected. The records stay separate.',
        'undone' => 'The approval was undone and the providers were separated again.',
        'refused' => 'The action could not be completed. The records may have changed; reload the page.',
    ],
];
