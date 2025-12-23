# PDF Parsing Complete Guide

## 📋 Mục Lục

1. [Tổng Quan](#tổng-quan)
2. [Quy Trình Đọc Và Phân Tích Dữ Liệu Chi Tiết](#quy-trình-đọc-và-phân-tích-dữ-liệu-chi-tiết)
3. [Kiến Trúc Hệ Thống](#kiến-trúc-hệ-thống)
4. [Các Format PDF Được Hỗ Trợ](#các-format-pdf-được-hỗ-trợ)
5. [Cấu Trúc Code](#cấu-trúc-code)
6. [Các Vấn Đề Đã Gặp Và Giải Pháp](#các-vấn-đề-đã-gặp-và-giải-pháp)
7. [Best Practices](#best-practices)
8. [Hướng Dẫn Debug](#hướng-dẫn-debug)
9. [Lưu Ý Quan Trọng](#lưu-ý-quan-trọng)
10. [Các Thay Đổi Quan Trọng](#các-thay-đổi-quan-trọng)

---

## Tổng Quan

Hệ thống PDF parsing được thiết kế để xử lý các file credit report từ IdentityIQ với nhiều format khác nhau. Parser có khả năng:

- ✅ Extract Credit Scores từ 3 bureaus (TransUnion, Experian, Equifax)
- ✅ Extract Personal Profile information
- ✅ Extract Credit Accounts với bureau-specific data
- ✅ Xử lý nhiều format khác nhau (tabular, inline, raw data view)
- ✅ Phát hiện và xử lý discrepancies giữa các bureaus
- ✅ Normalize và validate dữ liệu trước khi lưu

---

## Quy Trình Đọc Và Phân Tích Dữ Liệu Chi Tiết

### Tổng Quan Quy Trình

Quy trình parsing được chia thành 4 giai đoạn chính:

1. **Khởi Tạo & Extract Text từ PDF**
2. **Parse Credit Scores**
3. **Parse Personal Profiles**
4. **Parse Credit Accounts** (phức tạp nhất)

---

### GIAI ĐOẠN 1: Khởi Tạo & Extract Text từ PDF

#### Bước 1.1: Nhận PDF File

```php
// Entry point: CreditReportParserService::parsePdfAndSave()
public function parsePdfAndSave(Client $client, string $pdfPath): int
```

**Input:**

- `Client $client`: Client object từ database
- `string $pdfPath`: Đường dẫn đến file PDF

**Process:**

- Validate file tồn tại
- Detect format của PDF (auto-detect hoặc hint)

#### Bước 1.2: Parse PDF thành Text

```php
$parser = new \Smalot\PdfParser\Parser();
$pdf = $parser->parseFile($pdfPath);
$text = $pdf->getText();
```

**Công cụ:** Smalot PDF Parser library

**Output:** Raw text từ PDF (có thể có nhiều line breaks, spaces không đều)

#### Bước 1.3: Normalize Text

```php
// Remove excessive line breaks (page boundaries)
$text = preg_replace('/\n{3,}/', "\n\n", $text);
// Normalize spaces around line breaks
$text = preg_replace('/\s*\n\s*/', "\n", $text);
```

**Mục đích:**

- Loại bỏ line breaks thừa do page breaks
- Chuẩn hóa spaces để dễ parse hơn
- Đảm bảo text có format nhất quán

**Output:** Normalized text string

#### Bước 1.4: Khởi Tạo Database Transaction

```php
DB::beginTransaction();
```

**Mục đích:** Đảm bảo data consistency - nếu có lỗi, rollback tất cả

---

### GIAI ĐOẠN 2: Parse Credit Scores

#### Bước 2.1: Tìm Section Credit Scores

```php
$scores = $this->parseCreditScores($text);
```

**Pattern tìm kiếm:**

- Tìm section "CREDIT SCORE DASHBOARD" hoặc "CREDIT SCORES"
- Tìm table format với 3 bureaus: TransUnion, Experian, Equifax

**Format được hỗ trợ:**

**Format 1: Tabular Format**

```
|  TRANSUNION | EXPERIAN | EQUIFAX  |
| --- | --- | --- |
|  725 | 718 | 730  |
```

**Format 2: Inline Format**

```
TransUnion: 725
Experian: 718
Equifax: 730
```

#### Bước 2.2: Extract Scores từ Table

```php
// Pattern để match table format
$pattern = '/TRANSUNION\s*\|\s*EXPERIAN\s*\|\s*EQUIFAX.*?\n.*?\|.*?\|.*?\|.*?\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)/is';
```

**Logic:**

1. Tìm header row với 3 bureaus
2. Tìm data row tiếp theo
3. Extract 3 giá trị số (scores)
4. Map: cells[1] = TransUnion, cells[2] = Experian, cells[3] = Equifax

#### Bước 2.3: Extract Report Date & Reference Number

```php
$reportDate = $this->extractReportDate($text);
$referenceNumber = $this->extractReferenceNumber($text);
```

**Patterns:**

- Date: `Date:\s*(\d{1,2}\/\d{1,2}\/\d{4})`
- Reference: `Reference\s*#?:\s*([A-Z0-9\-]+)`

#### Bước 2.4: Lưu vào Database

```php
CreditScore::create([
    'client_id' => $client->id,
    'transunion_score' => $scores['transunion'] ?? null,
    'experian_score' => $scores['experian'] ?? null,
    'equifax_score' => $scores['equifax'] ?? null,
    'report_date' => $reportDate,
    'reference_number' => $referenceNumber,
]);
```

**Error Handling:** Nếu fail, log warning nhưng tiếp tục với các phần khác

---

### GIAI ĐOẠN 3: Parse Personal Profiles

#### Bước 3.1: Tìm Section Personal Profile

```php
$profiles = $this->parsePersonalProfiles($text);
```

**Pattern tìm kiếm:**

- Tìm section "PERSONAL PROFILE" hoặc "PERSONAL INFORMATION"

#### Bước 3.2: Extract Profile Data cho từng Bureau

**Format được hỗ trợ:**

**Format 1: Tabular Format (Phổ biến nhất)**

```
|  Field | TransUnion | Experian | Equifax  |
| --- | --- | --- | --- |
|  Name: | NGUYEN VAN A | NGUYEN V A | NGUYEN VAN A  |
|  Date of Birth: | 1990 | 1990 | 1990  |
|  Current Address: | 123 MAIN ST | 123 MAIN STREET | 123 MAIN ST  |
```

**Logic Extract:**

1. Tìm header row với 3 bureaus
2. Với mỗi field row:
   - Extract field name (cells[1])
   - Extract TransUnion value (cells[2])
   - Extract Experian value (cells[3])
   - Extract Equifax value (cells[4])
3. Map fields: Name, Date of Birth, Current Address, Employer

**Format 2: Per-Bureau Format**

```
TransUnion:
  Name: NGUYEN VAN A
  Date of Birth: 1990
  Address: 123 MAIN ST

Experian:
  Name: NGUYEN V A
  Date of Birth: 1990
  Address: 123 MAIN STREET
```

**Logic Extract:**

1. Tìm section cho từng bureau
2. Extract fields trong section đó
3. Lặp lại cho 3 bureaus

#### Bước 3.3: Normalize và Validate Data

```php
// Normalize name
$name = trim($name);
// Normalize address
$address = $this->normalizeAddress($address);
// Parse date of birth
$dob = $this->parseDate($dob);
```

#### Bước 3.4: Lưu vào Database

```php
foreach ($profiles as $profile) {
    PersonalProfile::updateOrCreate(
        [
            'client_id' => $client->id,
            'bureau' => $profile['bureau'],
        ],
        $profile
    );
}
```

**Lưu ý:** Sử dụng `updateOrCreate` để tránh duplicate

---

### GIAI ĐOẠN 4: Parse Credit Accounts (Phức Tạp Nhất)

#### Bước 4.1: Tìm Section Credit Accounts

```php
$accounts = $this->parseAccounts($text, $formatHint);
```

**Pattern tìm kiếm:**

- Tìm section "CREDIT ACCOUNTS" hoặc "TRADE LINES" hoặc "ACCOUNTS"

**Logic:**

1. Tìm start marker: "CREDIT ACCOUNTS"
2. Tìm end marker: "INQUIRIES" hoặc "PUBLIC RECORDS" hoặc "END OF REPORT"
3. Extract section giữa 2 markers

#### Bước 4.2: Extract Account Names và Account Numbers

**Pattern 1: Numbered Accounts (Phổ biến nhất)**

```php
$pattern1 = '/(\d+)\.\s+([A-Z][A-Z\s&.,\-()]+?)(?:\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+))?(?:\s*\([^)]+\))?(?:\s|$)/i';
```

**Ví dụ:**

```
1. CHASE BANK USA (Open Account - Good Standing)
Account #: 44445555***
```

**Logic:**

1. Match pattern: `1. ACCOUNT NAME Account #: NUMBER`
2. Extract account name (loại bỏ blacklist: Revolving, Auto Loan, etc.)
3. Extract account number (có thể có mask: `***`, `X`)
4. Validate account name không phải là header/type

**Pattern 2: Accounts không có số thứ tự**

```php
$pattern2 = '/([A-Z][A-Z\s&]{3,}?)\s*(?:\n|$).*?(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/is';
```

**Pattern 3: Raw Data View Format**

```php
$pattern3 = '/(?:TransUnion|Experian|Equifax)\s*\|\s*([A-Z][A-Z\s&]+?)\s*\|\s*([X\*\d\-]+)\s*\|\s*\$?([\d,]+\.?\d*)/i';
```

#### Bước 4.3: Extract Full Account Details cho mỗi Account

**Với mỗi account tìm được:**

```php
$accountData = $this->extractAccountFullDetails($section, $accountName, $accountNumber);
```

**Bước 4.3.1: Tìm Account Section**

**Logic:**

1. Tìm vị trí account name trong text
2. Tìm account number để verify
3. Tìm end marker: account tiếp theo hoặc "INQUIRIES"
4. Extract section giữa start và end

**Bước 4.3.2: Extract Account Type và Date Opened**

```php
// Account Type
if (preg_match('/Account Type[:\s]*([^\n]+)/i', $accountSection, $typeMatch)) {
    $accountData['account_type'] = trim($typeMatch[1]);
}

// Date Opened
if (preg_match('/Date Opened[:\s]*([0-9\/\-]+)/i', $accountSection, $dateMatch)) {
    $accountData['date_opened'] = $this->parseDate(trim($dateMatch[1]));
}
```

**Bước 4.3.3: Extract Bureau-Specific Data (QUAN TRỌNG NHẤT)**

**Với mỗi bureau (TransUnion, Experian, Equifax):**

```php
foreach ($bureaus as $bureau) {
    $bureauData = $this->extractBureauData($accountSection, $bureau);
    $accountData['bureau_data'][$bureau] = $bureauData;
}
```

**Thứ tự ưu tiên extract methods:**

**Priority 1: Table Format (Ưu tiên cao nhất)**

```php
$tableResult = $this->extractFromTable($accountSection, $bureau);
```

**Format:**

```
|   | TransUnion | Experian | Equifax  |
| Account Status: | Open | Open | Open  |
| Payment Status: | Current | Current | Current  |
| Balance: | $1,200 | $1,200 | $1,200  |
| High Limit: | $5,000 | $5,000 | $5,000  |
```

**Logic Extract:**

1. Tìm header row: `|   | TransUnion | Experian | Equifax  |`
2. Detect empty first column
3. Với mỗi data row:
   - Parse row: `Account Status: | Open | Open | Open  |`
   - Extract field name: "Account Status"
   - Calculate column index:
     - TransUnion (index 0) → cells[2]
     - Experian (index 1) → cells[3]
     - Equifax (index 2) → cells[4]
   - Extract value từ đúng column
4. Map field names:
   - "Account Status" → `status`
   - "Payment Status" → `payment_status`
   - "Balance" → `balance`
   - "High Limit" → `high_limit`
   - "Monthly Payment" → `monthly_pay`
   - "Past Due" → `past_due`
   - "Date Opened" → `date_opened`
   - "Last Reported" → `date_reported`

**Pattern Matching Chi Tiết:**

**Pattern 1: Với leading | và colon**

```php
// Format: "| Account Status: | Open | Open | Open  |"
if (preg_match('/^\s*\|\s*([^|:]+?):\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)(?:\s*\||\s*$)/i', $row, $cells)) {
    // cells[0] = full match
    // cells[1] = "Account Status" (field name)
    // cells[2] = "Open" (TransUnion value)
    // cells[3] = "Open" (Experian value)
    // cells[4] = "Open" (Equifax value)

    $fieldName = trim($cells[1]);
    $valueIndex = $columnIndex + 2; // +2 vì cells[0]=match, cells[1]=field name
    $value = trim($cells[$valueIndex]);
}
```

**Pattern 2: Không có leading | nhưng có colon**

```php
// Format: "Account Status: | Open | Open | Open  |"
elseif (preg_match('/^\s*([^|:]+?):\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)(?:\s*\||\s*$)/i', $row, $cells)) {
    // cells[0] = full match
    // cells[1] = "Account Status" (field name)
    // cells[2] = "Open" (TransUnion value)
    // cells[3] = "Open" (Experian value)
    // cells[4] = "Open" (Equifax value)

    $fieldName = trim($cells[1]);
    $valueIndex = $columnIndex + 2;
    $value = trim($cells[$valueIndex]);
}
```

**Pattern 3: Với leading | nhưng không có colon**

```php
// Format: "| Field Name | value1 | value2 | value3"
elseif (preg_match('/^\s*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)\s*\|\s*([^|]+)/i', $row, $cells)) {
    // cells[0] = full match
    // cells[1] = "Field Name" (có thể empty nếu là header row)
    // cells[2] = value1 (TransUnion)
    // cells[3] = value2 (Experian)
    // cells[4] = value3 (Equifax)

    $fieldName = trim($cells[1]);
    // Skip nếu empty hoặc là header
    if (empty($fieldName) || preg_match('/TransUnion|Experian|Equifax/i', $fieldName)) {
        continue;
    }
    $valueIndex = $columnIndex + 2;
    $value = trim($cells[$valueIndex]);
}
```

**Column Index Calculation Chi Tiết:**

**Ví dụ với format:**

```
|   | TransUnion | Experian | Equifax  |
| Account Status: | Open | Open | Open  |
```

**Header Row Parsing:**

- Pattern match: `|   | TransUnion | Experian | Equifax  |`
- cells[0] = full match
- cells[1] = empty (first column)
- cells[2] = "TransUnion"
- cells[3] = "Experian"
- cells[4] = "Equifax"

**Data Row Parsing:**

- Pattern match: `Account Status: | Open | Open | Open  |`
- cells[0] = full match
- cells[1] = "Account Status"
- cells[2] = "Open" (TransUnion)
- cells[3] = "Open" (Experian)
- cells[4] = "Open" (Equifax)

**Column Index Mapping:**

- TransUnion: `columnIndex = 0` → `valueIndex = 0 + 2 = 2` → `cells[2]` = "Open" ✓
- Experian: `columnIndex = 1` → `valueIndex = 1 + 2 = 3` → `cells[3]` = "Open" ✓
- Equifax: `columnIndex = 2` → `valueIndex = 2 + 2 = 4` → `cells[4]` = "Open" ✓

**Field Name Mapping:**

```php
// Map field names to data keys
if (stripos($fieldName, 'balance') !== false) {
    $bureauData['balance'] = $this->normalizer->normalizeBalance($value);
} elseif (stripos($fieldName, 'monthly payment') !== false || stripos($fieldName, 'monthly pay') !== false) {
    $bureauData['monthly_pay'] = $this->normalizer->normalizeBalance($value);
} elseif (stripos($fieldName, 'high limit') !== false || stripos($fieldName, 'credit limit') !== false || stripos($fieldName, 'limit') !== false) {
    $bureauData['high_limit'] = $this->normalizer->normalizeBalance($value);
} elseif (stripos($fieldName, 'payment status') !== false || stripos($fieldName, 'pay status') !== false) {
    // QUAN TRỌNG: Payment Status được extract TRƯỚC Account Status
    $bureauData['payment_status'] = trim($value);
} elseif (stripos($fieldName, 'account status') !== false || (stripos($fieldName, 'status') !== false && stripos($fieldName, 'payment') === false)) {
    // Account Status chỉ được extract nếu không phải Payment Status
    $bureauData['status'] = trim($value);
} elseif (stripos($fieldName, 'past due') !== false) {
    $bureauData['past_due'] = $this->normalizer->normalizeBalance($value);
} elseif (stripos($fieldName, 'date last active') !== false || stripos($fieldName, 'last payment') !== false) {
    $bureauData['date_last_active'] = $this->parseDate($value);
} elseif (stripos($fieldName, 'last reported') !== false || stripos($fieldName, 'date reported') !== false) {
    $bureauData['date_reported'] = $this->parseDate($value);
}
```

**Lưu ý quan trọng về Payment Status vs Account Status:**

- **Payment Status** được check TRƯỚC Account Status
- Điều này đảm bảo không bị nhầm lẫn giữa 2 loại status
- Payment Status: Current, Late 30 Days, Collection, etc.
- Account Status: Open, Closed, Paid, etc.

**Priority 2: Inline Table Format**

```php
$inlineResult = $this->extractFromInlineTable($accountSection, $bureau);
```

**Format:**

```
Balance: $1,350.00 $1,150.00 $1,250.00
High Limit: $5,000 $5,000 $5,000
```

**Logic:**

1. Tìm pattern: `Balance: $value1 $value2 $value3`
2. Extract 3 values
3. Map theo bureau index:
   - TransUnion (index 0) → value1
   - Experian (index 1) → value2
   - Equifax (index 2) → value3

**Priority 3: Raw Data View Format**

```php
$rawResult = $this->extractFromRawDataView($accountSection, $bureau, $accountName, $accountNumber);
```

**Format:**

```
TransUnion | PORTFOLIO RECOVERY | 99998888 | $900.00 | Collection | ...
```

**Logic:**

1. Tìm row bắt đầu với bureau name
2. Parse pipe-separated values
3. Extract: Account Name, Account Number, Balance, Status, Reason

**Priority 4: Bracketed Section Format**

```php
$bracketedResult = $this->extractFromBracketedSection($accountSection, $bureau);
```

**Format:**

```
[TransUnion Section]
  Balance: $1,200
  Status: Open
```

**Priority 5: Direct Table Rows (Fallback)**

```php
$directResult = $this->extractDirectlyFromTableRows($accountSection, $bureau);
```

**Mục đích:** Fallback nếu các methods trên không hoạt động

**Bước 4.3.4: Parse và Normalize Data**

**Balance:**

```php
$bureauData['balance'] = $this->normalizer->normalizeBalance($value);
// Handles: "$1,200.00", "$1200", "1200.00" → 1200.00
```

**Status:**

```php
$bureauData['status'] = $this->normalizer->normalizeStatus($value);
// Handles: "Open", "OPEN", "open" → "Open"
```

**Payment Status:**

```php
$bureauData['payment_status'] = $this->normalizePaymentStatus($value);
// Handles: "Current", "Late 30 Days", "Collection" → normalized values
```

**Dates:**

```php
$bureauData['date_opened'] = $this->parseDate($value);
// Handles: "01/15/2020", "2020-01-15", "Jan 15, 2020" → Carbon date
```

#### Bước 4.4: Tạo CreditItem Records cho Database

```php
$items = $this->createAccountItems($client, $accountData);
```

**Logic:**

**Với mỗi bureau (TransUnion, Experian, Equifax):**

1. **Lấy Bureau Data:**

```php
   $bureauData = $accountData['bureau_data'][$bureau] ?? [];
```

2. **Fallback nếu không có bureau_data:**

```php
   if (empty($bureauData) && isset($accountData['bureau']) && strtolower($accountData['bureau']) === 'all bureaus') {
       $bureauData = [
           'balance' => $accountData['balance'] ?? 0,
           'status' => $accountData['status'] ?? null,
           'payment_status' => $accountData['payment_status'] ?? null,
           // ... other fields
       ];
   }
```

3. **Check Duplicate:**

```php
    $exists = CreditItem::where('client_id', $client->id)
       ->where('bureau', $bureau)
       ->where('account_name', $accountData['account_name'])
       ->where('account_number', $accountData['account_number'] ?? null)
        ->exists();
```

4. **Normalize Status và Payment Status:**

   ```php
   $normalizedStatus = $this->normalizer->normalizeStatus($bureauData['status'] ?? null);
   $normalizedPaymentStatus = $this->normalizePaymentStatus($bureauData['payment_status'] ?? null);
   ```

5. **Create CreditItem:**
   ```php
   $item = CreditItem::create([
       'client_id' => $client->id,
       'bureau' => $bureau,
       'account_name' => $accountData['account_name'],
       'account_number' => $accountData['account_number'] ?? null,
       'account_type' => $accountData['account_type'] ?? null,
       'date_opened' => $accountData['date_opened'] ?? null,
       'date_last_active' => $bureauData['date_last_active'] ?? null,
       'date_reported' => $bureauData['date_reported'] ?? null,
       'balance' => $bureauData['balance'] ?? 0,
       'high_limit' => $bureauData['high_limit'] ?? null,
       'monthly_pay' => $bureauData['monthly_pay'] ?? null,
       'past_due' => $bureauData['past_due'] ?? null,
       'payment_history' => $bureauData['payment_history'] ?? null,
       'status' => $normalizedStatus,
       'payment_status' => $normalizedPaymentStatus,
       'reason' => $bureauData['reason'] ?? null,
       'dispute_status' => CreditItem::STATUS_PENDING,
   ]);
   ```

**Lưu ý quan trọng:**

- **Luôn tạo items cho tất cả 3 bureaus**, kể cả khi không có data
- Điều này đảm bảo tất cả accounts xuất hiện cho tất cả bureaus
- Nếu không có bureau_data, sử dụng general accountData hoặc empty values

#### Bước 4.5: Detect Discrepancies

```php
$discrepancies = $this->detectDiscrepancies($accountData);
```

**Logic:**

1. So sánh balance giữa 3 bureaus
2. So sánh status giữa 3 bureaus
3. So sánh payment_status giữa 3 bureaus
4. Flag nếu có khác biệt

**Ví dụ:**

```php
if ($tuBalance !== $expBalance || $tuBalance !== $eqBalance) {
    $discrepancies[] = 'Balance discrepancy';
}
```

#### Bước 4.6: Error Handling và Logging

**Với mỗi account:**

```php
try {
    // Process account
} catch (\Exception $e) {
    Log::error("Failed to process account: {$e->getMessage()}");
    // Continue with next account
}
```

**Logging quan trọng:**

- Account được extract
- Bureau data được tìm thấy
- Column extraction details
- Errors và warnings

---

### GIAI ĐOẠN 5: Commit Transaction và Return Results

#### Bước 5.1: Commit Database Transaction

```php
DB::commit();
```

**Nếu có lỗi:**

```php
DB::rollBack();
throw $e;
```

#### Bước 5.2: Return Results

```php
return [
    'scores' => $creditScore,
    'personal_profiles' => $profileCount,
    'accounts' => $accountCount,
    'discrepancies' => $discrepancies,
];
```

---

### Tóm Tắt Quy Trình Parsing

**Flow Diagram Chi Tiết:**

```
PDF File
    ↓
[1] Extract Text từ PDF
    ├── Parse PDF → Raw Text
    └── Normalize Text (remove excessive line breaks)
    ↓
[2] Parse Credit Scores
    ├── Tìm section "CREDIT SCORE DASHBOARD"
    ├── Extract scores từ table hoặc inline format
    ├── Extract report date và reference number
    └── Lưu vào CreditScore table
    ↓
[3] Parse Personal Profiles
    ├── Tìm section "PERSONAL PROFILE"
    ├── Extract profile data cho từng bureau (table hoặc per-bureau format)
    ├── Normalize data (name, address, DOB)
    └── Lưu vào PersonalProfile table (updateOrCreate)
    ↓
[4] Parse Credit Accounts (PHỨC TẠP NHẤT)
    ├── Tìm section "CREDIT ACCOUNTS"
    ├── Extract account names và numbers (3 patterns)
    │   ├── Pattern 1: Numbered accounts "1. ACCOUNT NAME"
    │   ├── Pattern 2: Accounts không có số
    │   └── Pattern 3: Raw data view format
    │
    ├── Với mỗi account:
    │   ├── Extract account section (từ account name đến account tiếp theo)
    │   ├── Extract account type và date opened
    │   │
    │   └── Extract bureau-specific data (cho mỗi bureau):
    │       ├── Priority 1: Table format (extractFromTable)
    │       │   ├── Tìm header row với 3 bureaus
    │       │   ├── Parse từng data row
    │       │   ├── Calculate column index
    │       │   ├── Extract value từ đúng column
    │       │   └── Map field names (Account Status, Payment Status, Balance, etc.)
    │       │
    │       ├── Priority 2: Inline table format (extractFromInlineTable)
    │       │   └── Format: "Balance: $X $Y $Z"
    │       │
    │       ├── Priority 3: Raw data view (extractFromRawDataView)
    │       │   └── Format: "Bureau | Name | # | Balance | Status"
    │       │
    │       ├── Priority 4: Bracketed section (extractFromBracketedSection)
    │       │   └── Format: "[TransUnion Section] ... data ..."
    │       │
    │       └── Priority 5: Direct table rows (extractDirectlyFromTableRows)
    │           └── Fallback method
    │
    │   ├── Normalize data (balance, status, dates)
    │   ├── Create CreditItem records cho 3 bureaus
    │   │   ├── Check duplicate
    │   │   ├── Normalize status và payment_status
    │   │   └── Create record với bureau-specific data
    │   │
    │   └── Detect discrepancies (so sánh giữa 3 bureaus)
    │
    └── Return account count và discrepancies
    ↓
[5] Commit Transaction
    ├── DB::commit()
    └── Return results
```

**Thời Gian Xử Lý Ước Tính:**

- PDF nhỏ (< 10 pages): ~2-5 giây
- PDF trung bình (10-50 pages): ~5-15 giây
- PDF lớn (> 50 pages): ~15-30 giây

**Memory Usage:**

- Text extraction: ~1-5 MB (tùy PDF size)
- Parsing process: ~5-20 MB (tùy số lượng accounts)

**Error Handling Strategy:**

- **Credit Scores fail:** Log warning, continue với profiles và accounts
- **Personal Profiles fail:** Log warning, continue với accounts
- **Single Account fail:** Log error, continue với accounts khác
- **All fail:** Rollback transaction, throw exception

**Key Points:**

1. **Text Normalization** là bước quan trọng đầu tiên - đảm bảo text có format nhất quán
2. **Table Format** được ưu tiên cao nhất vì đáng tin cậy và chứa đầy đủ thông tin
3. **Column Index Calculation** phải chính xác để không nhầm lẫn giữa các cột
4. **Payment Status** được extract TRƯỚC Account Status để tránh nhầm lẫn
5. **Luôn tạo items cho tất cả 3 bureaus** để đảm bảo data đầy đủ
6. **Transaction** đảm bảo data consistency - nếu có lỗi, rollback tất cả

---

## Kiến Trúc Hệ Thống

### File Structure

```
app/Services/
├── CreditReportParserService.php      # Main entry point
├── IdentityIqFullParser.php           # Core parser implementation
└── PdfParsing/
    └── DataNormalizer.php             # Data normalization utilities
```

### Flow Diagram

```
PDF File
    ↓
CreditReportParserService::parsePdfAndSave()
    ↓
IdentityIqFullParser::parseAndSave()
    ↓
├── parseCreditScores()
├── parsePersonalProfiles()
└── parseAccounts()
    ├── extractAccountFullDetails()
    │   ├── extractFromTable()         # Priority 1: Table format
    │   ├── extractFromInlineTable()   # Priority 2: Inline format
    │   ├── extractFromRawDataView()   # Priority 3: Raw data
    │   └── extractFromBracketedSection() # Priority 4: Bracketed
    └── createAccountItems()           # Create DB records
```

---

## Các Format PDF Được Hỗ Trợ

### 1. Combined 3-Bureau Format

Format phổ biến nhất, hiển thị tất cả 3 bureaus trong một bảng:

```
|   | TransUnion | Experian | Equifax  |
| Account Status: | Open | Open | Open  |
| Payment Status: | Current | Current | Current  |
| Balance: | $1,200 | $1,200 | $1,200  |
```

### 2. Per-Bureau Format

Mỗi bureau có section riêng:

```
TransUnion:
  Balance: $1,200
  Status: Open
  Payment Status: Current

Experian:
  Balance: $1,200
  Status: Open
  Payment Status: Current
```

### 3. Sample Format

Format đơn giản với dữ liệu inline:

```
Balance: $1,350.00 $1,150.00 $1,250.00
Status: Open Open Open
```

### 4. Raw Data View Format

Format với pipe separator:

```
TransUnion | PORTFOLIO RECOVERY | 99998888 | $900.00 | Collection | ...
```

### 5. Tabular Format với Header

Format có header rõ ràng:

```
BUREAU COMPARISON
| Item | TransUnion | Experian | Equifax |
| Balance | $1,200 | $1,200 | $1,200 |
```

---

## Cấu Trúc Code

### 1. IdentityIqFullParser.php

#### Main Methods

**`parseAndSave(Client $client, string $pdfPath, string $formatHint = 'auto')`**

- Entry point chính cho parsing
- Xử lý transaction để đảm bảo data consistency
- Parse và lưu: Credit Scores, Personal Profiles, Accounts

**`parseCreditScores(string $text)`**

- Extract credit scores từ 3 bureaus
- Hỗ trợ tabular format và inline format
- Return: `['transunion' => score, 'experian' => score, 'equifax' => score]`

**`parsePersonalProfiles(string $text)`**

- Extract personal information (name, DOB, address, employer)
- Hỗ trợ tabular format
- Return: Array of profile data per bureau

**`parseAccounts(string $text)`**

- Extract tất cả credit accounts
- Sử dụng multiple patterns để tìm accounts
- Return: Array of account data

**`extractAccountFullDetails(string $section, string $accountName, ?string $accountNumber)`**

- Extract chi tiết cho một account cụ thể
- Thử nhiều methods theo thứ tự ưu tiên:
  1. `extractFromTable()` - Table format (ưu tiên cao nhất)
  2. `extractFromInlineTable()` - Inline format
  3. `extractFromRawDataView()` - Raw data view
  4. `extractFromBracketedSection()` - Bracketed sections
  5. `extractDirectlyFromTableRows()` - Fallback cho table rows

**`createAccountItems(Client $client, array $accountData)`**

- Tạo CreditItem records cho mỗi bureau
- Đảm bảo tất cả 3 bureaus đều có records (kể cả khi không có data)
- Xử lý duplicate detection
- Normalize status và payment_status trước khi lưu

#### Extraction Methods

**`extractFromTable(string $section, string $bureau)`**

- Parse table format với `|` separator
- Hỗ trợ nhiều format:
  - `|   | TransUnion | Experian | Equifax  |`
  - `Account Status: | Open | Open | Open  |`
  - `| Account Status: | Open | Open | Open  |`
- Map field names: Account Status, Payment Status, Balance, etc.

**`extractFromInlineTable(string $section, string $bureau)`**

- Parse format: `Balance: $1,350.00 $1,150.00 $1,250.00`
- Xác định column index dựa trên bureau

**`extractFromRawDataView(string $section, string $bureau, string $accountName, ?string $accountNumber)`**

- Parse format: `TransUnion | ACCOUNT NAME | ACCOUNT# | $BALANCE | STATUS | REASON`
- Xử lý line breaks trong data

**`extractFromBracketedSection(string $section, string $bureau)`**

- Parse format: `[TransUnion Section] ... data ...`

**`extractDirectlyFromTableRows(string $section, string $bureau)`**

- Fallback method để extract trực tiếp từ table rows
- Sử dụng khi `extractFromTable()` không hoạt động
- Parse từng row với pattern: `Field: | value1 | value2 | value3`

### 2. DataNormalizer.php

**`normalizeBalance(string $value)`**

- Xử lý format: `$1,200.00`, `$1200`, `1200.00`
- Loại bỏ ký tự đặc biệt
- Convert về float

**`normalizeStatus(?string $status)`**

- Normalize status values: Open, Closed, Paid, etc.
- Xử lý case-insensitive matching
- Return standardized values

**`normalizePaymentStatus(?string $status)`**

- Normalize payment status: Current, Late 30 Days, Collection, etc.
- Phân biệt với Account Status

---

## Các Vấn Đề Đã Gặp Và Giải Pháp

### 1. Account Names Bị Cắt Ngắn

**Vấn đề:** Account names như "CHASE BANK USA" chỉ lấy được "CHAS"

**Nguyên nhân:** Regex pattern sử dụng non-greedy quantifier (`?`)

**Giải pháp:**

- Loại bỏ non-greedy quantifier trong pattern
- Sử dụng pattern: `/(\d+)\.\s+([A-Z][A-Z\s&.,\-()]+?)(?:\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+))?/i`
- Đảm bảo capture full account name trước khi match account number

### 2. Balance Hiển Thị $0.00

**Vấn đề:** Balance hiển thị $0.00 thay vì giá trị thực

**Nguyên nhân:**

- Không parse được inline table format: `Balance: $X $Y $Z`
- Regex không handle commas trong số

**Giải pháp:**

- Implement `extractFromInlineTable()` method
- Cải thiện `normalizeBalance()` để handle commas
- Pattern: `/(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/`

### 3. Credit Limit (High Limit) Sai

**Vấn đề:** Credit limit hiển thị "$5.00" thay vì "$5,000"

**Nguyên nhân:** Regex không capture đầy đủ số có commas

**Giải pháp:**

- Cải thiện regex pattern trong `extractAccountFullDetails()`
- Sử dụng `normalizeBalance()` để xử lý commas
- Pattern: `/\$?([\d,]+\.?\d*)/`

### 4. Duplicate Accounts

**Vấn đề:** Tạo nhiều records cho cùng một account

**Nguyên nhân:** Duplicate check không đầy đủ

**Giải pháp:**

- Cải thiện duplicate check trong `createAccountItems()`
- Check cả `account_name` và `account_number`
- Sử dụng `where()` với closure để check multiple conditions

### 5. Status Hiển Thị Nhiều Lần

**Vấn đề:** Status field có giá trị như "Open Open Open"

**Nguyên nhân:** Không normalize status trước khi lưu

**Giải pháp:**

- Sử dụng `DataNormalizer::normalizeStatus()` trước khi lưu
- Đảm bảo chỉ lưu một giá trị status duy nhất

### 6. Tabular Format Parsing Không Đúng

**Vấn đề:** Không parse được table format với `|` separator

**Nguyên nhân:** Pattern không match đúng format

**Giải pháp:**

- Cải thiện `extractFromTable()` method
- Hỗ trợ nhiều pattern:
  - `^\s*([^|:]+?):\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)(?:\s*\||\s*$)/i`
  - Handle leading/trailing `|`
  - Skip separator rows và header rows

### 7. Account Names Có "Revolving" và "Auto Loan"

**Vấn đề:** "Revolving" và "Auto Loan" được parse như account names

**Nguyên nhân:** Không có blacklist cho account types

**Giải pháp:**

- Thêm blacklist trong `parseAccounts()`:
  ```php
  $blacklist = [
   'REVOLVING', 'AUTO LOAN', 'INSTALLMENT',
   'CREDIT CARD', 'COLLECTION AGENCY', etc.
  ];
  ```
- Check nếu account name quá ngắn hoặc là common type thì skip

### 8. Status và Payment Status Không Được Extract

**Vấn đề:** Status và Payment Status không được extract từ table format

**Nguyên nhân:**

- `extractFromTable()` không được ưu tiên
- Pattern matching không đúng

**Giải pháp:**

- Ưu tiên `extractFromTable()` trong `extractAccountFullDetails()`
- Cải thiện pattern để match: `Account Status: | Open | Open | Open`
- Thêm `extractDirectlyFromTableRows()` như fallback
- Map field names đúng: "Account Status" → status, "Payment Status" → payment_status

### 10. Nhầm Lẫn Giữa Các Cột (Column Index Calculation)

**Vấn đề:** Account Status, Payment Status, Balance, High Limit bị nhầm lẫn giữa các cột

**Nguyên nhân:**

- Format PDF có empty first column: `|   | TransUnion | Experian | Equifax  |`
- Data rows: `Account Status: | Open | Open | Open  |`
- Column index calculation không đúng
- Pattern matching không handle đúng format với/không có leading `|`

**Giải pháp:**

- Detect empty first column trong header row
- Sử dụng nhiều patterns để match:
  - Pattern 1: `| Account Status: | Open | Open | Open  |` (có leading `|`)
  - Pattern 2: `Account Status: | Open | Open | Open  |` (không có leading `|`)
- Column index calculation:
  - TransUnion (index 0) → cells[2]
  - Experian (index 1) → cells[3]
  - Equifax (index 2) → cells[4]
- ValueIndex = ColumnIndex + 2 (vì cells[0]=match, cells[1]=field name)
- Thêm logging để debug column extraction

### 9. Equifax Thiếu Accounts

**Vấn đề:** Equifax chỉ có 1 account trong khi có nhiều accounts

**Nguyên nhân:**

- Logic trong `createAccountItems()` bỏ qua nếu không có `bureau_data`
- Không tạo items cho bureaus không có explicit data

**Giải pháp:**

- Sửa logic trong `createAccountItems()` để luôn tạo items cho tất cả 3 bureaus
- Sử dụng general `accountData` như fallback nếu không có `bureau_data`
- Đảm bảo tất cả accounts xuất hiện cho tất cả bureaus (kể cả với empty data)

---

## Best Practices

### 1. Text Normalization

Luôn normalize text trước khi parse:

```php
// Remove excessive line breaks
$text = preg_replace('/\n{3,}/', "\n\n", $text);
// Normalize spaces around line breaks
$text = preg_replace('/\s*\n\s*/', "\n", $text);
```

### 2. Multiple Extraction Methods

Luôn thử nhiều methods theo thứ tự ưu tiên:

1. Table format (most reliable)
2. Inline format
3. Raw data view
4. Bracketed sections
5. Direct row extraction (fallback)

### 3. Data Validation

Validate và normalize data trước khi lưu:

```php
$normalizedStatus = $this->normalizer->normalizeStatus($status);
$normalizedPaymentStatus = $this->normalizePaymentStatus($paymentStatus);
```

### 4. Error Handling

Sử dụng try-catch và logging:

```php
try {
// Parse logic
} catch (\Exception $e) {
Log::warning("Failed to parse: " . $e->getMessage());
// Continue with other data
}
```

### 5. Transaction Management

Sử dụng database transaction:

```php
DB::beginTransaction();
try {
// Save data
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

### 6. Duplicate Detection

Luôn check duplicate trước khi tạo records:

```php
$exists = CreditItem::where('client_id', $client->id)
->where('bureau', $bureau)
->where('account_name', $accountName)
->where('account_number', $accountNumber)
->exists();
```

### 7. Logging

Log đầy đủ để debug:

```php
Log::info("Extracted account: {$accountName}");
Log::debug("Table extraction - Field: {$fieldName}, Bureau: {$bureau}, Value: {$value}");
Log::warning("Could not extract bureau data for {$bureau}");
```

---

## Hướng Dẫn Debug

### 1. Enable Logging

Kiểm tra `storage/logs/laravel.log` để xem:

- Accounts được extract
- Bureau data được tìm thấy
- Errors và warnings

### 2. Debug Specific Account

Thêm logging trong `extractAccountFullDetails()`:

```php
Log::debug("Extracting account: {$accountName} (#{$accountNumber})");
Log::debug("Account section: " . substr($accountSection, 0, 500));
```

### 3. Debug Table Extraction

Thêm logging trong `extractFromTable()`:

```php
Log::debug("Table extraction - Field: {$fieldName}, Bureau: {$bureau}, Value: {$value}");
Log::debug("All cells: [TU={$cells[2]}, EXP={$cells[3]}, EQ={$cells[4]}]");
```

### 4. Test với Sample PDF

1. Upload PDF vào admin panel
2. Check logs trong `storage/logs/laravel.log`
3. Verify data trong database
4. Compare với PDF gốc

### 5. Common Issues Checklist

- [ ] Account names có đúng không?
- [ ] Balance có đúng không?
- [ ] Status và Payment Status có được extract không?
- [ ] Tất cả 3 bureaus có data không?
- [ ] Có duplicate accounts không?
- [ ] Account types có bị parse như account names không?

---

## Lưu Ý Quan Trọng

### 1. Account Name Blacklist

**QUAN TRỌNG:** Luôn check blacklist trước khi tạo account:

- "Revolving", "Auto Loan", "Installment" không phải account names
- "CREDIT ACCOUNTS", "TRADE LINES" là headers, không phải accounts

### 2. Status vs Payment Status

**QUAN TRỌNG:** Phân biệt rõ:

- **Account Status:** Open, Closed, Paid (trạng thái account)
- **Payment Status:** Current, Late 30 Days, Collection (trạng thái thanh toán)

Map đúng trong `extractFromTable()`:

```php
if (stripos($fieldName, 'payment status') !== false) {
    $bureauData['payment_status'] = trim($value);
} elseif (stripos($fieldName, 'account status') !== false) {
    $bureauData['status'] = trim($value);
}
```

### 3. Table Format Priority

**QUAN TRỌNG:** Luôn ưu tiên table format:

- Table format là format phổ biến nhất và đáng tin cậy nhất
- Nếu detect được table format, sử dụng nó trước các format khác

### 4. Bureau Data Fallback

**QUAN TRỌNG:** Đảm bảo tất cả bureaus có records:

- Nếu không có `bureau_data` cho một bureau, vẫn tạo record với general data
- Điều này đảm bảo tất cả accounts xuất hiện cho tất cả bureaus

### 5. Column Index Calculation

**QUAN TRỌNG:** Tính đúng column index:

- TransUnion = index 0 → cells[2] (nếu có empty first column) hoặc cells[1]
- Experian = index 1 → cells[3] hoặc cells[2]
- Equifax = index 2 → cells[4] hoặc cells[3]

### 6. Pattern Matching

**QUAN TRỌNG:** Sử dụng non-greedy quantifiers khi cần:

- `([^|]+?)` thay vì `([^|]+)` để tránh capture quá nhiều
- Nhưng không dùng cho account names (cần capture full name)

### 7. Date Parsing

**QUAN TRỌNG:** Xử lý nhiều format date:

- `01/15/2020` (MM/DD/YYYY)
- `2020-01-15` (YYYY-MM-DD)
- `Jan 15, 2020` (Text format)

---

## Các Thay Đổi Quan Trọng

### Version 1.0 (Initial)

- Basic PDF parsing
- Support tabular format
- Extract credit scores, profiles, accounts

### Version 1.1 (Account Name Fix)

- Fix account names bị cắt ngắn
- Improve regex patterns
- Better duplicate detection

### Version 1.2 (Balance Fix)

- Fix balance extraction
- Support inline table format
- Improve normalizeBalance()

### Version 1.3 (Status Fix)

- Fix status và payment status extraction
- Improve extractFromTable()
- Add extractDirectlyFromTableRows()

### Version 1.4 (Account Type Blacklist)

- Add blacklist cho account types
- Prevent "Revolving", "Auto Loan" được parse như account names
- Improve account name validation

### Version 1.5 (Equifax Fix)

- Fix missing Equifax accounts
- Ensure all bureaus have records
- Improve createAccountItems() logic

### Version 1.6 (Current)

- Complete table format support
- Multiple extraction methods với priority
- Comprehensive error handling và logging
- Full support cho tất cả format PDF

### Version 1.7 (Column Index Fix)

- Fix column index calculation cho table format
- Handle empty first column trong header row
- Improve pattern matching để handle cả format có/không có leading `|`
- Fix nhầm lẫn giữa các cột (Account Status, Payment Status, Balance, High Limit)
- Enhanced logging để debug column extraction

---

## Testing Checklist

Khi test parser, verify:

- [ ] Credit Scores được extract đúng cho 3 bureaus
- [ ] Personal Profiles được extract đúng
- [ ] Tất cả accounts được extract (không bỏ sót)
- [ ] Account names đúng (không có "Revolving", "Auto Loan")
- [ ] Balance đúng (không có $0.00 sai)
- [ ] Credit Limit đúng (không có $5.00 thay vì $5,000)
- [ ] Status được extract và normalize đúng
- [ ] Payment Status được extract và normalize đúng
- [ ] Tất cả 3 bureaus có records cho mỗi account
- [ ] Không có duplicate accounts
- [ ] Date formats được parse đúng
- [ ] Special characters được handle đúng

---

## Future Improvements

### 1. Machine Learning

- Sử dụng ML để detect format tự động
- Improve accuracy của extraction

### 2. OCR Support

- Support cho scanned PDFs
- Extract từ images

### 3. More Format Support

- Support thêm các format khác từ các providers khác
- Generic parser cho multiple providers

### 4. Performance Optimization

- Cache parsed results
- Parallel processing cho multiple accounts
- Optimize regex patterns

### 5. Better Error Recovery

- Auto-retry với different methods
- Suggest fixes cho common errors
- Better error messages

---

## Contact & Support

Nếu gặp vấn đề với PDF parsing:

1. Check logs trong `storage/logs/laravel.log`
2. Verify PDF format matches supported formats
3. Test với sample PDFs
4. Check database records
5. Review code changes trong version history

---

**Last Updated:** 2025-01-XX
**Version:** 1.6
**Maintainer:** Development Team
