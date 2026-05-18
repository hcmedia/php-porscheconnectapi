<?php

declare(strict_types=1);

namespace PorscheConnect;

use PorscheConnect\Exception\PorscheException;

class PorscheVehicle
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, mixed> */
    private array $status = [];

    /** @var array<string, mixed> */
    private array $capabilities = [];

    /** @var array<string, mixed> */
    private array $tripStatistics = [];

    /** @var array<string, string> */
    private array $pictureLocations = [];

    public RemoteServices $remoteServices;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $vin = '',
        array $data = [],
    ) {
        $this->data = $data;
        $this->remoteServices = new RemoteServices($this);
    }

    public function getVin(): string
    {
        return (string) ($this->data['vin'] ?? $this->vin ?: 'not available');
    }

    public function getModelName(): string
    {
        return (string) ($this->data['modelName'] ?? 'not available');
    }

    public function getModelYear(): string
    {
        return (string) ($this->data['modelType']['year'] ?? 'not available');
    }

    public function isConnected(): bool
    {
        return (bool) ($this->data['connect'] ?? false);
    }

    public function hasRemoteServices(): bool
    {
        return ($this->data['REMOTE_ACCESS_AUTHORIZATION']['isEnabled'] ?? false) === true;
    }

    public function getMainBatteryLevel(): int
    {
        if ($this->hasIceDrivetrain()) {
            return 0;
        }

        return (int) ($this->data['BATTERY_LEVEL']['percent'] ?? 0);
    }

    public function hasIceDrivetrain(): bool
    {
        $engine = $this->data['modelType']['engine'] ?? '';

        return $engine === 'PHEV' || $engine === 'COMBUSTION';
    }

    public function isVehicleLocked(): bool
    {
        return ($this->data['LOCK_STATE_VEHICLE']['isLocked'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @return array{lat: ?float, lon: ?float, heading: ?int}
     */
    public function getLocation(): array
    {
        $loc = $this->data['GPS_LOCATION']['location'] ?? null;
        $heading = $this->data['GPS_LOCATION']['direction'] ?? null;
        $lat = null;
        $lon = null;

        if (is_string($loc) && preg_match('/[\-\.0-9]+,[\-\.0-9]+/', $loc)) {
            [$lat, $lon] = array_map('floatval', explode(',', $loc));
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
            'heading' => $heading !== null ? (int) $heading : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getDoorsAndLids(): array
    {
        $result = [];
        foreach ($this->data as $key => $value) {
            if (!str_starts_with($key, 'OPEN_STATE_') || !is_array($value)) {
                continue;
            }
            $result[$key] = ($value['isOpen'] ?? false) ? 'Open' : 'Closed';
        }

        return $result;
    }

    public function isVehicleClosed(): bool
    {
        $openCount = 0;
        foreach ($this->data as $key => $value) {
            if (str_starts_with($key, 'OPEN_STATE_') && is_array($value) && ($value['isOpen'] ?? false)) {
                $openCount++;
            }
        }

        return $openCount === 0;
    }

    public function getStoredOverview(): void
    {
        $measurements = 'mf=' . implode('&mf=', Consts::MEASUREMENTS);

        try {
            $this->status = $this->connection->get("/connect/v1/vehicles/{$this->getVin()}?{$measurements}");
            $this->updateVehicleData();
        } catch (PorscheException) {
            // Match Python: log and continue
        }
    }

    public function getCurrentOverview(): void
    {
        $measurements = 'mf=' . implode('&mf=', Consts::MEASUREMENTS);
        $wakeup = '&wakeUpJob=' . $this->generateUuid();

        try {
            $this->status = $this->connection->get("/connect/v1/vehicles/{$this->getVin()}?{$measurements}{$wakeup}");
            $this->updateVehicleData();
        } catch (PorscheException) {
            // Match Python: log and continue
        }
    }

    public function getCapabilities(): void
    {
        $query = 'mf=' . implode('&mf=', Consts::MEASUREMENTS) . '&cf=' . implode('&cf=', Consts::COMMANDS);

        try {
            $this->capabilities = $this->connection->get("/connect/v1/vehicles/{$this->getVin()}?{$query}");
        } catch (PorscheException) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilitiesData(): array
    {
        return $this->capabilities;
    }

    public function getTripStatistics(): void
    {
        $measurements = 'mf=' . implode('&mf=', Consts::TRIP_STATISTICS);

        try {
            $this->tripStatistics = $this->connection->get("/connect/v1/vehicles/{$this->getVin()}?{$measurements}");
        } catch (PorscheException) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getTripStatisticsData(): array
    {
        return $this->tripStatistics;
    }

    public function getPictureLocations(): void
    {
        try {
            $resp = $this->connection->get("/connect/v1/vehicles/{$this->getVin()}/pictures");
            $this->pictureLocations = [];
            foreach ($resp as $picture) {
                if (is_array($picture) && isset($picture['view'], $picture['url'])) {
                    $this->pictureLocations[(string) $picture['view']] = (string) $picture['url'];
                }
            }
        } catch (PorscheException) {
        }
    }

    /**
     * @return array<string, string>
     */
    public function getPictureLocationsData(): array
    {
        return $this->pictureLocations;
    }

    private function updateVehicleData(): void
    {
        if (!isset($this->status['vin'])) {
            return;
        }

        $baseData = [
            'vin' => $this->status['vin'] ?? null,
            'modelName' => $this->status['modelName'] ?? null,
            'modelType' => $this->status['modelType'] ?? null,
            'timestamp' => $this->status['timestamp'] ?? null,
        ];
        $baseData['name'] = $this->status['customName'] ?? $baseData['modelName'];

        $measurementData = [];
        if (isset($this->status['measurements']) && is_array($this->status['measurements'])) {
            foreach ($this->status['measurements'] as $measurement) {
                if (!is_array($measurement)) {
                    continue;
                }
                if (($measurement['status']['isEnabled'] ?? false) !== true) {
                    continue;
                }
                $key = $measurement['key'] ?? null;
                if (is_string($key)) {
                    $measurementData[$key] = $measurement['value'] ?? null;
                }
            }
        }

        if (isset($measurementData['CHARGING_RATE']) && empty($measurementData['CHARGING_RATE']['chargingRate'])) {
            $measurementData['CHARGING_RATE']['chargingRate-kph'] = 0;
            $measurementData['CHARGING_RATE']['chargingPower'] = 0;
        }

        if (!empty($measurementData['CHARGING_RATE']['chargingRate'])) {
            $measurementData['CHARGING_RATE']['chargingRate-kph'] =
                $measurementData['CHARGING_RATE']['chargingRate'] * 60;
        }

        if (($measurementData['CHARGING_SUMMARY']['mode'] ?? null) === 'PROFILE') {
            $measurementData['CHARGING_SUMMARY']['minSoC'] =
                $measurementData['CHARGING_SUMMARY']['chargingProfile']['minSoC'] ?? null;
        }

        if (isset($measurementData['DEPARTURES']) && isset($measurementData['CHARGING_SETTINGS']['targetSoc'])) {
            $measurementData['CHARGING_SUMMARY']['minSoC'] = $measurementData['CHARGING_SETTINGS']['targetSoc'];
        }

        if (
            !isset($measurementData['DEPARTURES'])
            && ($measurementData['CHARGING_SUMMARY']['mode'] ?? null) === 'DIRECT'
        ) {
            $measurementData['CHARGING_SUMMARY']['minSoC'] = 100;
        }

        $this->data = array_merge($this->data, array_filter($baseData, fn ($v) => $v !== null), $measurementData);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
