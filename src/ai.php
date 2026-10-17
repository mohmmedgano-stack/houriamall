<?php
declare(strict_types=1);
/*
 * Provider-agnostic AI layer (Anthropic or OpenAI-compatible). Keys stay server-side, encrypted.
 * Every call is task-scoped, logged (tokens/status) and capped monthly. Core features never depend on it:
 * if it's off, fails, or produces numbers that aren't in the source data, we fall back to deterministic output.
 * The model can never change budgets or launch anything — it only returns text.
 */

function ai_integration(): ?array { return q_one("SELECT * FROM integrations WHERE type = 'ai' ORDER BY id LIMIT 1"); }
function ai_config(): array { $i = ai_integration(); return $i ? decrypt_json($i['config_enc']) : []; }
function ai_save_config(array $cfg): void {
    $cfg['provider'] = in_array($cfg['provider'] ?? '', ['anthropic', 'openai'], true) ? $cfg['provider'] : 'anthropic';
    $cfg['model'] = trim((string)($cfg['model'] ?? '')) ?: ($cfg['provider'] === 'anthropic' ? 'claude-sonnet-5-5' : 'gpt-4.1-mini');
    $cfg['monthly_calls'] = max(1, min(100000, (int)($cfg['monthly_calls'] ?? 500)));
    $old = ai_config();
    if (empty($cfg['api_key'])) $cfg['api_key'] = $old['api_key'] ?? '';
    $row = ['config_enc' => encrypt_json($cfg), 'status' => $cfg['api_key'] ? 'configured' : 'not_configured', 'updated_at' => now()];
    if ($i = ai_integration()) db_update('integrations', (int)$i['id'], $row);
    else db_insert('integrations', $row + ['type' => 'ai', 'name' => 'AI', 'created_at' => now()]);
    audit('ai.config', 'integration', null, ['provider' => $cfg['provider'], 'model' => $cfg['model']]);
}
function ai_calls_this_month(): int { return (int)q_val("SELECT COUNT(*) FROM ai_calls WHERE created_at >= ? AND status <> 'blocked'", [gmdate('Y-m-01 00:00:00')]); }
function ai_available(): bool {
    $c = ai_config();
    return !empty($c['api_key']) && !empty($c['enabled']) && ai_calls_this_month() < (int)($c['monthly_calls'] ?? 500);
}

/** One task-scoped completion. Returns [text|null, error|null]. */
function ai_complete(string $task, string $system, string $user, int $maxTokens = 1500): array {
    $c = ai_config();
    if (empty($c['api_key']) || empty($c['enabled'])) return [null, 'الـ AI مش متفعّل'];
    if (ai_calls_this_month() >= (int)($c['monthly_calls'] ?? 500)) {
        db_insert('ai_calls', ['task' => $task, 'provider' => $c['provider'], 'model' => $c['model'], 'status' => 'blocked', 'error' => 'monthly limit', 'user_id' => uid(), 'created_at' => now()]);
        return [null, 'وصلت لحد الاستخدام الشهري للـ AI'];
    }
    if ($c['provider'] === 'openai') {
        $url = rtrim((string)($c['base_url'] ?? 'https://api.openai.com/v1'), '/') . '/chat/completions';
        $headers = ['Authorization: Bearer ' . $c['api_key'], 'Content-Type: application/json'];
        $body = ['model' => $c['model'], 'max_tokens' => $maxTokens, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]]];
    } else {
        $url = 'https://api.anthropic.com/v1/messages';
        $headers = ['x-api-key: ' . $c['api_key'], 'anthropic-version: 2023-06-01', 'content-type: application/json'];
        $body = ['model' => $c['model'], 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]];
    }
    [$status, $resp] = ai_http($url, $headers, json_encode($body, JSON_UNESCAPED_UNICODE));
    $j = json_decode((string)$resp, true);
    $text = null; $in = $out = null; $err = null;
    if ($status >= 200 && $status < 300 && is_array($j)) {
        if ($c['provider'] === 'openai') { $text = $j['choices'][0]['message']['content'] ?? null; $in = $j['usage']['prompt_tokens'] ?? null; $out = $j['usage']['completion_tokens'] ?? null; }
        else { $text = implode('', array_map(fn($b) => $b['text'] ?? '', array_filter($j['content'] ?? [], fn($b) => ($b['type'] ?? '') === 'text'))); $in = $j['usage']['input_tokens'] ?? null; $out = $j['usage']['output_tokens'] ?? null; }
        if ($text === null || trim($text) === '') $err = 'رد فاضي من مزود الـ AI';
    } else $err = 'HTTP ' . $status . ': ' . mb_substr((string)($j['error']['message'] ?? $resp), 0, 200);
    db_insert('ai_calls', ['task' => $task, 'provider' => $c['provider'], 'model' => $c['model'], 'input_tokens' => $in, 'output_tokens' => $out,
        'status' => $err ? 'error' : 'ok', 'error' => $err ? redact($err) : null, 'user_id' => uid(), 'created_at' => now()]);
    return $err ? [null, $err] : [trim($text), null];
}
function ai_http(string $url, array $headers, string $body): array {
    if (!empty($GLOBALS['AI_HTTP'])) return ($GLOBALS['AI_HTTP'])($url, $headers, $body);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10]);
    $r = curl_exec($ch); $s = $r === false ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($r === false) $r = json_encode(['error' => ['message' => curl_error($ch)]]);
    curl_close($ch);
    return [$s, (string)$r];
}

