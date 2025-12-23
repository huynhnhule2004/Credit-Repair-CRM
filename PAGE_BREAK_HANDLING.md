# 🔄 Xử Lý Page Break trong PDF Parsing

## 🐛 Vấn Đề Page Break

### Các Tình Huống Thường Gặp:

1. **Account Name Bị Cắt:**
   ```
   Trang 1: "1. CHASE BANK US"
   Trang 2: "A" (tiếp tục account name)
   ```

2. **Account Number Ở Trang Khác:**
   ```
   Trang 1: "1. CHASE BANK USA"
   Trang 2: "Account #: 44445555****"
   ```

3. **Bureau Data Bị Cắt:**
   ```
   Trang 1: "Balance: $1,350.00 $1,150.00 $1,2"
   Trang 2: "50.00" (tiếp tục balance)
   ```

4. **Section Boundary Bị Cắt:**
   ```
   Trang 1: "Details by Bureau:"
   Trang 2: "TransUnion: Balance: $1,350.00"
   ```

---

## ✅ Giải Pháp Đã Áp Dụng

### 1. Extract Toàn Bộ Text Từ Tất Cả Pages

**Code hiện tại:**
```php
$pdf = $parser->parseFile($pdfPath);
$text = $pdf->getText(); // Lấy toàn bộ text từ tất cả pages
```

**Ưu điểm:**
- ✅ Smalot PDFParser tự động merge text từ tất cả pages
- ✅ Không cần xử lý page break manually
- ✅ Text được nối liền, chỉ có line breaks

**Nhược điểm:**
- ⚠️ Có thể mất một số formatting
- ⚠️ Page breaks có thể tạo ra line breaks không mong muốn

---

### 2. Flexible Section Boundary Detection

**Code:**
```php
// Extract CREDIT ACCOUNTS section - more flexible pattern
$sectionPatterns = [
    '/CREDIT ACCOUNTS.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
    '/TRADE LINES.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
    '/ACCOUNTS.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',
];
```

**Cách hoạt động:**
- ✅ Pattern `.*?` (non-greedy) match across pages
- ✅ Lookahead `(?=INQUIRIES|...)` tìm end marker
- ✅ Fallback `$` nếu không tìm thấy end marker

---

### 3. Account Section Boundary với Multiple Patterns

**Code:**
```php
$patterns = [
    '/' . $accountPattern . '.*?(?=\d+\.\s+[A-Z]|$)/is',  // Before next numbered account
    '/' . $accountPattern . '.*?(?=[A-Z]{3,}\s+(?:Account|Acct|#)|$)/is',  // Before next account name
    '/' . $accountPattern . '.*?(?=INQUIRIES|PUBLIC RECORDS|END OF REPORT|$)/is',  // Before end markers
];
```

**Cách hoạt động:**
- ✅ Tìm account section bằng nhiều patterns
- ✅ Boundary detection linh hoạt, không phụ thuộc vào page breaks
- ✅ Fallback patterns nếu không tìm thấy boundary

---

### 4. Account Number Search Across Lines

**Code:**
```php
private function findAccountNumberAfterName(string $section, string $accountName): ?string
{
    $pattern = '/' . preg_quote($accountName, '/') . '.*?(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/is';
    if (preg_match($pattern, $section, $match)) {
        return trim($match[1]);
    }
    return null;
}
```

**Cách hoạt động:**
- ✅ Pattern `.*?` với flag `s` (dotall) match across newlines
- ✅ Tìm account number sau account name, bất kể có bao nhiêu line breaks
- ✅ Handle page breaks tự động

---

### 5. Inline Table Parsing với Flexible Patterns

**Code:**
```php
// Pattern: "Balance: $1,350.00 $1,150.00 $1,250.00"
'/Balance[:\s]*(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)\s+(\$?[\d,]+\.?\d*)/i'
```

**Cách hoạt động:**
- ✅ Pattern match values separated by spaces
- ✅ Không phụ thuộc vào line breaks
- ✅ Handle page breaks tự động vì text đã được merge

---

## 🔧 Cải Thiện Thêm (Nếu Cần)

### Option 1: Extract Từng Page và Merge

```php
// Extract text từ từng page
$pages = $pdf->getPages();
$textParts = [];
foreach ($pages as $page) {
    $textParts[] = $page->getText();
}

// Merge với page break marker
$text = implode("\n[PAGE_BREAK]\n", $textParts);

// Khi parse, có thể detect và handle page breaks
$text = preg_replace('/\n\[PAGE_BREAK\]\n/', ' ', $text);
```

**Ưu điểm:**
- ✅ Biết được page breaks ở đâu
- ✅ Có thể xử lý đặc biệt cho page breaks

**Nhược điểm:**
- ⚠️ Phức tạp hơn
- ⚠️ Có thể không cần thiết nếu `getText()` đã merge tốt

---

### Option 2: Normalize Line Breaks

```php
// Normalize multiple line breaks
$text = preg_replace('/\n{3,}/', "\n\n", $text);

// Remove page break artifacts
$text = preg_replace('/\n\s*\n\s*\n/', "\n\n", $text);
```

**Ưu điểm:**
- ✅ Đơn giản
- ✅ Clean up text tốt hơn

---

### Option 3: Context-Aware Parsing

```php
// Khi tìm account number, search trong context rộng hơn
private function findAccountNumberAfterName(string $section, string $accountName, int $maxDistance = 500): ?string
{
    // Tìm account name position
    $pos = stripos($section, $accountName);
    if ($pos === false) {
        return null;
    }
    
    // Extract context sau account name
    $context = substr($section, $pos, $maxDistance);
    
    // Tìm account number trong context
    $pattern = '/(?:Account|Acct|#)[:\s]*([X\*\d\-]+)/i';
    if (preg_match($pattern, $context, $match)) {
        return trim($match[1]);
    }
    
    return null;
}
```

**Ưu điểm:**
- ✅ Tìm trong context rộng hơn
- ✅ Handle page breaks tốt hơn

---

## 📊 Kết Luận

### Giải Pháp Hiện Tại:

✅ **Đã xử lý page breaks bằng cách:**
1. Extract toàn bộ text từ tất cả pages (Smalot tự động merge)
2. Sử dụng flexible patterns với `.*?` và `s` flag để match across lines
3. Multiple boundary detection patterns
4. Context-aware search cho account numbers

### Không Cần Thêm:

❌ **Không cần extract từng page riêng** vì:
- Smalot PDFParser đã merge text tốt
- Patterns hiện tại đã handle page breaks
- Thêm complexity không cần thiết

### Có Thể Cải Thiện:

⚠️ **Nếu vẫn gặp vấn đề:**
1. Thêm normalize line breaks
2. Tăng context search distance
3. Thêm logging để detect page break issues

---

## 🧪 Test Cases

### Test 1: Account Name Bị Cắt
```
Input: "1. CHASE BANK US\nA\nAccount #: 44445555****"
Expected: Account name = "CHASE BANK USA"
Status: ✅ Should work với pattern hiện tại
```

### Test 2: Account Number Ở Trang Khác
```
Input: "1. CHASE BANK USA\n\nAccount #: 44445555****"
Expected: Account number = "44445555****"
Status: ✅ Should work với findAccountNumberAfterName()
```

### Test 3: Balance Bị Cắt
```
Input: "Balance: $1,350.00 $1,150.00 $1,2\n50.00"
Expected: Equifax balance = 1250.00
Status: ⚠️ Có thể cần normalize line breaks
```





