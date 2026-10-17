# Media Buying Command Center

منصة واحدة لإدارة شغل الـ Media Buying: الأداء، الليدز، الميزانيات، الكريتيف، الجماهير، التقارير، التنبيهات، الأتمتة، والأرباح — لأكتر من عميل وأكتر من حساب إعلاني.

- **التقنية:** PHP 8.1+ و SQLite (نفس تقنية أداة Gano Auto Reply) — من غير Composer ولا Build. بيشتغل على Hostinger زي ما هو.
- **الواجهة:** عربي RTL، موبايل وكمبيوتر، Dark/Light.
- **الاختبارات:** `php tests/run.php` (61 اختبار وحدة) + `python3 tests/http_smoke.py` (تشغيل حقيقي على سيرفر: تجهيز، دخول، كل الصفحات، استيراد، تقارير، تصدير، صلاحيات).

## الأدوات الـ 12

| # | الأداة | المسار | الحالة |
|---|---|---|---|
| 1 | Media Buying Dashboard | `?r=dashboard` | ✅ Campaign/Ad Set/Ad، فلاتر، مقارنة بالفترة السابقة، رسوم، أفضل/أسوأ حسب الهدف، تصدير CSV/Excel |
| 2 | Lead CRM | `?r=crm` | ✅ Pipeline قابل للتعديل، تكرار، توزيع، متابعات، سجل زمني، استيراد/تصدير |
| 3 | Campaign Watchdog | `?r=alerts` | ✅ قواعد لكل عميل/حساب/حملة، حد أدنى للبيانات، Cool-down، تنبيهات ميزانية — مراقبة فقط |
| 4 | Creative Analyzer | `?r=creatives` | ✅ مقارنة بمقياس الهدف، عينات صغيرة، تصنيف Hook/زاوية، مكتبة، أفكار مقترحة |
| 5 | Budget Planner | `?r=budgets` | ✅ مخطط/فعلي، Pacing، توقعات معلنة الافتراض، سيناريوهات غير خطية |
| 6 | Auto Report Generator | `?r=reports` | ✅ 7 أنواع، PDF عربي + Excel + CSV، شعار العميل، ملخص من الأرقام فقط |
| 7 | Campaign Auditor | `?r=auditor` | ✅ Pass/Warning/Fail/Not Checked، فحص آلي للي البيانات تسمح بيه بس |
| 8 | Ad Copy & Hook Generator | `?r=copy` | ✅ قوالب (من غير AI) أو AI، عامية مصرية، حفظ وربط بكريتيف ونتيجة الاختبار |
| 9 | Audience Tester | `?r=audience` | ✅ عمر/نوع/منطقة/Placement/Ad Set/نوع الجمهور، حجم العينة، z-test |
| 10 | Lead Quality Analyzer | `?r=leadquality` | ✅ Contact/Qualified/Appointment/Sales rate، تكلفة المؤهل والبيع، ROAS حقيقي، أسباب الخسارة |
| 11 | Client & Profit Tracker | `?r=finance` | ✅ خدمات وأتعاب، فواتير تلقائية، مدفوعات، مصاريف، ربح شهري/سنوي، ربحية كل عميل |
| 12 | Marketing Automation Hub | `?r=automation` | ✅ Trigger/Conditions/Actions، جدولة، سجل تنفيذ، إعادة محاولة، منع التكرار، 6 قوالب |
| + | Facebook Auto Reply | `?r=autoreply` | ✅ الأداة الحالية مدموجة كوحدة (قراءة فقط) + ربط الصفحات بالعملاء + أحداث الفشل |

## التشغيل محلياً

```bash
php -S 127.0.0.1:8000          # من جوه المجلد
php bin/cli.php setup-key      # مفتاح التجهيز الأول
# افتح http://127.0.0.1:8000 واعمل حساب المالك
php tests/run.php              # الاختبارات
```

## النشر على Hostinger

1. ارفع المجلد (Git deploy لفرع `command-center` على مجلد `public_html/command`).
2. الـ Cron (كل دقيقة): `* * * * * /usr/bin/php /home/USER/domains/DOMAIN/public_html/command/worker.php`
3. افتح الرابط، واعمل حساب المالك بمفتاح التجهيز (`php bin/cli.php setup-key` أو من ملف `data/setup.key`)، أو من الـ CLI:
   `php bin/cli.php create-user "الاسم" email@x.com 'Password123' owner`
