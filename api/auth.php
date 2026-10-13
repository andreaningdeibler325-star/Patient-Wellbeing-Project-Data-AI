<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/db.php';
require __DIR__ . '/session.php';

startSecureSession();

function authJson(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        authJson(400, [
            'success' => false,
            'error' => 'Request body is empty.'
        ]);
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        authJson(400, [
            'success' => false,
            'error' => 'Invalid JSON request.'
        ]);
    }

    return $data;
}

function hasEmailColumn(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT CASE
            WHEN COL_LENGTH('dbo.AppUser', 'Email') IS NOT NULL THEN 1
            ELSE 0
         END"
    );

    return (int)$stmt->fetchColumn() === 1;
}

function pwdColumnCanStoreHash(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        "SELECT CHARACTER_MAXIMUM_LENGTH
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = 'dbo'
           AND TABLE_NAME = 'AppUser'
           AND COLUMN_NAME = 'PWD'"
    );
    $stmt->execute();

    $length = $stmt->fetchColumn();

    if ($length === false || $length === null) {
        return false;
    }

    // SQL Server reports -1 for NVARCHAR(MAX)/VARCHAR(MAX).
    return (int)$length === -1 || (int)$length >= 255;
}

function isStrongPassword(string $password): bool
{
    return strlen($password) >= 8
        && strlen($password) <= 200
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1
        && preg_match('/[^A-Za-z0-9]/', $password) === 1;
}

function isValidUsername(string $username): bool
{
    return preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) === 1;
}

function isActiveUser(array $user): bool
{
    return strtoupper(trim((string)($user['IsActive'] ?? ''))) === 'Y';
}

function storeSession(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['ID'];
    $_SESSION['username'] = (string)$user['Username'];
    $_SESSION['email'] =
        isset($user['Email']) && $user['Email'] !== null
            ? (string)$user['Email']
            : null;
    $_SESSION['display_name'] =
        isset($user['DisplayName']) && $user['DisplayName'] !== null
            ? (string)$user['DisplayName']
            : null;
    $_SESSION['job_role'] =
        isset($user['JobRole']) && $user['JobRole'] !== null
            ? (string)$user['JobRole']
            : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    authJson(405, [
        'success' => false,
        'error' => 'POST required.'
    ]);
}

