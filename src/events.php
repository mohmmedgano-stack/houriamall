<?php
declare(strict_types=1);

/** Domain events feed the Automation Hub. Stored first, processed by the worker (and right away when cheap). */
function emit_event(string $type, ?int $clientId, string $ref = '', array $payload = []): int {
    return db_insert('events', ['type' => $type, 'client_id' => $clientId, 'ref' => $ref,
        'payload_json' => json_encode(redact($payload), JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
}

function event_types(): array {
    return [
        'lead.created' => 'وصول ليد جديد', 'lead.stage_changed' => 'تغيير مرحلة ليد', 'lead.won' => 'ليد اتحول لبيع (Won)',
        'lead.overdue' => 'تأخر متابعة ليد', 'alert.created' => 'تنبيه جديد من Watchdog', 'budget.threshold' => 'الميزانية قربت تخلص / اتخطت الحد',
        'performance.imported' => 'استيراد/مزامنة بيانات أداء', 'autoreply.failed' => 'فشل في الرد التلقائي على فيسبوك', 'schedule' => 'جدول زمني (يومي/أسبوعي/شهري)',
        'invoice.due' => 'فاتورة مستحقة',
    ];
}
