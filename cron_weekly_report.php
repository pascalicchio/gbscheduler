<?php
// cron_weekly_report.php - WEEKLY PAYROLL EMAIL (With Shortcut Link)
require 'db.php';

// --- CONFIGURATION ---
// CHANGE THIS to your live domain when you upload! (e.g., https://gbdavenport.com/scheduler)
$base_url = "https://gbscheduler.com/";

// Set Timezone
date_default_timezone_set('America/New_York');

$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end   = date('Y-m-d', strtotime('sunday this week'));
$month_start = date('Y-m-01');
$month_end   = date('Y-m-d');

$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$users_raw = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
$coaches = [];
foreach ($users_raw as $u) {
    $coaches[$u['id']] = $u;
}

function getPayrollData($pdo, $coaches, $start, $end)
{
    $data = [];

    // Regular Classes
    $sql_reg = "SELECT ea.user_id, ea.position, l.id as loc_id, TIMESTAMPDIFF(MINUTE, ct.start_time, ct.end_time)/60 as hours FROM event_assignments ea JOIN class_templates ct ON ea.template_id = ct.id JOIN locations l ON ct.location_id = l.id WHERE ea.class_date BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql_reg);
    $stmt->execute([$start, $end]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = $r['user_id'];
        $lid = $r['loc_id'];
        if (!isset($coaches[$uid])) continue;

        // Rounding Rule: < 1h becomes 1h
        $hours = (float)$r['hours'];
        if ($hours < 1) {
            $hours = 1.0;
        }

        $pay = $hours * (($r['position'] === 'head') ? $coaches[$uid]['rate_head_coach'] : $coaches[$uid]['rate_helper']);

        if (!isset($data[$lid]['total'])) $data[$lid]['total'] = 0;
        if (!isset($data[$lid]['rows'][$uid])) {
            $data[$lid]['rows'][$uid] = [
                'name' => $coaches[$uid]['name'],
                'reg_hrs' => 0,
                'reg_pay' => 0,
                'priv_pay' => 0,
                'total' => 0
            ];
        }

        $data[$lid]['rows'][$uid]['reg_hrs'] += $hours;
        $data[$lid]['rows'][$uid]['reg_pay'] += $pay;
        $data[$lid]['rows'][$uid]['total'] += $pay;
        $data[$lid]['total'] += $pay;
    }

    // Private Classes
    $sql_priv = "SELECT pc.user_id, pc.location_id as loc_id, pc.payout FROM private_classes pc WHERE pc.class_date BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql_priv);
    $stmt->execute([$start, $end]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $uid = $p['user_id'];
        $lid = $p['loc_id'];
        if (!isset($coaches[$uid])) continue;

        $pay = $p['payout'];

        if (!isset($data[$lid]['total'])) $data[$lid]['total'] = 0;
        if (!isset($data[$lid]['rows'][$uid])) {
            $data[$lid]['rows'][$uid] = [
                'name' => $coaches[$uid]['name'],
                'reg_hrs' => 0,
                'reg_pay' => 0,
                'priv_pay' => 0,
                'total' => 0
            ];
        }

        $data[$lid]['rows'][$uid]['priv_pay'] += $pay;
        $data[$lid]['rows'][$uid]['total'] += $pay;
        $data[$lid]['total'] += $pay;
    }
    return $data;
}

$weekly_data = getPayrollData($pdo, $coaches, $week_start, $week_end);
$monthly_data = getPayrollData($pdo, $coaches, $month_start, $month_end);

// --- BUILD EMAIL HTML ---
$subject = "Weekly Payroll Report - " . date('M d, Y');

