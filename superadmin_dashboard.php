<?php
//superadmin_dashboard.php
session_start();
require_once "config.php";

// Check if user is logged in and is super admin
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'super_admin') {
    header("Location: login.php");
    exit();
}

// Fetch admin info
$adminId = $_SESSION['admin_id'];
$adminStmt = $conn->prepare("SELECT * FROM admin_staff WHERE id = :id");
$adminStmt->bindParam(':id', $adminId);
$adminStmt->execute();
$admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
$adminName = $admin ? $admin['full_name'] : $_SESSION['admin_name'];
$adminRole = $admin ? $admin['role'] : $_SESSION['admin_role'];
$displayRole = ucwords(str_replace('_', ' ', $adminRole));

// Helper to check if a table exists
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

$hasPendingUsers = tableExists($conn, 'pending_users');
$hasMedicineRequests = tableExists($conn, 'medicine_requests');
$hasMedicines = tableExists($conn, 'medicines');
$hasAnnouncements = tableExists($conn, 'announcements');
$hasEvents = tableExists($conn, 'events');
$hasUsers = tableExists($conn, 'users');
$hasPatients = tableExists($conn, 'patients');
$hasConsultations = tableExists($conn, 'consultations');

// Count pending accounts
$pendingAccounts = 0;
if ($hasPendingUsers) {
    $pendingAccountsStmt = $conn->query("SELECT COUNT(*) FROM pending_users WHERE role = 'user'");
    $pendingAccounts = (int)$pendingAccountsStmt->fetchColumn();
}

// Count pending medicine requests
$pendingReqCount = 0;
if ($hasMedicineRequests) {
    $pendingReqCountStmt = $conn->query("SELECT COUNT(*) FROM medicine_requests WHERE request_status = 'pending'");
    $pendingReqCount = (int)$pendingReqCountStmt->fetchColumn();
}

// Count expired medicines
$expiredMedicines = 0;
if ($hasMedicines) {
    $expiredMedicinesStmt = $conn->query("SELECT COUNT(*) FROM medicines WHERE expiration_date < CURDATE() AND expiry_status = 'Expired'");
    $expiredMedicines = (int)$expiredMedicinesStmt->fetchColumn();
}

// Count out of stock medicines
$outOfStockMedicines = 0;
if ($hasMedicines) {
    $outOfStockMedicinesStmt = $conn->query("SELECT COUNT(*) FROM medicines WHERE stocks = 0 AND stock_status = 'Out of Stock' AND expiry_status != 'Expired'");
    $outOfStockMedicines = (int)$outOfStockMedicinesStmt->fetchColumn();
}

// Count to be claimed medicines (requests with status 'claimed', not yet picked up, and claim_until_date not expired)
$toBeClaimedMedicines = 0;
if ($hasMedicineRequests) {
    $toBeClaimedMedicinesStmt = $conn->query("SELECT COUNT(*) FROM medicine_requests WHERE request_status = 'to be claimed' AND claimed_date IS NULL AND claim_until_date >= CURDATE()");
    $toBeClaimedMedicines = (int)$toBeClaimedMedicinesStmt->fetchColumn();
}


// Count active announcements
$totalAnnouncements = 0;
if ($hasAnnouncements) {
    $totalAnnouncementsStmt = $conn->query("SELECT COUNT(*) FROM announcements WHERE status = 'active'");
    $totalAnnouncements = (int)$totalAnnouncementsStmt->fetchColumn();
}

// Count upcoming events
$upcomingEvents = 0;
if ($hasEvents) {
    $upcomingEventsStmt = $conn->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()");
    $upcomingEvents = (int)$upcomingEventsStmt->fetchColumn();
}

