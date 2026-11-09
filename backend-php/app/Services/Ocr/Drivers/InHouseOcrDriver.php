<?php

namespace App\Services\Ocr\Drivers;

use App\Services\Ocr\Contracts\OcrDriverInterface;
use App\Services\Ocr\DTO\KtpExtractionResult;
use Exception;

class InHouseOcrDriver implements OcrDriverInterface
{
    private string $tesseractBinary;
    private string $tessdataDir;
    private ?string $pythonHelperScript;
    private string $tempDir;

    public function __construct(array $config = [])
    {
        $this->tesseractBinary = $config['tesseract_binary'] ?? 'tesseract';
        $this->tessdataDir = $config['tessdata_dir'] ?? '';
        $this->pythonHelperScript = $config['python_helper_script'] ?? null;
        $this->tempDir = sys_get_temp_dir();
    }

    public function extractKtp(string $imageContent): KtpExtractionResult
    {
        $result = new KtpExtractionResult(false, 'in_house');

        // Simpan gambar sementara ke filesystem
        $tempImagePath = $this->saveToTempFile($imageContent);

        try {
            $rawText = '';

            // 1. Jika ada Python PaddleOCR helper script, prioritaskan (akurasi lebih tinggi)
            if ($this->pythonHelperScript && file_exists($this->pythonHelperScript)) {
                $rawText = $this->runPythonOcr($tempImagePath);
            } else {
                // 2. Fallback ke Tesseract OCR binary
                $rawText = $this->runTesseractOcr($tempImagePath);
            }

            if (empty(trim($rawText))) {
                throw new Exception("In-House OCR tidak dapat membaca teks dari gambar KTP.");
            }

            $result->rawResponse = ['raw_text' => $rawText];

            // 3. Parse teks KTP menggunakan RegEx Rule Engine khusus e-KTP Indonesia
            $parsed = $this->parseKtpText($rawText);

            $result->success = true;
            $result->nik = $parsed['nik'];
            $result->name = $parsed['name'];
            $result->birthPlace = $parsed['birth_place'];
            $result->birthDate = $parsed['birth_date'];
            $result->gender = $parsed['gender'];
            $result->bloodType = $parsed['blood_type'];
            $result->address = $parsed['address'];
            $result->rt = $parsed['rt'];
            $result->rw = $parsed['rw'];
            $result->village = $parsed['village'];
            $result->district = $parsed['district'];
            $result->cityRegency = $parsed['city_regency'];
            $result->province = $parsed['province'];
            $result->religion = $parsed['religion'];
            $result->maritalStatus = $parsed['marital_status'];
            $result->occupation = $parsed['occupation'];
            $result->nationality = $parsed['nationality'];
            $result->confidence = $parsed['nik'] ? 0.90 : 0.70;

            return $result;
        } catch (Exception $e) {
            $result->success = false;
            $result->errorMessage = $e->getMessage();
            return $result;
        } finally {
            if (file_exists($tempImagePath)) {
                @unlink($tempImagePath);
            }
        }
    }

    private function runTesseractOcr(string $imagePath): string
    {
        $outputBase = $this->tempDir . '/ocr_out_' . uniqid();
        $tessdataFlag = $this->tessdataDir ? "--tessdata-dir " . escapeshellarg($this->tessdataDir) : "";
        
        // Mode psm 6 (Assume a single uniform block of text) atau 4
        $cmd = escapeshellcmd($this->tesseractBinary) . " " . 
               escapeshellarg($imagePath) . " " . 
               escapeshellarg($outputBase) . " -l ind+eng --psm 6 " . $tessdataFlag . " 2>&1";

        exec($cmd, $output, $returnCode);

        $txtFile = $outputBase . '.txt';
        if (file_exists($txtFile)) {
            $text = file_get_contents($txtFile);
            @unlink($txtFile);
            return $text;
        }

        // Jika return code bukan 0 tapi ada output di stdout
        return implode("\n", $output);
    }

    private function runPythonOcr(string $imagePath): string
    {
        $cmd = "python " . escapeshellarg($this->pythonHelperScript) . " " . escapeshellarg($imagePath) . " 2>&1";
        exec($cmd, $output, $returnCode);
        return implode("\n", $output);
    }

