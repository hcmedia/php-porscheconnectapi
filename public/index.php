<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PorscheConnect\Api\JsonResponse;
use PorscheConnect\Api\Router;
use PorscheConnect\Api\SessionManager;
use PorscheConnect\Exception\PorscheCaptchaRequiredException;
use PorscheConnect\Exception\PorscheException;
use PorscheConnect\Exception\PorscheRemoteServiceException;
use PorscheConnect\Exception\PorscheWrongCredentialsException;
use PorscheConnect\PorscheVehicle;
use PorscheConnect\RemoteServices;
use PorscheConnect\VehicleOverviewSummary;

$router = new Router();
$sessions = new SessionManager();

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function requireSession(SessionManager $sessions): string
{
    $sessionId = $_SERVER['HTTP_X_SESSION_ID'] ?? $_GET['session_id'] ?? '';
    if ($sessionId === '' || $sessions->getAccount($sessionId) === null) {
        JsonResponse::error('Missing or invalid session. Use X-Session-Id header after POST /auth/login.', 401);
    }

    return $sessionId;
}

function requireVehicle(SessionManager $sessions, string $sessionId, string $vin): PorscheVehicle
{
    $account = $sessions->getAccount($sessionId);
    if ($account === null) {
        JsonResponse::error('Invalid session', 401);
    }

    try {
        $vehicle = $account->getVehicle($vin);
    } catch (\Throwable $e) {
        handleException($e);
    }

    if ($vehicle === null) {
        JsonResponse::error("Vehicle with VIN {$vin} not found", 404);
    }

    return $vehicle;
}

function handleException(\Throwable $e): never
{
    if ($e instanceof PorscheCaptchaRequiredException) {
        JsonResponse::error('Captcha required', 428, [
            'captcha' => $e->captcha,
            'state' => $e->state,
        ]);
    }

    if ($e instanceof PorscheWrongCredentialsException) {
        JsonResponse::error($e->getMessage(), 401);
    }

    if ($e instanceof PorscheRemoteServiceException) {
        JsonResponse::error($e->getMessage(), 502);
    }

    if ($e instanceof PorscheException) {
        $status = is_int($e->statusCode) && $e->statusCode >= 400 ? $e->statusCode : 500;
        JsonResponse::error($e->getMessage(), $status);
    }

    JsonResponse::error($e->getMessage(), 500);
}

function vehicleSummary(PorscheVehicle $vehicle): array
{
    return [
        'vin' => $vehicle->getVin(),
        'modelName' => $vehicle->getModelName(),
        'modelYear' => $vehicle->getModelYear(),
        'connected' => $vehicle->isConnected(),
        'hasRemoteServices' => $vehicle->hasRemoteServices(),
    ];
}

// --- Auth ---