$html = "
<html>
<head>
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; color: #333; background-color: #f4f6f9; padding: 20px; }
    .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    h2 { color: #2c3e50; margin-top: 0; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
    .sub { color: #777; font-size: 0.9em; margin-bottom: 25px; }
    
    .loc-block { margin-bottom: 30px; border: 1px solid #e1e4e8; border-radius: 8px; overflow: hidden; }
    .loc-header { background: #2c3e50; color: #fff; padding: 12px 15px; display: flex; justify-content: space-between; align-items: center; font-weight: bold; font-size: 1.1em; }
    .month-total { background: rgba(255,255,255,0.1); padding: 4px 10px; border-radius: 4px; font-size: 0.9em; font-family: monospace; }
    
    table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
    th { background: #f8f9fa; color: #666; font-weight: 600; text-transform: uppercase; font-size: 0.8em; padding: 10px 15px; text-align: left; border-bottom: 2px solid #eee; }
    td { padding: 10px 15px; border-bottom: 1px solid #f0f0f0; color: #333; }
    .col-money { text-align: right; font-family: monospace; font-weight: 600; }
    .row-total { color: #28a745; font-weight: bold; }
    .footer-row { background: #f1f3f5; font-weight: bold; }
    .footer-label { text-align: right; font-size: 0.8em; text-transform: uppercase; }
    
    /* Link Style */
    .report-link { text-decoration: none; margin-right: 5px; font-size: 1.1em; }
</style>
</head>
<body>
<div class='container'>
    <h2>Weekly Payroll Report</h2>
    <div class='sub'>
        <strong>Week:</strong> " . date('M d', strtotime($week_start)) . " - " . date('M d', strtotime($week_end)) . "<br>
        <strong>Month Context:</strong> " . date('M 01') . " - " . date('M d') . "
    </div>
";

$has_data = false;

foreach ($locations as $loc) {
    $lid = $loc['id'];

    $w_rows = $weekly_data[$lid]['rows'] ?? [];
    $w_total = $weekly_data[$lid]['total'] ?? 0;
    $m_total = $monthly_data[$lid]['total'] ?? 0;

    if (empty($w_rows) && $m_total == 0) continue;
    $has_data = true;

    $html .= "
    <div class='loc-block'>
        <div class='loc-header'>
            <span>{$loc['name']}</span>
            <span class='month-total'>Month Total: $" . number_format($m_total, 2) . "</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Coach</th>
                    <th>Reg. Hours</th>
                    <th class='col-money'>Reg. Pay</th>
                    <th class='col-money'>Priv. Pay</th>
                    <th class='col-money'>Weekly Total</th>
                </tr>
            </thead>
            <tbody>";

    if (empty($w_rows)) {
        $html .= "<tr><td colspan='5' style='text-align:center; padding:15px; color:#999;'>No activity this week</td></tr>";
    } else {
        // CHANGED: Added $uid key to the loop to build the link
        foreach ($w_rows as $uid => $row) {

            // Build the link to the detailed report
            $link = $base_url . "location_reports.php?start=" . $week_start . "&end=" . $week_end . "&coach_id=" . $uid . "&view=detailed";

            $html .= "
            <tr>
                <td>
                    <a href='{$link}' target='_blank' class='report-link' title='View Details'>📄</a>
                    {$row['name']}
                </td>
                <td>" . number_format($row['reg_hrs'], 2) . "</td>
                <td class='col-money'>$" . number_format($row['reg_pay'], 2) . "</td>
                <td class='col-money'>$" . number_format($row['priv_pay'], 2) . "</td>
                <td class='col-money row-total'>$" . number_format($row['total'], 2) . "</td>
            </tr>";
        }
        $html .= "
        <tr class='footer-row'>
            <td colspan='4' class='footer-label'>This Week Total:</td>
            <td class='col-money row-total' style='font-size:1.1em'>$" . number_format($w_total, 2) . "</td>
        </tr>";
    }

    $html .= "
            </tbody>
        </table>
    </div>";
}

if (!$has_data) {
    $html .= "<div style='padding:20px; text-align:center; color:#999;'>No payroll data found for this period.</div>";
}

$html .= "
    <div style='text-align:center; margin-top:30px; border-top:1px solid #eee; padding-top:20px; font-size:12px; color:#aaa;'>
        Automated Report from GB Scheduler System
    </div>
</div>
</body>
</html>
";

// echo "<div style='background:#fff3cd; color:#856404; padding:15px; text-align:center; border:1px solid #ffeeba; margin-bottom:20px;'>
//         <strong>⚠️ PREVIEW MODE</strong><br>Below is the email that will be sent to Admins.<br>
//         Link Base URL: <code>$base_url</code>
//       </div>";
// echo $html;
// die;

// UNCOMMENT TO SEND
$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= "From: GB Scheduler <no-reply@gbscheduler.com>" . "\r\n";

$stmt_admins = $pdo->query("SELECT email FROM users WHERE role = 'admin'");
$admins = $stmt_admins->fetchAll(PDO::FETCH_COLUMN);

foreach($admins as $email) {
    mail($email, $subject, $html, $headers);
}