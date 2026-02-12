<?php
$pageTitle = 'Upload ZenPlanner Data | GB Scheduler';
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
    $dataType = $_POST['data_type'] ?? '';
    $file = $_FILES['csv_file'];

    // Validate inputs
    if (!in_array($location, ['davenport', 'celebration'])) {
        $_SESSION['upload_message'] = 'Invalid location selected';
        $_SESSION['upload_success'] = false;
        header('Location: upload_zenplanner.php');
        exit;
    } elseif (!in_array($dataType, ['members', 'revenue', 'cancellations', 'holds'])) {
        $_SESSION['upload_message'] = 'Invalid data type selected';
        $_SESSION['upload_success'] = false;
        header('Location: upload_zenplanner.php');
        exit;
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['upload_message'] = 'File upload error';
        $_SESSION['upload_success'] = false;
        header('Location: upload_zenplanner.php');
        exit;
    } elseif (substr(strtolower($file['name']), -4) !== '.csv') {
        $_SESSION['upload_message'] = 'Please upload a CSV file';
        $_SESSION['upload_success'] = false;
        header('Location: upload_zenplanner.php');
        exit;
    } else {
        // Process CSV file
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle) {
            $rowCount = 0;
            $importCount = 0;

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

                    if ($dataType === 'members') {
                        // ZenPlanner Active Members columns: FullName, Membership Label, Membership Category,
                        // Mbr. Status, Mbr. Begin Date, Mbr. End Date
                        if (count($row) >= 6) {
                            // Parse begin date from "Feb 7, 2026" format to YYYY-MM-DD
                            $beginDate = date('Y-m-d', strtotime($row[4]));

                            // Generate member_id from name (for now, until we get actual IDs)
                            $memberId = strtolower(str_replace(' ', '_', $row[0])) . '_' . $location;

                            $stmt = $pdo->prepare("
                                INSERT INTO gb_members (member_id, name, email, status, join_date, location)
                                VALUES (?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    name = VALUES(name),
                                    status = VALUES(status),
                                    join_date = VALUES(join_date),
                                    location = VALUES(location)
                            ");
                            $stmt->execute([
                                $memberId, // Generated member_id
                                $row[0], // FullName
                                null, // Email (not in this export)
                                'active', // Status (all are active in this report)
                                $beginDate, // Mbr. Begin Date
                                $location
                            ]);
                            $importCount++;
                        }
                    } elseif ($dataType === 'holds') {
                        // ZenPlanner Holds columns: First Name, Last Name, Begin Date, End Date, Reason
                        if (count($row) >= 5) {
                            // Combine first and last name
                            $memberName = trim($row[0] . ' ' . $row[1]);

                            // Parse dates
                            $beginDate = date('Y-m-d', strtotime($row[2]));
                            $endDate = date('Y-m-d', strtotime($row[3]));

                            // Check for duplicate using unique constraint
                            $checkStmt = $pdo->prepare("
                                SELECT id FROM gb_holds
                                WHERE member_name = ? AND location = ? AND begin_date = ?
                            ");
                            $checkStmt->execute([$memberName, $location, $beginDate]);

                            if ($checkStmt->fetchColumn()) {
                                // Skip duplicate
                                continue;
                            }

                            $stmt = $pdo->prepare("
                                INSERT INTO gb_holds
                                (member_name, begin_date, end_date, reason, location)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $memberName,
                                $beginDate,
                                $endDate,
                                $row[4], // Reason
                                $location
                            ]);
                            $importCount++;
                        }
                    } elseif ($dataType === 'revenue') {
                        // ZenPlanner columns (cleaned): Payment Date, Payment Time, Receipt Number,
                        // First Name, Last Name, Payment Amount, Amount Approved
                        if (count($row) >= 7) {
                            // Parse payment date from "Jan 31, 2026" format to YYYY-MM-DD
                            $paymentDate = date('Y-m-d', strtotime($row[0]));

                            // Parse payment time from "12:34 AM" format to HH:MM:SS
                            $paymentTime = date('H:i:s', strtotime($row[1]));

                            // Receipt number
                            $receiptNumber = $row[2];

                            // Member name (First + Last)
                            $memberName = trim($row[3] . ' ' . $row[4]);

                            // Parse amount - remove $ and convert to float
                            $amount = floatval(str_replace(['$', ','], '', $row[5]));

                            // Check for duplicate using receipt_number
                            $checkStmt = $pdo->prepare("SELECT id FROM gb_revenue WHERE receipt_number = ?");
                            $checkStmt->execute([$receiptNumber]);

                            if ($checkStmt->fetchColumn()) {
                                // Skip duplicate
                                continue;
                            }

                            // Insert new payment
                            $stmt = $pdo->prepare("
                                INSERT INTO gb_revenue
                                (receipt_number, member_name, payment_date, payment_time, amount, location)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $receiptNumber,
                                $memberName,
                                $paymentDate,
                                $paymentTime,
                                $amount,
                                $location
                            ]);
                            $importCount++;
                        }
                    } elseif ($dataType === 'cancellations') {
                        // ZenPlanner columns (cleaned): Cancel Date, Reason, Comments,
                        // First Name, Last Name, Membership Label
                        if (count($row) >= 6) {
                            // Parse cancel date from "Jan 3, 2026" format to YYYY-MM-DD
                            $cancelDate = date('Y-m-d', strtotime($row[0]));

                            // Combine first and last name
                            $memberName = trim($row[3] . ' ' . $row[4]);

                            // Check for duplicate using unique constraint
                            $checkStmt = $pdo->prepare("
                                SELECT id FROM gb_cancellations
                                WHERE member_name = ? AND location = ? AND cancellation_date = ? AND membership_label = ?
                            ");
                            $checkStmt->execute([$memberName, $location, $cancelDate, $row[5]]);

                            if ($checkStmt->fetchColumn()) {
                                // Skip duplicate
                                continue;
                            }

                            $stmt = $pdo->prepare("
                                INSERT INTO gb_cancellations
                                (member_name, cancellation_date, reason, comments, membership_label, location)
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $memberName, // First Name + Last Name
                                $cancelDate, // Cancel Date (converted)
                                $row[1], // Reason
                                $row[2], // Comments
                                $row[5], // Membership Label
                                $location
                            ]);
                            $importCount++;
                        }
                    }
                }

                // Log upload history
                $stmt = $pdo->prepare("
                    INSERT INTO gb_upload_history (user_id, location, data_type, filename, rows_imported)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    getUserId(),
                    $location,
                    $dataType,
                    $file['name'],
                    $importCount
                ]);

                $pdo->commit();
                $_SESSION['upload_message'] = "Successfully imported {$importCount} records from {$rowCount} rows";
                $_SESSION['upload_success'] = true;
                header('Location: upload_zenplanner.php');
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $_SESSION['upload_message'] = "Import failed: " . $e->getMessage();
                $_SESSION['upload_success'] = false;
                header('Location: upload_zenplanner.php');
                exit;
            }

            fclose($handle);
        } else {
            $_SESSION['upload_message'] = 'Unable to read CSV file';
            $_SESSION['upload_success'] = false;
            header('Location: upload_zenplanner.php');
            exit;
        }
    }
}