/** Every number (≥ 2 digits) the model wrote must exist in the source data (any common rounding/format). */
function ai_numbers_supported(string $text, array $data): bool {
    $known = [];
    array_walk_recursive($data, function ($v) use (&$known) {
        if (!is_numeric($v)) return;
        $f = (float)$v;
        foreach ([$f, $f * 100] as $x) foreach ([0, 1, 2] as $d) $known[number_format(round($x, $d), $d, '.', '')] = true;
    });
    preg_match_all('/\d[\d,]*(?:\.\d+)?/u', to_latin_digits($text), $m);
    foreach ($m[0] as $n) {
        $n = str_replace(',', '', $n);
        if (strlen(preg_replace('/\D/', '', $n)) < 2) continue;                      // ignore single digits ("3 حملات")
        if (preg_match('/^(19|20)\d\d$/', $n)) continue;                              // years / dates
        if (isset($known[$n]) || isset($known[number_format((float)$n, 0, '.', '')]) || isset($known[number_format((float)$n, 2, '.', '')])) continue;
        return false;
    }
    return true;
}

function ai_report_summary(array $data): array {
    $facts = ['client' => $data['client']['name'], 'period' => [$data['from'], $data['to']], 'previous_period' => [$data['prev_from'], $data['prev_to']],
        'totals' => $data['totals'], 'goals' => $data['goals'], 'lead_quality' => $data['lead_quality'] ?? null, 'budgets' => $data['budgets'],
        'issues' => $data['issues'], 'recommendations' => $data['recommendations']];
    $system = "أنت محلل Media Buying بتكتب ملخص تنفيذي لتقرير عميل باللهجة المصرية المهنية.\nقواعد صارمة:\n- استخدم الأرقام الموجودة في البيانات بس، ومتحسبش أرقام جديدة ومتقربش بشكل مختلف.\n- متخترعش أسباب أو تفسيرات مش موجودة في البيانات. لو حاجة مش واضحة قول إنها محتاجة متابعة.\n- فرّق بين الحقيقة (من الأرقام) والتوقع أو الاقتراح، واكتب الاقتراحات كاقتراحات.\n- القيم null معناها البيانات مش متاحة.\n- 4 إلى 6 جمل، من غير عناوين ولا Markdown.";
    [$text, $err] = ai_complete('report_summary', $system, json_encode($facts, JSON_UNESCAPED_UNICODE), 700);
    if ($err) return [null, $err];
    if (!ai_numbers_supported($text, $facts)) { log_error('ai.guard', 'report summary contained unsupported numbers'); return [null, 'الملخص فيه أرقام مش موجودة في البيانات فاتشال']; }
    return [$text, null];
}

function ai_copy_generate(array $d): array {
    $lang = ['eg' => 'العامية المصرية', 'msa' => 'العربية الفصحى', 'en' => 'English'][$d['lang']];
    $system = "You are a senior performance-marketing copywriter. Write in $lang.\nHard rules:\n- Use ONLY the facts provided (product, description, USPs, price, offer, audience). Never invent features, numbers, results, guarantees, testimonials or scarcity.\n- If there is no offer, do not mention discounts or limited time.\n- No misleading or prohibited claims (Meta ad policies). Headlines ≤ 40 chars, descriptions ≤ 30 chars.\n- Return ONLY valid JSON with keys: hooks (8 strings), primary_text (3 strings), headlines (5), descriptions (3), ctas (4), reels ([{title, beats:[...]}] x3), scripts ([{title, scenes:[[time, line, visual]]}] x2), ugc (3 strings), angles ([{name, idea}] x5), ab_tests ([{test, a, b, metric, note}] x3), notes ([string]).";
    [$text, $err] = ai_complete('copy_generate', $system, json_encode($d, JSON_UNESCAPED_UNICODE), 3500);
    if ($err) return [null, $err];
    $json = preg_match('/\{.*\}/su', $text, $m) ? json_decode($m[0], true) : null;
    if (!is_array($json) || empty($json['hooks']) || empty($json['primary_text'])) return [null, 'رد الـ AI مش بالشكل المطلوب'];
    $json['headlines'] = array_map(fn($h) => mb_substr((string)$h, 0, 40), (array)($json['headlines'] ?? []));
    $json['descriptions'] = array_map(fn($h) => mb_substr((string)$h, 0, 30), (array)($json['descriptions'] ?? []));
    $json['notes'] = array_merge((array)($json['notes'] ?? []), ['اتكتب بالـ AI من المعلومات اللي دخلتها بس — راجع كل ادعاء قبل النشر.']);
    return [$json, null];
}
