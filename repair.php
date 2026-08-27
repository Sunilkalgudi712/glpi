<?php
/**
 * Public "No-Login" Guest Repair Request & Job Tracking Portal
 * Computer Repair Shop Service Desk
 */

define('GLPI_ROOT', __DIR__);
require_once GLPI_ROOT . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
global $DB;

$action = $_GET['action'] ?? 'form';
$tab = $_GET['tab'] ?? 'submit';
$successJob = null;
$errorMsg = null;
$searchResults = null;
$searchQuery = trim($_GET['q'] ?? '');

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_repair'])) {
    $customerName = trim($_POST['customer_name'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $deviceModel  = trim($_POST['device_model'] ?? '');
    $categoryId   = (int)($_POST['service_category'] ?? 1);
    $issueDesc    = trim($_POST['issue_description'] ?? '');

    if (empty($customerName) || empty($phone) || empty($deviceModel) || empty($issueDesc)) {
        $errorMsg = "Please fill in all required fields.";
    } else {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        $formattedContent = "<strong>Customer Name:</strong> " . htmlescape($customerName) . "<br>" .
                            "<strong>Contact Phone:</strong> " . htmlescape($phone) . "<br>" .
                            "<strong>Device Brand & Model:</strong> " . htmlescape($deviceModel) . "<br><br>" .
                            "<strong>Issue Description:</strong><br>" . nl2br(htmlescape($issueDesc));

        $ticket = new Ticket();
        $ticketId = $ticket->add([
            'name'               => "Temporary Job",
            'content'            => $formattedContent,
            'itilcategories_id'  => $categoryId,
            'status'             => CommonITILObject::INCOMING, // 1 - New/Received
            'urgency'            => 3,
            'impact'             => 3,
            'priority'           => 3,
            'entities_id'        => 0,
            'externalid'         => $cleanPhone,
            'type'               => Ticket::INCIDENT_TYPE,
        ]);

        if ($ticketId) {
            $jobTitle = "[JOB-{$ticketId}] {$deviceModel} - {$customerName}";
            $DB->update('glpi_tickets', ['name' => $jobTitle], ['WHERE' => ['id' => $ticketId]]);

            // Add an initial public follow-up confirmation
            $fup = new ITILFollowup();
            $fup->add([
                'items_id'   => $ticketId,
                'itemtype'   => 'Ticket',
                'content'    => "Repair request received and registered into shop intake queue.",
                'is_private' => 0,
                'users_id'   => 0,
            ]);

            // Fetch category name
            $catName = "General Hardware/Software Repair";
            $cat = new ITILCategory();
            if ($cat->getFromDB($categoryId)) {
                $catName = $cat->fields['completename'] ?: $cat->fields['name'];
            }

            $successJob = [
                'id'           => $ticketId,
                'job_number'   => "JOB-" . $ticketId,
                'title'        => $jobTitle,
                'customer'     => $customerName,
                'phone'        => $phone,
                'device'       => $deviceModel,
                'category'     => $catName,
                'issue'        => $issueDesc,
                'date'         => date('Y-m-d H:i:s'),
                'status'       => 'Received / Intake',
            ];
            $action = 'receipt';
        } else {
            $errorMsg = "Failed to create repair job. Please try again or contact the shop directly.";
        }
    }
}

// Handle Tracking Search
if (!empty($searchQuery)) {
    $tab = 'track';
    $cleanQuery = preg_replace('/[^0-9]/', '', $searchQuery);
    $intQuery = (int)$cleanQuery;

    $whereConditions = [];
    if (!empty($cleanQuery)) {
        $whereConditions['OR'] = [
            'externalid' => $cleanQuery,
            ['content' => ['LIKE', "%$cleanQuery%"]],
            ['name'    => ['LIKE', "%$cleanQuery%"]],
        ];
        if ($intQuery > 0) {
            $whereConditions['OR'][] = ['id' => $intQuery];
        }
    } else {
        $whereConditions['OR'] = [
            ['name'    => ['LIKE', "%$searchQuery%"]],
            ['content' => ['LIKE', "%$searchQuery%"]],
        ];
    }
    $whereConditions['is_deleted'] = 0;

    $resultsIterator = $DB->request([
        'FROM'  => 'glpi_tickets',
        'WHERE' => $whereConditions,
        'ORDER' => 'id DESC',
    ]);

    $searchResults = [];
    foreach ($resultsIterator as $row) {
        // Resolve Category
        $catName = "General Repair";
        if (!empty($row['itilcategories_id'])) {
            $cat = new ITILCategory();
            if ($cat->getFromDB($row['itilcategories_id'])) {
                $catName = $cat->fields['completename'] ?: $cat->fields['name'];
            }
        }

        // Status description
        $statusLabel = 'Received / Intake';
        $statusBadge = 'primary';
        $stepProgress = 20;

        switch ((int)$row['status']) {
            case 1: // INCOMING
                $statusLabel = 'Received / Intake';
                $statusBadge = 'primary';
                $stepProgress = 20;
                break;
            case 2: // ASSIGNED
                $statusLabel = 'Under Diagnostics';
                $statusBadge = 'info';
                $stepProgress = 45;
                break;
            case 3: // PLANNED
                $statusLabel = 'In Repair';
                $statusBadge = 'warning';
                $stepProgress = 70;
                break;
            case 4: // WAITING
                $statusLabel = 'Waiting for Parts / Approval';
                $statusBadge = 'secondary';
                $stepProgress = 60;
                break;
            case 5: // SOLVED
                $statusLabel = 'Ready for Pickup / Completed';
                $statusBadge = 'success';
                $stepProgress = 95;
                break;
            case 6: // CLOSED
                $statusLabel = 'Delivered / Closed';
                $statusBadge = 'dark';
                $stepProgress = 100;
                break;
        }

        // Public followups only
        $fups = $DB->request([
            'FROM'  => 'glpi_itilfollowups',
            'WHERE' => [
                'items_id'   => $row['id'],
                'itemtype'   => 'Ticket',
                'is_private' => 0,
            ],
            'ORDER' => 'id ASC',
        ]);

        $publicUpdates = [];
        foreach ($fups as $f) {
            $publicUpdates[] = [
                'date'    => $f['date_creation'],
                'content' => $f['content'],
            ];
        }

        $searchResults[] = [
            'id'             => $row['id'],
            'job_number'     => "JOB-" . $row['id'],
            'name'           => $row['name'],
            'status_code'    => (int)$row['status'],
            'status_label'   => $statusLabel,
            'status_badge'   => $statusBadge,
            'step_progress'  => $stepProgress,
            'category'       => $catName,
            'date_creation'  => $row['date_creation'],
            'updates'        => $publicUpdates,
        ];
    }
}

// Fetch categories for form dropdown
$categoriesList = [];
$catIt = $DB->request([
    'FROM'  => 'glpi_itilcategories',
    'WHERE' => ['is_helpdeskvisible' => 1],
    'ORDER' => 'completename ASC',
]);
foreach ($catIt as $c) {
    $categoriesList[] = [
        'id'   => $c['id'],
        'name' => $c['completename'] ?: $c['name'],
    ];
}
if (empty($categoriesList)) {
    $categoriesList = [
        ['id' => 1, 'name' => 'Hardware Repair'],
        ['id' => 2, 'name' => 'Software / OS'],
        ['id' => 3, 'name' => 'Data Recovery / Backup'],
        ['id' => 4, 'name' => 'General Diagnostics / Cleaning'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Computer Repair Shop - Service & Job Tracking Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #2c3e50;
        }
        .navbar-brand {
            font-weight: 700;
            font-size: 1.35rem;
            color: #0d6efd !important;
        }
        .main-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            background: #ffffff;
        }
        .nav-tabs .nav-link {
            font-weight: 600;
            font-size: 1.05rem;
            padding: 12px 24px;
            color: #6c757d;
            border: none;
            border-bottom: 3px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 3px solid #0d6efd;
            background: transparent;
        }
        .form-label {
            font-weight: 600;
            color: #34495e;
            margin-bottom: 6px;
        }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 14px;
            border: 1px solid #ced4da;
        }
        .form-control:focus, .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
        }
        .btn-submit {
            background-color: #0d6efd;
            border: none;
            border-radius: 8px;
            padding: 12px 28px;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .receipt-card {
            border-left: 5px solid #198754;
            background-color: #f8fafc;
            border-radius: 8px;
            padding: 24px;
        }
        .job-badge {
            font-size: 1.4rem;
            letter-spacing: 1px;
            font-weight: 800;
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
            background: #0d6efd;
            border: 2px solid #fff;
        }
        .step-progress-bar {
            height: 10px;
            border-radius: 5px;
        }
        @media print {
            .no-print { display: none !important; }
            .receipt-card { border: 1px solid #000; background: #fff; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom shadow-sm no-print">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="repair.php">
            <i class="ti ti-device-laptop fs-2"></i>
            <span>Computer Repair Shop</span>
        </a>
        <div class="d-flex align-items-center gap-3">
            <a href="repair.php?tab=submit" class="btn btn-outline-primary btn-sm"><i class="ti ti-plus me-1"></i>New Request</a>
            <a href="repair.php?tab=track" class="btn btn-primary btn-sm"><i class="ti ti-search me-1"></i>Track Job</a>
        </div>
    </div>
</nav>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-9 col-xl-8">

            <?php if ($errorMsg): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
                    <i class="ti ti-alert-circle me-2 fs-5"></i><?= htmlescape($errorMsg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($action === 'receipt' && $successJob): ?>
                <!-- CONFIRMATION RECEIPT VIEW -->
                <div class="main-card p-4 p-md-5 mb-4">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-success-subtle text-success rounded-circle p-3 mb-3">
                            <i class="ti ti-circle-check fs-1"></i>
                        </div>
                        <h2 class="fw-bold">Repair Request Registered!</h2>
                        <p class="text-muted">Your repair request has been registered. Please keep this Job ID for status tracking.</p>
                        <div class="badge bg-primary-subtle text-primary border border-primary job-badge px-4 py-2 mt-2">
                            <?= htmlescape($successJob['job_number']) ?>
                        </div>
                    </div>

                    <div class="receipt-card mb-4">
                        <h5 class="fw-bold mb-3 border-bottom pb-2"><i class="ti ti-receipt me-2"></i>Job Intake Summary</h5>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <span class="text-muted d-block small">Customer Name</span>
                                <strong><?= htmlescape($successJob['customer']) ?></strong>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted d-block small">Contact Phone</span>
                                <strong><?= htmlescape($successJob['phone']) ?></strong>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted d-block small">Device Brand & Model</span>
                                <strong><?= htmlescape($successJob['device']) ?></strong>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted d-block small">Service Category</span>
                                <strong><?= htmlescape($successJob['category']) ?></strong>
                            </div>
                            <div class="col-12">
                                <span class="text-muted d-block small">Reported Issue</span>
                                <div><?= nl2br(htmlescape($successJob['issue'])) ?></div>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted d-block small">Intake Date & Time</span>
                                <span><?= htmlescape($successJob['date']) ?></span>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted d-block small">Initial Status</span>
                                <span class="badge bg-primary"><?= htmlescape($successJob['status']) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 justify-content-between no-print">
                        <button onclick="window.print()" class="btn btn-outline-secondary">
                            <i class="ti ti-printer me-1"></i>Print Receipt
                        </button>
                        <div class="d-flex gap-2">
                            <a href="repair.php?tab=track&q=<?= urlencode($successJob['id']) ?>" class="btn btn-primary">
                                <i class="ti ti-search me-1"></i>Track This Job
                            </a>
                            <a href="repair.php?tab=submit" class="btn btn-outline-primary">
                                <i class="ti ti-plus me-1"></i>New Request
                            </a>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <!-- MAIN PORTAL CARD WITH TABS -->
                <div class="main-card p-4 p-md-5">

                    <ul class="nav nav-tabs mb-4 no-print" id="portalTabs">
                        <li class="nav-item">
                            <a class="nav-link <?= $tab === 'submit' ? 'active' : '' ?>" href="repair.php?tab=submit">
                                <i class="ti ti-tools me-1"></i>Submit Repair Request
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $tab === 'track' ? 'active' : '' ?>" href="repair.php?tab=track">
                                <i class="ti ti-truck-delivery me-1"></i>Track Repair Job
                            </a>
                        </li>
                    </ul>

                    <?php if ($tab === 'submit'): ?>
                        <!-- REPAIR INTAKE FORM -->
                        <div class="mb-4">
                            <h3 class="fw-bold mb-1">Computer Repair Intake</h3>
                            <p class="text-muted">No login required. Fill out the details below to register your device for repair.</p>
                        </div>

                        <form method="POST" action="repair.php?tab=submit" class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Customer Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="customer_name" class="form-control" placeholder="e.g. John Doe" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Mobile / Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" name="phone" class="form-control" placeholder="e.g. 555-123-4567" required>
                                <div class="form-text">Used to look up your job status and notify you.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Device Brand & Model <span class="text-danger">*</span></label>
                                <input type="text" name="device_model" class="form-control" placeholder="e.g. Dell Inspiron 15, MacBook Pro M1, Custom PC" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Service Type / Category <span class="text-danger">*</span></label>
                                <select name="service_category" class="form-select" required>
                                    <?php foreach ($categoriesList as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= htmlescape($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Issue Description & Symptoms <span class="text-danger">*</span></label>
                                <textarea name="issue_description" class="form-control" rows="4" placeholder="Please describe the fault or service needed (e.g. No display on boot, cracked screen, liquid spill, virus removal, overheating)..." required></textarea>
                            </div>

                            <div class="col-12 pt-3">
                                <button type="submit" name="submit_repair" value="1" class="btn btn-primary btn-submit w-100">
                                    <i class="ti ti-send me-1"></i>Submit Repair Request
                                </button>
                            </div>
                        </form>

                    <?php elseif ($tab === 'track'): ?>
                        <!-- JOB STATUS TRACKER -->
                        <div class="mb-4">
                            <h3 class="fw-bold mb-1">Track Your Repair Job</h3>
                            <p class="text-muted">Enter your Phone Number or Job ID (e.g. <code>JOB-15</code> or <code>5551234567</code>) to check live status.</p>
                        </div>

                        <form method="GET" action="repair.php" class="row g-2 mb-4">
                            <input type="hidden" name="tab" value="track">
                            <div class="col-sm-9">
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text bg-white"><i class="ti ti-search text-muted"></i></span>
                                    <input type="text" name="q" class="form-control" placeholder="Enter Phone Number or Job ID..." value="<?= htmlescape($searchQuery) ?>" required>
                                </div>
                            </div>
                            <div class="col-sm-3">
                                <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">Check Status</button>
                            </div>
                        </form>

                        <?php if ($searchResults !== null): ?>
                            <?php if (empty($searchResults)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="ti ti-search-off fs-1 d-block mb-2"></i>
                                    <h5>No Repair Jobs Found</h5>
                                    <p class="small">We could not find any active repair jobs matching "<strong><?= htmlescape($searchQuery) ?></strong>". Please check your Job ID or Phone number.</p>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-4">
                                    <?php foreach ($searchResults as $job): ?>
                                        <div class="card border rounded-3 p-4 shadow-sm bg-white">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                                                <div>
                                                    <span class="badge bg-primary-subtle text-primary border border-primary fw-bold px-3 py-1 me-2"><?= htmlescape($job['job_number']) ?></span>
                                                    <strong class="fs-5"><?= htmlescape($job['name']) ?></strong>
                                                </div>
                                                <span class="badge bg-<?= $job['status_badge'] ?> fs-6 px-3 py-2">
                                                    <?= htmlescape($job['status_label']) ?>
                                                </span>
                                            </div>

                                            <div class="mb-4">
                                                <label class="small text-muted fw-bold d-block mb-1">Repair Progress</label>
                                                <div class="progress step-progress-bar">
                                                    <div class="progress-bar bg-<?= $job['status_badge'] ?> progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?= $job['step_progress'] ?>%"></div>
                                                </div>
                                                <div class="d-flex justify-content-between text-muted small mt-1">
                                                    <span>Intake</span>
                                                    <span>Diagnostics</span>
                                                    <span>In Repair</span>
                                                    <span>Ready for Pickup</span>
                                                    <span>Delivered</span>
                                                </div>
                                            </div>

                                            <div class="row g-2 small mb-3 text-muted">
                                                <div class="col-sm-6">
                                                    <strong>Service Category:</strong> <?= htmlescape($job['category']) ?>
                                                </div>
                                                <div class="col-sm-6">
                                                    <strong>Received Date:</strong> <?= htmlescape($job['date_creation']) ?>
                                                </div>
                                            </div>

                                            <?php if (!empty($job['updates'])): ?>
                                                <div class="mt-3 pt-3 border-top">
                                                    <h6 class="fw-bold mb-3"><i class="ti ti-message-dots me-1"></i>Technician Updates & Follow-ups:</h6>
                                                    <ul class="timeline mb-0">
                                                        <?php foreach ($job['updates'] as $up): ?>
                                                            <li class="timeline-item">
                                                                <div class="small text-muted fw-semibold mb-1"><?= htmlescape($up['date']) ?></div>
                                                                <div class="p-2 bg-light rounded border text-dark">
                                                                    <?= nl2br(htmlescape(strip_tags($up['content']))) ?>
                                                                </div>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-light border small text-muted mb-0">
                                                    <i class="ti ti-info-circle me-1"></i>No public technician updates posted yet. Diagnostics in progress.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                    <?php endif; ?>

                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