// Get upload history
$historyStmt = $pdo->prepare("
    SELECT
        h.id,
        h.location,
        h.data_type,
        h.filename,
        h.rows_imported,
        h.upload_date,
        u.name as uploaded_by
    FROM gb_upload_history h
    JOIN users u ON h.user_id = u.id
    ORDER BY h.upload_date DESC
    LIMIT 20
");
$historyStmt->execute();
$uploadHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$extraCss = <<<CSS
/* Page Layout */
body {
    padding-top: 20px;
}

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    padding: 0 20px;
}

.page-header h2 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-dark);
}

.page-header h2 i {
    background-image: linear-gradient(135deg, rgb(0, 201, 255), rgb(146, 254, 157));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

@media (min-width: 768px) {
    .page-header h2 {
        font-size: 1.75rem;
    }
}

/* Upload Container */
.upload-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.upload-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 30px;
}

@media (max-width: 1024px) {
    .upload-grid {
        grid-template-columns: 1fr;
    }
}

.upload-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow-md);
    border: 1px solid #e8ecf2;
    padding: 30px;
}

.upload-card h3 {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 24px;
}

.form-group {
    margin-bottom: 24px;
}

.form-group > label {
    display: block;
    font-weight: 700;
    margin-bottom: 12px;
    color: var(--color-dark);
    font-size: 1rem;
}

