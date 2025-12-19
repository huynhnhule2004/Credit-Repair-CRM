# Credit Repair CRM - Ứng Dụng SaaS Sửa Chữa Tín Dụng

Ứng dụng quản lý khách hàng và quy trình sửa chữa tín dụng được xây dựng trên Laravel 11 và FilamentPHP v3.

## 🚀 Công Nghệ Sử Dụng

- **Backend:** Laravel 11 (PHP 8.2+)
- **Admin Panel:** FilamentPHP v3
- **Database:** MySQL
- **PDF Generation:** Spatie Laravel PDF
- **HTML Parser:** Symfony DomCrawler

## 📋 Yêu Cầu Hệ Thống

- PHP >= 8.2
- Composer
- MySQL >= 8.0
- Node.js >= 18.x
- NPM hoặc Yarn

## 🛠️ Cài Đặt

### 1. Clone Repository và Cài Đặt Dependencies

```bash
# Install PHP dependencies
composer install

# Install Node dependencies
npm install
```

### 2. Cấu Hình Environment

```bash
# Copy file .env
cp .env.example .env

# Generate application key
php artisan key:generate
```

Cấu hình database trong file `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ovcredit
DB_USERNAME=root
DB_PASSWORD=
```

### 3. Cài Đặt Các Package Bổ Sung

```bash
# Install Symfony DomCrawler
composer require symfony/dom-crawler

# Install Spatie Laravel PDF
composer require spatie/laravel-pdf

# Install Filament Shield (Optional - for permissions)
composer require bezhansalleh/filament-shield
```

### 4. Chạy Migrations và Seeders

```bash
# Run migrations
php artisan migrate

# Seed letter templates (optional)
php artisan db:seed --class=LetterTemplateSeeder
```

### 5. Tạo Admin User

```bash
# Create Filament admin user
php artisan make:filament-user
```

Nhập thông tin:
- Name: Admin
- Email: admin@example.com
- Password: password (hoặc bất kỳ mật khẩu nào bạn muốn)

### 6. Build Assets và Khởi Động Server

```bash
# Build frontend assets
npm run build

# Start development server
php artisan serve
```

Truy cập ứng dụng tại: `http://localhost:8000/admin`

## 📁 Cấu Trúc Dự Án

```
ovcredit/
├── app/
│   ├── Filament/
│   │   └── Resources/
│   │       ├── ClientResource.php
│   │       ├── LetterTemplateResource.php
│   │       └── ClientResource/
│   │           ├── Pages/
│   │           │   ├── ListClients.php
│   │           │   ├── CreateClient.php
│   │           │   └── EditClient.php
│   │           └── RelationManagers/
│   │               └── CreditItemsRelationManager.php
│   ├── Models/
│   │   ├── Client.php
│   │   ├── CreditItem.php
│   │   └── LetterTemplate.php
│   └── Services/
│       ├── CreditReportParserService.php
│       └── LetterGeneratorService.php
├── database/
│   ├── migrations/
│   │   ├── 2024_01_01_000001_create_clients_table.php
│   │   ├── 2024_01_01_000002_create_credit_items_table.php
│   │   └── 2024_01_01_000003_create_letter_templates_table.php
│   └── seeders/
│       └── LetterTemplateSeeder.php
└── resources/
    └── views/
        └── pdf/
            └── dispute-letter.blade.php
```

## 🎯 Tính Năng Chính

### 1. Quản Lý Khách Hàng (Clients)
- Thêm, sửa, xóa thông tin khách hàng
- Lưu trữ thông tin cá nhân: tên, địa chỉ, phone, SSN (4 số cuối), ngày sinh
- Lưu thông tin đăng nhập IdentityIQ portal

### 2. Import Báo Cáo Tín Dụng
- **Chức năng Import:** Có nút "Import Credit Report" trong trang danh sách Clients và trang Edit Client
- **Cách sử dụng:**
  1. Truy cập trang IdentityIQ của khách hàng
  2. Chuột phải → "View Page Source" (hoặc Ctrl+U)
  3. Copy toàn bộ HTML source code
  4. Paste vào textarea trong modal "Import Credit Report"
  5. Hệ thống tự động phân tích và lưu các khoản nợ xấu vào database

### 3. Quản Lý Credit Items
- Hiển thị danh sách các khoản nợ xấu của từng khách hàng
- Phân loại theo 3 Credit Bureau: TransUnion, Experian, Equifax
- Theo dõi trạng thái dispute: Pending, Sent, Deleted, Verified
- Badge màu sắc cho bureau và dispute status
- Filter theo bureau và dispute status

### 4. Generate Dispute Letters (PDF)
- **Bulk Action:** Chọn nhiều credit items cùng lúc
- **Chọn Template:** Chọn mẫu thư từ danh sách Letter Templates
- **Tùy chọn:** Generate một letter tổng hợp hoặc tách riêng theo từng bureau
- **Export PDF:** Download file PDF ngay lập tức
- **Auto-replacement:** Tự động thay thế placeholders với dữ liệu thật

### 5. Quản Lý Letter Templates
- Tạo và quản lý các mẫu thư khiếu nại
- Rich Text Editor với HTML support
- Hệ thống placeholders linh hoạt
- Phân loại templates theo type (dispute, goodwill, debt-validation)
- Toggle active/inactive
- Duplicate templates

