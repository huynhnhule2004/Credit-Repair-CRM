# 🔍 Phân Tích Lỗi Parsing từ PDF Thực Tế

## 📋 Các Lỗi Phát Hiện

### 1. ❌ Account Names Bị Cắt Ngắn
**Vấn đề:**
- "CHASE BANK USA" → hiển thị "CHAS"
- "MIDLAND CREDIT MANAGEMENT" → hiển thị "MIDL"  
- "WELLS FARGO DEALER SERVICES" → hiển thị "WELL"

**Nguyên nhân:**
- Pattern `([A-Z][A-Z\s&]{3,}?)` có `?` (non-greedy) làm nó dừng sớm
- Pattern không match với account name có nhiều từ

**Fix cần:**
- Bỏ `?` hoặc dùng pattern khác để lấy full name
- Pattern nên match đến khi gặp "Account #" hoặc dòng mới

---

### 2. ❌ Balance Hiển Thị $0.00 Thay Vì Giá Trị Thực
**Vấn đề:**
- CHASE BANK: PDF có $1,350.00 (TU), $1,150.00 (EXP), $1,250.00 (EQ) nhưng table hiển thị $0.00
- MIDLAND: PDF có $2,500.00/$2,550.00 nhưng có thể hiển thị sai

**Nguyên nhân:**
- Tabular format parsing không đúng
- CHASE BANK có format:
  ```
  |  TransUnion | Experian | Equifax  |
  Balance: $1,350.00 $1,150.00 $1,250.00
  ```
  - Không phải format "Item | TU | EXP | EQ" mà là inline format
- Extract không match được pattern

**Fix cần:**
- Thêm pattern cho inline tabular format
- Parse: "Balance: $X $Y $Z" → extract theo vị trí

---

### 3. ❌ Credit Limit (High Limit) Sai
**Vấn đề:**
- Equifax hiển thị "Credit Limit: $5.00" thay vì $5,000
- PDF có "High Limit: $5,000"

**Nguyên nhân:**
- Regex có thể match sai: `\$?([\d,]+\.?\d*)` có thể bắt "$5" thay vì "$5,000"
- Hoặc normalize balance sai khi có comma

**Fix cần:**
- Kiểm tra normalize balance với comma
- Đảm bảo regex match đầy đủ số với comma

---

### 4. ❌ Duplicate Accounts
**Vấn đề:**
- "Auto Loan" account xuất hiện 2 lần trong table
- Có thể do parse tạo duplicate

**Nguyên nhân:**
- Pattern matching có thể match cùng account nhiều lần
- Deduplication không hoạt động đúng

**Fix cần:**
- Cải thiện duplicate check
- Check bằng account_number + account_name

---

### 5. ❌ Status Hiển Thị Nhiều Lần
**Vấn đề:**
- "Current" hiển thị 3 lần stacked trong một cell

**Nguyên nhân:**
- Có thể do data structure issue
- Hoặc Filament table render issue

**Fix cần:**
- Kiểm tra data khi save
- Đảm bảo status chỉ có 1 giá trị

---

### 6. ❌ Tabular Format Parsing Không Đúng
**Vấn đề:**
- CHASE BANK có format inline:
  ```
  Balance: $1,350.00 $1,150.00 $1,250.00
  High Limit: $5,000 $5,000 $5,000
  Pay Status: Current Current Current
  ```
- WELLS FARGO có format table:
  ```
  |  Item | TransUnion | Experian | Equifax  |
  |  Balance | $15,400.00 | $15,400.00 | $15,400.00  |
  ```

**Nguyên nhân:**
- Chỉ handle format table với `|` separator
- Không handle inline format với space-separated values

**Fix cần:**
- Thêm method extract từ inline format
- Parse theo thứ tự: TransUnion, Experian, Equifax

---

## ✅ Các Fix Đã Thực Hiện

### Fix 1: Account Name Pattern ✅
**File:** `app/Services/IdentityIqFullParser.php`
**Dòng:** ~303
- ✅ Removed non-greedy `?` từ pattern `([A-Z][A-Z\s&]{3,}?)`
- ✅ Changed to `([A-Z][A-Z\s&]+?)` để lấy full account name
- ✅ Pattern giờ match: "1. CHASE BANK USA" thay vì chỉ "1. CHAS"

