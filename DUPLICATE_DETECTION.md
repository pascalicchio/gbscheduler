# Duplicate Detection System

## Overview
The ZenPlanner upload system includes smart duplicate detection to prevent importing the same data multiple times. This is especially important for weekly/monthly uploads where team members might accidentally re-upload the same file.

## How It Works

### Revenue/Payments ✅ **PROTECTED**
**Duplicate Key:** `receipt_number` (from ZenPlanner)

- Every payment has a unique Receipt Number (e.g., "52505")
- The system checks if this receipt number already exists before importing
- If found → **Skips the payment** (no duplicate created)
- If not found → **Imports the payment**

**Result:** You can safely re-upload the same payment file multiple times - duplicates are automatically skipped!

### Cancellations ⚠️ **NOT PROTECTED**
- Cancellations are imported as-is
- No automatic duplicate detection
- **Best Practice:** Only upload NEW cancellations each month

### Members ✅ **UPDATES EXISTING**
**Duplicate Key:** `member_id`

- If member_id already exists → **Updates** the existing record
- If member_id is new → **Inserts** new member
- This keeps member information current (status changes, email updates, etc.)

## Example Scenario

### Week 1: You upload `payments-week1.csv`
- 100 new payments imported
- Receipt numbers: 52001-52100

### Week 2: Team member accidentally uploads `payments-week1.csv` again
- System checks each receipt number
- Finds 52001-52100 already exist
- **Skips all 100 payments**
- Shows: "Successfully imported 0 records from 100 rows"

### Week 2: You upload `payments-week2.csv` (NEW payments)
- 120 payments in file
- Receipt numbers: 52101-52220
- System finds none exist
- **Imports all 120 new payments**
- Shows: "Successfully imported 120 records from 120 rows"

## Upload History Tracking

Every upload is logged in `gb_upload_history`:
- Who uploaded (user_id)
- When (upload_date)
- What location
- What data type
- How many rows imported

This helps you:
- ✅ See if data was already uploaded
- ✅ Track who uploaded what
- ✅ Identify accidental duplicate attempts
- ✅ Audit data imports

## Best Practices

### ✅ DO:
- Upload weekly or monthly payment exports from ZenPlanner
- Re-run uploads if unsure (duplicates are skipped)
- Check upload history before importing
- Export only NEW data when possible

### ❌ DON'T:
- Manually modify Receipt Numbers in CSV files
- Upload the same cancellations file multiple times
- Delete upload history (it's your audit trail)

## Technical Details

### Revenue Table Structure:
```sql
CREATE TABLE gb_revenue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,  ← Prevents duplicates!
    member_name VARCHAR(255) NOT NULL,
    payment_date DATE NOT NULL,
    payment_time TIME,
    amount DECIMAL(10,2) NOT NULL,
    payment_type VARCHAR(50),
    payment_method VARCHAR(50),
    status VARCHAR(50),
    location VARCHAR(50) NOT NULL,
    ...
);
```

### Duplicate Check Code:
```php
// Check if receipt already exists
$checkStmt = $pdo->prepare("SELECT id FROM gb_revenue WHERE receipt_number = ?");
$checkStmt->execute([$receiptNumber]);

if ($checkStmt->fetchColumn()) {
    // Skip duplicate
    continue;
}

// Insert new payment
$stmt = $pdo->prepare("INSERT INTO gb_revenue ...");
```

## Database Performance

With duplicate detection:
- **Celebration (400 payments/month):** ~5 seconds to check and import
- **Davenport (900 payments/month):** ~10 seconds to check and import
- **Re-uploading duplicates:** ~2 seconds (all skipped quickly)

The `UNIQUE` constraint on `receipt_number` provides database-level protection even if application logic fails.

## Monitoring Duplicates

Check for duplicate attempts in upload history:

```sql
SELECT
    location,
    data_type,
    filename,
    rows_imported,
    upload_date,
    CASE
        WHEN rows_imported = 0 THEN 'All duplicates (skipped)'
        WHEN rows_imported < 100 THEN 'Partial duplicates'
        ELSE 'All new data'
    END as result_type
FROM gb_upload_history
WHERE data_type = 'revenue'
ORDER BY upload_date DESC;
```

---

**Summary:** The system is designed to handle duplicate uploads gracefully. Receipt numbers are your protection against accidentally importing the same payments multiple times! 🛡️
