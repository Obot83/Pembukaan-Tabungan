<?php

namespace App\Http\Controllers;

use App\Services\Ocr\OcrManager;
use Exception;

class KtpOcrController
{
    private OcrManager $ocrManager;

    public function __construct(?OcrManager $ocrManager = null)
    {
        $this->ocrManager = $ocrManager ?: new OcrManager();
    }

    /**
     * Endpoint: POST /api/v1/ocr/ktp
     */
    public function extractKtp()
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            // Ambil input JSON atau Multipart Form Data
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $imageContent = null;

            if (isset($input['image'])) {
                $imageContent = $input['image'];
            } elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $imageContent = $_FILES['image']['tmp_name'];
            }

            if (empty($imageContent)) {
                http_response_code(422);
                echo json_encode([
                    'status' => 'ERROR',
                    'message' => 'Parameter [image] wajib diisi (berupa Base64 string atau file upload).',
                    'data' => null
                ]);
                return;
            }

            // Opsi untuk override driver lewat request query parameter atau header (misal: ?driver=in_house)
            $preferredDriver = $_GET['driver'] ?? null;

            // Eksekusi Ekstraksi OCR
            $result = $this->ocrManager->extract($imageContent, $preferredDriver);

            if (!$result->success) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'FAILED',
                    'message' => 'Ekstraksi OCR e-KTP gagal: ' . $result->errorMessage,
                    'driver_used' => $result->driver,
                    'data' => null
                ]);
                return;
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'SUCCESS',
                'message' => 'Ekstraksi data e-KTP berhasil.',
                'driver_used' => $result->driver,
                'confidence' => $result->confidence,
                'data' => $result->toArray()['data']
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'status' => 'ERROR',
                'message' => 'Terjadi kesalahan internal server: ' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
}