.radio-group {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
}

.radio-option {
    position: relative;
}

.radio-option input[type="radio"] {
    position: absolute;
    opacity: 0;
}

.radio-option label {
    display: block;
    padding: 12px 16px;
    background: #f8fafb;
    border: 2px solid #e8ecf2;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    font-weight: 600;
    font-size: 0.9rem;
}

.radio-option input[type="radio"]:checked + label {
    background: var(--gradient-primary);
    color: white;
    border-color: transparent;
}

.radio-option label:hover {
    border-color: var(--color-primary);
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e8ecf2;
    border-radius: 8px;
    font-size: 15px;
    transition: all 0.2s;
}

.form-control:focus {
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
    font-size: 16px;
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
    display: flex;
    align-items: center;
    gap: 12px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.table-wrapper {
    overflow-x: auto;
    margin: -10px;
    padding: 10px;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.history-table th {
    background: #f8fafb;
    padding: 10px 8px;
    text-align: left;
    font-weight: 700;
    color: var(--color-dark);
    border-bottom: 2px solid #e8ecf2;
    font-size: 0.85rem;
    white-space: nowrap;
}

.history-table td {
    padding: 10px 8px;
    border-bottom: 1px solid #e8ecf2;
    vertical-align: middle;
    font-size: 0.9rem;
}

.history-table td:nth-child(4) {
    word-break: break-word;
    min-width: 180px;
}

.history-table tr:hover {
    background: #f8fafb;
}

.date-cell {
    font-size: 0.9rem;
    line-height: 1.4;
}

.date-cell .date-line {
    font-weight: 600;
    color: var(--color-dark);
}

.date-cell .time-line {
    font-size: 0.85rem;
    color: #666;
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}

.badge-davenport {
    background: #e3f2fd;
    color: #1565c0;
}

.badge-celebration {
    background: #f3e5f5;
    color: #7b1fa2;
}

.badge-members {
    background: #e8f5e9;
    color: #2e7d32;
}

.badge-revenue {
    background: #fff3e0;
    color: #e65100;
}

.badge-cancellations {
    background: #ffebee;
    color: #c62828;
}

.badge-holds {
    background: #fff3e0;
    color: #f57c00;
}

.csv-instructions {
    background: #f8fafb;
    border-radius: 8px;
    margin-bottom: 20px;
    border-left: 4px solid var(--color-primary);
    overflow: hidden;
}

.csv-instructions-header {
    padding: 16px 20px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    user-select: none;
    transition: background 0.2s;
}

.csv-instructions-header:hover {
    background: #eef2f7;
}

.csv-instructions-header h4 {
    margin: 0;
    color: var(--color-dark);
    font-size: 0.95rem;
}

.csv-instructions-toggle {
    transition: transform 0.3s;
    color: var(--color-primary);
}

.csv-instructions-toggle.open {
    transform: rotate(180deg);
}

.csv-instructions-content {
    padding: 0 20px 20px 20px;
}

.csv-instructions ul {
    margin: 0 0 12px 0;
    padding-left: 20px;
}

.csv-instructions li {
    margin-bottom: 6px;
}

.csv-instructions p {
    margin: 0;
}
CSS;

require_once 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-upload"></i> Upload Data</h2>
    <?php include 'includes/nav-menu.php'; ?>
</div>

<div class="upload-container">

    <?php if ($uploadMessage): ?>
        <div class="alert alert-<?= $uploadSuccess ? 'success' : 'error' ?>">
            <i class="fas fa-<?= $uploadSuccess ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($uploadMessage) ?>
        </div>
    <?php endif; ?>

    <div class="upload-grid">

    <div class="upload-card">
        <h3><i class="fas fa-file-csv"></i> Upload CSV File</h3>

        <div class="csv-instructions" x-data="{ open: false }">
            <div class="csv-instructions-header" @click="open = !open">
                <h4>CSV Format Requirements:</h4>
                <i class="fas fa-chevron-down csv-instructions-toggle" :class="{ 'open': open }"></i>
            </div>
            <div class="csv-instructions-content" x-show="open" x-transition>
                <ul>
                    <li><strong>Active Members:</strong> Upload ZenPlanner active members export directly!</li>
                    <li><strong>Holds:</strong> Upload ZenPlanner holds report directly!</li>
                    <li><strong>Revenue/Payments:</strong> Upload ZenPlanner payments export directly!</li>
                    <li><strong>Cancellations:</strong> Upload ZenPlanner cancellations export directly!</li>
                </ul>
                <p style="margin-bottom: 0; margin-top: 12px; font-size: 14px; color: #666;">
                    <i class="fas fa-shield-alt"></i> Duplicate detection enabled - safe to re-upload files!
                </p>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>
                    <i class="fas fa-map-marker-alt"></i> Location
                </label>
                <div class="radio-group">
                    <div class="radio-option">
                        <input type="radio" name="location" id="loc_davenport" value="davenport" required>
                        <label for="loc_davenport">Davenport</label>
                    </div>
                    <div class="radio-option">
                        <input type="radio" name="location" id="loc_celebration" value="celebration" required>
                        <label for="loc_celebration">Celebration</label>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>
                    <i class="fas fa-database"></i> Data Type
                </label>
                <div class="radio-group">
                    <div class="radio-option">
                        <input type="radio" name="data_type" id="type_members" value="members" required>
                        <label for="type_members">Members</label>
                    </div>
                    <div class="radio-option">
                        <input type="radio" name="data_type" id="type_holds" value="holds" required>
                        <label for="type_holds">Holds</label>
                    </div>
                    <div class="radio-option">
                        <input type="radio" name="data_type" id="type_revenue" value="revenue" required>
                        <label for="type_revenue">Payments</label>
                    </div>
                    <div class="radio-option">
                        <input type="radio" name="data_type" id="type_cancellations" value="cancellations" required>
                        <label for="type_cancellations">Cancellations</label>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="csv_file">
                    <i class="fas fa-file-upload"></i> CSV File
                </label>
                <input
                    type="file"
                    name="csv_file"
                    id="csv_file"
                    class="form-control"
                    accept=".csv"
                    required
                >
            </div>

            <button type="submit" class="btn-upload">
                <i class="fas fa-upload"></i> Upload & Import
            </button>
        </form>
    </div>

    <div class="upload-card">
        <h3 style="margin-top: 0;"><i class="fas fa-history"></i> Upload History</h3>

        <?php if (empty($uploadHistory)): ?>
            <p style="text-align: center; color: #666; padding: 20px;">
                No uploads yet. Start by uploading your first CSV file above.
            </p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Location</th>
                            <th>Data Type</th>
                            <th>Filename</th>
                            <th>Rows</th>
                            <th>Uploaded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uploadHistory as $upload): ?>
                            <tr>
                                <td>
                                    <div class="date-cell">
                                        <div class="date-line"><?= date('M j, Y', strtotime($upload['upload_date'])) ?></div>
                                        <div class="time-line"><?= date('g:i A', strtotime($upload['upload_date'])) ?></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $upload['location'] ?>">
                                        <?= ucfirst($upload['location']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $upload['data_type'] ?>">
                                        <?= ucfirst($upload['data_type']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($upload['filename']) ?></td>
                                <td><?= number_format($upload['rows_imported']) ?></td>
                                <td><?= htmlspecialchars($upload['uploaded_by']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    </div><!-- end upload-grid -->

</div>

<?php require_once 'includes/footer.php'; ?>
