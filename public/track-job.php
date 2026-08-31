<?php
/**
 * Standalone No-Login Public Job Status Tracker
 * CREANEXA TECHNOLOGIES - Live Repair Progress
 * Access URL: http://localhost/glpi/track-job.php
 */

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

$searchResults = null;
$searchQuery   = trim($_GET['q'] ?? '');
$errorMsg      = null;

try {
    $pdo = getRepairShopPDO();
} catch (Exception $e) {
    $errorMsg = "Database connection error: " . $e->getMessage();
}

if (!empty($searchQuery) && isset($pdo)) {
    $cleanQuery = preg_replace('/[^0-9]/', '', $searchQuery);
    $intQuery   = (int)$cleanQuery;

    try {
        if ($intQuery > 0) {
            $stmt = $pdo->prepare("SELECT * FROM glpi_tickets 
                WHERE (id = :id OR externalid = :phone OR content LIKE :phone_like OR name LIKE :name_like) 
                  AND is_deleted = 0 
                ORDER BY id DESC");
            $stmt->execute([
                ':id'         => $intQuery,
                ':phone'      => $cleanQuery,
                ':phone_like' => "%$cleanQuery%",
                ':name_like'  => "%$searchQuery%",
            ]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM glpi_tickets 
                WHERE (name LIKE :name_like OR content LIKE :content_like) 
                  AND is_deleted = 0 
                ORDER BY id DESC");
            $stmt->execute([
                ':name_like'    => "%$searchQuery%",
                ':content_like' => "%$searchQuery%",
            ]);
        }

        $rows = $stmt->fetchAll();
        $searchResults = [];

        foreach ($rows as $row) {
            // Category Name
            $catName = "General Hardware/Software Repair";
            if (!empty($row['itilcategories_id'])) {
                $catStmt = $pdo->prepare("SELECT completename, name FROM glpi_itilcategories WHERE id = ?");
                $catStmt->execute([$row['itilcategories_id']]);
                $catRow = $catStmt->fetch();
                if ($catRow) {
                    $catName = $catRow['completename'] ?: $catRow['name'];
                }
            }

            // 5-Stage Status Lifecycle Mapping
            $statusCode = (int)$row['status'];
            $statusLabel = 'Received / Intake';
            $statusBadge = 'primary';
            $stepProgress = 20;
            $activeStep = 1;

            switch ($statusCode) {
                case 1: // INCOMING / New
                    $statusLabel  = 'Received / Intake';
                    $statusBadge  = 'primary';
                    $stepProgress = 20;
                    $activeStep   = 1;
                    break;
                case 2: // ASSIGNED / Processing
                    $statusLabel  = 'Under Diagnostics';
                    $statusBadge  = 'info';
                    $stepProgress = 40;
                    $activeStep   = 2;
                    break;
                case 3: // PLANNED
                case 4: // WAITING
                    $statusLabel  = ($statusCode == 4) ? 'Waiting for Parts / Approval' : 'In Repair';
                    $statusBadge  = 'warning text-dark';
                    $stepProgress = 60;
                    $activeStep   = 3;
                    break;
                case 5: // SOLVED / Solved
                    $statusLabel  = 'Ready for Pickup / Completed';
                    $statusBadge  = 'success';
                    $stepProgress = 80;
                    $activeStep   = 4;
                    break;
                case 6: // CLOSED
                    $statusLabel  = 'Delivered / Closed';
                    $statusBadge  = 'success';
                    $stepProgress = 100;
                    $activeStep   = 5;
                    break;
            }

            $custName = '';
            if (!empty($row['content']) && preg_match('/(?:Customer Name:\s*<\/strong>|\*\*Customer Name:\*\*)\s*([^\r\n<]+)/i', $row['content'], $mCName)) {
                $custName = trim(strip_tags($mCName[1]));
            }
            if (empty($custName)) {
                try {
                    $reStmt = $pdo->prepare("SELECT customer_name FROM glpi_plugin_repairenhancer_tickets WHERE tickets_id = ? LIMIT 1");
                    $reStmt->execute([$row['id']]);
                    $reRow = $reStmt->fetch();
                    if ($reRow && !empty($reRow['customer_name'])) {
                        $custName = $reRow['customer_name'];
                    }
                } catch (Exception $e) {}
            }
            if (empty($custName) && !empty($row['users_id_recipient'])) {
                try {
                    $uStmt = $pdo->prepare("SELECT realname, name, firstname FROM glpi_users WHERE id = ? LIMIT 1");
                    $uStmt->execute([$row['users_id_recipient']]);
                    $uRow = $uStmt->fetch();
                    if ($uRow) {
                        $custName = trim(($uRow['firstname'] ?? '') . ' ' . ($uRow['realname'] ?? '')) ?: $uRow['name'];
                    }
                } catch (Exception $e) {}
            }

            $searchResults[] = [
                'id'             => $row['id'],
                'job_number'     => "#JOB-" . $row['id'],
                'name'           => $row['name'],
                'customer_name'  => $custName,
                'status_code'    => $statusCode,
                'status_label'   => $statusLabel,
                'status_badge'   => $statusBadge,
                'step_progress'  => $stepProgress,
                'active_step'    => $activeStep,
                'category'       => $catName,
                'date_creation'  => $row['date_creation'],
                'updates'        => $publicUpdates,
            ];
        }
    } catch (Exception $ex) {
        $errorMsg = "Search error: " . $ex->getMessage();
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
        .step-progress-bar {
            height: 12px;
            border-radius: 6px;
            background-color: #e2e8f0;
        }
        .step-node {
            font-size: 0.82rem;
            transition: all 0.2s ease;
        }
        .step-node.active-step {
            color: #0284c7;
            font-weight: 700;
        }
        .step-node.completed-step {
            color: #10b981;
            font-weight: 700;
        }
        .step-node.inactive-step {
            color: #94a3b8;
        }
        .timeline {
            position: relative;
            padding-left: 20px;
            list-style: none;
        }
        .timeline:before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 3px;
            background: #e2e8f0;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        .timeline-item:before {
            content: '';
            position: absolute;
            left: -24px;
            top: 4px;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: #0284c7;
            border: 2px solid #fff;
        }
        footer {
            margin-top: auto;
            border-top: 1px solid #e2e8f0;
            background: #ffffff;
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
            <a href="repair-intake.php" class="btn btn-outline-light btn-sm d-flex align-items-center gap-1">
                <i class="ti ti-plus"></i> New Repair Intake
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
                    <i class="ti ti-alert-circle me-2 fs-5"></i><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- SEARCH CARD -->
            <div class="shop-card p-4 p-md-5 mb-4">
                <h4 class="fw-bold text-dark mb-1"><i class="ti ti-search me-2 text-primary"></i>Track Your Repair Job</h4>
                <p class="text-muted small mb-4">No login required. Enter your Phone Number or Job ID (e.g., <code>555-123-4567</code> or <code>JOB-18</code>) to view real-time diagnostics and service progress.</p>

                <form method="GET" action="track-job.php" class="row g-2">
                    <div class="col-sm-9">
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-white"><i class="ti ti-search text-muted"></i></span>
                            <input type="text" name="q" class="form-control" placeholder="Enter Phone Number or Job ID..." value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">Track Job</button>
                    </div>
                </form>
            </div>

            <!-- SEARCH RESULTS -->
            <?php if ($searchResults !== null): ?>
                <?php if (empty($searchResults)): ?>
                    <div class="shop-card p-5 text-center text-muted">
                        <i class="ti ti-search-off fs-1 d-block mb-3 text-secondary"></i>
                        <h5 class="fw-bold text-dark">No Repair Jobs Found</h5>
                        <p class="small mb-0">We could not find any active repair jobs matching "<strong><?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?></strong>". Please check your phone number or Job ID.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-4">
                        <?php foreach ($searchResults as $job): ?>
                            <div class="shop-card p-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                                    <div>
                                        <span class="badge bg-primary-subtle text-primary border border-primary fw-bold px-3 py-1 me-2"><?= htmlspecialchars($job['job_number'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <strong class="fs-5 text-dark"><?= htmlspecialchars($job['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                    <span class="badge bg-<?= $job['status_badge'] ?> fs-6 px-3 py-2">
                                        <?= htmlspecialchars($job['status_label'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>

                                <!-- 5-STAGE REPAIR PROGRESS BAR -->
                                <div class="mb-4 bg-light p-3 rounded-3 border">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="small text-dark fw-bold"><i class="ti ti-activity me-1 text-primary"></i>Repair Lifecycle Progress</span>
                                        <span class="badge bg-<?= ($job['step_progress'] == 100) ? 'success' : 'primary' ?>-subtle text-<?= ($job['step_progress'] == 100) ? 'success' : 'primary' ?> border border-<?= ($job['step_progress'] == 100) ? 'success' : 'primary' ?> fw-bold">
                                            <?= $job['step_progress'] ?>% Completed
                                        </span>
                                    </div>

                                    <div class="progress step-progress-bar mb-3">
                                        <div class="progress-bar <?= ($job['step_progress'] == 100) ? 'bg-success' : 'bg-primary' ?> progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?= $job['step_progress'] ?>%"></div>
                                    </div>

                                    <div class="row text-center g-1">
                                        <!-- Step 1: Intake -->
                                        <div class="col step-node <?= ($job['active_step'] >= 1) ? (($job['active_step'] == 1) ? 'active-step' : 'completed-step') : 'inactive-step' ?>">
                                            <i class="ti <?= ($job['active_step'] >= 1) ? 'ti-circle-check-filled' : 'ti-circle' ?> d-block fs-5 mb-1"></i>
                                            <span>Intake</span>
                                        </div>

                                        <!-- Step 2: Diagnostics -->
                                        <div class="col step-node <?= ($job['active_step'] >= 2) ? (($job['active_step'] == 2) ? 'active-step' : 'completed-step') : 'inactive-step' ?>">
                                            <i class="ti <?= ($job['active_step'] >= 2) ? 'ti-circle-check-filled' : 'ti-circle' ?> d-block fs-5 mb-1"></i>
                                            <span>Diagnostics</span>
                                        </div>

                                        <!-- Step 3: In Repair -->
                                        <div class="col step-node <?= ($job['active_step'] >= 3) ? (($job['active_step'] == 3) ? 'active-step' : 'completed-step') : 'inactive-step' ?>">
                                            <i class="ti <?= ($job['active_step'] >= 3) ? 'ti-circle-check-filled' : 'ti-circle' ?> d-block fs-5 mb-1"></i>
                                            <span>In Repair</span>
                                        </div>

                                        <!-- Step 4: Ready for Pickup -->
                                        <div class="col step-node <?= ($job['active_step'] >= 4) ? (($job['active_step'] == 4) ? 'active-step' : 'completed-step') : 'inactive-step' ?>">
                                            <i class="ti <?= ($job['active_step'] >= 4) ? 'ti-circle-check-filled' : 'ti-circle' ?> d-block fs-5 mb-1"></i>
                                            <span>Ready for Pickup</span>
                                        </div>

                                        <!-- Step 5: Delivered / Closed -->
                                        <div class="col step-node <?= ($job['active_step'] >= 5) ? 'completed-step' : 'inactive-step' ?>">
                                            <i class="ti <?= ($job['active_step'] >= 5) ? 'ti-circle-check-filled' : 'ti-circle' ?> d-block fs-5 mb-1"></i>
                                            <span>Delivered</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-2 small mb-3 text-muted">
                                    <?php if (!empty($job['customer_name'])): ?>
                                    <div class="col-sm-4">
                                        <strong>Customer:</strong> <span class="text-dark fw-bold"><?= htmlspecialchars($job['customer_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="col-sm-4">
                                        <strong>Service Category:</strong> <?= htmlspecialchars($job['category'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="col-sm-4">
                                        <strong>Intake Date:</strong> <?= htmlspecialchars($job['date_creation'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php else: ?>
                                    <div class="col-sm-6">
                                        <strong>Service Category:</strong> <?= htmlspecialchars($job['category'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="col-sm-6">
                                        <strong>Intake Date:</strong> <?= htmlspecialchars($job['date_creation'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($job['updates'])): ?>
                                    <div class="mt-3 pt-3 border-top">
                                        <h6 class="fw-bold mb-3 text-dark"><i class="ti ti-message-dots me-1 text-primary"></i>Technician Updates & Follow-ups:</h6>
                                        <ul class="timeline mb-0">
                                            <?php foreach ($job['updates'] as $up): ?>
                                                <li class="timeline-item">
                                                    <div class="small text-muted fw-semibold mb-1"><?= htmlspecialchars($up['date_creation'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="p-3 bg-light rounded border text-dark">
                                                        <?= nl2br(htmlspecialchars(strip_tags($up['content']), ENT_QUOTES, 'UTF-8')) ?>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light border small text-muted mb-0">
                                        <i class="ti ti-info-circle me-1"></i>No public technician updates posted yet. Initial diagnostics in progress.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
