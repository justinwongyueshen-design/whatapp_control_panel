<?php
/**
 * Phone Number Normalization Helper
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function get_default_country_code(): string {
    static $defaultCode = null;
    if ($defaultCode !== null) {
        return $defaultCode;
    }
    try {
        $db = get_db();
        $stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'default_country_code' LIMIT 1");
        $val = $stmt->fetchColumn();
        $defaultCode = !empty($val) ? preg_replace('/\D/', '', (string)$val) : '60';
    } catch (Exception $e) {
        $defaultCode = '60';
    }
    return $defaultCode;
}

/**
 * Normalizes phone number into international digit-only format (e.g., 60123456789)
 *
 * @param string $phone
 * @param string|null $defaultCountryCode
 * @return string|false Normalized phone string, or false if invalid
 */
function normalize_phone(string $phone, ?string $defaultCountryCode = null): string|false {
    // 1. Strip spaces, dashes, brackets, dots, plus signs
    $raw = trim($phone);
    if (empty($raw)) {
        return false;
    }

    // Check if original had leading plus or 00
    $hasPlus = str_starts_with($raw, '+');
    $digits = preg_replace('/\D/', '', $raw);

    if (empty($digits)) {
        return false;
    }

    $cc = $defaultCountryCode ?: get_default_country_code();

    // 2. Handle international prefix '00'
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    // 3. Handle local prefix '0' (e.g. 0123456789 -> 60123456789)
    if (str_starts_with($digits, '0')) {
        $digits = $cc . substr($digits, 1);
    } elseif (!$hasPlus && !str_starts_with($digits, $cc) && strlen($digits) <= 10) {
        // If no plus and doesn't start with country code, prepend default CC
        $digits = $cc . $digits;
    }

    // 4. Validate E.164 length limits (standard international phone: 8 to 15 digits)
    $len = strlen($digits);
    if ($len < 8 || $len > 15) {
        return false;
    }

    return $digits;
}
