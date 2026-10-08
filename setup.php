<?php
// ---------------------------------------------------------------
// Travel Buea - one-time database setup.
// Open http://travelbuea.test/setup.php (or http://localhost/travelbuea/setup.php)
// in your browser, choose an admin password, and click the button.
// ---------------------------------------------------------------
require __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

function page($title, $html) {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f3f4f6;margin:0;padding:2rem}'
       . '.box{max-width:480px;margin:auto;background:#fff;padding:2rem;border-radius:14px;box-shadow:0 4px 20px #0001}'
       . 'h1{margin-top:0;font-size:1.4rem}label{font-weight:600;display:block;margin:1rem 0 .3rem}'
       . 'input{width:100%;padding:.7rem;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}'
       . 'button{margin-top:1.2rem;padding:.8rem 1.4rem;background:#1d4ed8;color:#fff;border:0;border-radius:8px;font-weight:700;cursor:pointer}'
       . '.ok{color:#047857}.err{color:#b91c1c}code{background:#eef2ff;padding:.1rem .4rem;border-radius:4px}</style>'
       . '</head><body><div class="box">' . $html . '</div></body></html>';
    exit;
}

$lock = __DIR__ . '/setup.lock';
if (file_exists($lock)) {
    page('Already set up', '<h1>Already set up</h1><p>The database was created earlier. '
        . 'To run setup again, delete the file <code>setup.lock</code> in the project folder.</p>');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    page('Travel Buea setup',
        '<h1>Travel Buea - database setup</h1>'
        . '<p>This creates the <code>' . DB_NAME . '</code> database, its tables, the bus schedules, and the admin account.</p>'
        . '<form method="post"><label>Admin username</label>'
        . '<input value="admin@travelbuea.cm" disabled>'
        . '<label>Choose an admin password (min. 8 characters)</label>'
        . '<input type="password" name="admin_password" minlength="8" required>'
        . '<button type="submit">Create database</button></form>');
}

