<?php
//healthstaff_dashboard.php
session_start();
require_once "config.php";

// Check if user is logged in and is staff
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'health_staff') {
    header("Location: login.php");
    exit();
}

// Fetch staff info
$adminId = $_SESSION['admin_id'];
$adminStmt = $conn->prepare("SELECT * FROM admin_staff WHERE id = :id");
$adminStmt->bindParam(':id', $adminId);
$adminStmt->execute();
$admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
$adminName = $admin ? $admin['full_name'] : $_SESSION['admin_name'];
$adminRole = $admin ? $admin['role'] : $_SESSION['admin_role'];
$displayRole = ucwords(str_replace('_', ' ', $adminRole));

// Helper: check if a table exists in the current database
function tableExists(PDO $conn, string $tableName): bool {
    try {
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
        $stmt->bindParam(':t', $tableName);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

$hasMedicines = tableExists($conn, 'medicines');
$hasMedicineRequests = tableExists($conn, 'medicine_requests');
$hasRequestedMeds = tableExists($conn, 'requested_medicines');
$hasConsultations = tableExists($conn, 'consultations');
$hasPatients = tableExists($conn, 'patients');

// Get expiring medicines (within next 60 days)
$expiringMedicines = [];
if ($hasMedicines) {
    $expiryDate = date('Y-m-d', strtotime('+60 days'));
    $expiringStmt = $conn->prepare("SELECT id, generic_name, brand_name, expiration_date, stocks 
                                   FROM medicines 
                                   WHERE expiration_date <= :expiryDate 
                                   AND expiration_date >= CURDATE() 
                                   AND stocks > 0
                                   ORDER BY expiration_date ASC
                                   LIMIT 5");
    $expiringStmt->bindParam(':expiryDate', $expiryDate);
    $expiringStmt->execute();
    $expiringMedicines = $expiringStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get low stock medicines (below min_stock level)
$lowStockMedicines = [];
if ($hasMedicines) {
    $lowStockStmt = $conn->prepare("SELECT id, generic_name, brand_name, stocks, min_stock 
                                   FROM medicines 
                                   WHERE stocks <= min_stock 
                                   AND stocks > 0
                                   ORDER BY (stocks/min_stock) ASC
                                   LIMIT 5");
    $lowStockStmt->execute();
    $lowStockMedicines = $lowStockStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get pending medicine requests
$pendingRequests = [];
if ($hasMedicineRequests && $hasRequestedMeds) {
    $pendingRequestsStmt = $conn->prepare("SELECT mr.id, mr.full_name, mr.request_date, 
                                         COUNT(rm.id) as medicine_count
                                         FROM medicine_requests mr
                                         JOIN requested_medicines rm ON mr.id = rm.request_id
                                         WHERE mr.request_status IN ('requested', 'pending')
                                         GROUP BY mr.id
                                         ORDER BY mr.request_date ASC
                                         LIMIT 5");
    $pendingRequestsStmt->execute();
    $pendingRequests = $pendingRequestsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get recent consultations
$recentConsultations = [];
if ($hasConsultations && $hasPatients) {
    $recentConsultationsStmt = $conn->prepare("SELECT c.id, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                                            c.consultation_type, c.consultation_date
                                            FROM consultations c
                                            JOIN patients p ON c.patient_id = p.id
                                            ORDER BY c.created_at DESC
                                            LIMIT 5");
    $recentConsultationsStmt->execute();
    $recentConsultations = $recentConsultationsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Count total patients
$totalPatients = 0;
if ($hasPatients) {
    $totalPatientsStmt = $conn->query("SELECT COUNT(*) FROM patients");
    $totalPatients = (int)$totalPatientsStmt->fetchColumn();
}

// Count total medicines
$totalMedicines = 0;
if ($hasMedicines) {
    $totalMedicinesStmt = $conn->query("SELECT COUNT(*) FROM medicines");
    $totalMedicines = (int)$totalMedicinesStmt->fetchColumn();
}

// Count pending medicine requests
$pendingReqCount = 0;
if ($hasMedicineRequests) {
    $pendingReqCountStmt = $conn->query("SELECT COUNT(*) FROM medicine_requests WHERE request_status IN ('requested', 'pending')");
    $pendingReqCount = (int)$pendingReqCountStmt->fetchColumn();
}

// Count consultations this month
$currentMonth = date('Y-m');
$consultationsThisMonth = 0;
if ($hasConsultations) {
    $consultationsThisMonthStmt = $conn->prepare("SELECT COUNT(*) FROM consultations WHERE DATE_FORMAT(consultation_date, '%Y-%m') = :currentMonth");
    $consultationsThisMonthStmt->bindParam(':currentMonth', $currentMonth);
    $consultationsThisMonthStmt->execute();
    $consultationsThisMonth = (int)$consultationsThisMonthStmt->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Staff Dashboard</title>
    <link rel="stylesheet" href="css/admin_dashboard.css">
    <link rel="stylesheet" href="css/nav_footer.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Istok+Web&display=swap" rel="stylesheet">
    <style>
        .stats-cards {
            display: flex;
            justify-content: flex-start;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            width: 22%;
            margin-bottom: 15px;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card h3 {
            margin-top: 0;
            color: #555;
            font-size: 16px;
        }
        
        .stat-card .count {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin: 10px 0;
        }
        
        .alert-section {
            margin-bottom: 30px;
        }
        
        .alert-section h2 {
            margin-bottom: 15px;
            color: #333;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        
        .alert-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .alert-card {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 15px;
            flex: 1;
            min-width: 300px;
        }
        
        .alert-card h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .alert-list {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }
        
        .alert-list li {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .alert-list li:last-child {
            border-bottom: none;
        }
        
        .alert-list .critical {
            color: #d9534f;
            font-weight: bold;
        }
        
        .alert-list .warning {
            color: #f0ad4e;
            font-weight: bold;
        }
        
        .view-all {
            display: block;
            text-align: right;
            margin-top: 10px;
            color: #337ab7;
            text-decoration: none;
            font-size: 14px;
        }
        
        .view-all:hover {
            text-decoration: underline;
        }
        
        .expiry-date {
            color: #d9534f;
            font-weight: bold;
        }
        
        .stock-level {
            font-weight: bold;
        }
        
        .low-stock {
            color: #f0ad4e;
        }
        
        .critical-stock {
            color: #d9534f;
        }
        
        .request-date {
            color: #777;
            font-size: 12px;
        }
        
        .tiles-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 30px;
        }
        
        .tile {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: #f8f9fa;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            width: 200px;
            height: 150px;
            transition: transform 0.3s, background-color 0.3s;
        }
        
        .tile:hover {
            transform: translateY(-5px);
            background-color: #e9ecef;
        }
        
        .tile img {
            width: 40px;
            height: 40px;
            margin-bottom: 10px;
        }
        
        .tile h3 {
            margin: 0;
            color: #333;
            text-align: center;
        }
        
        a {
            text-decoration: none;
            color: inherit;
        }
    </style>
</head>
<body>
    <nav>
        <div class="logo-container">
            <img src="images/3s logo.png">
            <div>
                <h1>Maru-Health</h1>
                <p>Barangay Marulas 3S Health Station</p>
            </div>
        </div>
    </nav>

    <div class="sidebar">
        <div class="profile">
            <img src="images/profile-placeholder.png" alt="Staff">
            <div class="profile-details">
                <p class="admin_name"><strong><?= htmlspecialchars($adminName) ?></strong></p>
                <p class="role"><?= htmlspecialchars($displayRole) ?></p>
            </div>
        </div>
        <div class="menu">
            <?php 
                $current_page = basename($_SERVER['PHP_SELF']); 
                $dashboard_url = 'healthstaff_dashboard.php';
            ?>
            <p class="menu-header">ANALYTICS</p>
            <div class="menu-link-active">
                <img class="menu-icon" src="images/icons/dashboard_icon_active.png" alt="">
                <a href="<?= htmlspecialchars($dashboard_url) ?>" class="<?= $current_page == $dashboard_url ? 'active' : '' ?>">Dashboard</a>
            </div>
            <p class="menu-header">BASE</p>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/patient_icon.png" alt="">
                <a href="patient_management.php" class="<?= $current_page == 'patient_management.php' ? 'active' : '' ?>">Patient Management</a>
            </div>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/med_icon.png" alt="">
                <a href="medicine_management.php" class="<?= $current_page == 'medicine_management.php' ? 'active' : '' ?>">Medicine Management</a>
            </div>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/reqmd_icon.png" alt="">
                <a href="medicine_requests.php" class="<?= $current_page == 'medicine_requests.php' ? 'active' : '' ?>">Medicine Requests</a>
            </div>
            <p class="menu-header">OTHERS</p>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/logout_icon.png" alt="">
                <a href="logout.php" class="logout-button">Log Out</a>
            </div>
        </div>
    </div>

    <div class="dashboard-content">
        <h1>Staff Dashboard</h1>
        
        <!-- Stats Cards -->
        <div class="stats-cards">
            <div class="stat-card">
                <h3>Total Patients</h3>
                <div class="count"><?= $totalPatients ?></div>
                <a href="patient_stats.php" class="view-all">View Details</a>
            </div>
            
            <div class="stat-card">
                <h3>Total Medicines</h3>
                <div class="count"><?= $totalMedicines ?></div>
                <a href="medicine_stats.php" class="view-all">View Details</a>
            </div>
            
            <div class="stat-card">
                <h3>Pending Requests</h3>
                <div class="count"><?= $pendingReqCount ?></div>
                <a href="medicine_request_stats.php" class="view-all">View Details</a>
            </div>
            
            <div class="stat-card">
                <h3>Consultations This Month</h3>
                <div class="count"><?= $consultationsThisMonth ?></div>
                <a href="consultation_stats.php" class="view-all">View Details</a>
            </div>
        </div>
        
        <!-- Alert Sections -->
        <div class="alert-section">
            <h2>Critical Alerts</h2>
            
            <div class="alert-cards">
                <!-- Expiring Medicines -->
                <div class="alert-card">
                    <h3>Expiring Medicines</h3>
                    <?php if (count($expiringMedicines) > 0): ?>
                        <ul class="alert-list">
                            <?php foreach ($expiringMedicines as $medicine): ?>
                                <?php 
                                    $daysUntilExpiry = (strtotime($medicine['expiration_date']) - time()) / (60 * 60 * 24);
                                    $severityClass = $daysUntilExpiry <= 30 ? 'critical' : 'warning';
                                ?>
                                <li class="<?= $severityClass ?>">
                                    <?= htmlspecialchars($medicine['generic_name']) ?> 
                                    <?= !empty($medicine['brand_name']) ? '(' . htmlspecialchars($medicine['brand_name']) . ')' : '' ?>
                                    <br>
                                    <span class="expiry-date">Expires: <?= date('M d, Y', strtotime($medicine['expiration_date'])) ?></span>
                                    <span> - Stock: <?= $medicine['stocks'] ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="medicine_management.php?filter=expiring" class="view-all">View All Expiring</a>
                    <?php else: ?>
                        <p>No medicines expiring soon.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Low Stock Medicines -->
                <div class="alert-card">
                    <h3>Low Stock Medicines</h3>
                    <?php if (count($lowStockMedicines) > 0): ?>
                        <ul class="alert-list">
                            <?php foreach ($lowStockMedicines as $medicine): ?>
                                <?php 
                                    $stockRatio = $medicine['stocks'] / $medicine['min_stock'];
                                    $severityClass = $stockRatio <= 0.5 ? 'critical-stock' : 'low-stock';
                                ?>
                                <li>
                                    <?= htmlspecialchars($medicine['generic_name']) ?> 
                                    <?= !empty($medicine['brand_name']) ? '(' . htmlspecialchars($medicine['brand_name']) . ')' : '' ?>
                                    <br>
                                    <span class="stock-level <?= $severityClass ?>">
                                        Stock: <?= $medicine['stocks'] ?> (Min: <?= $medicine['min_stock'] ?>)
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="medicine_management.php?filter=low_stock" class="view-all">View All Low Stock</a>
                    <?php else: ?>
                        <p>No medicines with low stock.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="alert-section">
            <h2>Recent Activity</h2>
            
            <div class="alert-cards">
                <!-- Pending Medicine Requests -->
                <div class="alert-card">
                    <h3>Pending Medicine Requests</h3>
                    <?php if (count($pendingRequests) > 0): ?>
                        <ul class="alert-list">
                            <?php foreach ($pendingRequests as $request): ?>
                                <li>
                                    <strong><?= htmlspecialchars($request['full_name']) ?></strong> 
                                    (<?= $request['medicine_count'] ?> medicine<?= $request['medicine_count'] > 1 ? 's' : '' ?>)
                                    <br>
                                    <span class="request-date">Requested: <?= date('M d, Y g:i A', strtotime($request['request_date'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="medicine_requests.php" class="view-all">View All Requests</a>
                    <?php else: ?>
                        <p>No pending medicine requests.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Consultations -->
                <div class="alert-card">
                    <h3>Recent Consultations</h3>
                    <?php if (count($recentConsultations) > 0): ?>
                        <ul class="alert-list">
                            <?php foreach ($recentConsultations as $consultation): ?>
                                <li>
                                    <strong><?= htmlspecialchars($consultation['patient_name']) ?></strong> 
                                    - <?= htmlspecialchars($consultation['consultation_type']) ?>
                                    <br>
                                    <span class="request-date">Date: <?= date('M d, Y', strtotime($consultation['consultation_date'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="patient_management.php" class="view-all">View All Consultations</a>
                    <?php else: ?>
                        <p>No recent consultations.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>