try {
    $data = readJsonBody();
    $action = strtolower(trim((string)($data['action'] ?? '')));

    if ($action === 'session') {
        authJson(200, [
            'success' => true,
            'authenticated' => !empty($_SESSION['user_id']),
            'user' => empty($_SESSION['user_id']) ? null : [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'] ?? null,
                'email' => $_SESSION['email'] ?? null,
                'displayName' => $_SESSION['display_name'] ?? null,
                'jobRole' => $_SESSION['job_role'] ?? null,
            ],
        ]);
    }

    if ($action === 'logout') {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'] ?? '',
                (bool)$p['secure'],
                (bool)$p['httponly']
            );
        }

        session_destroy();

        authJson(200, [
            'success' => true,
            'message' => 'Signed out.'
        ]);
    }

    if (!in_array($action, ['login', 'signup'], true)) {
        authJson(400, [
            'success' => false,
            'error' => 'Unsupported action.'
        ]);
    }

    $pdo = getDb();
    $emailColumnExists = hasEmailColumn($pdo);

    // ================================================================
    // LOGIN
    // Front end sends:
    // {
    //   "action": "login",
    //   "loginIdentifier": "AdaNDeib" OR "name@example.com",
    //   "password": "...",
    //   "remember": false
    // }
    // ================================================================
    if ($action === 'login') {
        $loginIdentifier =
            trim((string)($data['loginIdentifier'] ?? $data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($loginIdentifier === '' || strlen($loginIdentifier) > 254) {
            authJson(400, [
                'success' => false,
                'error' => 'Enter your email or username.'
            ]);
        }

        // Do not apply new-account complexity rules during login.
        if ($password === '' || strlen($password) > 200) {
            authJson(400, [
                'success' => false,
                'error' => 'Enter your password.'
            ]);
        }

        $selectColumns =
            'ID, Username, PWD, DisplayName, JobRole, IsActive';

        if ($emailColumnExists) {
            $selectColumns .= ', Email';

            $stmt = $pdo->prepare(
                "SELECT TOP (1) {$selectColumns}
                 FROM dbo.AppUser
                 WHERE Username = :username
                    OR LOWER(Email) = :email"
            );

            $stmt->execute([
                ':username' => $loginIdentifier,
                ':email' => strtolower($loginIdentifier),
            ]);
        } else {
            // This lets us test an existing Username account before
            // we add the new Email column to dbo.AppUser.
            $stmt = $pdo->prepare(
                "SELECT TOP (1) {$selectColumns}
                 FROM dbo.AppUser
                 WHERE Username = :username"
            );

            $stmt->execute([
                ':username' => $loginIdentifier
            ]);
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Same public error for unknown user, bad password, or inactive user.
        if (!$user || !isActiveUser($user)) {
            authJson(401, [
                'success' => false,
                'error' => 'Invalid email/username or password.'
            ]);
        }

        $storedPassword = (string)$user['PWD'];

        // Preferred case: PWD already contains a password_hash() value.
        $validPassword = password_verify($password, $storedPassword);
        $plainTextUpgrade = false;

        // Transitional support for the existing prototype rows that still
        // contain readable test passwords. If the plaintext password matches,
        // allow the login and, when the PWD column is wide enough, immediately
        // replace it with a secure PHP password hash.
        if (
            !$validPassword
            && strlen($storedPassword) === strlen($password)
            && hash_equals($storedPassword, $password)
        ) {
            $validPassword = true;
            $plainTextUpgrade = true;
        }

        if (!$validPassword) {
            authJson(401, [
                'success' => false,
                'error' => 'Invalid email/username or password.'
            ]);
        }

        if ($plainTextUpgrade) {
            if (pwdColumnCanStoreHash($pdo)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);

                if ($newHash === false) {
                    throw new RuntimeException('Password hashing failed.');
                }

                $upgrade = $pdo->prepare(
                    'UPDATE dbo.AppUser
                     SET PWD = :hash
                     WHERE ID = :id'
                );

                $upgrade->execute([
                    ':hash' => $newHash,
                    ':id' => (int)$user['ID'],
                ]);

                // Keep local copy aligned with what is now stored.
                $user['PWD'] = $newHash;
            } else {
                error_log(
                    '[auth.php] Login succeeded, but dbo.AppUser.PWD is too short ' .
                    'to store a secure password hash. Increase it to NVARCHAR(255).'
                );
            }
        } elseif (password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
            if (pwdColumnCanStoreHash($pdo)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);

                if ($newHash === false) {
                    throw new RuntimeException('Password hashing failed.');
                }

                $rehash = $pdo->prepare(
                    'UPDATE dbo.AppUser
                     SET PWD = :hash
                     WHERE ID = :id'
                );

                $rehash->execute([
                    ':hash' => $newHash,
                    ':id' => (int)$user['ID'],
                ]);
            }
        }

        storeSession($user);

        authJson(200, [
            'success' => true,
            'message' => 'Signed in.',
            'redirect' => 'Dashboard.html',
            'user' => [
                'id' => (int)$user['ID'],
                'username' => (string)$user['Username'],
                'displayName' =>
                    $user['DisplayName'] !== null
                        ? (string)$user['DisplayName']
                        : null,
                'jobRole' =>
                    $user['JobRole'] !== null
                        ? (string)$user['JobRole']
                        : null,
            ],
        ]);
    }

    // ================================================================
    // SIGNUP
    // Front end sends:
    // {
    //   "action": "signup",
    //   "username": "...",
    //   "email": "...",
    //   "password": "..."
    // }
    // ================================================================
    $username = trim((string)($data['username'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');

    if (!isValidUsername($username)) {
        authJson(400, [
            'success' => false,
            'error' =>
                'Username must be 3–50 characters and use only letters, ' .
                'numbers, period, underscore, or hyphen.'
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        authJson(400, [
            'success' => false,
            'error' => 'Enter a valid email address.'
        ]);
    }

    if (!isStrongPassword($password)) {
        authJson(400, [
            'success' => false,
            'error' =>
                'Password must have at least 8 characters, including uppercase, ' .
                'lowercase, a number, and a special character.'
        ]);
    }

    if (!$emailColumnExists) {
        error_log(
            '[auth.php] Signup requested before dbo.AppUser.Email was added.'
        );

        authJson(503, [
            'success' => false,
            'error' =>
                'Account creation is not configured yet. Please try again after ' .
                'the database update.'
        ]);
    }

    if (!pwdColumnCanStoreHash($pdo)) {
        error_log(
            '[auth.php] Signup blocked because dbo.AppUser.PWD is shorter than 255 characters.'
        );

        authJson(503, [
            'success' => false,
            'error' =>
                'Account creation is not configured yet. Please try again after ' .
                'the database update.'
        ]);
    }

    $check = $pdo->prepare(
        'SELECT TOP (1) ID
         FROM dbo.AppUser
         WHERE Username = :username
            OR LOWER(Email) = :email'
    );

    $check->execute([
        ':username' => $username,
        ':email' => $email,
    ]);

    if ($check->fetchColumn() !== false) {
        authJson(409, [
            'success' => false,
            'error' => 'That username or email is already in use.'
        ]);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($hash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    /*
     * For this prototype, a newly created self-service account receives
     * JobRole='User' and IsActive='Y'. If you later add administrator
     * approval, change IsActive to 'N' here and activate users separately.
     */
    $insert = $pdo->prepare(
        "INSERT INTO dbo.AppUser
            (Username, Email, PWD, DisplayName, JobRole, IsActive, CDate)
         OUTPUT INSERTED.ID
         VALUES
            (:username, :email, :pwd, :displayName, 'User', 'Y', CONVERT(date, SYSDATETIME()))"
    );

    $insert->execute([
        ':username' => $username,
        ':email' => $email,
        ':pwd' => $hash,
        ':displayName' => $username,
    ]);

    $userId = (int)$insert->fetchColumn();

    $newUser = [
        'ID' => $userId,
        'Username' => $username,
        'Email' => $email,
        'DisplayName' => $username,
        'JobRole' => 'User',
        'IsActive' => 'Y',
    ];

    storeSession($newUser);

    authJson(201, [
        'success' => true,
        'message' => 'Account created.',
        'redirect' => 'Dashboard.html',
        'user' => [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'displayName' => $username,
            'jobRole' => 'User',
        ],
    ]);

} catch (PDOException $e) {
    error_log('[auth.php] Database error: ' . $e->getMessage());

    authJson(500, [
        'success' => false,
        'error' => 'Authentication service is temporarily unavailable.'
    ]);
} catch (Throwable $e) {
    error_log('[auth.php] Error: ' . $e->getMessage());

    authJson(500, [
        'success' => false,
        'error' => 'Authentication service is temporarily unavailable.'
    ]);
}
