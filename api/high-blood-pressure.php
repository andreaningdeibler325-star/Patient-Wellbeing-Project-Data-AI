<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function sendJson(int $statusCode, array $data): never
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sendJson(405, [
            'success' => false,
            'error' => 'Method not allowed. Please use POST.'
        ]);
    }

    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        sendJson(400, [
            'success' => false,
            'error' => 'Request body is empty.'
        ]);
    }

    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        sendJson(400, [
            'success' => false,
            'error' => 'Invalid JSON request.'
        ]);
    }

    $requiredFields = [
        'minimumAge',
        'minimumSystolic',
        'minimumDiastolic',
        'highSystolic',
        'highDiastolic'
    ];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data)) {
            sendJson(400, [
                'success' => false,
                'error' => "Missing required field: $field"
            ]);
        }
    }

    $minimumAge =
        filter_var($data['minimumAge'], FILTER_VALIDATE_INT);

    $minimumSystolic =
        filter_var($data['minimumSystolic'], FILTER_VALIDATE_INT);

    $minimumDiastolic =
        filter_var($data['minimumDiastolic'], FILTER_VALIDATE_INT);

    $highSystolic =
        filter_var($data['highSystolic'], FILTER_VALIDATE_INT);

    $highDiastolic =
        filter_var($data['highDiastolic'], FILTER_VALIDATE_INT);

    if ($minimumAge === false || $minimumAge < 18 || $minimumAge > 120) {
        sendJson(400, [
            'success' => false,
            'error' => 'minimumAge must be an integer between 18 and 120.'
        ]);
    }

    if (
        $minimumSystolic === false ||
        $minimumSystolic <= 0 ||
        $minimumSystolic > 300
    ) {
        sendJson(400, [
            'success' => false,
            'error' => 'minimumSystolic must be between 1 and 300.'
        ]);
    }

    if (
        $minimumDiastolic === false ||
        $minimumDiastolic <= 0 ||
        $minimumDiastolic > 200
    ) {
        sendJson(400, [
            'success' => false,
            'error' => 'minimumDiastolic must be between 1 and 200.'
        ]);
    }

    if (
        $highSystolic === false ||
        $highSystolic <= $minimumSystolic ||
        $highSystolic > 300
    ) {
        sendJson(400, [
            'success' => false,
            'error' =>
                'highSystolic must be greater than minimumSystolic and no more than 300.'
        ]);
    }

    if (
        $highDiastolic === false ||
        $highDiastolic <= $minimumDiastolic ||
        $highDiastolic > 200
    ) {
        sendJson(400, [
            'success' => false,
            'error' =>
                'highDiastolic must be greater than minimumDiastolic and no more than 200.'
        ]);
    }

    $dsn =
        "sqlsrv:Server=localhost;" .
        "Database=PatientWellbeingDB;" .
        "Encrypt=1;" .
        "TrustServerCertificate=1";

    $pdo = new PDO(
        $dsn,
        null,
        null,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $sql = "
        EXEC dbo.usp_FindHighBloodPressurePatients
            @MinimumAge = :minimumAge,
            @MinimumSystolic = :minimumSystolic,
            @MinimumDiastolic = :minimumDiastolic,
            @HighSystolic = :highSystolic,
            @HighDiastolic = :highDiastolic
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->bindValue(':minimumAge', $minimumAge, PDO::PARAM_INT);
    $stmt->bindValue(':minimumSystolic', $minimumSystolic, PDO::PARAM_INT);
    $stmt->bindValue(':minimumDiastolic', $minimumDiastolic, PDO::PARAM_INT);
    $stmt->bindValue(':highSystolic', $highSystolic, PDO::PARAM_INT);
    $stmt->bindValue(':highDiastolic', $highDiastolic, PDO::PARAM_INT);

    $stmt->execute();

    $patients = $stmt->fetchAll();

    sendJson(200, [
        'success' => true,
        'message' => 'High blood pressure query executed successfully.',
        'parameters' => [
            'minimumAge' => $minimumAge,
            'minimumSystolic' => $minimumSystolic,
            'minimumDiastolic' => $minimumDiastolic,
            'highSystolic' => $highSystolic,
            'highDiastolic' => $highDiastolic
        ],
        'count' => count($patients),
        'patients' => $patients
    ]);

} catch (PDOException $e) {

    error_log(
        '[high-blood-pressure.php] Database error: ' .
        $e->getMessage()
    );

    sendJson(500, [
        'success' => false,
        'error' => 'Database connection or query failed.'
    ]);

} catch (Throwable $e) {

    error_log(
        '[high-blood-pressure.php] Error: ' .
        $e->getMessage()
    );

    sendJson(500, [
        'success' => false,
        'error' => 'Internal server error.'
    ]);
}