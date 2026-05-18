<?php

declare(strict_types=1);

namespace PorscheConnect;

final class Consts
{
    public const AUTHORIZATION_SERVER = 'identity.porsche.com';
    public const REDIRECT_URI = 'my-porsche-app://auth0/callback';
    public const AUDIENCE = 'https://api.porsche.com';
    public const CLIENT_ID = 'XhygisuebbrqQ80byOuU5VncxLIm8E6H';
    public const X_CLIENT_ID = '41843fb4-691d-4970-85c7-2673e8ecef40';
    public const USER_AGENT = 'php-porsche-connect-api/0.1.0';
    public const API_BASE_URL = 'https://api.ppa.porsche.com/app';
    public const AUTHORIZATION_URL = 'https://' . self::AUTHORIZATION_SERVER . '/authorize';
    public const TOKEN_URL = 'https://' . self::AUTHORIZATION_SERVER . '/oauth/token';
    public const TIMEOUT = 90;
    public const TIRE_PRESSURE_TOLERANCE = 0.3;

    public const SCOPES = [
        'openid',
        'profile',
        'email',
        'offline_access',
        'mbb',
        'ssodb',
        'badge',
        'vin',
        'dealers',
        'cars',
        'charging',
        'manageCharging',
        'plugAndCharge',
        'climatisation',
        'manageClimatisation',
        'pid:user_profile.porscheid:read',
        'pid:user_profile.name:read',
        'pid:user_profile.vehicles:read',
        'pid:user_profile.dealers:read',
        'pid:user_profile.emails:read',
        'pid:user_profile.phones:read',
        'pid:user_profile.addresses:read',
        'pid:user_profile.birthdate:read',
        'pid:user_profile.locale:read',
        'pid:user_profile.legal:read',
    ];

    public const SCOPE = 'openid profile email offline_access mbb ssodb badge vin dealers cars charging manageCharging plugAndCharge climatisation manageClimatisation pid:user_profile.porscheid:read pid:user_profile.name:read pid:user_profile.vehicles:read pid:user_profile.dealers:read pid:user_profile.emails:read pid:user_profile.phones:read pid:user_profile.addresses:read pid:user_profile.birthdate:read pid:user_profile.locale:read pid:user_profile.legal:read';

    public const MEASUREMENTS = [
        'ACV_STATE',
        'ALARM_STATE',
        'BATTERY_CHARGING_STATE',
        'BATTERY_LEVEL',
        'BLEID_DDADATA',
        'CHARGING_PROFILES',
        'CHARGING_RATE',
        'CHARGING_SETTINGS',
        'CHARGING_SUMMARY',
        'CLIMATIZER_STATE',
        'DEPARTURES',
        'E_RANGE',
        'FUEL_LEVEL',
        'FUEL_RESERVE',
        'GLOBAL_PRIVACY_MODE',
        'GPS_LOCATION',
        'HEATING_STATE',
        'HVAC_STATE',
        'INTERMEDIATE_SERVICE_RANGE',
        'INTERMEDIATE_SERVICE_TIME',
        'LOCK_STATE_VEHICLE',
        'MAIN_SERVICE_RANGE',
        'MAIN_SERVICE_TIME',
        'MILEAGE',
        'OIL_LEVEL_CURRENT',
        'OIL_LEVEL_MAX',
        'OIL_LEVEL_MIN_WARNING',
        'OIL_SERVICE_RANGE',
        'OIL_SERVICE_TIME',
        'OPEN_STATE_CHARGE_FLAP_LEFT',
        'OPEN_STATE_CHARGE_FLAP_RIGHT',
        'OPEN_STATE_DOOR_FRONT_LEFT',
        'OPEN_STATE_DOOR_FRONT_RIGHT',
        'OPEN_STATE_DOOR_REAR_LEFT',
        'OPEN_STATE_DOOR_REAR_RIGHT',
        'OPEN_STATE_LID_FRONT',
        'OPEN_STATE_LID_REAR',
        'OPEN_STATE_SERVICE_FLAP',
        'OPEN_STATE_SPOILER',
        'OPEN_STATE_SUNROOF',
        'OPEN_STATE_SUNROOF_REAR',
        'OPEN_STATE_TOP',
        'OPEN_STATE_WINDOW_FRONT_LEFT',
        'OPEN_STATE_WINDOW_FRONT_RIGHT',
        'OPEN_STATE_WINDOW_REAR_LEFT',
        'OPEN_STATE_WINDOW_REAR_RIGHT',
        'PAIRING_CODE',
        'PARKING_BRAKE',
        'PARKING_LIGHT',
        'PRED_PRECON_LOCATION_EXCEPTIONS',
        'PRED_PRECON_USER_SETTINGS',
        'RANGE',
        'REMOTE_ACCESS_AUTHORIZATION',
        'SERVICE_PREDICTIONS',
        'THEFT_STATE',
        'TIMERS',
        'TIRE_PRESSURE',
        'VTS_MODES',
    ];

    public const COMMANDS = [
        'BLEID_AGREEMENT_GIVE',
        'BLEID_AGREEMENT_REVOKE',
        'BLEID_DEVICEKEY_UPLOAD',
        'B_CALL_TRIGGER',
        'CHARGING_PROFILES_EDIT',
        'CHARGING_SETTINGS_AUTOPLUG_EDIT',
        'CHARGING_SETTINGS_BATTERYCAREMODE_EDIT',
        'CHARGING_SETTINGS_CERTIFICATES_RESET',
        'CHARGING_SETTINGS_EDIT',
        'CHARGING_STOP',
        'CS_ACCOUNT_FEDERATION',
        'CS_APP_SHOP_ENABLE',
        'CS_APP_SHOP_UPLOAD',
        'CS_C2P_IN_VEHICLE_INFOTAINMENT',
        'CS_DESTINATION_SYNC',
        'CS_PCM_ACCOUNT_SERVICES',
        'CS_PCM_CALENDAR',
        'CS_PILOTED_PARKING',
        'CS_VOICE_MIMIC',
        'CS_YOUNITED',
        'CS_VIDEOSTREAMING_VOUCHER',
        'DEPARTURES_EDIT',
        'DIRECT_CHARGING_START',
        'DIRECT_CHARGING_STOP',
        'HONK_FLASH',
        'LOCK',
        'PRED_PRECON_LOCATION_EXCEPTION_EDIT',
        'PRED_PRECON_USER_SETTINGS_EDIT',
        'REMOTE_ACV_START',
        'REMOTE_ACV_STOP',
        'REMOTE_CLIMATIZER_START',
        'REMOTE_CLIMATIZER_STOP',
        'REMOTE_HEATING_START',
        'REMOTE_HEATING_STOP',
        'ROUTE_CALCULATE',
        'SERVICE_PREDICTIONS_VISIBILITY_EDIT',
        'SPIN_CHALLENGE',
        'SPIN_VALIDATION',
        'TIMERS_DISABLE',
        'TIMERS_EDIT',
        'UNLOCK',
    ];

    public const TRIP_STATISTICS = [
        'TRIP_STATISTICS_CYCLIC',
        'TRIP_STATISTICS_LONG_TERM',
        'TRIP_STATISTICS_LONG_TERM_HISTORY',
        'TRIP_STATISTICS_SHORT_TERM_HISTORY',
        'TRIP_STATISTICS_CYCLIC_HISTORY',
        'TRIP_STATISTICS_SHORT_TERM',
    ];
}
