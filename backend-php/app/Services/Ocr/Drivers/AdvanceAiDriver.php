<?php

namespace App\Services\Ocr\Drivers;

use App\Services\Ocr\Contracts\OcrDriverInterface;
use App\Services\Ocr\DTO\KtpExtractionResult;
use Exception;

class AdvanceAiDriver implements OcrDriverInterface
{
    private string $apiUrl;
    private string $accessKey;
    private string $secretKey;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->apiUrl = rtrim($config['api_url'] ?? 'https://api.advance.ai', '/');
        $this->accessKey = $config['access_key'] ?? '';
        $this->secretKey = $config['secret_key'] ?? '';
        $this->timeout = (int) ($config['timeout'] ?? 15);
    }

    public function extractKtp(string $imageContent): KtpExtractionResult
    {
        $result = new KtpExtractionResult(false, 'advance_ai');

        try {
            // Persiapkan payload base64 / binary
            $base64Data = $this->sanitizeBase64($imageContent);
            $endpoint = $this->apiUrl . '/openapi/face-identity/v1/id-card-ocr';
            $timestamp = round(microtime(true) * 1000);

            // Generate Advance.AI Signature: HMAC-SHA256(secretKey, accessKey + timestamp)
            $signature = hash_hmac('sha256', $this->accessKey . $timestamp, $this->secretKey);

            $headers = [
                'Content-Type: application/json',
                'X-ADVAI-KEY: ' . $this->accessKey,
                'X-ADVAI-TIMESTAMP: ' . $timestamp,
                'X-ADVAI-SIGNATURE: ' . $signature,
            ];

            $payload = json_encode([
                'ocrImage' => $base64Data,
                'countryCode' => 'ID',
                'cardType' => 'ID_CARD',
                'detectDirection' => true,
                'detectRisk' => true,
            ]);

            // Eksekusi HTTP Call via cURL
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception("Advance.ai cURL Error: {$curlError}");
            }

            $json = json_decode($responseBody, true);
            $result->rawResponse = $json ?? [];

            if ($httpCode !== 200 || !isset($json['code']) || $json['code'] !== 'SUCCESS') {
                $err = $json['message'] ?? "HTTP {$httpCode} Error from Advance.ai";
                throw new Exception("Advance.ai OCR failed: {$err}");
            }

            // Map data dari Advance.ai Response
            $data = $json['data'] ?? [];
            $result->success = true;
            $result->nik = $data['idNumber'] ?? $data['nik'] ?? null;
            $result->name = $data['name'] ?? null;
            $result->birthPlace = $data['placeOfBirth'] ?? $data['birthPlace'] ?? null;
            $result->birthDate = $this->formatDate($data['dateOfBirth'] ?? $data['birthDate'] ?? null);
            $result->gender = strtoupper($data['gender'] ?? '') === 'FEMALE' ? 'PEREMPUAN' : 'LAKI-LAKI';
            $result->bloodType = $data['bloodType'] ?? '-';
            $result->address = $data['address'] ?? null;
            $result->rt = $data['rt'] ?? '001';
            $result->rw = $data['rw'] ?? '001';
            $result->village = $data['village'] ?? $data['kelurahan'] ?? null;
            $result->district = $data['district'] ?? $data['kecamatan'] ?? null;
            $result->cityRegency = $data['city'] ?? $data['kabupaten'] ?? null;
            $result->province = $data['province'] ?? null;
            $result->religion = strtoupper($data['religion'] ?? 'ISLAM');
            $result->maritalStatus = strtoupper($data['maritalStatus'] ?? 'BELUM KAWIN');
            $result->occupation = strtoupper($data['occupation'] ?? 'KARYAWAN SWASTA');
            $result->nationality = $data['nationality'] ?? 'WNI';
            $result->confidence = (float) ($data['confidence'] ?? 0.98);

            return $result;
        } catch (Exception $e) {
            $result->success = false;
            $result->errorMessage = $e->getMessage();
            return $result;
        }
    }

    private function sanitizeBase64(string $input): string
    {
        if (file_exists($input)) {
            return base64_encode(file_get_contents($input));
        }
        if (str_contains($input, ';base64,')) {
            $parts = explode(';base64,', $input);
            return $parts[1] ?? $input;
        }
        return $input;
    }

    private function formatDate(?string $raw): ?string
    {
        if (!$raw) return null;
        // Tangani format DD-MM-YYYY atau YYYY-MM-DD
        if (preg_match('/^(\d{2})[- \/](\d{2})[- \/](\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return $raw;
    }
}
