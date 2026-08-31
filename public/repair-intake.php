<?php
/**
 * Standalone No-Login Public Repair Intake Form
 * CREANEXA TECHNOLOGIES - Repair Portal
 * Access URL: http://localhost/glpi/repair-intake.php
 */

// Helper to establish direct database connection
if (!function_exists('getRepairShopPDO')) {
function getRepairShopPDO(): PDO {
    $dbhost = 'localhost';
    $dbuser = 'root';
    $dbpassword = '';
    $dbdefault = 'glpidb';

    $paths = [
        dirname(__DIR__) . '/config/config_db.php',
        __DIR__ . '/../config/config_db.php',
        __DIR__ . '/config/config_db.php',
        'C:/wamp64/www/glpi/config/config_db.php',
        'D:/wamp/www/glpi/config/config_db.php',
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            $content = file_get_contents($path);
            if (preg_match('/\$dbhost\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) $dbhost = $m[1];
            if (preg_match('/\$dbuser\s*=\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $dbuser = $m[1];
            if (preg_match('/\$dbpassword\s*=\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) $dbpassword = $m[1];
            if (preg_match('/\$dbdefault\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) $dbdefault = $m[1];
            break;
        }
    }

    $dsn = "mysql:host={$dbhost};dbname={$dbdefault};charset=utf8mb4";
    return new PDO($dsn, $dbuser, $dbpassword, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
}

$isSuccess   = false;
$errorMsg    = null;
$jobReceipt  = null;

try {
    $pdo = getRepairShopPDO();
} catch (Exception $e) {
    $errorMsg = "Database connection error: " . $e->getMessage();
}

// Category mapping helper
if (!function_exists('resolveCategoryId')) {
function resolveCategoryId(string $categoryName, PDO $pdo): int {
    $mapping = [
        'Hardware Repair'                => 1,
        'Display / Screen Replacement'   => 2,
        'Battery / Charging Issue'       => 3,
        'OS / Software Installation'     => 7,
        'Data Recovery'                  => 10,
        'General Cleaning / Servicing'   => 11,
    ];

    if (isset($mapping[$categoryName])) {
        $stmt = $pdo->prepare("SELECT id FROM glpi_itilcategories WHERE id = ?");
        $stmt->execute([$mapping[$categoryName]]);
        if ($stmt->fetch()) {
            return $mapping[$categoryName];
        }
    }

    // Fallback: search by name
    $stmt = $pdo->prepare("SELECT id FROM glpi_itilcategories WHERE name LIKE ? LIMIT 1");
    $stmt->execute(["%$categoryName%"]);
    $row = $stmt->fetch();
    if ($row) {
        return (int)$row['id'];
    }

    return 1; // Default to Hardware Repair
}
}

// Handle POST Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_intake']) && isset($pdo)) {
    $customerName   = trim($_POST['customer_name'] ?? '');
    $phoneNumber    = trim($_POST['customer_phone'] ?? '');
    $laptopModel    = trim($_POST['laptop_model'] ?? '');
    $serialNumber   = trim($_POST['laptop_serial'] ?? '');
    $categoryName   = trim($_POST['category'] ?? 'Hardware Repair');
    $issueTitle     = trim($_POST['issue_title'] ?? '');
    $description    = trim($_POST['description'] ?? '');

    // Validation
    if (empty($customerName) || empty($phoneNumber) || empty($laptopModel) || empty($issueTitle) || empty($description)) {
        $errorMsg = "Please fill in all required fields (Name, Phone, Make/Model, Title, and Description).";
    } else {
        $cleanPhone    = preg_replace('/[^0-9]/', '', $phoneNumber);
        $categoryId    = resolveCategoryId($categoryName, $pdo);
        $serialDisplay = !empty($serialNumber) ? $serialNumber : 'N/A';

        // Format Job Content
        $formattedContent = "<strong>Customer Name:</strong> " . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . "<br>" .
                            "<strong>Phone Number:</strong> " . htmlspecialchars($phoneNumber, ENT_QUOTES, 'UTF-8') . "<br>" .
                            "<strong>Device Make & Model:</strong> " . htmlspecialchars($laptopModel, ENT_QUOTES, 'UTF-8') . "<br>" .
                            "<strong>Serial Number:</strong> " . htmlspecialchars($serialDisplay, ENT_QUOTES, 'UTF-8') . "<br>" .
                            "<strong>Service Category:</strong> " . htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') . "<br>" .
                            "<strong>Issue Title:</strong> " . htmlspecialchars($issueTitle, ENT_QUOTES, 'UTF-8') . "<br><br>" .
                            "<strong>Detailed Fault Description:</strong><br>" . nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8'));

        $initialTitle = "[JOB] " . $laptopModel . " - " . $issueTitle . " (" . $customerName . ")";

        try {
            // Find or Create Customer User in GLPI
            $userId = 0;
            $userLookup = $pdo->prepare("SELECT id FROM glpi_users WHERE phone = ? OR name = ? LIMIT 1");
            $userLookup->execute([$cleanPhone, $cleanPhone]);
            $uRow = $userLookup->fetch();
            if ($uRow) {
                $userId = (int)$uRow['id'];
                // Update realname if needed
                $uUpdate = $pdo->prepare("UPDATE glpi_users SET realname = ?, phone = ? WHERE id = ?");
                $uUpdate->execute([$customerName, $phoneNumber, $userId]);
            } else {
                $uInsert = $pdo->prepare("INSERT INTO glpi_users (name, realname, phone, is_active, entities_id, profiles_id, date_mod, date_creation) VALUES (?, ?, ?, 1, 0, 1, NOW(), NOW())");
                $uInsert->execute([$cleanPhone, $customerName, $phoneNumber]);
                $userId = (int)$pdo->lastInsertId();
            }

            $insertSql = "INSERT INTO glpi_tickets (
                entities_id, name, date, date_mod, date_creation,
                status, content, urgency, impact, priority,
                itilcategories_id, type, externalid, users_id_recipient, is_deleted
            ) VALUES (
                0, :name, NOW(), NOW(), NOW(),
                1, :content, 3, 3, 3,
                :cat_id, 1, :externalid, :user_id, 0
            )";

            $stmt = $pdo->prepare($insertSql);
            $stmt->execute([
                ':name'       => $initialTitle,
                ':content'    => $formattedContent,
                ':cat_id'     => $categoryId,
                ':externalid' => $cleanPhone,
                ':user_id'    => $userId,
            ]);

            $ticketId = (int)$pdo->lastInsertId();

            if ($ticketId > 0) {
                // Update final Job Title with unique ID and Customer Name
                $finalTitle = "[JOB-{$ticketId}] " . $laptopModel . " - " . $issueTitle . " (" . $customerName . ")";
                $updateStmt = $pdo->prepare("UPDATE glpi_tickets SET name = ? WHERE id = ?");
                $updateStmt->execute([$finalTitle, $ticketId]);

                // Insert Requester Actor in glpi_tickets_users
                if ($userId > 0) {
                    $tuStmt = $pdo->prepare("INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification) VALUES (?, ?, 1, 1)");
                    $tuStmt->execute([$ticketId, $userId]);
                }

                // Insert into glpi_plugin_repairenhancer_tickets
                try {
                    $trackingToken = bin2hex(random_bytes(32));
                    $reStmt = $pdo->prepare("INSERT INTO glpi_plugin_repairenhancer_tickets (
                        tickets_id, customer_name, device_type, device_model,
                        device_imei, device_condition, customer_phone,
                        estimated_cost, advance_deposit, tracking_token
                    ) VALUES (
                        :ticket_id, :c_name, 'Laptop', :model,
                        :imei, 'Standard Intake', :phone,
                        0.00, 0.00, :token
                    ) ON DUPLICATE KEY UPDATE customer_name = VALUES(customer_name), customer_phone = VALUES(customer_phone)");
                    $reStmt->execute([
                        ':ticket_id' => $ticketId,
                        ':c_name'    => $customerName,
                        ':model'     => $laptopModel,
                        ':imei'      => $serialDisplay,
                        ':phone'     => $phoneNumber,
                        ':token'     => $trackingToken,
                    ]);
                } catch (Exception $reEx) {
                    // Ignore plugin table error if table missing
                }

                // Insert initial public intake note
                $fupSql = "INSERT INTO glpi_itilfollowups (
                    itemtype, items_id, date_creation, date_mod,
                    users_id, content, is_private, requesttypes_id
                ) VALUES (
                    'Ticket', :ticket_id, NOW(), NOW(),
                    0, :content, 0, 1
                )";
                $fupStmt = $pdo->prepare($fupSql);
                $fupStmt->execute([
                    ':ticket_id' => $ticketId,
                    ':content'   => "Device received at CREANEXA TECHNOLOGIES customer intake desk. Diagnostic queue initialized.",
                ]);

                $isSuccess = true;
                $jobReceipt = [
                    'job_id'        => $ticketId,
                    'job_number'    => "#JOB-" . $ticketId,
                    'customer_name' => $customerName,
                    'phone'         => $phoneNumber,
                    'device_model'  => $laptopModel,
                    'serial_number' => $serialDisplay,
                    'category'      => $categoryName,
                    'title'         => $issueTitle,
                    'description'   => $description,
                    'date'          => date('d M Y, h:i A'),
                    'status'        => "Received / Under Diagnostics",
                ];
            } else {
                $errorMsg = "Failed to create repair job. Please try again.";
            }
        } catch (Exception $ex) {
            $errorMsg = "Error registering job: " . $ex->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CREANEXA TECHNOLOGIES - Repair Portal</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Tabler Icons CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
    <style>
        :root {
            --shop-primary: #0284c7;
            --shop-primary-dark: #0369a1;
            --shop-dark: #0f172a;
            --shop-bg: #f8fafc;
        }
        body {
            background-color: var(--shop-bg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #334155;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .header-bar {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding: 14px 0;
            margin-bottom: 30px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.15);
        }
        .shop-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.92rem;
            color: #1e293b;
            margin-bottom: 5px;
        }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            font-size: 0.95rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--shop-primary);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.18);
        }
        .btn-submit {
            background-color: var(--shop-primary);
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 600;
            font-size: 1.05rem;
            letter-spacing: 0.3px;
        }
        .btn-submit:hover {
            background-color: var(--shop-primary-dark);
        }
        .badge-job {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: 1px;
            padding: 8px 20px;
            border-radius: 8px;
        }
        .receipt-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 5px solid #10b981;
            border-radius: 8px;
            padding: 24px;
        }
        .receipt-row {
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .receipt-row:last-child {
            border-bottom: none;
        }
        .receipt-label {
            color: #64748b;
            font-size: 0.88rem;
            font-weight: 500;
        }
        .receipt-val {
            color: #0f172a;
            font-weight: 600;
            font-size: 0.95rem;
        }
        footer {
            margin-top: auto;
            border-top: 1px solid #e2e8f0;
            background: #ffffff;
        }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .shop-card { border: none; box-shadow: none; }
            .receipt-box { border: 1px solid #000; }
        }
    </style>
</head>
<body>

<!-- BRAND NAVBAR -->
<nav class="navbar navbar-expand-lg header-bar no-print">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="repair-intake.php">
            <img src="pics/creanexa_logo.png" alt="CREANEXA TECHNOLOGIES" style="max-height: 48px; width: auto;" />
        </a>
        <div class="d-flex gap-2 mt-2 mt-lg-0">
            <a href="track-job.php" class="btn btn-info btn-sm text-white fw-semibold d-flex align-items-center gap-1 shadow-sm">
                <i class="ti ti-search"></i> Track Existing Job
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
                    <div class="d-flex align-items-center gap-2">
                        <i class="ti ti-alert-circle fs-4"></i>
                        <div><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($isSuccess && $jobReceipt): ?>
                <!-- CONFIRMATION RECEIPT SCREEN -->
                <div class="shop-card p-4 p-md-5 mb-4 text-center">
                    <div class="d-inline-flex align-items-center justify-content-center bg-success-subtle text-success rounded-circle mb-3" style="width: 64px; height: 64px;">
                        <i class="ti ti-circle-check fs-1"></i>
                    </div>
                    <h3 class="fw-bold text-dark mb-1">Repair Request Registered!</h3>
                    <p class="text-muted mb-3">Your repair request has been successfully created with <strong>CREANEXA TECHNOLOGIES</strong>.</p>
                    
                    <div class="d-inline-block badge bg-primary-subtle text-primary border border-primary badge-job mb-4">
                        <?= htmlspecialchars($jobReceipt['job_number'], ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div class="receipt-box text-start mb-4">
                        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
                            <i class="ti ti-receipt me-1 text-success"></i> Customer Intake Receipt
                        </h5>

                        <div class="row receipt-row">
                            <div class="col-sm-5 receipt-label">Customer Full Name</div>
                            <div class="col-sm-7 receipt-val"><?= htmlspecialchars($jobReceipt['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <div class="row receipt-row">
                            <div class="col-sm-5 receipt-label">Contact Phone Number</div>
                            <div class="col-sm-7 receipt-val"><?= htmlspecialchars($jobReceipt['phone'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <div class="row receipt-row">
                            <div class="col-sm-5 receipt-label">Device Make & Model</div>
                            <div class="col-sm-7 receipt-val"><?= htmlspecialchars($jobReceipt['device_model'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <div class="row receipt-row">
                            <div class="col-sm-5 receipt-label">Serial Number / Service Tag</div>
                            <div class="col-sm-7 receipt-val"><?= htmlspecialchars($jobReceipt['serial_number'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <div class="row receipt-row">
                            <div class="col-sm-5 receipt-label">Service Category</div>
                            <div class="col-sm-7 receipt-val"><?= htmlspecialchars($jobReceipt['category'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <div class="row receipt-row">
                            <div class="col-sm-5 receipt-label">Issue Summary</div>
                            <div class="col-sm-7 receipt-val"><?= htmlspecialchars($jobReceipt['title'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <div class="row receipt-row">
                            <div class="col-sm-5 receipt-label">Fault Description</div>
                            <div class="col-sm-7 receipt-val fw-normal text-muted"><?= nl2br(htmlspecialchars($jobReceipt['description'], ENT_QUOTES, 'UTF-8')) ?></div>
                        </div>

                        <div class="row receipt-row">
                            <div class="col-sm-5 receipt-label">Intake Date & Time</div>
                            <div class="col-sm-7 receipt-val"><?= htmlspecialchars($jobReceipt['date'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>

                        <div class="row receipt-row">
                            <div class="col-sm-5 receipt-label">Current Job Status</div>
                            <div class="col-sm-7 receipt-val">
                                <span class="badge bg-primary px-3 py-1"><?= htmlspecialchars($jobReceipt['status'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info text-start d-flex align-items-center gap-2 mb-4">
                        <i class="ti ti-info-circle fs-4 flex-shrink-0"></i>
                        <div class="small">
                            <strong>Instructions:</strong> Please save this Job ID to check your repair status online or when picking up your device at <strong>CREANEXA TECHNOLOGIES</strong>.
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between gap-2 no-print">
                        <button onclick="window.print()" class="btn btn-outline-secondary">
                            <i class="ti ti-printer me-1"></i> Print Receipt
                        </button>
                        <div class="d-flex gap-2">
                            <a href="track-job.php?q=<?= urlencode($jobReceipt['job_id']) ?>" class="btn btn-primary">
                                <i class="ti ti-search me-1"></i> Track This Job
                            </a>
                            <a href="repair-intake.php" class="btn btn-outline-primary">
                                <i class="ti ti-plus me-1"></i> Submit Another Device
                            </a>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <!-- INTAKE WEB FORM -->
                <div class="shop-card p-4 p-md-5">
                    <div class="border-bottom pb-3 mb-4">
                        <h4 class="fw-bold text-dark mb-1"><i class="ti ti-tools me-2 text-primary"></i>Device Repair Intake Form</h4>
                        <p class="text-muted small mb-0">No login required. Submit your device details to book a repair diagnostic with CREANEXA TECHNOLOGIES.</p>
                    </div>

                    <form method="POST" action="repair-intake.php" class="row g-3">
                        <!-- 1. Customer Name -->
                        <div class="col-md-6">
                            <label class="form-label">Customer Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="customer_name" class="form-control" placeholder="e.g. John Smith" required>
                        </div>

                        <!-- 2. Customer Phone Number -->
                        <div class="col-md-6">
                            <label class="form-label">Customer Phone Number <span class="text-danger">*</span></label>
                            <input type="tel" name="customer_phone" class="form-control" placeholder="e.g. 555-123-4567" required>
                            <div class="form-text small">Used for status tracking and repair notifications.</div>
                        </div>

                        <!-- 3. Laptop Make & Model -->
                        <div class="col-md-6">
                            <label class="form-label">Laptop / Device Make & Model <span class="text-danger">*</span></label>
                            <input type="text" name="laptop_model" class="form-control" placeholder="e.g. Dell Inspiron 15, HP Pavilion, MacBook Air" required>
                        </div>

                        <!-- 4. Laptop Serial Number -->
                        <div class="col-md-6">
                            <label class="form-label">Laptop Serial Number <span class="text-muted font-monospace small">(Optional / Service Tag)</span></label>
                            <input type="text" name="laptop_serial" class="form-control" placeholder="e.g. CN-0XYZ12-34567 or Service Tag">
                        </div>

                        <!-- 5. Category Dropdown -->
                        <div class="col-12">
                            <label class="form-label">Repair Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-select" required>
                                <option value="Hardware Repair">Hardware Repair</option>
                                <option value="OS / Software Installation">OS / Software Installation</option>
                                <option value="Display / Screen Replacement">Display / Screen Replacement</option>
                                <option value="Battery / Charging Issue">Battery / Charging Issue</option>
                                <option value="Data Recovery">Data Recovery</option>
                                <option value="General Cleaning / Servicing">General Cleaning / Servicing</option>
                            </select>
                        </div>

                        <!-- 6. Issue Title -->
                        <div class="col-12">
                            <label class="form-label">Issue Summary / Title <span class="text-danger">*</span></label>
                            <input type="text" name="issue_title" class="form-control" placeholder="e.g. Laptop not powering on / Screen flickering / Blue Screen crash" required>
                        </div>

                        <!-- 7. Detailed Description -->
                        <div class="col-12">
                            <label class="form-label">Detailed Fault Description <span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Please provide detailed symptoms, what happened before the issue started, liquid spills, error messages, etc..." required></textarea>
                        </div>

                        <!-- Submit Button -->
                        <div class="col-12 pt-3">
                            <button type="submit" name="submit_intake" value="1" class="btn btn-primary btn-submit w-100 shadow-sm d-flex align-items-center justify-content-center gap-2">
                                <i class="ti ti-send fs-5"></i> Submit Repair Request
                            </button>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<!-- FOOTER -->
<footer class="text-center py-4 text-muted small no-print">
    <div class="container">
        &copy; 2026 <strong>CREANEXA TECHNOLOGIES</strong>. All rights reserved.
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
