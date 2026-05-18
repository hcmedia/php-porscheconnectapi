<?php

declare(strict_types=1);

namespace PorscheConnect;

use PorscheConnect\Exception\PorscheRemoteServiceException;

class RemoteServices
{
    private const POLLING_DELAY_SECONDS = 1;
    private const POLLING_TIMEOUT_SECONDS = 240;

    public function __construct(
        private readonly PorscheVehicle $vehicle,
    ) {
    }

    public function flashIndicators(): RemoteServiceStatus
    {
        return $this->sendCommand([
            'key' => 'HONK_FLASH',
            'payload' => ['mode' => 'FLASH', 'spin' => null],
        ]);
    }

    public function honkAndFlashIndicators(): RemoteServiceStatus
    {
        return $this->sendCommand([
            'key' => 'HONK_FLASH',
            'payload' => ['mode' => 'HONK_AND_FLASH', 'spin' => null],
        ]);
    }

    public function climatiseOn(
        float $targetTemperature = 293.15,
        bool $frontLeft = false,
        bool $frontRight = false,
        bool $rearLeft = false,
        bool $rearRight = false,
    ): RemoteServiceStatus {
        return $this->sendCommand([
            'key' => 'REMOTE_CLIMATIZER_START',
            'payload' => [
                'climateZonesEnabled' => [
                    'frontLeft' => $frontLeft,
                    'frontRight' => $frontRight,
                    'rearLeft' => $rearLeft,
                    'rearRight' => $rearRight,
                ],
                'targetTemperature' => $targetTemperature,
            ],
        ]);
    }

    public function climatiseOff(): RemoteServiceStatus
    {
        return $this->sendCommand([
            'key' => 'REMOTE_CLIMATIZER_STOP',
            'payload' => (object) [],
        ]);
    }

    public function directChargeOn(): RemoteServiceStatus
    {
        return $this->sendCommand([
            'key' => 'DIRECT_CHARGING_START',
            'payload' => ['spin' => null],
        ]);
    }

    public function directChargeOff(): RemoteServiceStatus
    {
        return $this->sendCommand([
            'key' => 'DIRECT_CHARGING_STOP',
            'payload' => ['spin' => null],
        ]);
    }

    public function lockVehicle(): RemoteServiceStatus
    {
        return $this->sendCommand([
            'key' => 'LOCK',
            'payload' => ['spin' => null],
        ]);
    }

    public function unlockVehicle(string $pin): ?RemoteServiceStatus
    {
        $challenge = $this->getChallenge();
        if ($challenge === null) {
            return null;
        }

        $pinHash = strtoupper(hash('sha512', hex2bin($pin . $challenge)));

        return $this->sendCommand([
            'key' => 'UNLOCK',
            'payload' => [
                'spin' => [
                    'challenge' => $challenge,
                    'hash' => $pinHash,
                ],
            ],
        ]);
    }

    public function updateChargingProfile(?int $profileId = null, ?int $minimumChargeLevel = null): RemoteServiceStatus
    {
        $this->vehicle->getStoredOverview();
        $data = $this->vehicle->getData();
        $chargingProfilesList = $data['CHARGING_PROFILES']['list'] ?? [];

        if ($profileId === null) {
            foreach ($chargingProfilesList as $profile) {
                if (($profile['isEnabled'] ?? false) === true) {
                    $profileId = (int) $profile['id'];
                    break;
                }
            }
        }

        if ($minimumChargeLevel !== null) {
            $minimumChargeLevel = min(max($minimumChargeLevel, 25), 100);
            foreach ($chargingProfilesList as $i => $item) {
                if ((int) ($item['id'] ?? 0) === $profileId) {
                    $chargingProfilesList[$i]['minSoc'] = $minimumChargeLevel;
                }
            }
        }

        return $this->sendCommand([
            'key' => 'CHARGING_PROFILES_EDIT',
            'payload' => ['list' => $chargingProfilesList],
        ]);
    }

