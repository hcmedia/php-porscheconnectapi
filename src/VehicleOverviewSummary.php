<?php

declare(strict_types=1);

namespace PorscheConnect;

final class VehicleOverviewSummary
{
    private const float TIRE_PRESSURE_CHECK_THRESHOLD_BAR = 0.21;

    private const int SERVICE_WARNING_MAX_DAYS = 90;

    private const int SERVICE_WARNING_MAX_KILOMETERS = 5000;

    /** @var array<string, string> */
    private const TIRE_POSITION_LABELS = [
        'frontLeftTire' => 'linken Vorderrad',
        'frontRightTire' => 'rechten Vorderrad',
        'rearLeftTire' => 'linken Hinterrad',
        'rearRightTire' => 'rechten Hinterrad',
    ];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function fromVehicleData(array $data, ?string $vin = null): array
    {
        $batteryPercent = self::intOrNull($data, 'BATTERY_LEVEL', 'percent');
        $eRangeKm = self::intOrNull($data, 'E_RANGE', 'kilometers');
        $serviceDays = self::intOrNull($data, 'MAIN_SERVICE_TIME', 'days');
        $serviceRangeKm = self::intOrNull($data, 'MAIN_SERVICE_RANGE', 'kilometers');
        $mileageKm = self::intOrNull($data, 'MILEAGE', 'kilometers');
        $tirePressure = [
            'frontLeftTire' => [
                'differenceBar' => self::floatOrNull($data, 'TIRE_PRESSURE', 'frontLeftTire', 'differenceBar'),
            ],
            'rearLeftTire' => [
                'differenceBar' => self::floatOrNull($data, 'TIRE_PRESSURE', 'rearLeftTire', 'differenceBar'),
            ],
            'frontRightTire' => [
                'differenceBar' => self::floatOrNull($data, 'TIRE_PRESSURE', 'frontRightTire', 'differenceBar'),
            ],
            'rearRightTire' => [
                'differenceBar' => self::floatOrNull($data, 'TIRE_PRESSURE', 'rearRightTire', 'differenceBar'),
            ],
        ];

        return [
            'vin' => $vin ?? ($data['vin'] ?? null),
            'updatedAt' => $data['timestamp'] ?? gmdate('c'),
            'BATTERY_LEVEL' => [
                'percent' => $batteryPercent,
            ],
            'E_RANGE' => [
                'kilometers' => $eRangeKm,
            ],
            'MAIN_SERVICE_TIME' => [
                'days' => $serviceDays,
            ],
            'MAIN_SERVICE_RANGE' => [
                'kilometers' => $serviceRangeKm,
            ],
            'MILEAGE' => [
                'kilometers' => $mileageKm,
            ],
            'TIRE_PRESSURE' => $tirePressure,
            'messages' => [
                [
                    'type' => 'tts',
                    'text' => self::buildTtsMessage(
                        $batteryPercent,
                        $eRangeKm,
                        $serviceDays,
                        $serviceRangeKm,
                        $mileageKm,
                        $tirePressure,
                    ),
                ],
            ],
        ];
    }

    /**
     * @param array<string, array{differenceBar: ?float}> $tirePressure
     */
    private static function buildTtsMessage(
        ?int $batteryPercent,
        ?int $eRangeKm,
        ?int $serviceDays,
        ?int $serviceRangeKm,
        ?int $mileageKm,
        array $tirePressure,
    ): string {
        $message = sprintf(
            'Der Akkustand beträgt %s Prozent, Reichweite: %s Kilometer.',
            self::formatTtsNumber($batteryPercent),
            self::formatTtsNumber($eRangeKm),
        );

        if (self::shouldAnnounceService($serviceDays, $serviceRangeKm)) {
            $message .= sprintf(
                ' Service fällig in %s Tagen oder %s Kilometer.',
                self::formatTtsNumber($serviceDays),
                self::formatTtsNumber($serviceRangeKm),
            );
        }

        /*$message .= sprintf(
            ' Bisher gefahrene Kilometer: %s.',
            self::formatTtsNumber($mileageKm),
        );*/

        $tiresToCheck = self::tiresNeedingCheck($tirePressure);
        if ($tiresToCheck !== []) {
            $message .= ' Bitte überprüfen Sie den Reifendruck ' . self::joinGermanList(
                array_map(static fn (string $label): string => 'am ' . $label, $tiresToCheck),
            ) . '.';
        }

        return $message;
    }

    private static function shouldAnnounceService(?int $serviceDays, ?int $serviceRangeKm): bool
    {
        return ($serviceDays !== null && $serviceDays < self::SERVICE_WARNING_MAX_DAYS)
            || ($serviceRangeKm !== null && $serviceRangeKm < self::SERVICE_WARNING_MAX_KILOMETERS);
    }

    /**
     * @param array<string, array{differenceBar: ?float}> $tirePressure
     * @return list<string>
     */
    private static function tiresNeedingCheck(array $tirePressure): array
    {
        $tires = [];
        foreach (self::TIRE_POSITION_LABELS as $position => $label) {
            $differenceBar = $tirePressure[$position]['differenceBar'] ?? null;
            if ($differenceBar !== null && self::tireNeedsPressureCheck($differenceBar)) {
                $tires[] = $label;
            }
        }

        return $tires;
    }

    private static function tireNeedsPressureCheck(float $differenceBar): bool
    {
        return abs($differenceBar) >= self::TIRE_PRESSURE_CHECK_THRESHOLD_BAR - 1e-6;
    }

    /**
     * @param list<string> $items
     */
    private static function joinGermanList(array $items): string
    {
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        if ($count === 2) {
            return $items[0] . ' und ' . $items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' und ' . $last;
    }

    private static function formatTtsNumber(?int $value): string
    {
        return $value !== null ? (string) $value : 'unbekannt';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function toJson(array $data, ?string $vin = null): string
    {
        return json_encode(
            self::fromVehicleData($data, $vin),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intOrNull(array $data, string ...$path): ?int
    {
        $value = self::getNested($data, ...$path);

        return is_int($value) || is_float($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function floatOrNull(array $data, string ...$path): ?float
    {
        $value = self::getNested($data, ...$path);

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function getNested(array $data, string ...$path): mixed
    {
        $current = $data;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
