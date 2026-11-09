<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default OCR Driver
    |--------------------------------------------------------------------------
    |
    | Driver aktif yang digunakan untuk ekstraksi e-KTP.
    | Pilihan: "advance_ai", "in_house"
    |
    */
    'default' => getenv('OCR_DRIVER') ?: 'advance_ai',

    /*
    |--------------------------------------------------------------------------
    | Auto Fallback
    |--------------------------------------------------------------------------
    |
    | Jika true, sistem akan otomatis mencoba In-House OCR jika Advance.ai gagal.
    |
    */
    'auto_fallback' => filter_var(getenv('OCR_AUTO_FALLBACK') ?: true, FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Driver Configurations
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'advance_ai' => [
            'api_url' => getenv('ADVANCE_AI_API_URL') ?: 'https://api.advance.ai',
            'access_key' => getenv('ADVANCE_AI_ACCESS_KEY') ?: '',
            'secret_key' => getenv('ADVANCE_AI_SECRET_KEY') ?: '',
            'timeout' => (int) (getenv('ADVANCE_AI_TIMEOUT') ?: 15),
        ],

        'in_house' => [
            'tesseract_binary' => getenv('TESSERACT_BINARY') ?: 'tesseract',
            'tessdata_dir' => getenv('TESSDATA_DIR') ?: '',
            'python_helper_script' => getenv('PYTHON_OCR_SCRIPT') ?: null,
        ],
    ],
];
