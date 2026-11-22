<?php

/*
| Only friendlier field names are defined here; every message text comes from the framework's
| English file (translation files merge key by key, so nothing else needs to be duplicated).
*/

$fields = [
    'name' => 'name',
    'address' => 'address',
    'city' => 'city',
    'district' => 'district',
    'specialization' => 'specialization',
    'hours' => 'working hours',
    'discount_text' => 'discount',
    'package_text' => 'package',
];
$languages = ['ar' => 'Arabic', 'en' => 'English'];

$translated = [];
foreach ($languages as $locale => $label) {
    foreach ($fields as $field => $name) {
        $translated["translations.$locale.$field"] = "$label $name";
    }
}

return [
    'attributes' => $translated + [
        'provider_type_id' => 'type',
        'map_url' => 'map link',
        'is_active' => 'status',
        'name_en' => 'English name',
        'name_ar' => 'Arabic name',
        'sort_order' => 'sort order',
        'translations' => 'translations',
        'from' => 'from date',
        'to' => 'to date',
    ],
];
