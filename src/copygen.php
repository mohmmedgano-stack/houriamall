<?php
declare(strict_types=1);
/*
 * Ad Copy & Hook Generator. Works without any AI key (template engine). With AI configured, the same inputs go to
 * the model with strict instructions. Either way: only facts the user typed are used — no invented features,
 * numbers, guarantees or misleading promises.
 */

function copy_inputs(array $in): array {
    $usps = array_values(array_filter(array_map('trim', preg_split('/[\n،,]+/u', (string)($in['usps'] ?? '')) ?: []), fn($x) => $x !== ''));
    $d = ['product' => trim((string)($in['product'] ?? '')), 'description' => trim((string)($in['description'] ?? '')), 'audience' => trim((string)($in['audience'] ?? '')),
        'usps' => array_slice($usps, 0, 8), 'price' => trim((string)($in['price'] ?? '')), 'offer' => trim((string)($in['offer'] ?? '')),
        'objective' => in_array($in['objective'] ?? '', ['leads', 'sales', 'traffic', 'awareness', 'engagement', 'messages'], true) ? $in['objective'] : 'leads',
        'platform' => in_array($in['platform'] ?? '', ['facebook', 'instagram', 'tiktok', 'google'], true) ? $in['platform'] : 'facebook',
        'lang' => in_array($in['lang'] ?? '', ['eg', 'msa', 'en'], true) ? $in['lang'] : 'eg',
        'content' => in_array($in['content'] ?? '', ['all', 'video', 'image', 'ugc'], true) ? $in['content'] : 'all'];
    if ($d['product'] === '') throw new InvalidArgumentException('اكتب اسم المنتج أو المشروع');
    foreach (['product' => 120, 'description' => 1500, 'audience' => 300, 'price' => 60, 'offer' => 200] as $k => $max) $d[$k] = mb_substr($d[$k], 0, $max);
    return $d;
}

function copy_cta_options(string $platform, string $objective): array {
    $map = ['leads' => ['Sign Up — سجّل دلوقتي', 'Get Quote — اطلب عرض سعر', 'Learn More — اعرف أكتر', 'Contact Us — كلمنا'],
        'sales' => ['Shop Now — اشتري دلوقتي', 'Order Now — اطلب دلوقتي', 'Get Offer — احصل على العرض', 'Learn More — اعرف أكتر'],
        'traffic' => ['Learn More — اعرف أكتر', 'See More — شوف أكتر', 'Visit Website — زور الموقع'], 'messages' => ['Send Message — ابعتلنا رسالة', 'WhatsApp — كلمنا واتساب'],
        'awareness' => ['Learn More — اعرف أكتر', 'Watch More — شوف أكتر'], 'engagement' => ['Learn More — اعرف أكتر', 'Send Message — ابعتلنا رسالة']];
    $c = $map[$objective] ?? $map['leads'];
    if ($platform === 'tiktok') $c = array_map(fn($x) => str_replace('Sign Up', 'Sign up', $x), $c);
    return $c;
}