### Fix 2: Inline Tabular Format ✅
**File:** `app/Services/IdentityIqFullParser.php`
**Method:** `extractFromInlineTable()` (mới)
- ✅ Thêm method mới để extract từ format: "Balance: $1,350.00 $1,150.00 $1,250.00"
- ✅ Extract theo vị trí: TransUnion=0, Experian=1, Equifax=2
- ✅ Handle: Balance, High Limit, Pay Status, Monthly Pay, Comments
- ✅ Normalize numeric values trước khi return

### Fix 3: High Limit Normalization ✅
**File:** `app/Services/IdentityIqFullParser.php`
**Dòng:** ~612-625
- ✅ Thêm multiple patterns cho High Limit extraction
- ✅ Đảm bảo remove comma trước khi normalize: "$5,000" → 5000"
- ✅ Normalize đúng: không còn "$5.00" thay vì "$5,000"

### Fix 4: Duplicate Detection ✅
**File:** `app/Services/IdentityIqFullParser.php`
**Method:** `createAccountItems()` - dòng ~831-839
- ✅ Fix logic duplicate check
- ✅ Check bằng cả account_name VÀ account_number
- ✅ Nếu account_number null, chỉ check bằng account_name
- ✅ Prevent duplicate entries trong database

### Fix 5: Status Normalization ✅
**File:** `app/Services/IdentityIqFullParser.php`
**Method:** `createAccountItems()` - dòng ~841
- ✅ Normalize status trước khi save
- ✅ Prevent multiple "Current" values stacked
- ✅ Use `DataNormalizer::normalizeStatus()` để chuẩn hóa

### Fix 6: Balance Extraction ✅
**File:** `app/Services/IdentityIqFullParser.php`
**Method:** `extractAccountFullDetails()` - dòng ~566-576
- ✅ Improved balance extraction patterns
- ✅ Handle comma trong balance: "$1,350.00" → 1350.0
- ✅ Extract từ cả inline table và standard format

---

## 🔧 Các Fix Cần Thực Hiện (Đã Hoàn Thành)

### Fix 1: Account Name Pattern
```php
// Trước (sai):
$pattern1 = '/(\d+)\.\s+([A-Z][A-Z\s&]{3,}?)(?:\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+))?/i';

// Sau (đúng):
$pattern1 = '/(\d+)\.\s+([A-Z][A-Z\s&]+?)(?:\s+(?:Account|Acct|#)[:\s]*([X\*\d\-]+))?/i';
// Hoặc tốt hơn:
$pattern1 = '/(\d+)\.\s+([A-Z][A-Z\s&]+?)(?:\s+Account\s*#|$)/i';
```

### Fix 2: Inline Tabular Format
```php
// Thêm method mới:
private function extractFromInlineTable(string $section, string $bureau): string
{
    // Pattern: "Balance: $1,350.00 $1,150.00 $1,250.00"
    // Extract theo vị trí: TU=0, EXP=1, EQ=2
    $bureauIndex = ['TransUnion' => 0, 'Experian' => 1, 'Equifax' => 2];
    $index = $bureauIndex[$bureau] ?? null;
    
    if ($index === null) return '';
    
    // Match: "Balance: $X $Y $Z"
    if (preg_match('/Balance[:\s]*(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)/i', $section, $match)) {
        return "Balance: " . $match[$index + 1];
    }
    
    // Tương tự cho High Limit, Pay Status, etc.
}
```

### Fix 3: High Limit Normalization
```php
// Đảm bảo normalize balance handle comma đúng:
public function normalizeBalance($balance): float
{
    // Remove $ và spaces
    $balance = preg_replace('/[^\d.,\-]/', '', (string)$balance);
    
    // Handle comma: $5,000 → 5000
    $balance = str_replace(',', '', $balance);
    
    return (float) $balance;
}
```

### Fix 4: Improve Duplicate Detection
```php
// Check duplicate bằng cả account_name và account_number
$exists = CreditItem::where('client_id', $client->id)
    ->where('bureau', $bureau)
    ->where(function($query) use ($accountName, $accountNumber) {
        $query->where('account_name', $accountName)
              ->where('account_number', $accountNumber);
    })
    ->exists();
```