    /**
     * Parsing RegEx Khusus Format e-KTP Republik Indonesia
     */
    private function parseKtpText(string $text): array
    {
        $lines = explode("\n", $text);
        $cleanLines = array_map(function($line) {
            return trim(preg_replace('/[^\w\s\/:,\.-]/', '', $line));
        }, $lines);
        $cleanText = implode("\n", $cleanLines);

        $data = [
            'nik' => null,
            'name' => null,
            'birth_place' => null,
            'birth_date' => null,
            'gender' => 'LAKI-LAKI',
            'blood_type' => '-',
            'address' => null,
            'rt' => '001',
            'rw' => '001',
            'village' => null,
            'district' => null,
            'city_regency' => null,
            'province' => null,
            'religion' => 'ISLAM',
            'marital_status' => 'BELUM KAWIN',
            'occupation' => 'KARYAWAN SWASTA',
            'nationality' => 'WNI'
        ];

        // 1. Ekstraksi Provinsi & Kota/Kabupaten
        if (preg_match('/PROVINSI\s+([A-Z\s]+)/i', $cleanText, $m)) {
            $data['province'] = trim($m[1]);
        }
        if (preg_match('/(KOTA|KABUPATEN)\s+([A-Z\s]+)/i', $cleanText, $m)) {
            $data['city_regency'] = trim($m[1] . ' ' . $m[2]);
        }

        // 2. Ekstraksi NIK (16 digit angka)
        // Tangani kemungkinan salah baca OCR (misal huruf O/D/L/I jadi angka 0/1)
        if (preg_match('/(?:NIK|NIk|N1K)[\s:=]*([0-9OIl\s]{16,20})/i', $cleanText, $m)) {
            $rawNik = str_replace(['O', 'o', 'I', 'l', ' '], ['0', '0', '1', '1', ''], $m[1]);
            if (strlen($rawNik) >= 16) {
                $data['nik'] = substr($rawNik, 0, 16);
            }
        } elseif (preg_match('/\b([1-9][0-9]{15})\b/', $cleanText, $m)) {
            $data['nik'] = $m[1];
        }

        // 3. Ekstraksi Nama
        if (preg_match('/Nama[\s:=]+([A-Z\s\.,\']+)(?=\n|Tempat|Tgl)/i', $cleanText, $m)) {
            $data['name'] = trim($m[1]);
        }

        // 4. Ekstraksi Tempat/Tgl Lahir
        if (preg_match('/(?:Tempat|Tgl\s*Lahir|Lahir)[\s:=]+([A-Z\s]+)[,:\s]+(\d{2}[- \/]\d{2}[- \/]\d{4})/i', $cleanText, $m)) {
            $data['birth_place'] = trim($m[1]);
            $rawDate = str_replace(['/', ' '], '-', $m[2]);
            $dateParts = explode('-', $rawDate);
            if (count($dateParts) === 3) {
                $data['birth_date'] = "{$dateParts[2]}-{$dateParts[1]}-{$dateParts[0]}";
            }
        }

        // 5. Jenis Kelamin & Gol Darah
        if (preg_match('/(PEREMPUAN|WANITA)/i', $cleanText)) {
            $data['gender'] = 'PEREMPUAN';
        } else {
            $data['gender'] = 'LAKI-LAKI';
        }
        if (preg_match('/Gol[\.:\s]*Darah[\s:=]*([A-Z\+-]+)/i', $cleanText, $m)) {
            $data['blood_type'] = trim($m[1]);
        }

        // 6. Alamat, RT/RW, Kelurahan, Kecamatan
        if (preg_match('/Alamat[\s:=]+([A-Z0-9\s\.,\/-]+)(?=\n|RT|RW)/i', $cleanText, $m)) {
            $data['address'] = trim($m[1]);
        }
        if (preg_match('/RT[\/]?RW[\s:=]+(\d{1,3})[\s\/-]+(\d{1,3})/i', $cleanText, $m)) {
            $data['rt'] = str_pad($m[1], 3, '0', STR_PAD_LEFT);
            $data['rw'] = str_pad($m[2], 3, '0', STR_PAD_LEFT);
        }
        if (preg_match('/(?:Kel|Desa|Kelurahan)[\s:=]+([A-Z\s]+)/i', $cleanText, $m)) {
            $data['village'] = trim($m[1]);
        }
        if (preg_match('/(?:Kec|Kecamatan)[\s:=]+([A-Z\s]+)/i', $cleanText, $m)) {
            $data['district'] = trim($m[1]);
        }

        // 7. Agama
        if (preg_match('/Agama[\s:=]+(ISLAM|KRISTEN|KATOLIK|HINDU|BUDDHA|KONGHUCU)/i', $cleanText, $m)) {
            $data['religion'] = strtoupper(trim($m[1]));
        }

        // 8. Status Perkawinan
        if (preg_match('/Status\s*(?:Perkawinan)?[\s:=]+(BELUM\s*KAWIN|KAWIN|CERAI\s*HIDUP|CERAI\s*MATI)/i', $cleanText, $m)) {
            $data['marital_status'] = strtoupper(trim($m[1]));
        }

        // 9. Pekerjaan
        if (preg_match('/Pekerjaan[\s:=]+([A-Z\s]+)(?=\n|Kewarganegaraan|WNI)/i', $cleanText, $m)) {
            $data['occupation'] = strtoupper(trim($m[1]));
        }

        return $data;
    }

    private function saveToTempFile(string $imageContent): string
    {
        $tempFile = $this->tempDir . '/ktp_input_' . uniqid() . '.jpg';
        if (file_exists($imageContent)) {
            copy($imageContent, $tempFile);
            return $tempFile;
        }

        if (str_contains($imageContent, ';base64,')) {
            $parts = explode(';base64,', $imageContent);
            $imageContent = $parts[1] ?? $imageContent;
        }

        $decoded = base64_decode($imageContent);
        file_put_contents($tempFile, $decoded);
        return $tempFile;
    }
}
