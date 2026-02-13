# ZenPlanner Data Management Setup Guide

This guide explains how to set up and use the ZenPlanner data management features in your GB Scheduler panel.

## 1. Database Setup

First, you need to create the database tables. Run the migration SQL file:

### Option A: Using MySQL Command Line
```bash
mysql -u root -p gbscheduler_mma < migrations/005_add_zenplanner_tables.sql
```

### Option B: Using phpMyAdmin or MySQL Workbench
1. Open your database tool
2. Select the `gbscheduler_mma` database
3. Open and execute the file: `migrations/005_add_zenplanner_tables.sql`

### Option C: Using Herd
If you're using Laravel Herd, you can use the built-in database tools or run:
```bash
cd /Users/fillipe/Herd/gb-scheduler2
mysql -h 127.0.0.1 -u root -pevery1000 gbscheduler_mma < migrations/005_add_zenplanner_tables.sql
```

## 2. Database Tables Created

The migration creates 4 tables:

1. **gb_members** - Member information
   - member_id (Primary Key)
   - name, email, status, join_date, location

2. **gb_revenue** - Revenue/payment transactions
   - transaction_id (Primary Key)
   - member_id, amount, payment_date, location

3. **gb_cancellations** - Membership cancellations
   - id (Auto-increment Primary Key)
   - member_id, member_name, cancellation_date, reason, location

4. **gb_upload_history** - Upload tracking
   - id, user_id, location, data_type, filename, rows_imported, upload_date

## 3. Exporting Data from ZenPlanner

### Members Export
Export the following columns from ZenPlanner:
- **Column 1:** Member ID
- **Column 2:** Name
- **Column 3:** Email
- **Column 4:** Status (active, inactive, hold, etc.)
- **Column 5:** Join Date (YYYY-MM-DD format)

**Example CSV:**
```csv
member_id,name,email,status,join_date
ZP001,John Smith,john@example.com,active,2024-01-15
ZP002,Jane Doe,jane@example.com,active,2024-02-01
ZP003,Bob Wilson,bob@example.com,inactive,2023-12-10
```

### Revenue Export
Export the following columns:
- **Column 1:** Transaction ID
- **Column 2:** Member ID
- **Column 3:** Amount (numeric, no currency symbols)
- **Column 4:** Payment Date (YYYY-MM-DD format)

**Example CSV:**
```csv
transaction_id,member_id,amount,payment_date
TXN001,ZP001,150.00,2024-02-01
TXN002,ZP002,150.00,2024-02-01
TXN003,ZP001,150.00,2024-01-01
```

### Cancellations Export
**Good News!** The cancellations CSV can be exported directly from ZenPlanner without modification. The system automatically handles ZenPlanner's export format.

ZenPlanner exports these columns (all are automatically processed):
- Cancel Date, Reason, Cancelled By, Reason (sub), Comments
- First Name, Last Name, Company, Trial Member?, Email Address
- Phone Number, Address, Status, Membership Label, Membership Category

**You don't need to reformat this CSV - just export and upload!**

**Example ZenPlanner CSV:**
```csv
"Cancel Date","Reason","Cancelled By","Reason (sub)","Comments","First Name","Last Name","Company","Trial Member?","Email Address","Phone Number","Address","Status","Membership Label","Membership Category"
"Jan 3, 2026","TRANSFER POLICY","Staff","","","Maximus","Alevato","","No","taraalevato@gmail.com","+14077339672","3918 Brookmyra Dr., Orlando, FL 32837","Alumni","BJJ FAMILY GOLD","ADULTS/TEENS/KIDS MEMBERSHIP"
```

## 4. Uploading Data

### Access the Upload Page
1. Log in to GB Scheduler as a **Manager** or **Admin**
2. Click the **Menu** button
3. Select **Upload ZenPlanner Data**

### Upload Process
1. **Select Location:** Choose "Davenport" or "Celebration"
2. **Select Data Type:** Choose "Members", "Revenue", or "Cancellations"
3. **Choose CSV File:** Click the file input and select your CSV file
4. **Click Upload & Import**