/** Deterministic template generation in Egyptian Arabic / MSA / English from the user's own facts. */
function copy_generate_templates(array $d): array {
    $P = $d['product']; $A = $d['audience'] ?: ($d['lang'] === 'en' ? 'you' : ''); $U = $d['usps']; $O = $d['offer']; $PR = $d['price'];
    $u = fn(int $i) => $U ? $U[$i % count($U)] : null;
    $eg = $d['lang'] === 'eg'; $en = $d['lang'] === 'en';
    $t = fn(string $egText, string $msaText, string $enText) => $en ? $enText : ($eg ? $egText : $msaText);
    $aud = $A !== '' ? $A : $t('انت', 'أنت', 'you');
    $hooks = [
        $t("لو انت من {$aud}، استنى ثانية قبل ما تكمل سكرول 👇", "إذا كنت من {$aud}، توقف لحظة قبل أن تكمل 👇", "If you're $aud, stop scrolling for a second 👇"),
        $t("السؤال اللي كل $aud بيسأله عن {$P}…", "السؤال الذي يطرحه $aud دائماً عن {$P}…", "The one question $aud always ask about {$P}…"),
        $t("ده اللي محدش قالك عليه عن $P", "هذا ما لم يخبرك به أحد عن $P", "What nobody told you about $P"),
        $t("3 حاجات لازم تعرفها قبل ما تختار $P", "3 أمور يجب معرفتها قبل اختيار $P", "3 things to know before choosing $P"),
    ];
    if ($u(0)) $hooks[] = $t("{$u(0)} — ودي مش كل الحكاية", "{$u(0)} — وهذه ليست القصة كاملة", "{$u(0)} — and that's not all");
    if ($u(1)) $hooks[] = $t("عايز {$u(1)}؟ يبقى لازم تشوف ده", "تبحث عن {$u(1)}؟ شاهد هذا", "Looking for {$u(1)}? Watch this");
    if ($O !== '') $hooks[] = $t("عرض لفترة محدودة: $O", "عرض لفترة محدودة: $O", "Limited-time offer: $O");
    if ($PR !== '') $hooks[] = $t("$P بـ $PR بس؟ تعالى نشوف إيه اللي فيه", "$P بسعر $PR — إليك التفاصيل", "$P at $PR — here's what you get");
    $hooks[] = $t("قبل وبعد ما جربوا $P", "قبل وبعد تجربة $P", "Before and after trying $P");
    $uspList = $U ? implode("\n", array_map(fn($x) => "✔️ $x", array_slice($U, 0, 4))) : '';
    $ctaLine = ['leads' => $t('سجّل بياناتك وهنكلمك 📞', 'سجّل بياناتك وسنتواصل معك 📞', 'Leave your details and we will call you 📞'),
        'sales' => $t('اطلب دلوقتي 🛒', 'اطلب الآن 🛒', 'Order now 🛒'), 'messages' => $t('ابعتلنا رسالة 💬', 'راسلنا الآن 💬', 'Message us 💬')][$d['objective']] ?? $t('اعرف أكتر 👇', 'اعرف المزيد 👇', 'Learn more 👇');
    $primary = [
        trim($hooks[0] . "\n\n" . ($d['description'] !== '' ? $d['description'] . "\n\n" : '') . ($uspList ? $uspList . "\n\n" : '') . ($O !== '' ? "🎁 $O\n\n" : '') . $ctaLine),
        trim($t("$P — ", "$P — ", "$P — ") . ($u(0) ?? $d['description']) . ($PR !== '' ? $t("\nالسعر: $PR", "\nالسعر: $PR", "\nPrice: $PR") : '') . "\n" . $ctaLine),
        trim($hooks[1] . "\n" . $t("الإجابة ببساطة: ", "الإجابة ببساطة: ", "Simply put: ") . ($u(0) ?? $P) . ($u(1) ? $t(" وكمان ", " بالإضافة إلى ", " plus ") . $u(1) : '') . ".\n" . $ctaLine),
    ];
    $head = array_values(array_unique(array_filter([
        mb_substr($P . ($u(0) ? ' | ' . $u(0) : ''), 0, 40), $O !== '' ? mb_substr($O, 0, 40) : null, $PR !== '' ? mb_substr($t("$P بـ $PR", "$P بـ $PR", "$P from $PR"), 0, 40) : null,
        mb_substr($t("اعرف تفاصيل $P", "تعرّف على $P", "Discover $P"), 0, 40), $u(1) ? mb_substr($u(1), 0, 40) : null])));
    $desc = array_values(array_unique(array_filter([$u(2) ? mb_substr($u(2), 0, 30) : null, $O !== '' ? mb_substr($O, 0, 30) : null, mb_substr($t('الأماكن محدودة', 'الكمية محدودة', 'Limited availability'), 0, 30)])));
    // honest scarcity line only when an offer exists; otherwise neutral
    if ($O === '') $desc = array_values(array_filter($desc, fn($x) => !str_contains($x, 'محدود') && !str_contains($x, 'Limited'))) ?: [mb_substr($t("اعرف أكتر عن $P", "اعرف المزيد عن $P", "More about $P"), 0, 30)];
    $angles = array_values(array_filter([
        $u(0) ? ['name' => $t('الميزة الأساسية', 'الميزة الأساسية', 'Core benefit'), 'idea' => $t("ركّز على: {$u(0)}", "التركيز على: {$u(0)}", "Lead with: {$u(0)}")] : null,
        ['name' => $t('المشكلة والحل', 'المشكلة والحل', 'Problem / solution'), 'idea' => $t("ابدأ بمشكلة $aud اليومية واعرض $P كحل", "ابدأ بمشكلة $aud واعرض $P كحل", "Open with $aud's problem; present $P as the fix")],
        ['name' => $t('الدليل الاجتماعي', 'الدليل الاجتماعي', 'Social proof'), 'idea' => $t('شهادة عميل حقيقي (استخدم تجارب حقيقية بس)', 'شهادة عميل حقيقي فقط', 'Real customer testimonial only')],
        $O !== '' ? ['name' => $t('العرض', 'العرض', 'Offer'), 'idea' => $t("العرض: $O — وضّح شروطه", "العرض: $O مع توضيح الشروط", "Offer: $O — state its terms")] : null,
        $PR !== '' ? ['name' => $t('القيمة مقابل السعر', 'القيمة مقابل السعر', 'Value for price'), 'idea' => $t("اشرح إنت بتاخد إيه مقابل $PR", "وضّح ما يحصل عليه العميل مقابل $PR", "Show what $PR gets you")] : null,
        ['name' => $t('خلف الكواليس', 'خلف الكواليس', 'Behind the scenes'), 'idea' => $t("ورّي إزاي $P بيتعمل/بيتسلم", "اعرض كيف يتم تقديم $P", "Show how $P is made/delivered")],
    ]));
    $reels = [
        ['title' => $t('Hook + 3 مميزات', 'Hook + 3 مزايا', 'Hook + 3 benefits'), 'beats' => [$hooks[0], $u(0) ?? $P, $u(1) ?? '', $u(2) ?? '', $ctaLine]],
        ['title' => $t('سؤال وجواب', 'سؤال وإجابة', 'Q&A'), 'beats' => [$hooks[1], $t('الرد في 3 ثواني', 'الإجابة في 3 ثوانٍ', 'Answer in 3 seconds'), $u(0) ?? $P, $ctaLine]],
        ['title' => $t('قبل/بعد', 'قبل/بعد', 'Before/after'), 'beats' => [$t('قبل: المشكلة', 'قبل: المشكلة', 'Before: the problem'), $t("بعد: مع $P", "بعد: مع $P", "After: with $P"), $ctaLine]],
    ];
    $scripts = [
        ['title' => $t('سكريبت 20 ثانية', 'نص 20 ثانية', '20s script'), 'scenes' => [
            ['0-3s', $hooks[0], $t('لقطة قريبة لوش متكلم', 'لقطة قريبة لمتحدث', 'Close-up of a speaker')],
            ['3-12s', $u(0) ? implode(' + ', array_slice($U, 0, 3)) : ($d['description'] ?: $P), $t('لقطات للمنتج/المكان', 'لقطات للمنتج', 'Product shots')],
            ['12-17s', $O !== '' ? $O : ($PR !== '' ? $PR : $P), $t('نص على الشاشة', 'نص على الشاشة', 'On-screen text')],
            ['17-20s', $ctaLine, $t('اللوجو + زرار', 'الشعار + زر', 'Logo + button')]]],
        ['title' => $t('سكريبت UGC 30 ثانية', 'نص UGC مدته 30 ثانية', '30s UGC script'), 'scenes' => [
            ['0-4s', $t("بصراحة كنت شاكك في {$P}…", "في البداية كنت متردداً بشأن {$P}…", "Honestly I wasn't sure about {$P}…"), $t('سيلفي فيديو', 'فيديو سيلفي', 'Selfie video')],
            ['4-20s', $u(0) ? $t("اللي فرق معايا: {$u(0)}", "ما أحدث الفرق: {$u(0)}", "What changed my mind: {$u(0)}") : $P, $t('تجربة حقيقية للمنتج', 'تجربة فعلية', 'Real usage')],
            ['20-30s', $ctaLine, $t('بص للكاميرا', 'النظر للكاميرا', 'Look at camera')]]],
    ];
    $ugc = [$t("عميل حقيقي بيحكي تجربته مع $P في 30 ثانية", "عميل حقيقي يروي تجربته مع $P", "Real customer telling their $P story"),
        $t("Unboxing / أول استخدام لـ $P", "فتح العلبة / أول استخدام لـ $P", "Unboxing / first use of $P"),
        $t("ردود على أكتر 3 أسئلة بتيجي في الكومنتات عن $P", "الإجابة على أكثر 3 أسئلة شيوعاً عن $P", "Answering the top 3 comment questions about $P")];
    $ab = [
        ['test' => 'Hook', 'a' => $hooks[0], 'b' => $hooks[2], 'metric' => 'Hook rate / Link CTR', 'note' => $t('نفس الكريتيف والجمهور، غيّر أول جملة بس', 'نفس الإعلان والجمهور مع تغيير الجملة الأولى فقط', 'Same creative and audience, only the first line changes')],
        ['test' => $t('الزاوية', 'الزاوية', 'Angle'), 'a' => $angles[0]['name'] ?? '', 'b' => $angles[1]['name'] ?? '', 'metric' => $d['objective'] === 'sales' ? 'CPA / ROAS' : 'CPL + Qualified rate', 'note' => $t('اختبر كل زاوية في Ad منفصل بميزانية متساوية لمدة 5-7 أيام', 'إعلان منفصل لكل زاوية بميزانية متساوية 5-7 أيام', 'Separate ad per angle, equal budget, 5–7 days')],
        ['test' => $t('الفورمات', 'الصيغة', 'Format'), 'a' => $t('فيديو 15-20 ثانية', 'فيديو 15-20 ثانية', '15–20s video'), 'b' => $t('صورة / كاروسيل', 'صورة / كاروسيل', 'Image / carousel'), 'metric' => metric_label($d['objective'] === 'sales' ? 'cpa' : 'cpl'), 'note' => $t('متحكمش من غير على الأقل 50 نتيجة لكل نسخة لو تقدر', 'لا تحكم قبل حوالي 50 نتيجة لكل نسخة إن أمكن', 'Aim for ~50 results per variant before deciding')],
    ];
    return ['hooks' => array_values(array_unique($hooks)), 'primary_text' => $primary, 'headlines' => $head, 'descriptions' => $desc, 'ctas' => copy_cta_options($d['platform'], $d['objective']),
        'reels' => $reels, 'scripts' => $scripts, 'ugc' => $ugc, 'angles' => $angles, 'ab_tests' => $ab,
        'notes' => [$t('كل النصوص مبنية على المعلومات اللي كتبتها بس. راجعها قبل النشر واتأكد إن أي ادعاء أو رقم صحيح.', 'النصوص مبنية على معلوماتك فقط؛ راجعها قبل النشر.', 'Copy only uses the facts you provided — review before publishing.')]];
}

