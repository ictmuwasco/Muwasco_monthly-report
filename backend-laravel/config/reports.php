<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Report document header
    |--------------------------------------------------------------------------
    | Fixed institutional header used on the monthly monitoring PDF report
    | (preserved from the legacy TCPDF implementation).
    */

    'agency'          => 'ATHI WATER WORKS DEVELOPMENT AGENCY',
    'document_title'  => 'MONITORING TEMPLATE AND DATA CAPTURE FORMAT FOR MUWASCO',
    'document_scope'  => '(PRODUCTION, WATER QUALITY, SALES, REVENUE AND EXPENDITURE)',
    'wsp_name'        => 'MUWASCO',

    /*
    |--------------------------------------------------------------------------
    | Signatories
    |--------------------------------------------------------------------------
    | Previously hard-coded in the legacy PDF generator; now configurable.
    | 'after_category' places a mid-document signature break after that many
    | categories (legacy: after the 5th, i.e. Water Quality → Technical Manager).
    */

    'min_months'      => 3,

    'signatories'     => [
        'mid' => [
            'label' => 'Above data verified by:',
            'name'  => 'PETER KARENJU - TECHNICAL MANAGER',
        ],
        'final' => [
            ['name' => 'JOSEPH MAINA (CMT) - COMMERCIAL MANAGER'],
            ['name' => "ENG. D. NG'ANG'A - MANAGING DIRECTOR"],
        ],
    ],

    'after_category_break' => 5,
];