### Important Notes
- The first row of your CSV must contain column headers
- Data will be automatically matched to the correct location
- Duplicate entries will be updated (for members and revenue)
- Upload history is tracked and displayed on the page

## 5. Viewing the Dashboard

### Access the School Dashboard
1. Log in as an **Admin** (only admins can view this page)
2. Click the **Menu** button
3. Select **School Dashboard**

### Dashboard Features
The dashboard shows both locations side-by-side with:

- **Active Members:** Total active members at each location
- **Revenue (30 days):** Total revenue for the last 30 days
- **Cancellations (30 days):** Number of cancellations in the last 30 days
- **Trend Indicators:** Revenue change compared to previous 30 days
- **New Members:** Members who joined in the last 7 days
- **Retention Rate:** Calculated as (1 - cancellations/active members) × 100
- **Member Status Breakdown:** Count of members by status (active, inactive, etc.)
- **Top Cancellation Reasons:** Most common reasons for cancellations

## 6. User Permissions

### Manager & Admin Access
- Upload ZenPlanner Data ✓
- View upload history ✓
- School Dashboard (Admin only)

### Regular User/Coach Access
- No access to ZenPlanner features ✗

## 7. Troubleshooting

### Upload Issues

**Problem:** "Invalid location selected"
- **Solution:** Make sure you selected either "davenport" or "celebration"

**Problem:** "Invalid data type selected"
- **Solution:** Choose Members, Revenue, or Cancellations from the dropdown

**Problem:** "Please upload a CSV file"
- **Solution:** Ensure your file has a .csv extension

**Problem:** "Import failed: Duplicate entry"
- **Solution:** This is normal for members/revenue - data will be updated. For cancellations, check for duplicate cancellation records.

**Problem:** "Foreign key constraint fails"
- **Solution:** Make sure you upload Members data before Revenue or Cancellations (they reference member_id)

### Dashboard Issues

**Problem:** "No Data Available" message
- **Solution:** Upload some data first using the Upload ZenPlanner Data page

**Problem:** Revenue trend shows incorrect percentage
- **Solution:** Make sure you have at least 60 days of revenue data for accurate comparisons

## 8. Best Practices

1. **Upload Order:** Upload Members first, then Revenue and Cancellations
2. **Regular Updates:** Upload fresh data weekly or monthly to keep dashboard current
3. **Date Formats:** Always use YYYY-MM-DD format for dates
4. **Clean Data:** Remove any special characters or formatting from ZenPlanner exports
5. **Backup:** Keep your CSV files as backups before uploading

## 9. Maintenance

### Clearing Old Data
If you need to clear and re-import data:

```sql
-- Clear all ZenPlanner data (use with caution!)
TRUNCATE TABLE gb_cancellations;
TRUNCATE TABLE gb_revenue;
TRUNCATE TABLE gb_members;
TRUNCATE TABLE gb_upload_history;
```

### Checking Data
Verify your uploaded data:

```sql
-- Count members by location
SELECT location, status, COUNT(*) as count
FROM gb_members
GROUP BY location, status;

-- Total revenue by location (last 30 days)
SELECT location, SUM(amount) as total
FROM gb_revenue
WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY location;

-- Recent cancellations
SELECT location, COUNT(*) as count
FROM gb_cancellations
WHERE cancellation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY location;
```

## 10. Support

For issues or questions:
- Check the upload history table to see if data was imported
- Verify CSV format matches the requirements exactly
- Ensure dates are in YYYY-MM-DD format
- Check that member_id values are consistent across all exports

---

**Quick Start Checklist:**
- [ ] Run database migration (Section 1)
- [ ] Export Members data from ZenPlanner
- [ ] Export Revenue data from ZenPlanner
- [ ] Export Cancellations data from ZenPlanner
- [ ] Upload Members CSV for Davenport
- [ ] Upload Members CSV for Celebration
- [ ] Upload Revenue CSVs
- [ ] Upload Cancellations CSVs
- [ ] View School Dashboard to verify data

---

*Last Updated: February 11, 2026*
