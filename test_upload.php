<?php
// mock session
session_start();
$_SESSION['user_id'] = 1;

require 'db.php';
$stmt = $pdo->prepare("SELECT id FROM campaigns LIMIT 1");
$stmt->execute();
$validCampaignId = $stmt->fetchColumn();

if (!$validCampaignId) {
    die("No campaigns in DB!");
}

$_POST['campaign_id'] = $validCampaignId;
$_FILES['report_file'] = [
    'name' => 'Inbound_Daily_Report_20260225-213638.csv',
    'type' => 'text/csv',
    'tmp_name' => 'C:\\xampp\\htdocs\\ponche-xtreme\\Inbound_Daily_Report_20260225-213638.csv',
    'error' => 0,
    'size' => filesize('C:\\xampp\\htdocs\\ponche-xtreme\\Inbound_Daily_Report_20260225-213638.csv')
];

$_SERVER['REQUEST_METHOD'] = 'POST';

// Mock auth bypass completely
$content = file_get_contents('api/campaign_staffing.php');
$content = preg_replace('/if \(!userHasPermission.*/', 'if (false) {', $content);
$content = str_replace('is_uploaded_file($fileTmp)', 'file_exists($fileTmp)', $content);
file_put_contents('api/campaign_staffing_test.php', '<?php ' . explode('<?php', $content, 2)[1]);

require 'api/campaign_staffing_test.php';
