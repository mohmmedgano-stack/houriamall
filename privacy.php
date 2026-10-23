<?php $host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'ganoadsai.online', ENT_QUOTES, 'UTF-8'); ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacy Policy – <?= $host ?></title>
<style>body{font-family:system-ui,Tahoma,sans-serif;max-width:760px;margin:40px auto;padding:0 16px;line-height:1.8;color:#222;background:#fff}h1{font-size:1.5rem}h2{font-size:1.1rem;margin-top:28px}.en{direction:ltr;text-align:left;border-top:1px solid #ddd;margin-top:40px;padding-top:20px}</style>
</head>
<body>
<h1>سياسة الخصوصية</h1>
<p>هذه الأداة تُستخدم من قِبل مالك الموقع <?= $host ?> للرد التلقائي على تعليقات صفحات فيسبوك وحسابات تيك توك التي يديرها، وإرسال رسالة خاصة واحدة لصاحب التعليق على فيسبوك.</p>
<h2>البيانات التي نستخدمها</h2>
<p>نستقبل من فيسبوك وتيك توك نص التعليق واسم صاحبه ومعرّف التعليق فقط، ونستخدمها لإرسال الرد ولحفظ سجل داخلي يمنع تكرار الرد. لا نبيع أو نشارك أي بيانات مع أي طرف ثالث.</p>
<h2>حذف البيانات</h2>
<p>يمكنك طلب حذف أي بيانات تخصك بمراسلة الصفحة التي علّقت عليها، وسيتم حذفها خلال 30 يومًا.</p>
<div class="en">
<h1>Privacy Policy</h1>
<p>This tool is operated by the owner of <?= $host ?> to automatically reply to comments on Facebook Pages and TikTok accounts they manage, and to send one private reply to the commenter on Facebook.</p>
<p>We only receive the comment text, commenter name and comment ID from Facebook and TikTok, used solely to send the reply and keep an internal log preventing duplicate replies. No data is sold or shared with third parties.</p>
<p>To request deletion of your data, message the Page you commented on; it will be deleted within 30 days.</p>
</div>
</body>
</html>
