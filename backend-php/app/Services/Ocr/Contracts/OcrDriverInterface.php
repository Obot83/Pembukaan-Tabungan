<?php

namespace App\Services\Ocr\Contracts;

use App\Services\Ocr\DTO\KtpExtractionResult;

interface OcrDriverInterface
{
    /**
     * Ekstraksi data identitas e-KTP dari file path atau base64 image string.
     *
     * @param string $imageContent Base64 encoded string atau absolute file path
     * @return KtpExtractionResult
     * @throws \Exception
     */
    public function extractKtp(string $imageContent): KtpExtractionResult;
}
