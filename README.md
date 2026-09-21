# Customer Loyalty Platform · منصة ولاء الزبائن

A loyalty system for retail shops — Angular front end, Laravel API, MySQL.

**[English](#english) · [العربية](#العربية)**

---

<a name="english"></a>

# English

This file covers two things only: **how to install it** and **which account to sign in with**.

## 1. Requirements

| Needed | Minimum | Note |
|---|---|---|
| PHP | 8.2 | ships with XAMPP |
| Composer | 2.x | [getcomposer.org](https://getcomposer.org) |
| MySQL / MariaDB | 8.x or equivalent | ships with XAMPP |
| Node.js | 20 | [nodejs.org](https://nodejs.org) |
| npm | 10 | comes with Node |

---

## 2. Installing the API

All commands run from the `backend` folder.

**1. Install the packages**

```bash
cd backend
composer install
```

**2. Prepare the environment file**

```bash
cp .env.example .env
php artisan key:generate
```

**3. Create an empty database**

From phpMyAdmin, create a database named:

```
Customer_Loyalty_Platform
```

Then make sure these values in `.env` match your setup:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=Customer_Loyalty_Platform
DB_USERNAME=root
DB_PASSWORD=
```

**4. Create the tables and the seed data**

> **Before running this**: if you want new registration requests to reach your own
> inbox, add a line to `.env` with your real address. The default is a fake one —
> details in [section 5](#5-email).
>
> ```env
> SEED_PLATFORM_ADMIN_EMAIL=your-real-address@gmail.com
> ```

```bash
php artisan migrate --seed
```

Expected last line of output:

```
Seeded. Every account uses the password: password
```

**5. Link the uploads folder**

Without this step, uploaded logos and pictures will not appear:

```bash
php artisan storage:link
```

**6. Start the server and leave it running**

```bash
php artisan serve
```

The API is now on `http://localhost:8000`.

---

## 3. Installing the front end

In a second terminal:

```bash
cd frontend
npm install
npm start
```

Then open:

```
http://localhost:4200
```

> If you started the API on a port other than 8000, change `apiUrl` in
> `frontend/src/environments/environment.ts`.

---

## 4. Sign-in accounts

> **The password for every account is `password`.**

| Email | Password | Role |
|---|---|---|
| `admin@platform.test` | `password` | Platform supervisor |
| `owner@alnoor.test` | `password` | Shop owner — Al Noor Stores |
| `manager@alnoor.test` | `password` | Branch manager — Damascus branch |
| `rep@alnoor.test` | `password` | Sales rep — Damascus branch |
| `owner@zahra.test` | `password` | Shop owner — Zahra Boutique |
| `manager@zahra.test` | `password` | Branch manager — main branch |
| `rep@zahra.test` | `password` | Sales rep — main branch |

---

## 5. Email

**To try the system without setting up SMTP** — put this in `.env`:

```env
MAIL_MAILER=log
```

Every message is then written to a text file instead of being sent, and the links and
codes can be read out of it:

```
backend/storage/logs/laravel.log
```

**For real email**:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=your-address@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_ADDRESS="your-address@gmail.com"
```

> Gmail needs an **App Password**, not the account's ordinary password.

### Who sends and who receives

These are two different things, and mixing them up causes confusion:

- **`MAIL_USERNAME` and `MAIL_FROM_ADDRESS`** are the **sending account**. Every message
  the system sends leaves from it and appears to the recipient as coming from it, and
  a copy stays in that account's **Sent** folder — not in its inbox.
- **The recipient** depends on the operation: an invitation goes to the staff member's
  address, an approval or rejection decision to the shop's registered address, and a
  password reset to the account holder's own address.

### The supervisor's address

Notifications about **new registration requests** go to the platform supervisor's
account, which by default is:

```
admin@platform.test
```

That domain **does not exist**, so those notifications reach nobody until it is
changed. To receive them, add this line to `.env` **before** running
`php artisan migrate --seed`:

```env
SEED_PLATFORM_ADMIN_EMAIL=your-real-address@gmail.com
```

> **If you have already run `migrate --seed`**: an account's email is its identifier and
> cannot be changed from the "My account" screen. Add the line above to `.env`, then
> rebuild the data:
>
> ```bash
> php artisan migrate:fresh --seed
> ```

---

## 6. Optional — filling a shop with trade

`migrate --seed` creates the structure: plans, two shops, branches, staff and a
loyalty rule. It creates no customers and no invoices, so the reports and the charts
open empty.

To fill the first shop (Al Noor Stores) with customers, invoices spread over recent
months, and one paid reward:

```bash
php artisan db:seed --class=DemoDataSeeder
```

Safe to run more than once — it clears that shop's own trade first, and touches
nothing belonging to the second shop. The second shop is left empty on purpose: an
empty tenant beside a full one is what makes the data isolation visible.

---
---

<a name="العربية"></a>

# العربية

هذا الملف فيه شيئان فقط: **كيف تُثبَّت** و**بأي حساب تدخل**.

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

---

## ٦. اختياري — ملء محل بالحركة التجارية

الأمر `migrate --seed` ينشئ الهيكل: الخطط، ومحلّين، والفروع، والموظفين، وقاعدة ولاء.
ولا ينشئ أي زبون ولا أي فاتورة، فتفتح التقارير والرسوم البيانية فارغة.

لملء المحل الأول (Al Noor Stores) بزبائن وفواتير موزّعة على الأشهر الماضية ومكافأة
مصروفة:

```bash
php artisan db:seed --class=DemoDataSeeder
```

يمكن تنفيذه أكثر من مرة — يمسح حركة ذلك المحل أولاً، ولا يلمس شيئاً يتعلق بالمحل
الثاني. والمحل الثاني يُترك فارغاً عن قصد: مستأجر فارغ بجانب مستأجر ممتلئ هو ما يجعل
عزل البيانات مرئياً.
