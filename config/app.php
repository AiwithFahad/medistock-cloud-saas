<?php
/**
 * MediStock Cloud — application configuration.
 * Values are read from environment variables (see .env.example).
 */
return [
    'name'        => getenv('APP_NAME') ?: 'MediStock Cloud',
    'env'         => getenv('APP_ENV') ?: 'production',
    'debug'       => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'url'         => rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/'),
    'timezone'    => getenv('APP_TIMEZONE') ?: 'UTC',
    'currency'    => getenv('APP_CURRENCY') ?: 'USD',
    'trial_days'  => (int)(getenv('APP_TRIAL_DAYS') ?: 14),
    'grace_days'  => (int)(getenv('APP_GRACE_DAYS') ?: 7),
    // Set to "1" after installation to lock the web installer.
    'installed'   => getenv('APP_INSTALLED') ?: '0',
];
