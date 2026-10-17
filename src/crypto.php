<?php
declare(strict_types=1);
/*
 * Encryption for integration secrets. Uses libsodium (secretbox) when available, otherwise OpenSSL AES-256-GCM —
 * some hosts ship PHP CLI without sodium, and the worker must be able to read the same secrets as the web app.
 * The key lives in data/secret.key (blocked from the web), separate from the database file.
 */

function secret_key(): string {
    $f = DATA_DIR . '/secret.key';
    if (!is_file($f)) {
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0750, true);
        file_put_contents($f, base64_encode(random_bytes(32)), LOCK_EX);
        @chmod($f, 0600);
    }
    $k = base64_decode(trim((string)file_get_contents($f)), true);
    if ($k === false || strlen($k) !== 32) throw new RuntimeException('secret.key is damaged');
    return $k;
}
function encrypt_str(string $plain): string {
    // v2 (OpenSSL GCM) works everywhere, so new values always use it; v1 (sodium) values stay readable
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('encryption failed');
    return 'v2:' . base64_encode($iv . $tag . $ct);
}
function decrypt_str(?string $enc): ?string {
    if (!$enc) return null;
    if (str_starts_with($enc, 'v2:')) {
        $raw = base64_decode(substr($enc, 3), true);
        if ($raw === false || strlen($raw) < 29) return null;
        $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $out === false ? null : $out;
    }
    if (str_starts_with($enc, 'v1:') && function_exists('sodium_crypto_secretbox_open')) {
        $raw = base64_decode(substr($enc, 3), true);
        if ($raw === false || strlen($raw) < 24) return null;
        $out = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), secret_key());
        return $out === false ? null : $out;
    }
    return null;
}
function encrypt_json(array $a): string { return encrypt_str(json_encode($a, JSON_UNESCAPED_UNICODE)); }
function decrypt_json(?string $enc): array { $s = decrypt_str($enc); $a = $s ? json_decode($s, true) : null; return is_array($a) ? $a : []; }

/** Show only the last 4 characters of a secret. */
function mask_secret(?string $s): string { return $s ? '••••' . substr($s, -4) : ''; }

/** Remove anything that looks like a secret before writing to logs. */
function redact($v) {
    if (is_array($v)) {
        $o = [];
        foreach ($v as $k => $x) $o[$k] = preg_match('/token|secret|password|key|authorization/i', (string)$k) ? '[redacted]' : redact($x);
        return $o;
    }
    if (is_string($v)) return preg_replace('/(access_token=|appsecret_proof=|sk-ant-[a-z0-9-]*|EAA)[A-Za-z0-9_\-\.]+/i', '$1[redacted]', $v) ?? $v;
    return $v;
}