// Fetch expiring medicines (Expiring within a month, a week, or already expired)
$expiringMedicines = [];
if ($hasMedicines) {
    $expiringMedicinesStmt = $conn->query("
        SELECT generic_name, brand_name, expiration_date, expiry_status 
        FROM medicines 
        WHERE expiry_status IN ('Expiring within a month', 'Expiring within a week')
        ORDER BY expiration_date ASC
        LIMIT 5
    ");
    $expiringMedicines = $expiringMedicinesStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch low stock medicines (Low Stock or Out of Stock)
$lowStockMedicines = [];
if ($hasMedicines) {
    $lowStockMedicinesStmt = $conn->query("
        SELECT generic_name, brand_name, stocks, min_stock, stock_status 
        FROM medicines 
        WHERE stock_status IN ('Low Stock', 'Out of Stock')
        AND expiry_status != 'Expired'
        ORDER BY stocks ASC
        LIMIT 5
    ");
    $lowStockMedicines = $lowStockMedicinesStmt->fetchAll(PDO::FETCH_ASSOC);
}


// Count total users
$totalUsers = 0;
if ($hasUsers) {
    $totalUsersStmt = $conn->query("SELECT COUNT(*) FROM users");
    $totalUsers = (int)$totalUsersStmt->fetchColumn();
}

// Count total medicines
$totalMedicines = 0;
if ($hasMedicines) {
    $totalMedicinesStmt = $conn->query("SELECT COUNT(*) FROM medicines");
    $totalMedicines = (int)$totalMedicinesStmt->fetchColumn();
}

// Count total patients
$totalPatients = 0;
if ($hasPatients) {
    $totalPatientsStmt = $conn->query("SELECT COUNT(*) FROM patients");
    $totalPatients = (int)$totalPatientsStmt->fetchColumn();
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
    <title>Super Admin Dashboard</title>
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
            align-items: flex-start;
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
            <img src="images/profile-placeholder.png" alt="Admin">
            <div class="profile-details">
                <p class="admin_name"><strong><?= htmlspecialchars($adminName) ?></strong></p>
                <p class="role"><?= htmlspecialchars($displayRole) ?></p>
            </div>
        </div>
        <div class="menu">
            <?php 
                $current_page = basename($_SERVER['PHP_SELF']); 

                // Determine dashboard URL based on role
                $dashboard_url = ''; // Default
                if ($adminRole === 'super_admin') {
                    $dashboard_url = 'superadmin_dashboard.php';
                } elseif ($adminRole === 'admin') {
                    $dashboard_url = 'admin_dashboard.php';
                } elseif ($adminRole === 'staff') {
                    $dashboard_url = 'staff_dashboard.php';
                }
            ?>
            <p class="menu-header">ANALYTICS</p>

            <div class="menu-link-active">
                <img class="menu-icon" src="images/icons/dashboard_icon_active.png" alt="">
                <a href="<?= htmlspecialchars($dashboard_url) ?>" class="<?= $current_page == $dashboard_url ? 'active' : '' ?>">Dashboard</a>
            </div>
            <p class="menu-header">BASE</p>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/account_approval_icon.png" alt="">
                <a href="manage_staff.php" class="<?= $current_page == 'manage_staff.php' ? 'active' : '' ?>">Manage Staff</a>
            </div>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/account_approval_icon.png" alt="">
                <a href="account_approval.php" class="<?= $current_page == 'account_approval.php' ? 'active' : '' ?>">Account Approval</a>
            </div>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/announcement_icon.png" alt="">
                <a href="announcements.php" class="<?= $current_page == 'announcements.php' ? 'active' : '' ?>">Announcement</a>
            </div>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/calendar_icon.png" alt="">
                <a href="edit_calendar.php" class="<?= $current_page == 'edit_calendar.php' ? 'active' : '' ?>">Calendar</a>
            </div>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/calendar_icon.png" alt="">
                <a href="service_management.php" class="<?= $current_page == 'service_management.php' ? 'active' : '' ?>">Service Management</a>
            </div>
            <p class="menu-header">OTHERS</p>
            <div class="menu-link">
                <img class="menu-icon" src="images/icons/logout_icon.png" alt="">
                <a href="logout.php" class="logout-button">Log Out</a>
            </div>
        </div>
    </div>

    <div class="dashboard-content">
        <h1>Dashboard Overview</h1>
        
        <!-- Stats Cards -->
        <div class="stats-cards">
            <div class="stat-card">
                <h3>Pending Accounts</h3>
                <div class="count"><?= $pendingAccounts ?></div>
                <a href="account_requests.php" class="view-all">View Details</a>
            </div>

            <div class="stat-card">
                <h3>Pending Medicine Requests</h3>
                <div class="count"><?= $pendingReqCount ?></div>
                <a href="requests.php" class="view-all">View Details</a>
            </div>
            
            <div class="stat-card">
                <h3>Expired Medicines</h3>
                <div class="count"><?= $expiredMedicines ?></div>
                <a href="medicine_management.php?expiry_status=Expired" class="view-all">View Details</a>
            </div>

            <div class="stat-card">
                <h3>Out of Stock Medicines</h3>
                <div class="count"><?= $outOfStockMedicines ?></div>
                <a href="medicine_management.php?stock_status=Out of Stock" class="view-all">View Details</a>
            </div>

            <div class="stat-card">
                <h3>To Be Claimed Medicines</h3>
                <div class="count"><?= $toBeClaimedMedicines ?></div>
                <a href="pending_requests.php" class="view-all">View Details</a>
            </div>
            
            <div class="stat-card">
                <h3>Active Announcements</h3>
                <div class="count"><?= $totalAnnouncements ?></div>
                <a href="announcements.php" class="view-all">View Details</a>
            </div>
            <div class="stat-card">
                <h3>Upcoming Events</h3>
                <div class="count"><?= $upcomingEvents ?></div>
                <a href="edit_calendar.php" class="view-all">View Details</a>
            </div>
        </div> 

        <!-- Alert Sections -->
        <div class="alert-section">
            <h2>Critical Alerts</h2>
            
            <div class="alert-cards">
                <!-- Expiring Medicines -->
                <div class="alert-card">
                    <h3>Expiring Medicines</h3>
                    <ul class="alert-list">
                        <?php if (empty($expiringMedicines)): ?>
                            <li>No expiring medicines at the moment.</li>
                        <?php else: ?>
                            <?php foreach ($expiringMedicines as $medicine): ?>
                                <li class="<?= $medicine['expiry_status'] == 'Expired' ? 'critical' : 'warning' ?>">
                                    <?= htmlspecialchars($medicine['generic_name']) ?>
                                    <?php if (!empty($medicine['brand_name'])): ?>
                                        (<?= htmlspecialchars($medicine['brand_name']) ?>)
                                    <?php endif; ?>
                                    - Expiry: <span class="expiry-date"><?= htmlspecialchars($medicine['expiration_date']) ?></span>
                                    (<?= htmlspecialchars($medicine['expiry_status']) ?>)
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                    <a href="medicine_management.php?expiry_status=Expiring within a month" class="view-all">View All Expiring Medicines</a>
                </div>
                
                <!-- Low Stock Medicines -->
                <div class="alert-card">
                    <h3>Low Stock Medicines</h3>
                    <ul class="alert-list">
                        <?php if (empty($lowStockMedicines)): ?>
                            <li>No low stock medicines at the moment.</li>
                        <?php else: ?>
                            <?php foreach ($lowStockMedicines as $medicine): ?>
                                <li class="<?= $medicine['stock_status'] == 'Out of Stock' ? 'critical' : 'warning' ?>">
                                    <?= htmlspecialchars($medicine['generic_name']) ?>
                                    <?php if (!empty($medicine['brand_name'])): ?>
                                        (<?= htmlspecialchars($medicine['brand_name']) ?>)
                                    <?php endif; ?>
                                    - Stock: <span class="stock-level <?= $medicine['stock_status'] == 'Out of Stock' ? 'critical-stock' : 'low-stock' ?>">
                                        <?= htmlspecialchars($medicine['stocks']) ?> / Min: <?= htmlspecialchars($medicine['min_stock']) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                    <a href="medicine_management.php?stock_status=Low Stock" class="view-all">View All Low Stock Medicines</a>
                </div>
            </div>
        </div>

        <h2>Reports and Statistics</h2>

        <div class="stats-cards">
            <div class="stat-card">
                <h3>Total Users</h3>
                <div class="count"><?= $totalUsers ?></div>
                <a href="users_stats.php" class="view-all">View Details</a>
            </div>
            
            <div class="stat-card">
                <h3>Total Medicines</h3>
                <div class="count"><?= $totalMedicines ?></div>
                <a href="medicine_stats.php" class="view-all">View Details</a>
            </div>

            <div class="stat-card">
                <h3>Total Patients</h3>
                <div class="count"><?= $totalPatients ?></div>
                <a href="patient_stats.php" class="view-all">View Details</a>
            </div>
            
            <div class="stat-card">
                <h3>Consultations This Month</h3>
                <div class="count"><?= $consultationsThisMonth ?></div>
                <a href="consultation_stats.php" class="view-all">View Details</a>
            </div>
        </div>

    </div>
</body>
</html>