# منابع مجاز accmeta-bot

ربات فقط لینک مقاله یا خبر تخصصی حسابداری و حسابرسی را از گروه دریافت می‌کند؛ سایت‌ها را خودکار پایش نمی‌کند. شرط پذیرش دسترسی به بدنهٔ کامل و قابل‌استخراجِ صفحه بدون ثبت‌نام، ورود یا اشتراک است. از فهرست اولیهٔ ۱۸ منبع، پنج منبع باقی مانده‌اند؛ `IAASB` و `INTHEBLACK` نیز از منابع قبلی حفظ شده‌اند. در مجموع allowlist شامل ۷ منبع است.

نمونه‌های زیر بررسی شده‌اند. متن مقاله هنگام پردازش با همان HTTP client و parser ربات دریافت می‌شود؛ اگر پاسخ فقط پیش‌نمایش/چکیده باشد، صفحه به ورود نیاز داشته باشد، بدنهٔ کامل استخراج نشود یا درخواست ربات با CAPTCHA/403 مسدود شود، آن منبع برای ترجمه قابل‌استفاده نیست. فایل PDF استانداردها جزو مسیرهای مجاز نیست.

## نهادهای استانداردگذار و حرفه‌ای

| منبع | صفحهٔ رسمی | نمونهٔ مقاله یا خبر کامل | کاربرد |
|---|---|---|---|
| **IFRS Foundation** | [صفحهٔ بنیاد](https://www.ifrs.org/) | [خبر رسمی IFRS](https://www.ifrs.org/news-and-events/news/2024/04/new-ifrs-accounting-standard-will-aid-investor-analysis-of-companies-financial-performance/) | خبرها و توضیح‌های رسمی؛ متن استانداردها یا PDFها پذیرفته نمی‌شوند. |
| **IIA — The Institute of Internal Auditors** | [صفحهٔ انجمن](https://www.theiia.org/) | [مقالهٔ حرفه‌ای IIA](https://www.theiia.org/en/content/articles/global-best-practices/2026/internal-audit-upskilling-for-critical-ai-capabilities/) | مقاله‌های کامل حرفه‌ای حسابرسی داخلی؛ parser متن کامل را از ظرف `displaymode-full` برمی‌دارد. |
| **IAASB** | [صفحهٔ هیئت](https://www.iaasb.org/) | [راهنمای رسمی IAASB](https://www.iaasb.org/news-events/2023-12/iaasb-issues-guidance-assurance-practitioners-when-citing-ifrs-accounting-standards) | خبرها و راهنماهای عمومی حسابرسی و اطمینان‌بخشی. |

## انجمن‌ها و نشریه‌های حرفه‌ای

| منبع | صفحهٔ رسمی | نمونهٔ مقالهٔ کامل | کاربرد |
|---|---|---|---|
| **AICPA & CIMA — Journal of Accountancy** | [صفحهٔ مجله](https://www.journalofaccountancy.com/) | [مقالهٔ حرفه‌ای](https://www.journalofaccountancy.com/news/2023/nov/how-artificial-intelligence-can-help-save-accounting/) | مقاله‌های حرفه‌ای حسابداری، حسابرسی، گزارشگری مالی و مالیات. |
| **The CPA Journal — NYSSCPA** | [صفحهٔ نشریه](https://www.cpajournal.com/) | [مقالهٔ حسابرسی](https://www.cpajournal.com/2026/09/02/evaluating-the-use-of-ai-in-financial-statement-audits/) | مقاله‌های حرفه‌ای حسابداری، حسابرسی و مالیات. |
| **Accounting Today** | [صفحهٔ رسانه](https://www.accountingtoday.com/) | [گزارش حرفه‌ای](https://www.accountingtoday.com/list/accounting-in-2026-the-year-ahead-in-numbers) | خبرها و تحلیل‌های حسابداری حرفه‌ای؛ نمونهٔ بالا با دسترسی رایگان کامل باز شد. |
| **INTHEBLACK — CPA Australia** | [صفحهٔ مجله](https://intheblack.cpaaustralia.com.au/) | [مقالهٔ حرفه‌ای](https://intheblack.cpaaustralia.com.au/careers-and-workplace/capability-accountants-need-to-build-career-resilience) | مقاله‌های کامل عمومی دربارهٔ حسابداری، حسابرسی و حرفه. |

## منابع حذف‌شده

`JAE`, `CAR`, `RAST`, `AOS` و `JAPP` فقط پیش‌نمایش/چکیده نشان می‌دادند یا متن کامل را به ورود سازمانی، اشتراک یا خرید محدود می‌کردند. `JAR` به‌دلیل سد امنیتی Cloudflare قابل‌بررسی نبود و متن کامل عمومی آن تأیید نشد. `TAR` نیز پیش‌تر به‌دلیل CAPTCHA حذف شده بود.

`AJPT`, `MAJ` و `IJA` برای بعضی نمونه‌ها برچسب دسترسی آزاد داشتند، اما درخواست مستقیم HTTP client خود ربات با 403/CAPTCHA مسدود شد. `FASB` و `PCAOB` نیز در آزمون client ربات 403 دادند. صفحهٔ نمونهٔ `IMA / Strategic Finance` پاسخ 200 داد، اما بدنهٔ مقاله از HTML قابل‌استخراج با parser ربات نبود. چون Accmeta امکان حل CAPTCHA، ورود به حساب یا دورزدن paywall را ندارد، این ۶ منبع نیز حذف شدند.

## محدودیت فنی

Allowlist به دامنه و مسیر مقالهٔ هر منبع محدود است؛ صفحهٔ خانه، فهرست، فایل PDF، دامنهٔ مشابه و مسیر نامرتبط پذیرفته نمی‌شوند. فقط متن کامل استخراج‌شده از صفحهٔ عمومی ترجمه می‌شود. ربات paywall، ثبت‌نام اجباری یا سد CAPTCHA را دور نمی‌زند؛ در صورت نبود متن کامل، پردازش باید متوقف شود.