$adminPassword = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
if (strlen($adminPassword) < 8) {
    page('Setup', '<h1 class="err">Password too short</h1><p>Go back and use at least 8 characters.</p>');
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        identifier VARCHAR(120) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_identifier (identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        token_hash CHAR(64) NOT NULL PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS schedules (
        id VARCHAR(20) NOT NULL PRIMARY KEY,
        bus_id VARCHAR(30) NOT NULL,
        agency VARCHAR(60) NOT NULL,
        logo_key VARCHAR(60) NOT NULL DEFAULT '',
        travel_class VARCHAR(60) NOT NULL,
        origin VARCHAR(60) NOT NULL,
        destination VARCHAR(60) NOT NULL,
        departure_time VARCHAR(20) NOT NULL,
        arrival_time VARCHAR(20) NOT NULL,
        duration VARCHAR(20) NOT NULL,
        price_cfa INT UNSIGNED NOT NULL,
        seats_total INT UNSIGNED NOT NULL,
        features TEXT NOT NULL,
        departure_terminal VARCHAR(80) NOT NULL,
        arrival_terminal VARCHAR(80) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        booking_ref VARCHAR(20) NOT NULL,
        user_id INT UNSIGNED NULL,
        schedule_id VARCHAR(20) NULL,
        passenger_name VARCHAR(120) NOT NULL,
        phone VARCHAR(40) NOT NULL,
        id_passport VARCHAR(60) NOT NULL,
        route VARCHAR(120) NOT NULL,
        agency VARCHAR(60) NOT NULL,
        schedule_time VARCHAR(60) NOT NULL,
        seat_number VARCHAR(6) NOT NULL,
        travel_class VARCHAR(60) NOT NULL,
        amount_cfa INT UNSIGNED NOT NULL,
        payment_method VARCHAR(30) NOT NULL,
        payment_status VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Confirmed',
        transit_code VARCHAR(30) NOT NULL,
        bus_id VARCHAR(30) NOT NULL,
        travel_date DATE NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_ref (booking_ref),
        UNIQUE KEY uq_seat (schedule_id, seat_number, travel_date),
        KEY idx_transit (transit_code),
        KEY idx_user (user_id),
        CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_bookings_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        booking_ref VARCHAR(20) NOT NULL,
        provider VARCHAR(30) NOT NULL,
        phone VARCHAR(40) NOT NULL,
        amount_cfa INT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_ref (booking_ref)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- bus schedules (same buses that were hardcoded in the page) ----
    $schedules = array(
        array('id' => 'sch-101', 'bus' => 'BX-204', 'agency' => 'Musango', 'logo' => 'musangoLogo', 'cls' => 'Standard Classic', 'o' => 'Buea', 'd' => 'Douala', 'dep' => '07:30 AM', 'arr' => '09:15 AM', 'dur' => '1h 45m', 'price' => 3000, 'seats' => 8, 'feats' => array('AC', 'Daily'), 'dt' => 'Mile 17 Park', 'at' => 'Bonaberi Park', 'sort' => 1),
        array('id' => 'sch-102', 'bus' => 'BX-308', 'agency' => 'Guarantee', 'logo' => 'guaranteeLogo', 'cls' => 'VIP Air-con', 'o' => 'Buea', 'd' => 'Douala', 'dep' => '09:00 AM', 'arr' => '11:00 AM', 'dur' => '2h 00m', 'price' => 6000, 'seats' => 15, 'feats' => array('AC', 'Express', 'Recliner'), 'dt' => 'Molyko Terminal', 'at' => 'Akwa Terminal', 'sort' => 2),
        array('id' => 'sch-103', 'bus' => 'GL-501', 'agency' => 'Golden', 'logo' => 'goldenLogo', 'cls' => 'Premium VIP', 'o' => 'Buea', 'd' => 'Douala', 'dep' => '10:45 AM', 'arr' => '12:35 PM', 'dur' => '1h 50m', 'price' => 8000, 'seats' => 2, 'feats' => array('WiFi', 'AC', 'Snacks'), 'dt' => 'Mile 17 Park', 'at' => 'Besengue Park', 'sort' => 3),
        array('id' => 'sch-104', 'bus' => 'MS-VIP2', 'agency' => 'Musango', 'logo' => 'musangoVipLogo', 'cls' => 'VIP Air-con', 'o' => 'Buea', 'd' => 'Douala', 'dep' => '01:00 PM', 'arr' => '02:45 PM', 'dur' => '1h 45m', 'price' => 6000, 'seats' => 12, 'feats' => array('AC', 'Comfort', 'WiFi'), 'dt' => 'Mile 17 Park', 'at' => 'Bonaberi Park', 'sort' => 4),
        array('id' => 'sch-105', 'bus' => 'GE-701', 'agency' => 'General Express', 'logo' => 'generalExpressLogo', 'cls' => 'VIP Executive', 'o' => 'Buea', 'd' => 'Yaoundé', 'dep' => '06:30 AM', 'arr' => '11:45 AM', 'dur' => '5h 15m', 'price' => 8500, 'seats' => 5, 'feats' => array('WiFi', 'AC', 'Recliner', 'Snacks'), 'dt' => 'Mile 17 Park', 'at' => 'Mvan Terminal', 'sort' => 5),
        array('id' => 'sch-106', 'bus' => 'MS-YAO3', 'agency' => 'Musango', 'logo' => 'musangoLogo', 'cls' => 'Classic', 'o' => 'Buea', 'd' => 'Yaoundé', 'dep' => '10:30 AM', 'arr' => '03:45 PM', 'dur' => '5h 15m', 'price' => 6000, 'seats' => 18, 'feats' => array('Classic', 'Comfort'), 'dt' => 'Mile 17 Park', 'at' => 'Mvan Terminal', 'sort' => 6),
        array('id' => 'sch-107', 'bus' => 'KRI-901', 'agency' => 'Guarantee', 'logo' => 'guaranteeLogo', 'cls' => 'VIP Air-con', 'o' => 'Buea', 'd' => 'Kribi', 'dep' => '08:15 AM', 'arr' => '12:15 PM', 'dur' => '4h 00m', 'price' => 7000, 'seats' => 9, 'feats' => array('Express', 'Tourist', 'AC'), 'dt' => 'Mile 17 Park', 'at' => 'Kribi Beach Central', 'sort' => 7),
        array('id' => 'sch-108', 'bus' => 'LMB-101', 'agency' => 'Musango', 'logo' => 'musangoLogo', 'cls' => 'Standard Classic', 'o' => 'Buea', 'd' => 'Limbe', 'dep' => '07:00 AM', 'arr' => '07:45 AM', 'dur' => '0h 45m', 'price' => 1000, 'seats' => 22, 'feats' => array('Shuttle', 'Frequent'), 'dt' => 'Mile 17 Park', 'at' => 'Limbe Down Beach', 'sort' => 8),
    );
    $ins = $pdo->prepare('INSERT INTO schedules
        (id, bus_id, agency, logo_key, travel_class, origin, destination, departure_time, arrival_time, duration,
         price_cfa, seats_total, features, departure_terminal, arrival_terminal, sort_order)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE bus_id=VALUES(bus_id)');
    foreach ($schedules as $s) {
        $ins->execute(array($s['id'], $s['bus'], $s['agency'], $s['logo'], $s['cls'], $s['o'], $s['d'],
            $s['dep'], $s['arr'], $s['dur'], $s['price'], $s['seats'], json_encode($s['feats']),
            $s['dt'], $s['at'], $s['sort']));
    }

    // ---- admin account ----
    $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $now = date('Y-m-d H:i:s');
    $chk = $pdo->prepare('SELECT id FROM users WHERE identifier = ?');
    $chk->execute(array('admin@travelbuea.cm'));
    if ($chk->fetch()) {
        $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin' WHERE identifier = ?")
            ->execute(array($hash, 'admin@travelbuea.cm'));
    } else {
        $pdo->prepare("INSERT INTO users (name, identifier, password_hash, role, created_at) VALUES (?,?,?,?,?)")
            ->execute(array('Travel Buea Admin', 'admin@travelbuea.cm', $hash, 'admin', $now));
    }

    file_put_contents($lock, 'setup done ' . $now);
    page('Setup complete',
        '<h1 class="ok">Setup complete &#10003;</h1>'
        . '<p>Database <code>' . DB_NAME . '</code> is ready.</p>'
        . '<p>Admin login: <code>admin@travelbuea.cm</code> with the password you just chose.</p>'
        . '<p><a href="index.html">Open the Travel Buea website</a></p>');
} catch (Exception $e) {
    page('Setup failed', '<h1 class="err">Setup failed</h1><p>Make sure Laragon is running (Apache <b>and</b> MySQL are green).</p>'
        . '<p><code>' . htmlspecialchars($e->getMessage()) . '</code></p>');
}
