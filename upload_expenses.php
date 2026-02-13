<?php
$pageTitle = 'Upload Expense Data | GB Scheduler';
require_once 'includes/config.php';
require_once 'db.php';

// Require manager or admin access
requireAuth(['admin', 'manager']);

// Display flash message from session if exists
$uploadMessage = '';
$uploadSuccess = false;
if (isset($_SESSION['upload_message'])) {
    $uploadMessage = $_SESSION['upload_message'];
    $uploadSuccess = $_SESSION['upload_success'] ?? false;
    unset($_SESSION['upload_message'], $_SESSION['upload_success']);
}

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $location = $_POST['location'] ?? '';
    $file = $_FILES['csv_file'];

    // Validate inputs
    if (!in_array($location, ['davenport', 'celebration'])) {
        $_SESSION['upload_message'] = 'Invalid location selected';
        $_SESSION['upload_success'] = false;
        header('Location: upload_expenses.php');
        exit;
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['upload_message'] = 'File upload error';
        $_SESSION['upload_success'] = false;
        header('Location: upload_expenses.php');
        exit;
    } elseif (!in_array(substr(strtolower($file['name']), -4), ['.csv', 'xlsx'])) {
        $_SESSION['upload_message'] = 'Please upload a CSV or Excel file';
        $_SESSION['upload_success'] = false;
        header('Location: upload_expenses.php');
        exit;
    } else {
        // Process CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle) {
            $rowCount = 0;
            $importCount = 0;
            $skippedCount = 0;

            // Skip empty first line (if exists) and header row
            $firstLine = fgetcsv($handle);
            // If first line is empty or has only one empty element, read the actual header
            if (empty($firstLine) || (count($firstLine) === 1 && trim($firstLine[0]) === '')) {
                $headers = fgetcsv($handle); // Read actual headers
            } else {
                $headers = $firstLine; // First line was the header
            }

            try {
                $pdo->beginTransaction();

                while (($row = fgetcsv($handle)) !== false) {
                    $rowCount++;

                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        continue;
                    }

                    // Expected CSV format:
                    // Date, Account/Category, Vendor, Amount, [Description]
                    // Example: "01/31/2025", "Rent & Utilities", "ABC Property Management", "4500.00", "January rent"

                    if (count($row) >= 4) {
                        // Parse date - handle different formats
                        $dateString = trim($row[0]);
                        $expenseDate = null;

                        // Try multiple date formats
                        $formats = ['m/d/Y', 'Y-m-d', 'M j, Y', 'd/m/Y'];
                        foreach ($formats as $format) {
                            $date = DateTime::createFromFormat($format, $dateString);
                            if ($date !== false) {
                                $expenseDate = $date->format('Y-m-d');
                                break;
                            }
                        }

                        // Fallback to strtotime
                        if (!$expenseDate) {
                            $timestamp = strtotime($dateString);
                            if ($timestamp !== false) {
                                $expenseDate = date('Y-m-d', $timestamp);
                            }
                        }

                        if (!$expenseDate) {
                            $skippedCount++;
                            continue; // Skip invalid date
                        }

                        $category = trim($row[1]);
                        $vendor = isset($row[2]) ? trim($row[2]) : '';

                        // Parse amount - remove $ and , and convert to float
                        $amountString = trim($row[3]);
                        $amount = floatval(str_replace(['$', ',', ' '], '', $amountString));

                        // Skip if amount is 0 or negative
                        if ($amount <= 0) {
                            $skippedCount++;
                            continue;
                        }

                        $description = isset($row[4]) ? trim($row[4]) : '';

                        // Insert expense
                        $stmt = $pdo->prepare("
                            INSERT INTO gb_expenses
                            (location, category, vendor, amount, expense_date, description)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $location,
                            $category,
                            $vendor,
                            $amount,
                            $expenseDate,
                            $description
                        ]);
                        $importCount++;
                    } else {
                        $skippedCount++;
                    }
                }

                $pdo->commit();
                fclose($handle);

                $_SESSION['upload_message'] = "Successfully imported $importCount expenses for $location" .
                    ($skippedCount > 0 ? " ($skippedCount rows skipped)" : "");
                $_SESSION['upload_success'] = true;
            } catch (PDOException $e) {
                $pdo->rollBack();
                fclose($handle);
                $_SESSION['upload_message'] = 'Database error: ' . $e->getMessage();
                $_SESSION['upload_success'] = false;
            }
        } else {
            $_SESSION['upload_message'] = 'Failed to open CSV file';
            $_SESSION['upload_success'] = false;
        }

        header('Location: upload_expenses.php');
        exit;
    }
}

// Get upload statistics
$stats = [];
try {
    $stmt = $pdo->query("
        SELECT
            location,
            COUNT(*) as total_expenses,
            SUM(amount) as total_amount,
            MIN(expense_date) as earliest_date,
            MAX(expense_date) as latest_date
        FROM gb_expenses
        GROUP BY location
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stats[$row['location']] = $row;
    }
} catch (PDOException $e) {
    // Ignore errors
}

$extraCss = <<<CSS
.upload-container {
    max-width: 800px;
    margin: 40px auto;
    padding: 0 20px;
}

.upload-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow-md);
    border: 1px solid #e8ecf2;
    padding: 30px;
    margin-bottom: 30px;
}

.upload-card h3 {
    margin: 0 0 20px 0;
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-dark);
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    color: var(--color-dark);
    margin-bottom: 8px;
    font-size: 0.9rem;
}

