<?php

namespace App\Services\Ocr;

use App\Services\Ocr\Contracts\OcrDriverInterface;
use App\Services\Ocr\Drivers\AdvanceAiDriver;
use App\Services\Ocr\Drivers\InHouseOcrDriver;
use App\Services\Ocr\DTO\KtpExtractionResult;
use InvalidArgumentException;

class OcrManager
{
    private array $config;
    private array $drivers = [];
    private string $defaultDriver;

    public function __construct(array $config = [])
    {
        // Load default config jika tidak di-inject lewat framework
        if (empty($config)) {
            $configFile = __DIR__ . '/../../../../config/ocr.php';
            $config = file_exists($configFile) ? require $configFile : [
                'default' => getenv('OCR_DRIVER') ?: 'advance_ai',
                'auto_fallback' => true,
                'drivers' => [
                    'advance_ai' => [
                        'api_url' => getenv('ADVANCE_AI_API_URL') ?: 'https://api.advance.ai',
                        'access_key' => getenv('ADVANCE_AI_ACCESS_KEY') ?: '',
                        'secret_key' => getenv('ADVANCE_AI_SECRET_KEY') ?: '',
                        'timeout' => 15,
                    ],
                    'in_house' => [
                        'tesseract_binary' => getenv('TESSERACT_BINARY') ?: 'tesseract',
                        'tessdata_dir' => getenv('TESSDATA_DIR') ?: '',
                        'python_helper_script' => getenv('PYTHON_OCR_SCRIPT') ?: null,
                    ]
                ]
            ];
        }

        $this->config = $config;
        $this->defaultDriver = $config['default'] ?? 'advance_ai';
    }

    /**
     * Dapatkan instance driver OCR tertentu
     */
    public function driver(?string $name = null): OcrDriverInterface
    {
        $name = $name ?: $this->defaultDriver;

        if (!isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->createDriver($name);
        }

        return $this->drivers[$name];
    }

    /**
     * Eksekusi ekstraksi KTP dengan dukungan Auto-Fallback
     */
    public function extract(string $imageContent, ?string $preferredDriver = null): KtpExtractionResult
    {
        $driverName = $preferredDriver ?: $this->defaultDriver;
        $driver = $this->driver($driverName);

        $result = $driver->extractKtp($imageContent);

        // Auto Fallback ke In-House jika Advance.AI gagal dan auto_fallback diaktifkan
        $shouldFallback = ($this->config['auto_fallback'] ?? true) && 
                          !$result->success && 
                          $driverName === 'advance_ai';

        if ($shouldFallback) {
            error_log("Advance.ai OCR failed ({$result->errorMessage}). Mengaktifkan Fallback ke In-House OCR Engine...");
            $fallbackDriver = $this->driver('in_house');
            $fallbackResult = $fallbackDriver->extractKtp($imageContent);
            if ($fallbackResult->success) {
                return $fallbackResult;
            }
        }

        return $result;
    }

    private function createDriver(string $name): OcrDriverInterface
    {
        switch ($name) {
            case 'advance_ai':
                return new AdvanceAiDriver($this->config['drivers']['advance_ai'] ?? []);
            case 'in_house':
                return new InHouseOcrDriver($this->config['drivers']['in_house'] ?? []);
            default:
                throw new InvalidArgumentException("Driver OCR [{$name}] tidak didukung.");
        }
    }
}