4. ملف `.htaccess` بيمنع الوصول لـ `src/ views/ data/ lib/ tests/ bin/ docs/`. اتأكد إن `https://DOMAIN/command/data/app.sqlite` بيرجع 404.
5. PHP 8.1+ بإضافات: `pdo_sqlite, sodium, zip, mbstring, curl, simplexml` (كلها موجودة افتراضياً على Hostinger).

## النسخ الاحتياطي

- من الإعدادات ← "تنزيل نسخة احتياطية"، أو `php bin/cli.php backup`.
- **مهم:** خد نسخة من `data/secret.key` كمان — من غيره مفاتيح Meta و AI المتشفرة مش هتتقري. (الباقي كله بيترجع عادي.)
- اقتراح: Cron يومي `php bin/cli.php backup /home/USER/backups/cc-$(date +\%F).sqlite`.

## ربط Meta Marketing API

1. developers.facebook.com ← App نوع Business ← ضيف Marketing API و Facebook Login for Business.
2. الصلاحيات: `ads_read, read_insights, leads_retrieval, pages_show_list, pages_read_engagement, pages_manage_ads, business_management`.
3. في التكاملات: حط App ID/Secret، وبعدين "تسجيل الدخول بفيسبوك" أو الصق **System User Token** (الأثبت — مش بينتهي).
4. "عرض الحسابات الإعلانية" ← اربط كل حساب بعميله. المزامنة بتشتغل كل 3 ساعات (آخر 3 أيام عشان تحديثات الإسناد) وأول مرة بتسحب 30 يوم.
5. الحسابات اللي انت Admin عليها كفاية فيها Standard Access. حسابات ناس تانية محتاجة Advanced Access + App Review.

الأمان: التوكن والـ Secret متشفرين (libsodium) ومفتاحهم في ملف منفصل، وكل طلب بيتبعت بـ `appsecret_proof`، والتوكن مش بيظهر في الواجهة ولا السجلات. الربط **للقراءة فقط** — مفيش أي كود بيعدل حملات أو ميزانيات.

## الذكاء الاصطناعي (اختياري)

التكاملات ← AI: Anthropic أو أي مزود OpenAI-compatible، موديل، مفتاح، حد شهري. بيستخدم في ملخص التقارير وتوليد الإعلانات. لو مش متفعّل أو فشل، كل حاجة بتشتغل بالبديل المحسوب. الملخص اللي فيه رقم مش موجود في بيانات التقرير بيترفض أوتوماتيك.

## قواعد الأرقام

- البيانات المش متاحة = **N/A**، مش صفر. القسمة على صفر = N/A.
- Reach و Frequency مش بيتجمعوا على أيام/حملات (بيظهروا بس لما الفترة صف واحد).
- العملات مش بتتجمع أبداً — كل عملة لوحدها.
- CPL = الصرف من الصفوف اللي فيها ليدز متسجلة ÷ الليدز (صرف الترافيك أو المبيعات مش بيدخل). نفس الفكرة لـ CPA و ROAS.
- الليدز والمبيعات منفصلين دايماً، والـ Results بتفضل بنوعها.
- أتعابك ≠ صرف العميل الإعلاني: الصرف عمره ما بيدخل في الإيرادات.

## الأدوار

| الدور | الصلاحيات |
|---|---|
| المالك / المدير | كل حاجة |
| ميديا باير | التحليلات، الميزانيات، التنبيهات، التقارير، الكريتيف، المراجعة، الكوبي، الأتمتة — لعملاءه |
| مبيعات | الـ CRM بس، والليدز المسندة ليه |
| مشاهدة | الأداء والتقارير قراءة فقط |

## القيود المعروفة

- ربط Meta اتختبر على بيئة محاكاة لـ Graph API (اختبارات `tests/test_integrations.php`) — الاتصال الحقيقي بيتأكد أول ما تحط التوكن، وصفحة التكاملات بتعرض نتيجة الاختبار الفعلي.
- الإيميل بيعتمد على `mail()` بتاع السيرفر.
- تعديل قواعد الرد التلقائي بيفضل من لوحة الأداة نفسها (المنصة بتقرا بس، عشان مفيش تعارض في الكتابة على قاعدة بياناتها).
- Google Ads و TikTok: الحسابات بتتضاف يدوي وبياناتها بالاستيراد (CSV/Excel)، من غير API.
- SQLite مناسب لعدد عملاء ومستخدمين صغير/متوسط. لو كبر جداً، الكود كله PDO وممكن ينقل لـ MySQL.