### 6. Các Action Hữu Ích
- **Mark as Sent:** Đánh dấu credit items đã gửi thư
- **Mark as Deleted:** Đánh dấu items đã được xóa khỏi credit report
- **Bulk Actions:** Xử lý nhiều records cùng lúc

## 🔧 Sử Dụng Services

### CreditReportParserService

```php
use App\Services\CreditReportParserService;
use App\Models\Client;

$parserService = app(CreditReportParserService::class);
$client = Client::find(1);
$htmlContent = '...'; // HTML from IdentityIQ

// Parse and save credit items
$importedCount = $parserService->parseAndSave($client, $htmlContent);

// Alternative: Parse from table format
$importedCount = $parserService->parseFromTable($client, $htmlContent);
```

### LetterGeneratorService

```php
use App\Services\LetterGeneratorService;
use App\Models\Client;
use App\Models\LetterTemplate;

$letterService = app(LetterGeneratorService::class);
$client = Client::find(1);
$template = LetterTemplate::find(1);
$selectedItems = $client->creditItems()->pending()->get();

// Generate single PDF
$pdf = $letterService->generate($client, $template, $selectedItems);
return $pdf->download();

// Generate for specific bureau
$pdf = $letterService->generateForBureau($client, $template, 'transunion');

// Generate separate PDFs for each bureau
$pdfs = $letterService->generateByBureau($client, $template, $selectedItems);
```

## 🔐 Placeholders Có Sẵn

Các placeholders sau được hỗ trợ trong Letter Templates:

- `{{client_name}}` - Tên đầy đủ của khách hàng
- `{{client_first_name}}` - Tên
- `{{client_last_name}}` - Họ
- `{{client_address}}` - Địa chỉ
- `{{client_city}}` - Thành phố
- `{{client_state}}` - Tiểu bang
- `{{client_zip}}` - Mã ZIP
- `{{client_phone}}` - Số điện thoại
- `{{client_email}}` - Email
- `{{client_ssn}}` - 4 số cuối SSN
- `{{client_dob}}` - Ngày sinh
- `{{dispute_items}}` - Danh sách items tranh chấp (HTML list)
- `{{current_date}}` - Ngày hiện tại
- `{{bureau_name}}` - Tên credit bureau

## 📊 Database Schema

### Table: clients
- Lưu thông tin khách hàng
- Foreign key cho credit_items

### Table: credit_items
- Lưu các khoản nợ xấu
- Relationships: belongsTo Client
- Enums: bureau, dispute_status

### Table: letter_templates
- Lưu mẫu thư
- Hỗ trợ HTML content với placeholders

## 🎨 Màu Sắc Badge

### Bureau Badges
- TransUnion: Blue (info)
- Experian: Yellow (warning)
- Equifax: Green (success)

### Dispute Status Badges
- Pending: Yellow (warning)
- Sent: Blue (info)
- Deleted: Green (success)
- Verified: Red (danger)

## 🔄 Workflow Điển Hình

1. **Tạo Client mới** trong ClientResource
2. **Import Credit Report** từ IdentityIQ
3. **Review Credit Items** trong tab Credit Items
4. **Chọn items cần dispute**
5. **Generate Letter** bằng bulk action
6. **Chọn Template** phù hợp
7. **Download PDF** và gửi đến Credit Bureau
8. **Mark as Sent** sau khi gửi
9. **Theo dõi kết quả** và cập nhật status

## 🐛 Troubleshooting

### Parser không tìm thấy items
- Kiểm tra HTML selectors trong `CreditReportParserService`
- IdentityIQ có thể thay đổi cấu trúc HTML, cần update selectors
- Sử dụng method `parseFromTable()` nếu dữ liệu ở dạng bảng

### PDF không generate
- Kiểm tra `spatie/laravel-pdf` đã được cài đặt đúng
- Xem log trong `storage/logs/laravel.log`
- Kiểm tra view template `resources/views/pdf/dispute-letter.blade.php`

### Filament không hiện Resources
- Chạy: `php artisan filament:clear-cache`
- Chạy: `php artisan optimize:clear`

## 📝 Notes

- **Security:** SSN chỉ lưu 4 số cuối
- **Soft Deletes:** Tất cả models đều sử dụng soft deletes
- **SOLID Principles:** Service classes tách biệt logic khỏi Controllers
- **Clean Code:** Code có comments và tuân thủ PSR standards

## 🚧 Phát Triển Tiếp

### Tính năng có thể mở rộng:
- [ ] Tự động gửi thư qua mail/fax API
- [ ] Dashboard với analytics và reports
- [ ] Client portal để khách hàng tự theo dõi
- [ ] Multi-tenancy cho nhiều agency
- [ ] Automated follow-up reminders
- [ ] Document storage và versioning
- [ ] Email templates và campaigns
- [ ] Payment processing integration

## 📞 Support

Nếu gặp vấn đề, vui lòng:
1. Kiểm tra logs trong `storage/logs/`
2. Review documentation của FilamentPHP
3. Kiểm tra database migrations đã chạy đầy đủ

---

**Developed with ❤️ using Laravel & FilamentPHP**
