# accmeta-bot

ربات ترجمهٔ مقاله‌های تخصصی انگلیسی حسابداری و حسابرسی به فارسی؛ پیاده‌سازی‌شده با همان جریان عملیاتی Worker مستقل `accmeta-hook`.

## رفتار ربات

مدیر یا کاربر مجاز، لینک مقاله را در گروه می‌فرستد. ربات دامنهٔ مجاز را کنترل می‌کند، مقاله را دریافت و ترجمه می‌کند، عنوان تاپیک و پیش‌نویس را برای تأیید نشان می‌دهد و پس از تأیید، تاپیک جدید می‌سازد یا تاپیک همان مقاله را به‌روزرسانی می‌کند. ربات سایت‌ها را به‌صورت خودکار پایش نمی‌کند. منابع و محدودیت‌های parser در [مستند منابع](docs/supported-sources.md) ثبت شده‌اند.

## معماری اجرا

`Telegram → Cloudflare Worker accmeta-hook → GitHub repository_dispatch → GitHub Actions → PHP bot → Telegram`

Worker فقط update را موقتاً در KV مستقل ذخیره می‌کند و یک کلید تصادفی را به `repository_dispatch` می‌فرستد. Workflow update را با secret از مسیر `/pending/{key}` می‌گیرد و به `bot.php` می‌دهد. محتوای update در event عمومی GitHub قرار نمی‌گیرد. منطق پردازش مقاله، ترجمه و انتشار فقط در PHP bot است.

## منابع نسخهٔ اول

- `journalofaccountancy.com` — خبرها و مقاله‌های عمومی Audit & Assurance و حسابداری.
- `ifrs.org` — خبرها و توضیح‌های عمومی IFRS Foundation در مسیر `/news-and-events/news/`.
- `iaasb.org` — خبرهای عمومی استانداردهای بین‌المللی حسابرسی و اطمینان‌بخشی.
- `intheblack.cpaaustralia.com.au` — مقاله‌های عمومی CPA Australia / INTHEBLACK.

لینک‌های خارج از allowlist، صفحه‌های ورود/اشتراک و مقاله‌هایی که متن کافی از HTML عمومی در آن‌ها یافت نشود پردازش نمی‌شوند. افزودن دامنهٔ جدید باید با selector و تست آفلاین همراه باشد.

## مدل‌ها

نام مدل‌ها و providerها همان defaults عمومی JCM هستند: Gemini `gemini-3.5-flash,gemini-3.1-flash-lite` و fallback سازگار با OpenAI در `https://tokenharbor.ai/v1` با مدل `deepseek-v4.1-flash:free`. secretهای API 
؛ مخزن هدف credentialهای مستقل خودش را از GitHub Actions secrets دریافت می‌کند.

## GitHub Actions secrets

برای اجرای end-to-end این secrets در مخزن `mortezahonar/accmeta-bot` لازم است:

- `ACCMETA_BOT_TOKEN`: توکن اختصاصی `@Accmeta_bot`.
- `ACCMETA_CHAT_ID`: شناسهٔ عددی گروه مقصد.
- `GEMINI_API_KEY`: کلید Gemini.
- `FALLBACK_API_KEY`: کلید fallback در TokenHarbor؛ اختیاری است، اما برای حفظ fallback JCM توصیه می‌شود.
- `ACCMETA_HOOK_URL`: URL عمومی Worker مستقل `accmeta-hook`.
- `ACCMETA_BOT_SECRET`: همان secret binding با نام `BOT_SECRET` در Worker؛ برای دریافت یک‌بارهٔ update.
- `ACCMETA_TELEGRAM_SECRET`: همان secret binding با نام `TELEGRAM_SECRET` در Worker؛ برای ثبت امن webhook در Telegram.

مدل‌ها/endpointها در `config.php` پیش‌فرض دارند و قابل override با environment variable هستند. برای توسعهٔ محلی می‌توان `config.example.php` را به `secrets.php` کپی کرد؛ `secrets.php` نباید commit شود.

برای بررسی دستی fallback، workflow با نام `Accmeta fallback provider check` فقط یک درخواست کوتاه «Reply with OK only» می‌فرستد؛ خروجی مدل و کلید log نمی‌شوند و متن مقاله‌ای استفاده نمی‌شود.

## Cloudflare Worker

تنظیمات Worker فقط روی `accmeta-hook` انجام می‌شود و باید دقیقاً از قرارداد `worker/accmeta-hook.js` پیروی کند. bindingها: KV مستقل با نام `UPDATES`، secretهای `TELEGRAM_SECRET` و `BOT_SECRET`، secret `GH_TOKEN` با دسترسی محدود به repository dispatch فقط به `mortezahonar/accmeta-bot` و variable متنی `REPO=mortezahonar/accmeta-bot`. هیچ binding یا secret مربوط به `jcm-hook` share نمی‌شود.

## رفتار persistence و حریم خصوصی

مطابق تصمیم صریح مالک پروژه، رفتار JCM عیناً حفظ می‌شود: GitHub Actions محتویات پوشهٔ `state/` را به مخزن commit می‌کند تا state بین runnerهای موقت پایدار بماند. مخزن هدف عمومی است؛ بنابراین پیش‌نویس‌های در انتظار تأیید و متن ترجمه‌شده‌ای که در `state/pending/` ذخیره شود، در commitهای عمومی قابل مشاهده خواهد بود و حذف بعدی آن commitهای قبلی را از تاریخچه پاک نمی‌کند. این رفتار انتخاب‌شده برای برابری با JCM است.

## آزمون‌ها

تست‌ها آفلاین‌اند و به Telegram یا provider واقعی درخواست نمی‌زنند. در GitHub Actions، parserهای منابع، حفاظت RTL، رفتار providerها، حفاظت لینک/کد و rate limit اجرا می‌شوند.