    public function updateChargingSetting(?int $targetSoc = null): RemoteServiceStatus
    {
        if ($targetSoc !== null) {
            $targetSoc = min(max($targetSoc, 25), 100);
        }

        return $this->sendCommand([
            'key' => 'CHARGING_SETTINGS_EDIT',
            'payload' => [
                'targetSoc' => $targetSoc,
                'spin' => null,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendCommand(array $payload): RemoteServiceStatus
    {
        $connection = $this->vehicle->getConnection();
        $vin = $this->vehicle->getVin();

        $response = $connection->post("/connect/v1/vehicles/{$vin}/commands", json: $payload);

        $statusId = $response['status']['id'] ?? null;
        $resultCode = $response['status']['result'] ?? null;

        if ($resultCode === null) {
            throw new PorscheRemoteServiceException('Did not receive response for remote service request');
        }

        if ($statusId && $resultCode === 'ACCEPTED') {
            $status = $this->blockUntilDone((string) $statusId);
        } else {
            $status = new RemoteServiceStatus($response);
        }

        sleep(self::POLLING_DELAY_SECONDS);
        $this->vehicle->getStoredOverview();

        return $status;
    }

    private function getChallenge(): ?string
    {
        $connection = $this->vehicle->getConnection();
        $vin = $this->vehicle->getVin();

        $response = $connection->post("/connect/v1/vehicles/{$vin}/commands", json: [
            'key' => 'SPIN_CHALLENGE',
            'payload' => ['spin' => null],
        ]);

        return $response['data']['challenge'] ?? null;
    }

    private function blockUntilDone(string $statusId): RemoteServiceStatus
    {
        $failAfter = time() + self::POLLING_TIMEOUT_SECONDS;
        $status = null;

        while (time() < $failAfter) {
            sleep(self::POLLING_DELAY_SECONDS);
            $status = $this->getRemoteServiceStatus($statusId);

            if ($status->state === ExecutionState::Error) {
                throw new PorscheRemoteServiceException(
                    "Remote service failed with state '" . json_encode($status->details) . "'",
                );
            }

            if ($status->state !== ExecutionState::Unknown) {
                return $status;
            }
        }

        $currentState = $status?->state->value ?? 'Unknown';
        throw new PorscheRemoteServiceException(
            "Did not receive remote service result for '{$statusId}' in " . self::POLLING_TIMEOUT_SECONDS . " seconds. Current state: {$currentState}",
        );
    }

    private function getRemoteServiceStatus(string $statusId): RemoteServiceStatus
    {
        $connection = $this->vehicle->getConnection();
        $vin = $this->vehicle->getVin();
        $statusMsg = $connection->get("/connect/v1/vehicles/{$vin}/commands/{$statusId}");

        return new RemoteServiceStatus($statusMsg, $statusId);
    }
}

enum ExecutionState: string
{
    case Performed = 'PERFORMED';
    case Error = 'ERROR';
    case Unknown = 'UNKNOWN';

    public static function fromString(?string $value): self
    {
        if ($value === null) {
            return self::Unknown;
        }

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $value) === 0) {
                return $case;
            }
        }

        return self::Unknown;
    }
}

class RemoteServiceStatus
{
    public readonly ?string $status;
    public readonly ExecutionState $state;

    /** @var array<string, mixed> */
    public readonly array $details;

    /**
     * @param array<string, mixed>|string $response
     */
    public function __construct(array|string $response, public readonly ?string $statusId = null)
    {
        if (is_string($response)) {
            $this->status = $response;
            $this->state = ExecutionState::fromString($response);
            $this->details = ['status' => $response];

            return;
        }

        $this->status = $response['status']['result'] ?? null;
        $this->state = ExecutionState::fromString($this->status);
        $this->details = $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'state' => $this->state->value,
            'statusId' => $this->statusId,
            'details' => $this->details,
        ];
    }
}
