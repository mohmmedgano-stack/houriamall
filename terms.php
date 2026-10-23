<?php $host = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'ganoadsai.online', ENT_QUOTES, 'UTF-8'); ?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terms of Service – <?= $host ?></title>
<style>body{font-family:system-ui,Tahoma,sans-serif;max-width:760px;margin:40px auto;padding:0 16px;line-height:1.8;color:#222;background:#fff}h1{font-size:1.5rem}.en{direction:ltr;text-align:left;border-top:1px solid #ddd;margin-top:40px;padding-top:20px}</style>
</head>
<body>
<h1>شروط الاستخدام</h1>
<p>هذه الأداة خاصة بمالك الموقع <?= $host ?>، ويستخدمها للرد التلقائي على التعليقات في حسابات فيسبوك وتيك توك التي يملكها أو يديرها. لا تُقدَّم الأداة للجمهور، ولا يُسمح باستخدامها إلا بإذن المالك.</p>
<p>تلتزم الأداة بشروط وسياسات فيسبوك وتيك توك، ولا ترسل إلا الردود التي يكتبها صاحب الحساب.</p>
<div class="en">
<h1>Terms of Service</h1>
<p>This tool is privately operated by the owner of <?= $host ?> to automatically reply to comments on Facebook Pages and TikTok accounts they own or manage. It is not offered to the public and may only be used with the owner's permission.</p>
<p>The tool follows the Facebook and TikTok platform terms and only posts replies written by the account owner.</p>
</div>
</body>
</html>
