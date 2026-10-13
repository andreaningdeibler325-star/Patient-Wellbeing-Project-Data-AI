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

    // Only POST requests are allowed.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sendJson(405, [
            'success' => false,
            'error' => 'Method not allowed. Please use POST.'
        ]);
    }

    // Read JSON sent by JavaScript.
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

    // Required parameters.
    $requiredFields = [
        'minimumAge',
        'minimumIndicators',
        'monthsSinceCheckup'
    ];

    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data)) {
            sendJson(400, [
                'success' => false,
                'error' => "Missing required field: $field"
            ]);
        }
    }

    // Validate integers.
    $minimumAge =
        filter_var($data['minimumAge'], FILTER_VALIDATE_INT);

    $minimumIndicators =
        filter_var($data['minimumIndicators'], FILTER_VALIDATE_INT);

    $monthsSinceCheckup =
        filter_var($data['monthsSinceCheckup'], FILTER_VALIDATE_INT);

    if ($minimumAge === false || $minimumAge < 0 || $minimumAge > 120) {
        sendJson(400, [
            'success' => false,
            'error' => 'minimumAge must be an integer between 0 and 120.'
        ]);
    }

    if (
        $minimumIndicators === false ||
        $minimumIndicators < 1 ||
        $minimumIndicators > 7
    ) {
        sendJson(400, [
            'success' => false,
            'error' => 'minimumIndicators must be an integer between 1 and 7.'
        ]);
    }

    if (
        $monthsSinceCheckup === false ||
        $monthsSinceCheckup < 1 ||
        $monthsSinceCheckup > 120
    ) {
        sendJson(400, [
            'success' => false,
            'error' => 'monthsSinceCheckup must be an integer between 1 and 120.'
        ]);
    }

    // New code 
    // Connect to SQL Server and execute the stored procedure.
try {

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

    // Call the stored procedure using parameterized values.
    $sql = "
        EXEC dbo.usp_FindWellbeingFollowUpCandidates
            @MinimumAge = :minimumAge,
            @MinimumIndicators = :minimumIndicators,
            @MonthsSinceCheckup = :monthsSinceCheckup
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->bindValue(
        ':minimumAge',
        $minimumAge,
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        ':minimumIndicators',
        $minimumIndicators,
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        ':monthsSinceCheckup',
        $monthsSinceCheckup,
        PDO::PARAM_INT
    );

    $stmt->execute();

    $patients = $stmt->fetchAll();

    sendJson(200, [
        'success' => true,
        'message' => 'Query executed successfully.',
        'parameters' => [
            'minimumAge' => $minimumAge,
            'minimumIndicators' => $minimumIndicators,
            'monthsSinceCheckup' => $monthsSinceCheckup
        ],
        'count' => count($patients),
        'patients' => $patients
    ]);

} catch (PDOException $e) {

    error_log('Database error: ' . $e->getMessage());

    sendJson(500, [
        'success' => false,
        'error' => 'Database connection or query failed.'
    ]);
}




} catch (Throwable $e) {

    sendJson(500, [
        'success' => false,
        'error' => 'Internal server error.'
    ]);
}


?>