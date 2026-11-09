<?php

namespace App\Services\Ocr\DTO;

class KtpExtractionResult
{
    public bool $success;
    public string $driver;
    public ?string $nik = null;
    public ?string $name = null;
    public ?string $birthPlace = null;
    public ?string $birthDate = null; // Format: YYYY-MM-DD
    public ?string $gender = null; // LAKI-LAKI | PEREMPUAN
    public ?string $bloodType = null;
    public ?string $address = null;
    public ?string $rt = null;
    public ?string $rw = null;
    public ?string $village = null;
    public ?string $district = null;
    public ?string $cityRegency = null;
    public ?string $province = null;
    public ?string $religion = null;
    public ?string $maritalStatus = null;
    public ?string $occupation = null;
    public ?string $nationality = 'WNI';
    public float $confidence = 0.0;
    public array $rawResponse = [];
    public ?string $errorMessage = null;

    public function __construct(bool $success = true, string $driver = 'unknown')
    {
        $this->success = $success;
        $this->driver = $driver;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'driver' => $this->driver,
            'confidence' => $this->confidence,
            'data' => [
                'nik' => $this->nik,
                'name' => $this->name,
                'birth_place' => $this->birthPlace,
                'birth_date' => $this->birthDate,
                'gender' => $this->gender,
                'blood_type' => $this->bloodType,
                'address' => $this->address,
                'rt' => $this->rt,
                'rw' => $this->rw,
                'village' => $this->village,
                'district' => $this->district,
                'city_regency' => $this->cityRegency,
                'province' => $this->province,
                'religion' => $this->religion,
                'marital_status' => $this->maritalStatus,
                'occupation' => $this->occupation,
                'nationality' => $this->nationality,
            ],
            'error_message' => $this->errorMessage,
        ];
    }
}
