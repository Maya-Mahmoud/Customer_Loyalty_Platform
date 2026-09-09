# منصة ولاء الزبائن

نظام ولاء للمحلات التجارية — واجهة Angular وخادم Laravel وقاعدة MySQL.

هذا الملف فيه شيئان فقط: **كيف تُثبَّت** و**بأي حساب تدخل**.

---

## ١. المتطلبات

| المطلوب | الإصدار الأدنى | ملاحظة |
|---|---|---|
| PHP | 8.2 | موجود مع XAMPP |
| Composer | 2.x | [getcomposer.org](https://getcomposer.org) |
| MySQL / MariaDB | 8.x أو ما يوازيها | موجود مع XAMPP |
| Node.js | 20 | [nodejs.org](https://nodejs.org) |
| npm | 10 | يأتي مع Node |



---

## ٢. تثبيت الخادم

كل الأوامر من مجلد `backend`.

**١. نصّب الحزم**

```bash
cd backend
composer install
```

**٢. جهّز ملف الإعدادات**

```bash
cp .env.example .env
php artisan key:generate
```


**٣. أنشئ قاعدة بيانات فاضية**

من phpMyAdmin أنشئ قاعدة اسمها:

```
Customer_Loyalty_Platform
```

وتأكد أن هذه القيم في `.env` تطابق إعداداتك:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=Customer_Loyalty_Platform
DB_USERNAME=root
DB_PASSWORD=
```

**٤. أنشئ الجداول واملأها بالبيانات التجريبية**

> **قبل تنفيذ هذا الأمر**: إن أردت أن تصلك إشعارات طلبات التسجيل الجديدة على بريدك، أضف
> سطراً إلى `.env` بعنوانك الحقيقي. الافتراضي عنوان وهمي، والتفاصيل في
> [القسم ٥](#٥-البريد-الإلكتروني).
>
> ```env
> SEED_PLATFORM_ADMIN_EMAIL=your-real-address@gmail.com
> ```

```bash
php artisan migrate --seed
```

المخرج المتوقّع في الآخر:

```
Seeded. Every account uses the password: password
```

**٥. اربط مجلد الملفات المرفوعة**

بلا هذه الخطوة الشعارات والصور المرفوعة لن تظهر:

```bash
php artisan storage:link
```

**٦. شغّل الخادم واتركه شغالاً**

```bash
php artisan serve
```

صار الخادم على `http://localhost:8000`.

---

## ٣. تثبيت الواجهة

بنافذة طرفية ثانية:

```bash
cd frontend
npm install
npm start
```

وافتح المتصفح على:

```
http://localhost:4200
```

> إذا شغّلت الخادم على منفذ غير 8000، عدّل `apiUrl` في `frontend/src/environments/environment.ts`.

---

## ٤. حسابات الدخول

> **كلمة السر لكل الحسابات: `password`**

| البريد الإلكتروني | كلمة السر | الدور |
|---|---|---|
| `admin@platform.test` | `password` | مشرف المنصة |
| `owner@alnoor.test` | `password` | صاحب محل — Al Noor Stores |
| `manager@alnoor.test` | `password` | مدير فرع — فرع دمشق |
| `rep@alnoor.test` | `password` | مندوب بيع — فرع دمشق |
| `owner@zahra.test` | `password` | صاحب محل — Zahra Boutique |
| `manager@zahra.test` | `password` | مدير فرع — الفرع الرئيسي |
| `rep@zahra.test` | `password` | مندوب بيع — الفرع الرئيسي |

---

## ٥. البريد الإلكتروني

**للتجربة بلا إعداد SMTP** — ضع في `.env`:

```env
MAIL_MAILER=log
```

فيُكتب كل بريد في ملف نصي بدل أن يُبعت، والروابط والرموز تجدها فيه:

```
backend/storage/logs/laravel.log
```

**لبريد حقيقي**:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=your-address@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_ADDRESS="your-address@gmail.com"
```

> مع Gmail يلزم **App Password** لا كلمة سر الحساب العادية.

### من يُرسل ومن يستلم

هذان أمران مختلفان، وخلطهما يربك:

- **`MAIL_USERNAME` و `MAIL_FROM_ADDRESS`** هما **حساب الإرسال**. كل بريد يبعثه النظام يخرج من هذا الحساب ويظهر للمستلم كأنه منه، وتبقى نسخة منه في مجلد **Sent** لصاحبه لا في صندوق الوارد.
- **المستلم** يتحدد بحسب العملية نفسها: الدعوة تذهب إلى بريد الموظف، وقرار الموافقة أو الرفض إلى بريد المحل المسجّل، واستعادة كلمة السر إلى بريد صاحب الحساب.

### بريد مشرف المنصة

إشعارات **طلبات التسجيل الجديدة** تذهب إلى بريد حساب مشرف المنصة، وهو افتراضياً:

```
admin@platform.test
```

وهذا **نطاق وهمي غير موجود**، فلن تصل تلك الإشعارات إلى أحد ما لم تغيّره. لتصلك، أضف
هذا السطر إلى `.env` **قبل** تنفيذ `php artisan migrate --seed`:

```env
SEED_PLATFORM_ADMIN_EMAIL=your-real-address@gmail.com
```

> **إن كنت قد نفّذت `migrate --seed` مسبقاً**: بريد الحساب هو معرّفه، ولا يمكن تعديله من
> شاشة «حسابي». فأضف السطر أعلاه إلى `.env` ثم أعد بناء البيانات:
>
> ```bash
> php artisan migrate:fresh --seed
> ```
>