.form-group select,
.form-group input[type="file"] {
    width: 100%;
    padding: 12px;
    border: 2px solid #e8ecf2;
    border-radius: 8px;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.form-group select:focus,
.form-group input[type="file"]:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(0, 201, 255, 0.1);
}

.btn-upload {
    background: var(--gradient-primary);
    color: white;
    padding: 14px 32px;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    transition: all 0.2s;
    width: 100%;
}

.btn-upload:hover {
    background: var(--gradient-primary-hover);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 201, 255, 0.3);
}

.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-success {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #a5d6a7;
}

.alert-error {
    background: #ffebee;
    color: #c62828;
    border: 1px solid #ef9a9a;
}

.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
}

.stat-card {
    background: #f8fafb;
    padding: 20px;
    border-radius: 8px;
    border: 2px solid #e8ecf2;
}

.stat-card h4 {
    margin: 0 0 12px 0;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--color-primary);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-dark);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 0.85rem;
    color: #666;
}

.info-box {
    background: #e3f2fd;
    border: 1px solid #90caf9;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.info-box h4 {
    margin: 0 0 8px 0;
    color: #1565c0;
    font-size: 0.95rem;
    font-weight: 700;
}

.info-box ul {
    margin: 8px 0 0 0;
    padding-left: 20px;
}

.info-box li {
    color: #1565c0;
    font-size: 0.85rem;
    margin-bottom: 4px;
}

.code-example {
    background: #2c3e50;
    color: #92fe9d;
    padding: 12px;
    border-radius: 6px;
    font-family: monospace;
    font-size: 0.85rem;
    overflow-x: auto;
    margin-top: 8px;
}
CSS;

require_once 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-file-invoice-dollar"></i> Upload Expense Data</h2>
    <?php include 'includes/nav-menu.php'; ?>
</div>

<div class="upload-container">

    <?php if ($uploadMessage): ?>
        <div class="alert <?= $uploadSuccess ? 'alert-success' : 'alert-error' ?>">
            <i class="fas fa-<?= $uploadSuccess ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($uploadMessage) ?>
        </div>
    <?php endif; ?>

    <div class="upload-card">
        <h3><i class="fas fa-cloud-upload-alt"></i> Upload QuickBooks Expense Data</h3>

        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Expected CSV Format</h4>
            <ul>
                <li><strong>Column 1:</strong> Date (MM/DD/YYYY, YYYY-MM-DD, or "Jan 31, 2025")</li>
                <li><strong>Column 2:</strong> Category/Account (e.g., "Rent & Utilities", "Coaching Salaries")</li>
                <li><strong>Column 3:</strong> Vendor (optional)</li>
                <li><strong>Column 4:</strong> Amount (with or without $ sign)</li>
                <li><strong>Column 5:</strong> Description (optional)</li>
            </ul>
            <div class="code-example">01/31/2025,Rent & Utilities,ABC Property Management,4500.00,January rent</div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="location">
                    <i class="fas fa-map-marker-alt"></i> Location *
                </label>
                <select name="location" id="location" required>
                    <option value="">Select Location</option>
                    <option value="davenport">Davenport</option>
                    <option value="celebration">Celebration</option>
                </select>
            </div>

            <div class="form-group">
                <label for="csv_file">
                    <i class="fas fa-file-csv"></i> CSV File *
                </label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
            </div>

            <button type="submit" class="btn-upload">
                <i class="fas fa-upload"></i> Upload Expenses
            </button>
        </form>
    </div>

    <?php if (!empty($stats)): ?>
    <div class="upload-card">
        <h3><i class="fas fa-chart-bar"></i> Current Expense Data</h3>
        <div class="stats-grid">
            <?php foreach (['davenport', 'celebration'] as $loc): ?>
                <?php if (isset($stats[$loc])): ?>
                    <div class="stat-card">
                        <h4><?= ucfirst($loc) ?></h4>
                        <div class="stat-value">$<?= number_format($stats[$loc]['total_amount'], 0) ?></div>
                        <div class="stat-label"><?= number_format($stats[$loc]['total_expenses']) ?> expense records</div>
                        <div class="stat-label" style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e8ecf2;">
                            <?= date('M j, Y', strtotime($stats[$loc]['earliest_date'])) ?> -
                            <?= date('M j, Y', strtotime($stats[$loc]['latest_date'])) ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="stat-card" style="opacity: 0.5;">
                        <h4><?= ucfirst($loc) ?></h4>
                        <div class="stat-value">$0</div>
                        <div class="stat-label">No data uploaded</div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="upload-card" style="background: #f8fafb;">
        <h3><i class="fas fa-question-circle"></i> How to Export from QuickBooks</h3>
        <ol style="color: #666; line-height: 1.8;">
            <li>Log into <strong>QuickBooks Online</strong></li>
            <li>Go to <strong>Reports</strong> → Search for <strong>"Profit and Loss Detail"</strong></li>
            <li>Set your <strong>date range</strong> (e.g., "This Month" or "Last 30 Days")</li>
            <li>Click <strong>Customize</strong> → Under Rows/Columns, filter by Location if needed</li>
            <li>Click <strong>Export</strong> → <strong>Export to Excel</strong></li>
            <li>Open the Excel file and create a simple CSV with: Date, Category, Vendor, Amount</li>
            <li>Upload here!</li>
        </ol>
    </div>

</div>

<?php require_once 'includes/footer.php'; ?>