$router->post('/auth/login', function () use ($sessions): void {
    $body = readJsonBody();
    $email = $body['email'] ?? $_POST['email'] ?? null;
    $password = $body['password'] ?? $_POST['password'] ?? null;
    $captchaCode = $body['captcha_code'] ?? null;
    $captchaState = $body['state'] ?? null;
    $token = $body['token'] ?? null;

    if ($token === null && ($email === null || $password === null)) {
        JsonResponse::error('email and password are required (or provide token)', 422);
    }

    $sessionId = null;

    try {
        $sessionId = $sessions->createSession(
            is_string($email) ? $email : null,
            is_string($password) ? $password : null,
            is_array($token) ? $token : null,
            is_string($captchaCode) ? $captchaCode : null,
            is_string($captchaState) ? $captchaState : null,
        );

        if (is_string($captchaCode) && is_string($captchaState)) {
            $sessions->setCaptcha($sessionId, $captchaCode, $captchaState);
        }

        $tokenData = $sessions->getToken($sessionId);

        if ($tokenData === null || ($tokenData['access_token'] ?? null) === null) {
            JsonResponse::error('Authentication failed: no access token received', 502);
        }

        JsonResponse::send([
            'sessionId' => $sessionId,
            'token' => $tokenData,
        ], 201, ['X-Session-Id' => $sessionId]);
    } catch (PorscheCaptchaRequiredException $e) {
        JsonResponse::error('Captcha required', 428, [
            'sessionId' => $sessionId,
            'captcha' => $e->captcha,
            'state' => $e->state,
        ]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->post('/auth/captcha', function () use ($sessions): void {
    $body = readJsonBody();
    $sessionId = $body['session_id'] ?? $_SERVER['HTTP_X_SESSION_ID'] ?? '';
    $captchaCode = $body['captcha_code'] ?? null;
    $state = $body['state'] ?? null;

    if ($sessionId === '' || !is_string($captchaCode) || !is_string($state)) {
        JsonResponse::error('session_id, captcha_code and state are required', 422);
    }

    try {
        $sessions->setCaptcha($sessionId, $captchaCode, $state);
        $tokenData = $sessions->getToken($sessionId);
        JsonResponse::send(['token' => $tokenData]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/auth/token', function () use ($sessions): void {
    $sessionId = requireSession($sessions);

    try {
        JsonResponse::send(['token' => $sessions->getToken($sessionId)]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

// --- Vehicles ---

$router->get('/vehicles', function () use ($sessions): void {
    $sessionId = requireSession($sessions);
    $account = $sessions->getAccount($sessionId);

    try {
        $vehicles = $account->getVehicles();
        JsonResponse::send([
            'vehicles' => array_map('vehicleSummary', $vehicles),
        ]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/vehicles/{vin}', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    JsonResponse::send([
        'vehicle' => vehicleSummary($vehicle),
        'data' => $vehicle->getData(),
    ]);
});

$router->get('/vehicles/{vin}/overview/stored', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    try {
        $vehicle->getStoredOverview();
        JsonResponse::send(['data' => $vehicle->getData()]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/vehicles/{vin}/overview/current', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    try {
        $vehicle->getCurrentOverview();
        JsonResponse::send(['data' => $vehicle->getData()]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/vehicles/{vin}/overview/summary', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);

    try {
        $sessions->ensureValidToken($sessionId);
    } catch (PorscheCaptchaRequiredException $e) {
        handleException($e);
    }

    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    try {
        $vehicle->getCurrentOverview();
        $summary = VehicleOverviewSummary::fromVehicleData($vehicle->getData(), $params['vin']);

        $sessions->persistConnection($sessionId, $vehicle->getConnection());

        if (($_GET['persist'] ?? '') === '1') {
            $outputDir = dirname(__DIR__) . '/public/data';
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }
            $safeVin = preg_replace('/[^A-Za-z0-9]/', '', $params['vin']);
            file_put_contents(
                $outputDir . '/' . $safeVin . '.json',
                json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX,
            );
        }

        JsonResponse::send($summary);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/vehicles/{vin}/capabilities', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    try {
        $vehicle->getCapabilities();
        JsonResponse::send(['capabilities' => $vehicle->getCapabilitiesData()]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/vehicles/{vin}/trip-statistics', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    try {
        $vehicle->getTripStatistics();
        JsonResponse::send(['tripStatistics' => $vehicle->getTripStatisticsData()]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/vehicles/{vin}/pictures', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    try {
        $vehicle->getPictureLocations();
        JsonResponse::send(['pictures' => $vehicle->getPictureLocationsData()]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/vehicles/{vin}/location', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    try {
        $vehicle->getStoredOverview();
        JsonResponse::send(['location' => $vehicle->getLocation()]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/vehicles/{vin}/battery', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    try {
        $vehicle->getStoredOverview();
        JsonResponse::send(['batteryLevel' => $vehicle->getMainBatteryLevel()]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

$router->get('/vehicles/{vin}/doors', function (array $params) use ($sessions): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $params['vin']);

    try {
        $vehicle->getStoredOverview();
        JsonResponse::send([
            'closed' => $vehicle->isVehicleClosed(),
            'doorsAndLids' => $vehicle->getDoorsAndLids(),
        ]);
    } catch (\Throwable $e) {
        handleException($e);
    }
});

// --- Remote commands ---

$command = static function (
    SessionManager $sessions,
    string $vin,
    callable $action,
): void {
    $sessionId = requireSession($sessions);
    $vehicle = requireVehicle($sessions, $sessionId, $vin);

    try {
        $result = $action(new RemoteServices($vehicle));
        JsonResponse::send(['result' => $result->toArray()]);
    } catch (\Throwable $e) {
        handleException($e);
    }
};

$router->post('/vehicles/{vin}/commands/flash', fn (array $p) => $command(
    $sessions,
    $p['vin'],
    fn (RemoteServices $s) => $s->flashIndicators(),
));

$router->post('/vehicles/{vin}/commands/honk-flash', fn (array $p) => $command(
    $sessions,
    $p['vin'],
    fn (RemoteServices $s) => $s->honkAndFlashIndicators(),
));

$router->post('/vehicles/{vin}/commands/climatise-on', function (array $params) use ($sessions, $command): void {
    $body = readJsonBody();
    $command($sessions, $params['vin'], fn (RemoteServices $s) => $s->climatiseOn(
        (float) ($body['targetTemperature'] ?? 293.15),
        (bool) ($body['frontLeft'] ?? false),
        (bool) ($body['frontRight'] ?? false),
        (bool) ($body['rearLeft'] ?? false),
        (bool) ($body['rearRight'] ?? false),
    ));
});

$router->post('/vehicles/{vin}/commands/climatise-off', fn (array $p) => $command(
    $sessions,
    $p['vin'],
    fn (RemoteServices $s) => $s->climatiseOff(),
));

$router->post('/vehicles/{vin}/commands/direct-charge-on', fn (array $p) => $command(
    $sessions,
    $p['vin'],
    fn (RemoteServices $s) => $s->directChargeOn(),
));

$router->post('/vehicles/{vin}/commands/direct-charge-off', fn (array $p) => $command(
    $sessions,
    $p['vin'],
    fn (RemoteServices $s) => $s->directChargeOff(),
));

$router->post('/vehicles/{vin}/commands/lock', fn (array $p) => $command(
    $sessions,
    $p['vin'],
    fn (RemoteServices $s) => $s->lockVehicle(),
));

$router->post('/vehicles/{vin}/commands/unlock', function (array $params) use ($sessions, $command): void {
    $body = readJsonBody();
    $pin = $body['pin'] ?? null;
    if (!is_string($pin) || $pin === '') {
        JsonResponse::error('pin is required (hex string)', 422);
    }

    $command($sessions, $params['vin'], fn (RemoteServices $s) => $s->unlockVehicle($pin)
        ?? throw new PorscheRemoteServiceException('Could not obtain SPIN challenge'));
});

$router->post('/vehicles/{vin}/commands/charging-profile', function (array $params) use ($sessions, $command): void {
    $body = readJsonBody();
    $command($sessions, $params['vin'], fn (RemoteServices $s) => $s->updateChargingProfile(
        isset($body['profileId']) ? (int) $body['profileId'] : null,
        isset($body['minimumChargeLevel']) ? (int) $body['minimumChargeLevel'] : null,
    ));
});

$router->post('/vehicles/{vin}/commands/charging-settings', function (array $params) use ($sessions, $command): void {
    $body = readJsonBody();
    $command($sessions, $params['vin'], fn (RemoteServices $s) => $s->updateChargingSetting(
        isset($body['targetSoc']) ? (int) $body['targetSoc'] : null,
    ));
});

// --- Health ---

$router->get('/health', fn () => JsonResponse::send(['status' => 'ok']));

// --- Dispatch ---

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

try {
    $result = $router->dispatch($method, $uri);
    if ($result === null) {
        JsonResponse::error('Not found', 404);
    }
} catch (\Throwable $e) {
    handleException($e);
}
