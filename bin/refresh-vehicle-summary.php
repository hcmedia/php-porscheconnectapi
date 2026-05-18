#!/usr/bin/env php
<?php

declare(strict_types=1);

use PorscheConnect\Api\SessionManager;
use PorscheConnect\Exception\PorscheCaptchaRequiredException;
use PorscheConnect\VehicleOverviewSummary;

require dirname(__DIR__) . '/vendor/autoload.php';

$sessionId = $argv[1] ?? null;
$vin = $argv[2] ?? null;

if ($sessionId === null || $vin === null) {
    fwrite(STDERR, "Usage: php bin/refresh-vehicle-summary.php <session_id> <vin>\n");
    exit(1);
}

$sessions = new SessionManager();

if (!$sessions->canWriteSession($sessionId)) {
    fwrite(STDERR, "Warning: Session file is not writable – token will not be saved.\n");
    fwrite(STDERR, "  Fix: sudo chmod 664 storage/sessions/{$sessionId}.json\n");
    fwrite(STDERR, "       sudo chown www-data:hcmedia storage/sessions/{$sessionId}.json\n");
}

try {
    if ($sessions->ensureValidToken($sessionId) === null) {
        fwrite(STDERR, "Session not found: {$sessionId}\n");
        exit(1);
    }
} catch (PorscheCaptchaRequiredException) {
    fwrite(STDERR, "Captcha required – please log in via API first.\n");
    exit(1);
}

$account = $sessions->getAccount($sessionId);
if ($account === null) {
    fwrite(STDERR, "Session not found: {$sessionId}\n");
    exit(1);
}

$vehicle = $account->getVehicle($vin);
if ($vehicle === null) {
    foreach ($account->getVehicles(true) as $candidate) {
        if ($candidate->getVin() === $vin) {
            $vehicle = $candidate;
            break;
        }
    }
}

if ($vehicle === null) {
    fwrite(STDERR, "Vehicle not found: {$vin}\n");
    exit(1);
}

$vehicle->getCurrentOverview();
$summary = VehicleOverviewSummary::fromVehicleData($vehicle->getData(), $vin);

$outputDir = dirname(__DIR__) . '/public/data';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$outputPath = $outputDir . '/' . preg_replace('/[^A-Za-z0-9]/', '', $vin) . '.json';
file_put_contents(
    $outputPath,
    json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    LOCK_EX,
);

$sessions->persistConnection($sessionId, $vehicle->getConnection());

echo $outputPath . PHP_EOL;
