# Localization Guide (Persian-First)

This document explains how to add, modify, and use Persian strings in
the GitiArts codebase. Read this before adding any new user-facing text.

## The One Rule

> **No Persian strings in PHP files. No English strings in UI.**
>
> All user-facing text lives in `resources/lang/fa.json`. PHP code reads
> from there via `Lang::get('dotted.key')`.

## File Layout

```
resources/lang/
├── fa.json    ← Persian (default, primary)
└── en.json    ← English (fallback for technical audiences)
```

`fa.json` is the **single source of truth**. `en.json` is maintained as
a sibling for bilingual support and developer-facing reference. If a
string is missing in `en.json` but present in `fa.json`, the active
locale (Persian) wins.

## Namespace Conventions

Keys are organized into namespaces using dotted notation:

| Namespace       | Purpose                                                    |
| --------------- | --------------------------------------------------------- |
| `menu.*`        | Menu management UI                                        |
| `order.*`       | Order taking / receipts                                   |
| `employee.*`    | Employee management                                       |
| `error.*`       | Generic error messages shown to users                     |
| `success.*`     | Success toasts / confirmations                            |
| `validation.*`  | Form validation error messages                            |
| `email.*`       | Email subject + body templates                            |
| `sms.*`         | SMS templates (OTP, notifications)                         |
| `pdf.*`         | PDF receipt / invoice strings                             |
| `auth.*`        | Login / logout / password reset                           |
| `dashboard.*`   | Admin dashboard widgets                                    |
| `export.*`      | Export / import flow error messages                       |
| `update.*`      | Update channel (standalone mode) error messages           |
| `common.*`      | Generic UI verbs (save, cancel, delete, etc.)             |

## Adding a New String

1. **Add to `fa.json`:**

   ```json
   "report.revenue.title": "گزارش درآمد"
   ```

2. **Use in PHP code:**

   ```php
   use GitiArts\Phase2\Localization\Lang;
   $title = Lang::get('report.revenue.title');
   ```

3. **Optionally add to `en.json`** for bilingual builds:

   ```json
   "report.revenue.title": "Revenue Report"
   ```

4. **Add a test** in `tests/LangTest.php` to lock the Persian text:

   ```php
   public function test_revenue_report_title_is_persian(): void
   {
       self::assertSame('گزارش درآمد', Lang::get('report.revenue.title'));
   }
   ```

## Interpolation

Use `{placeholder}` syntax in JSON values:

```json
"validation.min_length": "حداقل {min} کاراکتر لازم است."
```

```php
Lang::get('validation.min_length', ['min' => PersianDigits::toPersian(3)]);
// → "حداقل ۳ کاراکتر لازم است."
```

## Digits

ALWAYS convert numbers to Persian digits before display. Use:

- `PersianDigits::toPersian($int)` — raw conversion
- `PersianDigits::formatNumber($int)` — with thousands separator
- `Currency::format($int)` — Toman amounts with suffix

NEVER call `number_format()` directly on a value that will reach the UI.

## Dates

Store ALL dates in the database as Gregorian ISO 8601 (`YYYY-MM-DD HH:MM:SS`).
Convert to Jalali ONLY at the presentation layer:

```php
echo JalaliDate::format($row['created_at'], 'Y/m/d H:i');
// → "۱۴۰۵/۰۷/۱۱ ۱۴:۳۰"
```

If you need to accept a Jalali date from user input, normalize it back to
Gregorian BEFORE storing:

```php
$gregorian = JalaliDate::toGregorian($_POST['date']);
$pdo->prepare('INSERT INTO orders (created_at) VALUES (?)')->execute([$gregorian]);
```

## HTML

Every HTML response from a controller MUST start with:

```html
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    ...
</head>
```

Never rely on browser auto-detection. The `dir="rtl"` attribute must be
explicit on the `<html>` element.

## Accepting User Input

When a user submits a form with Persian digits, ALWAYS normalize before
database operations:

```php
$mobile = PersianDigits::normalize($_POST['mobile']);
// "۰۹۱۲۳۴۵۶۷۸۹" → "09123456789"
```

Validators handle this automatically:

```php
Validator::isIranianMobile('۰۹۱۲۳۴۵۶۷۸۹'); // true
```

## SMS / Email Templates

SMS and email templates live in `fa.json` under the `sms.*` and `email.*`
namespaces. NEVER compose SMS body by concatenating strings in PHP:

```php
// ❌ FORBIDDEN
$sms = "کد تأیید: $code";

// ✅ CORRECT
$sms = Lang::get('sms.otp_body', [
    'code'    => PersianDigits::toPersian($code),
    'expires' => JalaliDate::format(date('Y-m-d H:i:s'), 'H:i'),
]);
```

## PDF Generation

PDF receipts use the same `Lang::get()` mechanism. When using libraries
like TCPDF or mPDF, ensure the font you select supports Persian glyphs
(Noto Sans Arabic, DejaVu Sans, etc.) and set RTL mode on the document.

## Testing Persian Strings

Every new string added to `fa.json` MUST have a corresponding test in
`tests/LangTest.php`. The test asserts the exact Persian output so
regressions are caught at CI time.

For multibyte safety, use `mb_strlen()`, `mb_substr()`, and
`mb_strtolower()` — never their single-byte equivalents.
