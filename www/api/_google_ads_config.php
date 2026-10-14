<?php
declare(strict_types=1);

function google_ads_id(): string
{
    $id = trim((string) ($_ENV['GOOGLE_ADS_ID'] ?? getenv('GOOGLE_ADS_ID') ?: ''));
    return $id !== '' ? $id : 'AW-18167666446';
}

/** GA4 Measurement ID (вебпотік strim.in.ua). */
function ga4_measurement_id(): string
{
    $id = trim((string) ($_ENV['GA4_MEASUREMENT_ID'] ?? getenv('GA4_MEASUREMENT_ID') ?: ''));
    return $id !== '' ? $id : 'G-5G2LNXY143';
}

function google_ads_purchase_label(): string
{
    $label = trim((string) ($_ENV['GOOGLE_ADS_PURCHASE_LABEL'] ?? getenv('GOOGLE_ADS_PURCHASE_LABEL') ?: ''));
    return $label !== '' ? $label : 'a_xMCMH4nK4cEl6ugtdD';
}

/** send_to для gtag('event','conversion',…) — порожньо, якщо label не задано. */
function google_ads_purchase_send_to(): string
{
    $label = google_ads_purchase_label();
    if ($label === '') {
        return '';
    }
    $id = google_ads_id();
    if (strpos($label, '/') !== false) {
        return $label;
    }

    return $id . '/' . $label;
}

function google_ads_purchase_value_uah(): float
{
    $n = (float) ($_ENV['GOOGLE_ADS_PURCHASE_VALUE_UAH'] ?? getenv('GOOGLE_ADS_PURCHASE_VALUE_UAH') ?: '500');
    return $n > 0 ? $n : 500.0;
}

function google_ads_registration_label(): string
{
    $label = trim((string) ($_ENV['GOOGLE_ADS_REGISTRATION_LABEL'] ?? getenv('GOOGLE_ADS_REGISTRATION_LABEL') ?: ''));
    return $label !== '' ? $label : '3WR_COGmz7QcEI6ugtdD';
}

function google_ads_registration_send_to(): string
{
    $label = google_ads_registration_label();
    if ($label === '') {
        return '';
    }
    $id = google_ads_id();
    if (strpos($label, '/') !== false) {
        return $label;
    }

    return $id . '/' . $label;
}

function google_ads_registration_value_uah(): float
{
    $n = (float) ($_ENV['GOOGLE_ADS_REGISTRATION_VALUE_UAH'] ?? getenv('GOOGLE_ADS_REGISTRATION_VALUE_UAH') ?: '10');
    return $n > 0 ? $n : 10.0;
}
