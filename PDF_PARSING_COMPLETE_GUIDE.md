# 📄 Hướng Dẫn Đầy Đủ: Đọc và Parse File PDF Credit Report

## 📋 Mục Lục

1. [Tổng Quan Hệ Thống](#tổng-quan-hệ-thống)
2. [Kiến Trúc và Components](#kiến-trúc-và-components)
3. [7 Chiến Lược Parsing](#7-chiến-lược-parsing)
4. [IdentityIQ Full Parser](#identityiq-full-parser)
5. [OCR Support cho Scanned PDFs](#ocr-support-cho-scanned-pdfs)
6. [Data Normalization](#data-normalization)
7. [Deduplication Logic](#deduplication-logic)
8. [Cấu Trúc Dữ Liệu Output](#cấu-trúc-dữ-liệu-output)
9. [Discrepancy Detection](#discrepancy-detection)
10. [Usage & Examples](#usage--examples)
11. [Troubleshooting](#troubleshooting)
12. [Best Practices](#best-practices)

---

## 🎯 Tổng Quan Hệ Thống

### Mục Đích
Hệ thống được thiết kế để **tự động parse và extract thông tin từ file PDF Credit Report** với nhiều định dạng khác nhau, đặc biệt tối ưu cho **IdentityIQ 3-Bureau Credit Reports**.

### Tính Năng Chính
- ✅ **7 Parsing Strategies** - Tự động thử nhiều cách parse khác nhau
- ✅ **OCR Support** - Xử lý scanned PDFs
- ✅ **Data Normalization** - Chuẩn hóa dữ liệu từ nhiều nguồn
- ✅ **Deduplication** - Tránh trùng lặp thông minh
- ✅ **Discrepancy Detection** - Tự động phát hiện lỗi giữa các bureaus
- ✅ **Full IdentityIQ Support** - Parse đầy đủ Credit Scores, Personal Profiles, Accounts

### Luồng Xử Lý Tổng Quan

```
PDF File Upload
    ↓
Extract Text (Smalot PDFParser)
    ↓
Check if Scanned? → OCR (Tesseract)
    ↓
Try 7 Parsing Strategies (Sequential)
    ├─ Strategy 1: Pipe-Separated
    ├─ Strategy 2: Tab-Separated
    ├─ Strategy 3: Comma-Separated
    ├─ Strategy 4: Regex Pattern Matching
    ├─ Strategy 5: Fixed-Width Columns
    ├─ Strategy 6: Keyword-Based Sections
    └─ Strategy 7: IdentityIQ Full Parser ⭐
    ↓
Normalize All Items
    ↓
Deduplicate (Unique Keys)
    ↓
Detect Discrepancies
    ↓
Save to Database
    ↓
Return Result with Discrepancies
```

---

## 🏗️ Kiến Trúc và Components

### Core Services

#### 1. `CreditReportParserService`
**Location:** `app/Services/CreditReportParserService.php`

**Chức năng:**
- Main service để parse PDF
- Quản lý 7 parsing strategies
- Tích hợp OCR và Normalization
- Xử lý deduplication

**Methods:**
- `parsePdfAndSave(Client $client, string $pdfPath): int` - Main method
- `parseAndSave(Client $client, string $htmlContent): int` - Parse HTML
- `parsePipeSeparated()`, `parseTabSeparated()`, etc. - Individual strategies

#### 2. `IdentityIqFullParser`
**Location:** `app/Services/IdentityIqFullParser.php`

**Chức năng:**
- Parser chuyên biệt cho IdentityIQ format
- Extract Credit Scores, Personal Profiles, Accounts
- Detect discrepancies

**Methods:**
- `parseAndSave(Client $client, string $pdfPath): array` - Complete parse
- `parseCreditScores(string $text): ?array`
- `parsePersonalProfiles(string $text): array`
- `parseAccounts(string $text): array`
- `detectDiscrepancies(array $accountData): array`

#### 3. `DataNormalizer`
**Location:** `app/Services/PdfParsing/DataNormalizer.php`

**Chức năng:**
- Chuẩn hóa dữ liệu từ nhiều nguồn
- Normalize account numbers, balances, status, bureau names

**Methods:**
- `normalizeAccountNumber(string $accountNumber): string`
- `normalizeBalance($balance): float`
- `normalizeStatus(?string $status): ?string`
- `normalizeBureau(string $bureau): ?string`
- `normalizeItem(array $item): array`

#### 4. `TesseractOcrService`
**Location:** `app/Services/PdfParsing/TesseractOcrService.php`

**Chức năng:**
- OCR cho scanned PDFs
- Auto-detect scanned PDFs
- Convert PDF → Images → OCR → Text

**Methods:**
- `extractText(string $pdfPath): string`
- `needsOcr(string $pdfPath, string $extractedText): bool`

### Models

#### `CreditItem`
**Fields:**
- `client_id`, `bureau`, `account_name`, `account_number`
- `account_type`, `date_opened`, `date_last_active`, `date_reported`
- `balance`, `high_limit`, `monthly_pay`, `past_due`
- `status`, `reason`, `dispute_status`

#### `CreditScore`
**Fields:**
- `client_id`, `transunion_score`, `experian_score`, `equifax_score`
- `report_date`, `reference_number`

#### `PersonalProfile`
**Fields:**
- `client_id`, `bureau`
- `name`, `date_of_birth`, `current_address`, `previous_address`, `employer`
- `date_reported`

---

## 🔍 7 Chiến Lược Parsing

### Strategy 1: Pipe-Separated Format

**Format:** `Bureau | Account Name | Account Number | Balance | Status | Reason`

**Ví dụ:**
```
TransUnion | ABC BANK | 1234567890 | $1,250.00 | Charge Off | Inaccurate late payment
Experian | XYZ CREDIT | 9876543210 | $500.00 | Collection | 
Equifax | DEF LOAN | 5555555555 | $2,000.00 | Late Payment | 30 days late
```

**Implementation:**
```php
private function parsePipeSeparated(Client $client, string $text): array
{
    $lines = preg_split('/\R+/', $text);
    $items = [];

    foreach ($lines as $line) {
        if (strpos($line, '|') === false) continue;
        
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 5) continue;
        
        $item = $this->extractItemFromParts($parts);
        if ($item) $items[] = $item;
    }

    return $items;
}
```

**Use Case:** Format chuẩn, dễ parse nhất

---

### Strategy 2: Tab-Separated Format

**Format:** `Bureau \t Account Name \t Account Number \t Balance \t Status \t Reason`

**Ví dụ:**
```
TransUnion	ABC BANK	1234567890	$1,250.00	Charge Off	Inaccurate late payment
```

**Implementation:**
```php
private function parseTabSeparated(Client $client, string $text): array
{
    $lines = preg_split('/\R+/', $text);
    $items = [];

    foreach ($lines as $line) {
        if (strpos($line, "\t") === false) continue;
        
        $parts = array_map('trim', explode("\t", $line));
        if (count($parts) < 5) continue;
        
        $item = $this->extractItemFromParts($parts);
        if ($item) $items[] = $item;
    }

    return $items;
}
```

**Use Case:** Copy từ Excel/Spreadsheet

---

### Strategy 3: Comma-Separated Format (CSV-like)

**Format:** `Bureau, Account Name, Account Number, Balance, Status, Reason`

**Ví dụ:**
```
TransUnion, ABC BANK, 1234567890, $1,250.00, Charge Off, Inaccurate late payment
```

**Implementation:**
```php
private function parseCommaSeparated(Client $client, string $text): array
{
    $lines = preg_split('/\R+/', $text);
    $items = [];

    foreach ($lines as $line) {
        if (strpos($line, ',') === false) continue;
        
        $parts = str_getcsv($line); // Handles quoted values
        if (count($parts) < 5) continue;
        
        $parts = array_map('trim', $parts);
        $item = $this->extractItemFromParts($parts);
        if ($item) $items[] = $item;
    }

    return $items;
}
```

**Use Case:** CSV exports

---

### Strategy 4: Regex Pattern Matching ⭐ IMPROVED

**Format:** Không cố định, tìm patterns trong text

**Patterns được nhận diện:**

**Pattern 1:** Bureau name + Account info (với masked accounts)
```
TransUnion ABC BANK Account: 44445555**** Balance: $1,250.00
Experian XYZ CREDIT Acct #: 1234**** Bal: $500.00
```

**Pattern 2:** Account number (masked) + Name + Balance + Status
```
44445555**** ABC BANK $1,250.00 Charge Off
1234**** XYZ CREDIT $500.00 Collection
```

**Pattern 3:** Dedicated masked account pattern
```
Account: XXXX1234 ABC BANK $1,250.00
Acct #: 1234**** XYZ CREDIT $500.00
```

**Regex Patterns:**
```php
// Pattern 1: Bureau + Account Name + Account Number (masked) + Balance
/(?:TransUnion|Experian|Equifax)\s+([A-Z][A-Z\s&]+?)\s+(?:Account|Acct|#)[:\s]*([X\*\d]{4,})\s+(?:Balance|Bal|Amount)[:\s]*\$?([\d,]+\.?\d*)/i

// Pattern 2: Account Number (masked) + Name + Balance + Status
/([X\*\d]{4,})\s+([A-Z][A-Z\s&]+?)\s+\$?([\d,]+\.?\d*)\s+([A-Z][A-Z\s]+)/i

// Pattern 3: Dedicated masked account
/(?:Account|Acct|#)[:\s]*([X\*\-]{0,}\d{4,}[X\*\-]{0,})\s+([A-Z][A-Z\s&]+?)\s+\$?([\d,]+\.?\d*)/i
```

**Improvements:**
- ✅ Hỗ trợ masked accounts: `XXXX1234`, `1234****`, `****-****-****-1234`
- ✅ Tự động detect bureau từ context
- ✅ Extract account numbers với độ dài tối thiểu 4 ký tự (thay vì 8)

**Use Case:** Free-form text, IdentityIQ reports

---

### Strategy 5: Fixed-Width Column Parsing ⭐ IMPROVED

**Format:** Dữ liệu căn chỉnh theo cột với khoảng trắng cố định

**Ví dụ:**
```
TransUnion  ABC BANK           44445555****  $1,250.00  Charge Off
Experian    XYZ CREDIT         1234****      $500.00    Collection
```

**Implementation:**
```php
private function parseFixedWidth(Client $client, string $text): array
{
    $lines = preg_split('/\R+/', $text);
    $dataLines = [];
    
    // Find lines with account numbers and balances
    foreach ($lines as $line) {
        if (preg_match('/[X\*\d]{4,}/', $line) && preg_match('/\$?[\d,]+\.?\d*/', $line)) {
            $dataLines[] = rtrim($line); // Keep trailing spaces
        }
    }
    
    // Detect column positions using vertical alignment
    $columnPositions = $this->detectColumnPositions($dataLines);
    
    if (empty($columnPositions)) {
        // Fallback: Use spacing-based approach
        foreach ($dataLines as $line) {
            $parts = preg_split('/\s{2,}/', trim($line));
            // ... extract data
        }
    } else {
        // Use vertical alignment
        foreach ($dataLines as $line) {
            $item = $this->extractItemFromAlignedColumns($line, $columnPositions);
            // ... extract data
        }
    }
}
```

**Improvements:**
- ✅ **Vertical Alignment Detection** - Phân tích nhiều dòng cùng lúc
- ✅ Tìm column positions bằng cách so sánh 60%+ lines
- ✅ Fallback về spacing-based nếu không detect được

**Use Case:** PDF với fixed-width columns

---

### Strategy 6: Keyword-Based Section Parsing ⭐ IMPROVED

**Format:** Tìm theo tên bureau và extract data từ section đó

**Ví dụ:**
```
TransUnion Section:
ABC BANK
Account: 44445555****
Balance: $1,250.00
Status: Charge Off

Experian Section:
XYZ CREDIT
Account: 1234****
Balance: $500.00
```

**Implementation:**
```php
private function parseByKeywords(Client $client, string $text): array
{
    $items = [];
    $bureaus = [
        'transunion' => ['TransUnion', 'Trans Union', 'TU'],
        'experian' => ['Experian', 'EXP'],
        'equifax' => ['Equifax', 'EFX'],
    ];
    
    // Find all bureau positions
    $bureauPositions = [];
    foreach ($bureaus as $bureauKey => $bureauNames) {
        foreach ($bureauNames as $bureauName) {
            preg_match_all('/\b' . preg_quote($bureauName, '/') . '\b/i', 
                $text, $matches, PREG_OFFSET_CAPTURE);
            // ... collect positions
        }
    }
    
    // Sort by position
    usort($bureauPositions, fn($a, $b) => $a['position'] <=> $b['position']);
    
    // Extract sections with dynamic boundaries
    foreach ($bureauPositions as $idx => $bureauPos) {
        $startPos = $bureauPos['position'];
        
        // Find end position: next bureau or end marker
        $endPos = strlen($text);
        if (isset($bureauPositions[$idx + 1])) {
            $endPos = $bureauPositions[$idx + 1]['position'];
        } else {
            // Look for "End of Report" markers
            $endMarkers = ['/End of Report/i', '/End of Credit Report/i'];
            // ... find end position
        }
        
        // Extract section with dynamic boundary
        $section = substr($text, $startPos, $endPos - $startPos);
        // ... extract accounts from section
    }
}
```

**Improvements:**
- ✅ **Dynamic Boundary** - Thay vì fixed 2000 chars
- ✅ Section kết thúc tại: bureau tiếp theo hoặc "End of Report"
- ✅ Support multiple bureau name variations

**Use Case:** Section-based reports

---

### Strategy 7: IdentityIQ Full Parser ⭐ NEW

**Format:** IdentityIQ structured format với nested bureau data

**Cấu trúc:**
```
CREDIT SCORE DASHBOARD:
TransUnion: 645
Experian: 650
Equifax: 620

PERSONAL PROFILE:
TransUnion: ALEX MINH TRAN
Experian: ALEX M TRAN
Equifax: ALEX TRAN

CREDIT ACCOUNTS:
1. CHASE BANK USA
   Account #: 44445555****
   Account Type: Credit Card
   Date Opened: 01/10/2020
   Bureau: All Bureaus
   Details by Bureau:
      TransUnion:
         Balance: $1,250.00
         High Limit: $5,000
         Pay Status: Current
         Monthly Pay: $50
         Comments: Paid as agreed.
```

**Implementation:**
- Sử dụng `IdentityIqFullParser` service
- Parse 3 phần: Scores, Profiles, Accounts
- Extract bureau-specific data
- Detect discrepancies

**Use Case:** IdentityIQ 3-Bureau Credit Reports

---

## 🎯 IdentityIQ Full Parser

### Overview

`IdentityIqFullParser` là parser chuyên biệt cho IdentityIQ format, extract đầy đủ:
- ✅ Credit Scores từ 3 bureaus
- ✅ Personal Profiles với variations
- ✅ Accounts với bureau-specific data
- ✅ Discrepancy detection

### Parse Credit Scores

**Input:**
```
CREDIT SCORE DASHBOARD:
TRANSUNION: 645
EXPERIAN: 650
EQUIFAX: 620
```

**Output:**
```json
{
  "transunion": 645,
  "experian": 650,
  "equifax": 620
}
```

**Code:**
```php
private function parseCreditScores(string $text): ?array
{
    $scores = [];
    
    if (preg_match('/TransUnion[:\s]*(\d+)/i', $text, $tuMatch)) {
        $scores['transunion'] = (int) $tuMatch[1];
    }
    if (preg_match('/Experian[:\s]*(\d+)/i', $text, $expMatch)) {
        $scores['experian'] = (int) $expMatch[1];
    }
    if (preg_match('/Equifax[:\s]*(\d+)/i', $text, $eqMatch)) {
        $scores['equifax'] = (int) $eqMatch[1];
    }
    
    return !empty($scores) ? $scores : null;
}
```

### Parse Personal Profiles

**Input:**
```
PERSONAL PROFILE:
Name:
   TransUnion: ALEX MINH TRAN
   Experian: ALEX M TRAN
   Equifax: ALEX TRAN
Current Address:
   TransUnion: 1234 OAK STREET, SAN JOSE, CA 95123
   Experian: 1234 OAK ST, SAN JOSE, CA 95123
```

**Output:**
```json
[
  {
    "bureau": "transunion",
    "name": "ALEX MINH TRAN",
    "current_address": "1234 OAK STREET, SAN JOSE, CA 95123",
    "previous_address": "55 OLD ROAD, AUSTIN, TX 78000",
    "employer": "TECH SOFT INC"
  },
  {
    "bureau": "experian",
    "name": "ALEX M TRAN",
    "current_address": "1234 OAK ST, SAN JOSE, CA 95123",
    "previous_address": "55 OLD ROAD, AUSTIN, TX 78000",
    "employer": "TECH SOFT"
  }
]
```

**Code:**
```php
private function parsePersonalProfiles(string $text): array
{
    $profiles = [];
    
    // Extract PERSONAL PROFILE section
    if (!preg_match('/PERSONAL PROFILE.*?(?=CREDIT ACCOUNTS|$)/is', $text, $profileSection)) {
        return $profiles;
    }
    
    $section = $profileSection[0];
    $bureaus = ['transunion', 'experian', 'equifax'];
    
    foreach ($bureaus as $bureau) {
        $bureauName = ucfirst($bureau);
        $profile = ['bureau' => $bureau];
        
        // Extract name
        if (preg_match('/' . preg_quote($bureauName, '/') . '.*?Name[:\s]*([^\n]+)/i', $section, $nameMatch)) {
            $profile['name'] = trim($nameMatch[1]);
        }
        
        // Extract addresses, employer, etc.
        // ...
        
        $profiles[] = $profile;
    }
    
    return $profiles;
}
```

### Parse Accounts với Bureau-Specific Data

**Input:**
```
1. MIDLAND CREDIT MANAGEMENT
   Account #: 88990011
   Account Type: Collection Agency
   
   [TransUnion Section]
   Date Reported: 11/01/2025
   Date Last Active: 06/01/2018
   Balance: $2,500.00
   Status: Collection Account
   
   [Experian Section]
   Date Reported: 11/05/2025
   Date Last Active: 06/01/2018
   Balance: $2,550.00  ⚠️ DISCREPANCY
   Status: Collection
   
   [Equifax Section]
   Date Reported: 10/20/2025
   Date Last Active: 05/01/2018  ⚠️ DISCREPANCY
   Balance: $2,500.00
   Status: Collection
```

**Output:**
```json
{
  "account_name": "MIDLAND CREDIT MANAGEMENT",
  "account_number": "88990011",
  "account_type": "Collection Agency",
  "bureau_data": {
    "transunion": {
      "balance": 2500.00,
      "date_last_active": "2018-06-01",
      "date_reported": "2025-11-01",
      "status": "Collection Account"
    },
    "experian": {
      "balance": 2550.00,
      "date_last_active": "2018-06-01",
      "date_reported": "2025-11-05",
      "status": "Collection"
    },
    "equifax": {
      "balance": 2500.00,
      "date_last_active": "2018-05-01",
      "date_reported": "2025-10-20",
      "status": "Collection"
    }
  },
  "dispute_flags": ["INACCURATE_BALANCE", "INACCURATE_DATE"]
}
```

**Code:**
```php
private function extractAccountFullDetails(string $section, string $accountName, string $accountNumber): array
{
    $accountData = [
        'account_type' => null,
        'date_opened' => null,
        'bureau_data' => [],
    ];
    
    // Find account section
    $accountPattern = preg_quote($accountName, '/');
    if (!preg_match('/' . $accountPattern . '.*?(?=\d+\.\s+[A-Z]|$)/is', $section, $accountMatch)) {
        return $accountData;
    }
    
    $accountSection = $accountMatch[0];
    
    // Extract account type, date opened, etc.
    // ...
    
    // Extract bureau-specific data
    $bureaus = ['TransUnion', 'Experian', 'Equifax'];
    foreach ($bureaus as $bureau) {
        $bureauKey = strtolower($bureau);
        $bureauData = [
            'balance' => null,
            'date_last_active' => null,
            'date_reported' => null,
            'status' => null,
            'past_due' => null,
            // ...
        ];
        
        // Extract from bureau section
        // ...
        
        $accountData['bureau_data'][$bureauKey] = $bureauData;
    }
    
    return $accountData;
}
```

---

## 🔍 OCR Support cho Scanned PDFs

### Vấn Đề
Smalot PDFParser chỉ extract được text từ PDF gốc (text-based). Nếu PDF là scanned image, sẽ trả về chuỗi rỗng.

### Giải Pháp

#### Auto-Detection
```php
public function needsOcr(string $pdfPath, string $extractedText): bool
{
    // If extracted text is too short, likely a scanned PDF
    if (strlen(trim($extractedText)) < 100) {
        return true;
    }
    
    // Check if text contains mostly non-alphanumeric
    $alphanumericRatio = preg_match_all('/[a-zA-Z0-9]/', $extractedText) / max(strlen($extractedText), 1);
    if ($alphanumericRatio < 0.3) {
        return true;
    }
    
    return false;
}
```

#### OCR Process
```php
public function extractText(string $pdfPath): string
{
    // 1. Convert PDF to images
    $images = $this->pdfToImages($pdfPath);
    
    // 2. OCR each image
    $allText = '';
    foreach ($images as $imagePath) {
        $text = $this->ocrImage($imagePath);
        $allText .= $text . "\n";
    }
    
    return trim($allText);
}
```

#### Requirements
- **Tesseract OCR** installed
- **pdftoppm** (poppler-utils) for PDF to image conversion

#### Installation
```bash
# Ubuntu/Debian
sudo apt-get install tesseract-ocr poppler-utils

# macOS
brew install tesseract poppler

# Windows
# Download from: https://github.com/UB-Mannheim/tesseract/wiki
```

---

## 🔄 Data Normalization

### Mục Đích
Chuẩn hóa dữ liệu từ nhiều nguồn để đảm bảo consistency.

### Account Number Normalization

**Input:** `XXXX1234`, `1234****`, `****-****-****-1234`, `1234567890`

**Output:** `1234` (last 4 digits)

**Code:**
```php
public function normalizeAccountNumber(string $accountNumber): string
{
    $accountNumber = trim($accountNumber);
    $accountNumber = str_replace(['-', ' ', '_'], '', $accountNumber);
    
    // Extract last 4 digits if masked
    if (preg_match('/(\d{4})(?:[X\*]+|\d*)$/', $accountNumber, $matches)) {
        return $matches[1];
    }
    
    // If fully masked, try to extract any digits
    if (preg_match('/(\d+)/', $accountNumber, $matches)) {
        return $matches[1];
    }
    
    return $accountNumber;
}
```

### Balance Normalization

**Input:** `$1,200.00`, `1200`, `1.200,00` (European format)

**Output:** `1200.0` (float)

**Code:**
```php
public function normalizeBalance($balance): float
{
    if (is_numeric($balance)) {
        return (float) $balance;
    }
    
    $balance = trim((string) $balance);
    $balance = preg_replace('/[^\d.,\-]/', '', $balance);
    
    // Handle European format (1.200,00)
    if (preg_match('/^(\d{1,3}(?:\.\d{3})*),(\d+)$/', $balance, $matches)) {
        $balance = str_replace('.', '', $matches[1]) . '.' . $matches[2];
    } else {
        $balance = str_replace(',', '', $balance);
    }
    
    return (float) $balance;
}
```

### Status Normalization

**Input:** `Chrg Off`, `Charged-off`, `C/O`, `charge off`

**Output:** `CHARGED_OFF`

**Status Mapping:**
```php
$statusMap = [
    // Charged Off variations
    'charged off' => 'CHARGED_OFF',
    'charge off' => 'CHARGED_OFF',
    'charge-off' => 'CHARGED_OFF',
    'charged-off' => 'CHARGED_OFF',
    'chrg off' => 'CHARGED_OFF',
    'c/o' => 'CHARGED_OFF',
    'co' => 'CHARGED_OFF',
    
    // Collection variations
    'collection' => 'COLLECTION',
    'collections' => 'COLLECTION',
    'in collection' => 'COLLECTION',
    
    // Late Payment variations
    'late payment' => 'LATE_PAYMENT',
    'late' => 'LATE_PAYMENT',
    'delinquent' => 'LATE_PAYMENT',
    
    // ... và nhiều variations khác
];
```

---

## 🔐 Deduplication Logic

### Vấn Đề
Cùng một account có thể được parse nhiều lần từ các strategies khác nhau.

### Giải Pháp

#### 1. Collect-Then-Save Pattern
```php
// Collect all items from all strategies first
$allItems = [];
$allItems = array_merge($allItems, $this->parsePipeSeparated(...));
$allItems = array_merge($allItems, $this->parseTabSeparated(...));
// ... all strategies

// Normalize all items
$normalizedItems = [];
foreach ($allItems as $item) {
    $normalized = $this->normalizer->normalizeItem($item);
    $normalizedItems[] = $normalized;
}

// Create unique keys and deduplicate
$seenKeys = [];
$uniqueItems = [];
foreach ($normalizedItems as $item) {
    $uniqueKey = $this->createUniqueKey($client->id, $item);
    if (!isset($seenKeys[$uniqueKey])) {
        $seenKeys[$uniqueKey] = true;
        $uniqueItems[] = $item;
    }
}

// Save unique items
foreach ($uniqueItems as $item) {
    $this->saveCreditItem($client, $item);
}
```

#### 2. Unique Key Generation
```php
private function createUniqueKey(int $clientId, array $item): string
{
    $accountNumber = $this->normalizer->normalizeAccountNumber($item['account_number'] ?? '');
    return md5("{$clientId}_{$item['bureau']}_{$accountNumber}");
}
```

#### 3. Database-Level Duplicate Check
```php
private function saveCreditItem(Client $client, array $item): bool
{
    // Normalize account number for duplicate check
    $normalizedAccountNumber = $this->normalizer->normalizeAccountNumber($item['account_number']);
    
    // Check for duplicates using normalized account number
    $exists = CreditItem::where('client_id', $client->id)
        ->where('bureau', $item['bureau'])
        ->where(function($query) use ($item, $normalizedAccountNumber) {
            $query->where('account_number', $item['account_number'])
                  ->orWhereRaw('SUBSTRING(account_number, -4) = ?', [$normalizedAccountNumber]);
        })
        ->exists();
    
    if ($exists) {
        return false;
    }
    
    // Create record
    CreditItem::create([...]);
    return true;
}
```

---

## 📊 Cấu Trúc Dữ Liệu Output

### IdentityIQ Full Parser Output

```json
{
  "scores": {
    "id": 1,
    "client_id": 1,
    "transunion_score": 645,
    "experian_score": 650,
    "equifax_score": 620,
    "report_date": "2025-12-20",
    "reference_number": "998877-IIQ"
  },
  "personal_profiles": 3,
  "accounts": 5,
  "discrepancies": [
    {
      "account_name": "MIDLAND CREDIT MANAGEMENT",
      "account_number": "88990011",
      "flags": ["INACCURATE_BALANCE", "INACCURATE_DATE"]
    },
    {
      "account_name": "WELLS FARGO DEALER SERVICES",
      "account_number": "112233****",
      "flags": ["STATUS_CONFLICT"]
    }
  ]
}
```

### Credit Items Created

Mỗi account với "All Bureaus" sẽ tạo **3 Credit Items** riêng:

```json
[
  {
    "id": 1,
    "client_id": 1,
    "bureau": "transunion",
    "account_name": "CHASE BANK USA",
    "account_number": "44445555****",
    "account_type": "Credit Card",
    "date_opened": "2020-01-10",
    "balance": 1250.00,
    "high_limit": 5000.00,
    "monthly_pay": 50.00,
    "status": "Current",
    "reason": "Paid as agreed.",
    "dispute_status": "pending"
  },
  {
    "id": 2,
    "bureau": "experian",
    // ... same account, different bureau
  },
  {
    "id": 3,
    "bureau": "equifax",
    // ... same account, different bureau
  }
]
```

---

## ⚠️ Discrepancy Detection

### Các Loại Discrepancies

#### 1. INACCURATE_BALANCE
**Phát hiện khi:** Balance khác nhau giữa các bureaus

**Ví dụ:**
- TransUnion: $2,500.00
- Experian: $2,550.00 ⚠️
- Equifax: $2,500.00

**Code:**
```php
$balances = array_filter(array_column($bureauData, 'balance'));
if (count(array_unique($balances)) > 1) {
    $flags[] = 'INACCURATE_BALANCE';
}
```

#### 2. INACCURATE_DATE
**Phát hiện khi:** Date last active khác nhau

**Ví dụ:**
- TransUnion: 2018-06-01
- Experian: 2018-06-01
- Equifax: 2018-05-01 ⚠️

**Code:**
```php
$dates = array_filter(array_column($bureauData, 'date_last_active'));
if (count(array_unique($dates)) > 1) {
    $flags[] = 'INACCURATE_DATE';
}
```

#### 3. STATUS_CONFLICT
**Phát hiện khi:** Một bureau báo "Late" trong khi các bureau khác báo "Current"

**Ví dụ:**
- TransUnion: "Late 30 Days" ⚠️
- Experian: "Current"
- Equifax: "Current"

**Code:**
```php
$statuses = array_filter(array_column($bureauData, 'status'));
$statusValues = array_map('strtolower', $statuses);

$hasLate = false;
$hasCurrent = false;
foreach ($statusValues as $status) {
    if (stripos($status, 'late') !== false || stripos($status, 'delinquent') !== false) {
        $hasLate = true;
    }
    if (stripos($status, 'current') !== false || stripos($status, 'good') !== false) {
        $hasCurrent = true;
    }
}

if ($hasLate && $hasCurrent) {
    $flags[] = 'STATUS_CONFLICT';
}
```

---

## 💻 Usage & Examples

### Basic Usage

```php
use App\Services\CreditReportParserService;

$parserService = app(CreditReportParserService::class);
$client = Client::find(1);
$pdfPath = storage_path('app/credit-reports/report.pdf');

try {
    $count = $parserService->parsePdfAndSave($client, $pdfPath);
    echo "Imported {$count} items";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### IdentityIQ Full Parser

```php
use App\Services\IdentityIqFullParser;

$parser = new IdentityIqFullParser();
$result = $parser->parseAndSave($client, $pdfPath);

// Access results
$scores = $result['scores']; // CreditScore model
$profileCount = $result['personal_profiles']; // 3
$accountCount = $result['accounts']; // 5
$discrepancies = $result['discrepancies']; // Array of discrepancies

// Process discrepancies
foreach ($discrepancies as $discrepancy) {
    echo "Account: {$discrepancy['account_name']}\n";
    echo "Flags: " . implode(', ', $discrepancy['flags']) . "\n";
}
```

### Parse HTML (IdentityIQ Source)

```php
$htmlContent = '...'; // HTML source from IdentityIQ
$count = $parserService->parseAndSave($client, $htmlContent);
```

---

## 🐛 Troubleshooting

### Không Parse Được Items

**Symptoms:**
- Return count = 0
- Exception: "Could not parse any credit items from PDF"

**Solutions:**

#### Bước 1: Kiểm Tra Log File
```bash
# Windows PowerShell
Get-Content storage/logs/laravel.log -Tail 200

# Linux/Mac
tail -f storage/logs/laravel.log
```

Tìm các dòng quan trọng:
- `"Starting to parse accounts from PDF..."` - Parser đã bắt đầu
- `"Pattern 1 found X matches"` - Số accounts tìm được
- `"Total accounts found: X"` - Tổng số accounts
- `"No accounts found in PDF. Text preview: ..."` - Text preview
- `"Processing account: ..."` - Accounts đang được xử lý
- `"Created X items for account ..."` - Số items được tạo

#### Bước 2: Kiểm Tra Database Migrations
**Lỗi thường gặp:** `Table 'credit_scores' doesn't exist` hoặc `Column 'account_type' not found`

**Giải pháp:**
```bash
php artisan migrate:status  # Kiểm tra migrations
php artisan migrate         # Chạy migrations nếu chưa chạy
```

**Migrations cần thiết:**
- `create_credit_scores_table`
- `create_personal_profiles_table`
- `add_additional_fields_to_credit_items_table` (account_type, date_opened, high_limit, monthly_pay)
- `add_date_last_active_and_past_due_to_credit_items_table`

#### Bước 3: Kiểm Tra Text Preview
Nếu log có "Text preview", kiểm tra:
- Có chứa "CREDIT ACCOUNTS" hoặc "TRADE LINES" không?
- Có chứa account names như "CHASE BANK", "MIDLAND CREDIT" không?
- Format có đúng như expected không?

#### Bước 4: Test Pattern Matching
```php
// Trong tinker
php artisan tinker

$parser = new \Smalot\PdfParser\Parser();
$pdf = $parser->parseFile('path/to/file.pdf');
$text = $pdf->getText();

// Test pattern 1
preg_match_all('/(\d+)\.\s+([A-Z][A-Z\s&]{3,}?)(?:\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+))?/i', $text, $matches);
print_r($matches);

// Test IdentityIQ format detection
preg_match('/IdentityIQ|CREDIT SCORE DASHBOARD|PERSONAL PROFILE|CREDIT ACCOUNTS/i', $text);
```

#### Bước 5: Verify PDF Format
- PDF có phải scanned? → Cần OCR (check Tesseract installation)
- PDF có text content? → Check với PDF reader
- File name có ký tự đặc biệt? → Có thể gây lỗi path

### Parse Sai Data

**Symptoms:**
- Items được tạo nhưng data sai
- Missing fields
- Wrong bureau assignment
- "Account has bureau_data: no"

**Solutions:**

1. **Check regex patterns:**
   - Adjust patterns trong Strategy 4
   - Test với regex tester
   - Xem log để biết pattern nào match

2. **Verify column positions:**
   - Fixed-width: Check column alignment
   - Adjust `detectColumnPositions()` logic
   - Xem text preview để verify format

3. **Improve extraction:**
   - Add more patterns
   - Improve account name extraction
   - Check `extractAccountFullDetails()` method

4. **Bureau Data Issues:**
   - Log sẽ show: "Account has bureau_data: no"
   - Kiểm tra format "Details by Bureau" trong text
   - Có thể cần cải thiện `extractFromTable()`, `extractFromRawDataView()`

### Accounts Tìm Được Nhưng Không Tạo Items

**Symptoms:**
- "Created 0 items for account ..."
- Log shows accounts found but no items created

**Solutions:**
1. **Check `createAccountItems()` method:**
   - Bureau_data có balance hoặc status không?
   - Có thể duplicate check đang block

2. **Check duplicate detection:**
   - Item có thể đã tồn tại trong database
   - Check unique key generation logic

3. **Check database constraints:**
   - Required fields có đầy đủ không?
   - Foreign key constraints

### OCR Issues

**Symptoms:**
- OCR fails
- Poor text quality
- "Tesseract OCR not found"

**Solutions:**
1. **Check Tesseract installation:**
   ```bash
   tesseract --version
   ```
   - Windows: Download from https://github.com/UB-Mannheim/tesseract/wiki
   - Ubuntu: `sudo apt-get install tesseract-ocr poppler-utils`
   - macOS: `brew install tesseract poppler`

2. **Check OCR Service:**
   - Log sẽ show: "Tesseract OCR not found. OCR functionality will be disabled."
   - Service sẽ fallback về text extraction thông thường
   - Không throw error, chỉ disable OCR

3. **Improve image quality:**
   - Increase DPI: `pdftoppm -r 300`
   - Pre-process images
   - Check PDF quality

4. **Try alternative OCR:**
   - Google Vision API
   - AWS Textract
   - Azure Computer Vision

### Performance Issues

**Symptoms:**
- Parse quá chậm
- Timeout

**Solutions:**
1. **Split large PDFs:**
   - Parse từng page
   - Process in background jobs

2. **Optimize regex:**
   - Reduce backtracking
   - Use more specific patterns

3. **Database optimization:**
   - Add indexes
   - Batch inserts

---

## 📚 Best Practices

### 1. Error Handling
- Luôn wrap trong try-catch
- Log chi tiết từng bước
- Graceful fallback giữa các strategies

### 2. Logging
```php
Log::info("Parsing PDF for client {$client->id}");
Log::info("Strategy 1 found {$count} items");
Log::warning("Failed to parse item: {$error}");
Log::error("Critical error: {$exception->getMessage()}");
```

### 3. Validation
- Validate input PDF exists
- Check file size
- Verify PDF format

### 4. Testing
- Test với nhiều format khác nhau
- Test với edge cases (empty PDF, corrupted PDF)
- Test với scanned PDFs

### 5. Monitoring
- Track parsing success rate
- Monitor discrepancy detection
- Alert on parsing failures

---

## 📁 File Structure

```
app/
├── Services/
│   ├── CreditReportParserService.php       # Main parser service
│   ├── IdentityIqFullParser.php            # IdentityIQ specialized parser
│   └── PdfParsing/
│       ├── DataNormalizer.php              # Data normalization
│       ├── TesseractOcrService.php         # OCR service
│       ├── OcrServiceInterface.php         # OCR interface
│       ├── PdfParserStrategyInterface.php  # Strategy interface
│       └── IdentityIqStructuredParser.php  # IdentityIQ structured parser
├── Models/
│   ├── CreditItem.php                      # Credit items model
│   ├── CreditScore.php                     # Credit scores model
│   └── PersonalProfile.php                 # Personal profiles model
database/
└── migrations/
    ├── create_credit_items_table.php
    ├── create_credit_scores_table.php
    ├── create_personal_profiles_table.php
    ├── add_additional_fields_to_credit_items_table.php  # account_type, date_opened, high_limit, monthly_pay
    └── add_date_last_active_and_past_due_to_credit_items_table.php
```

## 🗄️ Database Schema

### CreditItems Table
- `id`, `client_id`, `bureau`, `account_name`, `account_number`
- `account_type` (nullable) - Credit Card, Loan, Collection Agency, etc.
- `date_opened` (nullable) - Date account was opened
- `date_last_active` (nullable) - Last activity date
- `date_reported` (nullable) - Date reported to bureau
- `balance`, `high_limit` (nullable), `monthly_pay` (nullable), `past_due` (nullable)
- `status`, `reason`, `dispute_status`

### CreditScores Table
- `id`, `client_id`
- `transunion_score`, `experian_score`, `equifax_score` (nullable)
- `report_date` (nullable), `reference_number` (nullable)

### PersonalProfiles Table
- `id`, `client_id`, `bureau` (nullable)
- `name`, `date_of_birth`, `current_address`, `previous_address`, `employer`
- `date_reported` (nullable)

---

## 🎯 Summary

### Hệ Thống Hiện Tại Có Thể:

✅ **Parse 7 định dạng PDF khác nhau** với strategies tự động
✅ **Xử lý scanned PDFs** với OCR support
✅ **Extract đầy đủ thông tin** từ IdentityIQ reports:
   - Credit Scores (3 bureaus)
   - Personal Profiles với variations
   - Accounts với bureau-specific data
✅ **Detect discrepancies** tự động (balance, date, status conflicts)
✅ **Normalize data** từ nhiều nguồn
✅ **Deduplicate** thông minh
✅ **Handle masked accounts** (XXXX1234, 1234****)

### Kết Quả:

Với file PDF IdentityIQ 3 trang, hệ thống sẽ:
1. Extract Credit Scores: `{transunion: 645, experian: 650, equifax: 620}`
2. Extract Personal Profiles: 3 profiles với tất cả variations
3. Extract Accounts: Với data riêng cho từng bureau
4. Detect Discrepancies: Tự động flag các lỗi
5. Save tất cả vào database

**Status: ✅ Production Ready!**

---

## 📝 Changelog

### Version 2.1 (2025-12-20)
- ✅ Fixed database migrations issues
- ✅ Improved error handling for partial data
- ✅ Enhanced logging for debugging
- ✅ Fixed Filament redirect issue
- ✅ Added alternative parsing method for known account names

### Version 2.0 (2025-12-20)
- ✅ Added IdentityIQ Full Parser (Strategy 7)
- ✅ Added OCR support with Tesseract
- ✅ Added Data Normalization layer
- ✅ Improved deduplication logic
- ✅ Added Credit Scores and Personal Profiles models
- ✅ Added discrepancy detection

### Version 1.0
- ✅ Initial 6 parsing strategies
- ✅ Basic PDF parsing functionality

---

**Last Updated:** 2025-12-20
**Version:** 2.1
**Main Documentation File:** `PDF_PARSING_COMPLETE_GUIDE.md`

