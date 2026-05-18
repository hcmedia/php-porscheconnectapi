<?php

declare(strict_types=1);

namespace PorscheConnect;

class PorscheConnectAccount
{
    /** @var list<PorscheVehicle> */
    private array $vehicles = [];

    public function __construct(
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        ?array $token = null,
        private ?Connection $connection = null,
    ) {
        if ($this->connection === null) {
            $this->connection = new Connection($username, $password, token: $token);
        }
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    private function initVehicles(): void
    {
        $vehicleList = $this->connection->get('/connect/v1/vehicles');
        $this->vehicles = [];

        foreach ($vehicleList as $vehicle) {
            if (!is_array($vehicle)) {
                continue;
            }

            $this->vehicles[] = new PorscheVehicle(
                connection: $this->connection,
                vin: (string) ($vehicle['vin'] ?? ''),
                data: $vehicle,
            );
        }
    }

    /**
     * @return list<PorscheVehicle>
     */
    public function getVehicles(bool $forceInit = false): array
    {
        if ($this->vehicles === [] || $forceInit) {
            $this->initVehicles();
        }

        return $this->vehicles;
    }

    public function getVehicle(string $vin): ?PorscheVehicle
    {
        if ($this->vehicles === []) {
            $this->initVehicles();
        }

        foreach ($this->vehicles as $vehicle) {
            if ($vehicle->getVin() === $vin) {
                return $vehicle;
            }
        }

        return null;
    }
}
