# Required Composer Packages for Credit Repair CRM

## Installation Commands

Run these commands in your terminal after setting up Laravel 11:

```bash
# 1. Install Symfony DomCrawler (for HTML parsing)
composer require symfony/dom-crawler

# 2. Install Spatie Laravel PDF (for PDF generation)
composer require spatie/laravel-pdf

# 3. FilamentPHP v3 should already be installed with Laravel starter kit
# If not, install FilamentPHP:
composer require filament/filament:"^3.0"

# 4. Run Filament installation
php artisan filament:install --panels

# 5. Optional: Install Filament Shield for permissions
composer require bezhansalleh/filament-shield
```

## Package Details

### 1. symfony/dom-crawler
- **Purpose:** Parse HTML content from IdentityIQ
- **Used in:** `CreditReportParserService`
- **Version:** Latest compatible with PHP 8.2+

### 2. spatie/laravel-pdf
- **Purpose:** Generate PDF dispute letters
- **Used in:** `LetterGeneratorService`
- **Features:** PDF generation from Blade views
- **Documentation:** https://github.com/spatie/laravel-pdf

### 3. filament/filament
- **Purpose:** Admin panel framework
- **Version:** v3.x
- **Features:** Forms, Tables, Actions, Notifications
- **Documentation:** https://filamentphp.com/docs

## Additional Configuration

### For Spatie Laravel PDF

After installation, publish the config file (optional):

```bash
php artisan vendor:publish --provider="Spatie\LaravelPdf\LaravelPdfServiceProvider"
```

You may need to install a PDF engine. The package supports multiple engines:

#### Option 1: Using Browsershot (Recommended)
```bash
composer require spatie/browsershot

# Install Node dependencies
npm install puppeteer
```

#### Option 2: Using DomPDF (Simpler, no Node required)
```bash
# Already included with spatie/laravel-pdf
# Just ensure you're using the domPdf driver in config
```

Update `.env` file if needed:
```env
PDF_DEFAULT_ENGINE=browsershot
# or
PDF_DEFAULT_ENGINE=domPdf
```

### For Filament

Make sure your `config/app.php` includes:

```php
'providers' => [
    // ...
    App\Providers\Filament\AdminPanelProvider::class,
],
```

## Verify Installation

Check all packages are installed:

```bash
composer show | grep -E "symfony/dom-crawler|spatie/laravel-pdf|filament/filament"
```

## Post-Installation Steps

1. Run migrations:
```bash
php artisan migrate
```

2. Create admin user:
```bash
php artisan make:filament-user
```

3. Seed letter templates (optional):
```bash
php artisan db:seed --class=LetterTemplateSeeder
```

4. Clear caches:
```bash
php artisan optimize:clear
php artisan filament:clear-cache
```

## Minimum Requirements

- PHP >= 8.2
- Laravel >= 11.0
- MySQL >= 8.0
- Composer >= 2.0
- Node.js >= 18.x (if using Browsershot)

## Troubleshooting

### If you get "Class not found" errors:
```bash
composer dump-autoload
php artisan optimize:clear
```

### If Filament pages don't show:
```bash
php artisan filament:clear-cache
php artisan optimize:clear
```

### If PDF generation fails:
```bash
# Check if puppeteer is installed (for Browsershot)
npm list puppeteer

# Or switch to domPdf in .env
PDF_DEFAULT_ENGINE=domPdf
```