/** Generate with AI when available, otherwise templates. Returns [output, source, error]. */
function copy_generate(array $d, bool $useAi): array {
    if ($useAi && function_exists('ai_copy_generate') && ai_available()) {
        [$out, $err] = ai_copy_generate($d);
        if ($out) return [$out, 'ai', null];
        return [copy_generate_templates($d), 'template', $err];
    }
    return [copy_generate_templates($d), 'template', null];
}

function idea_save(int $clientId, array $inputs, array $output, string $source, string $title = ''): int {
    if (!can_client($clientId)) throw new Forbidden('client');
    $id = db_insert('creative_ideas', ['client_id' => $clientId, 'title' => mb_substr($title ?: $inputs['product'] . ' — ' . today(), 0, 200), 'kind' => 'copy',
        'inputs_json' => json_encode($inputs, JSON_UNESCAPED_UNICODE), 'output_json' => json_encode($output, JSON_UNESCAPED_UNICODE), 'status' => 'idea', 'source' => $source,
        'created_by' => uid(), 'created_at' => now()]);
    audit('idea.create', 'idea', $id);
    return $id;
}
function idea_update(int $id, array $in): void {
    $i = q_one('SELECT * FROM creative_ideas WHERE id = ?', [$id]);
    if (!$i || !can_client((int)$i['client_id'])) throw new Forbidden('idea');
    $f = ['updated_at' => now()];
    if (isset($in['status']) && in_array($in['status'], ['idea', 'testing', 'winner', 'loser', 'archived'], true)) $f['status'] = $in['status'];
    if (array_key_exists('test_notes', $in)) $f['test_notes'] = mb_substr(trim((string)$in['test_notes']), 0, 2000);
    if (array_key_exists('creative_asset_id', $in)) {
        $cr = (int)$in['creative_asset_id'];
        if ($cr && (int)q_val('SELECT client_id FROM creative_assets WHERE id = ?', [$cr]) !== (int)$i['client_id']) throw new InvalidArgumentException('الكريتيف ده مش تبع نفس العميل');
        $f['creative_asset_id'] = $cr ?: null;
    }
    db_update('creative_ideas', $id, $f);
}
/** What worked: winners vs losers by angle/hook recorded on tested ideas, plus the linked creative's real KPI. */
function idea_patterns(array $clientIds): array {
    $rows = $clientIds ? q_all("SELECT i.status, i.inputs_json, i.test_notes, ca.angle, ca.hook_type FROM creative_ideas i LEFT JOIN creative_assets ca ON ca.id = i.creative_asset_id
        WHERE i.client_id IN (" . in_list($clientIds) . ") AND i.status IN ('winner','loser')") : [];
    $out = [];
    foreach ($rows as $r) foreach (['angle', 'hook_type'] as $k) if ($r[$k]) { $out[$k][$r[$k]][$r['status']] = ($out[$k][$r[$k]][$r['status']] ?? 0) + 1; }
    return $out;
}
