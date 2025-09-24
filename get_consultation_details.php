<?php
// get_consultation_details.php
require_once 'config.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['id'])) {
        echo json_encode(['error' => 'Missing id']);
        exit();
    }
    $id = (int)$_GET['id'];

    $stmt = $conn->prepare("SELECT id, patient_id, consultation_type, consultation_date, reason_for_consultation, blood_pressure, temperature, diagnosis, prescribed_medicine, treatment_given, consulting_physician_nurse FROM consultations WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) {
        echo json_encode(['error' => 'Not found']);
        exit();
    }
    echo json_encode($data);
} catch (Exception $e) {
    echo json_encode(['error' => 'Failed']);
}
?>


