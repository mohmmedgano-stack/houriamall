<?php
declare(strict_types=1);

/** Secret key for encrypting integration credentials. Lives in data/ (blocked from the web), separate from the DB file. */
function secret_key(): string {
    $f = DATA_DIR . '/secret.key';
    if (!is_file($f)) {
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0750, true);
        file_put_contents($f, base64_encode(sodium_crypto_secretbox_keygen()), LOCK_EX);
        @chmod($f, 0600);
    }
    $k = base64_decode(trim((string)file_get_contents($f)), true);
    if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('secret.key is damaged');
    return $k;
}
function encrypt_str(string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secret_key()));
}
function decrypt_str(?string $enc): ?string {
    if (!$enc || !str_starts_with($enc, 'v1:')) return null;
    $raw = base64_decode(substr($enc, 3), true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secret_key());
    return $out === false ? null : $out;
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
