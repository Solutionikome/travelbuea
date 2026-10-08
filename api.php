<?php
// ---------------------------------------------------------------
// Travel Buea - API
// Every request looks like:  api.php?route=<name>
// Sign-in tokens are sent in the "X-Auth-Token" header.
// ---------------------------------------------------------------
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ---------- small helpers ----------
function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail($message, $code = 400) { out(array('error' => $message), $code); }

function body() {
    static $b = null;
    if ($b === null) {
        $b = json_decode(file_get_contents('php://input'), true);
        if (!is_array($b)) { $b = array(); }
    }
    return $b;
}
// Read a field exactly as sent (used for passwords - never trimmed)
function rawField($key) {
    $b = body();
    return (isset($b[$key]) && is_string($b[$key])) ? $b[$key] : '';
}
// Read a text field from the JSON body (trimmed, length-limited)
function field($key, $max = 200) {
    $b = body();
    if (!isset($b[$key]) || (!is_string($b[$key]) && !is_numeric($b[$key]))) { return ''; }
    return mb_substr(trim((string)$b[$key]), 0, $max);
}

// Phones and emails can be typed in many ways ("+237 677 89 22 10", "677892210", "Ann@Mail.com").
// Turn them into ONE standard form so sign-up and login always match.
function normalizeIdentifier($raw) {
    $v = trim($raw);
    if (strpos($v, '@') !== false) { return strtolower($v); }   // email: lower-case
    $digits = preg_replace('/\D+/', '', $v);                    // phone: digits only
    if ($digits === '' || $digits === null) { return strtolower($v); }
    if (strlen($digits) > 9 && substr($digits, 0, 3) === '237') { $digits = substr($digits, 3); } // drop country code
    return $digits;
}
// Full names: tidy extra spaces so "Emmanuel   Ncho " matches "Emmanuel Ncho"
function cleanName($raw) {
    $v = preg_replace('/\s+/u', ' ', trim($raw));
    return mb_substr($v === null ? trim($raw) : $v, 0, 100);
}
// Older accounts may have been saved before normalizing - still let them in
function legacyIdentifier($raw) { return strtolower(trim($raw)); }

$SEATS = array('01A','01B','01C','01D','02A','02B','02C','02D','03A','03B','03C','03D',
               '12A','12B','12C','12D','14A','14B','14C','14D');
$PAY_METHODS = array('MTN MoMo', 'Orange Money', 'Terminal Cash');
$SERVICE_FEE = 200;

// ---------- sign-in tokens ----------
function issueToken($userId, $hours) {
    $token = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    db()->prepare('DELETE FROM api_tokens WHERE expires_at < ?')->execute(array($now));
    db()->prepare('INSERT INTO api_tokens (token_hash, user_id, created_at, expires_at) VALUES (?,?,?,?)')
        ->execute(array(hash('sha256', $token), $userId, $now, date('Y-m-d H:i:s', time() + $hours * 3600)));
    return $token;
}
function authUser($role = null) {
    $t = isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? $_SERVER['HTTP_X_AUTH_TOKEN'] : '';
    if ($t === '') { return null; }
    $st = db()->prepare('SELECT u.* FROM api_tokens t JOIN users u ON u.id = t.user_id
                         WHERE t.token_hash = ? AND t.expires_at > ?');
    $st->execute(array(hash('sha256', $t), date('Y-m-d H:i:s')));
    $u = $st->fetch();
    if (!$u) { return null; }
    if ($role !== null && $u['role'] !== $role) { return null; }
    return $u;
}
function requireUser($role) {
    $u = authUser($role);
    if (!$u) { fail($role === 'admin' ? 'Admin sign-in required.' : 'Please sign in first.', 401); }
    return $u;
}
function publicUser($u) { return array('name' => $u['name'], 'identifier' => $u['identifier']); }

// ---------- converting database rows to what the web page expects ----------
function bookingOut($r) {
    return array(
        'id'              => $r['booking_ref'],
        'passengerName'   => $r['passenger_name'],
        'phone'           => $r['phone'],
        'idPassport'      => $r['id_passport'],
        'route'           => $r['route'],
        'agency'          => $r['agency'],
        'scheduleTime'    => $r['schedule_time'],
        'seatNumber'      => $r['seat_number'],
        'travelClass'     => $r['travel_class'],
        'amountCFA'       => (int)$r['amount_cfa'],
        'paymentMethod'   => $r['payment_method'],
        'paymentStatus'   => $r['payment_status'],
        'status'          => $r['status'],
        'transitCode'     => $r['transit_code'],
        'busId'           => $r['bus_id'],
        'date'            => date('d M Y', strtotime($r['travel_date'])),
        'bookedByAccount' => isset($r['identifier']) ? $r['identifier'] : null,
    );
}
function scheduleOut($r) {
    $features = json_decode($r['features'], true);
    return array(
        'id'                => $r['id'],
        'busId'             => $r['bus_id'],
        'agency'            => $r['agency'],
        'logoKey'           => $r['logo_key'],
        'travelClass'       => $r['travel_class'],
        'origin'            => $r['origin'],
        'destination'       => $r['destination'],
        'departureTime'     => $r['departure_time'],
        'arrivalTime'       => $r['arrival_time'],
        'duration'          => $r['duration'],
        'priceCFA'          => (int)$r['price_cfa'],
        'seatsLeft'         => max(0, (int)$r['seats_total'] - (int)$r['booked']),
        'features'          => is_array($features) ? $features : array(),
        'departureTerminal' => $r['departure_terminal'],
        'arrivalTerminal'   => $r['arrival_terminal'],
    );
}
function fetchBookings($where, $params) {
    $st = db()->prepare('SELECT b.*, u.identifier FROM bookings b
                         LEFT JOIN users u ON u.id = b.user_id ' . $where . ' ORDER BY b.id DESC');
    $st->execute($params);
    return array_map('bookingOut', $st->fetchAll());
}
function isDuplicate($e, $keyName) {
    return $e instanceof PDOException && isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062
        && ($keyName === null || strpos($e->getMessage(), $keyName) !== false);
}
function newRefNumber($attempt) {
    return $attempt < 10 ? random_int(1000, 9999) : random_int(10000, 999999);
}

// ---------- routing ----------
$route  = isset($_GET['route']) ? trim($_GET['route'], '/') : '';
$method = $_SERVER['REQUEST_METHOD'];

try {

    // ===== public: bus schedules =====
    if ($route === 'schedules' && $method === 'GET') {
        $st = db()->prepare("SELECT s.*, (SELECT COUNT(*) FROM bookings b
                 WHERE b.schedule_id = s.id AND b.travel_date = ? AND b.status <> 'Canceled') AS booked
                 FROM schedules s ORDER BY s.sort_order, s.id");
        $st->execute(array(date('Y-m-d')));
        out(array_map('scheduleOut', $st->fetchAll()));
    }

    // ===== public: which seats are already taken today =====
    if ($route === 'seats' && $method === 'GET') {
        $sid = isset($_GET['schedule_id']) ? (string)$_GET['schedule_id'] : '';
        $st = db()->prepare("SELECT seat_number FROM bookings
                             WHERE schedule_id = ? AND travel_date = ? AND status <> 'Canceled'");
        $st->execute(array($sid, date('Y-m-d')));
        out(array('taken' => array_column($st->fetchAll(), 'seat_number')));
    }

    // ===== customer: register / login / me / logout =====
    if ($route === 'register' && $method === 'POST') {
        $name = cleanName(field('name', 100));
        $identifier = normalizeIdentifier(field('identifier', 120));
        $pw = rawField('password');
        if ($name === '') { fail('Please enter your full name.'); }
        if ($identifier === '') { fail('Please enter your phone number or email.'); }
        if (strlen($pw) < 6) { fail('Password must be at least 6 characters.'); }

        // Names and phone numbers/emails must both be unique (checked against ALL accounts, admin included)
        $q = db()->prepare('SELECT id FROM users WHERE name = ? LIMIT 1');
        $q->execute(array($name));
        $nameTaken = (bool)$q->fetch();

        $q = db()->prepare('SELECT id FROM users WHERE identifier IN (?, ?) LIMIT 1');
        $q->execute(array($identifier, legacyIdentifier(field('identifier', 120))));
        $idTaken = (bool)$q->fetch();

        if ($nameTaken && $idTaken) { fail('This name and this phone number/email already exist. Please log in instead.', 409); }
        if ($nameTaken) { fail('This name already exists. Please use a different full name, or log in if it is your account.', 409); }
        if ($idTaken)   { fail('This phone number or email already exists. Please log in instead.', 409); }

        try {
            db()->prepare('INSERT INTO users (name, identifier, password_hash, role, created_at) VALUES (?,?,?,?,?)')
                ->execute(array($name, $identifier, password_hash($pw, PASSWORD_DEFAULT), 'customer', date('Y-m-d H:i:s')));
        } catch (PDOException $e) {
            // two people submitting at the very same moment
            if (isDuplicate($e, null)) { fail('This phone number or email already exists. Please log in instead.', 409); }
            throw $e;
        }
        $id = (int)db()->lastInsertId();
        out(array('token' => issueToken($id, 24 * 30),
                  'user' => array('name' => $name, 'identifier' => $identifier)), 201);
    }

    if ($route === 'login' && $method === 'POST') {
        $rawId = field('identifier', 120);   // can be a phone, an email, or the full name used at sign-up
        $pw = rawField('password');
        $u = null;

        // 1) try phone number / email first
        $st = db()->prepare("SELECT * FROM users WHERE identifier IN (?, ?) AND role = 'customer' ORDER BY id ASC LIMIT 1");
        $st->execute(array(normalizeIdentifier($rawId), legacyIdentifier($rawId)));
        $byId = $st->fetch();

        if ($byId) {
            if (!password_verify($pw, $byId['password_hash'])) {
                usleep(400000); // slow down password guessing
                fail('Incorrect password. Please try again.', 401);
            }
            $u = $byId;
        } else {
            // 2) otherwise try the full name. Names are not unique, so the password decides which account it is.
            $st = db()->prepare("SELECT * FROM users WHERE name = ? AND role = 'customer' ORDER BY id ASC LIMIT 50");
            $st->execute(array(cleanName($rawId)));
            $named = $st->fetchAll();
            if (!$named) {
                usleep(400000);
                fail('No account found with that name, phone or email. Please sign up first.', 401);
            }
            $matches = array();
            foreach ($named as $cand) {
                if (password_verify($pw, $cand['password_hash'])) { $matches[] = $cand; }
            }
            if (count($matches) === 0) {
                usleep(400000);
                fail('Incorrect password. Please try again.', 401);
            }
            if (count($matches) > 1) {
                fail('More than one account uses this name and password. Please log in with your phone number or email.', 409);
            }
            $u = $matches[0];
        }
        out(array('token' => issueToken((int)$u['id'], 24 * 30), 'user' => publicUser($u)));
    }

    if ($route === 'me' && $method === 'GET') {
        $u = requireUser('customer');
        out(array('user' => publicUser($u)));
    }

    if ($route === 'logout' && $method === 'POST') {
        $t = isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? $_SERVER['HTTP_X_AUTH_TOKEN'] : '';
        if ($t !== '') {
            db()->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute(array(hash('sha256', $t)));
        }
        out(array('ok' => true));
    }

    // ===== customer: book a seat / my tickets =====
    if ($route === 'bookings' && $method === 'POST') {
        $u = requireUser('customer');
        $sid   = field('schedule_id', 20);
        $seat  = strtoupper(field('seat_number', 4));
        $name  = field('passenger_name', 120);
        $phone = field('phone', 40);
        $idp   = field('id_passport', 60);
        $pm    = field('payment_method', 30);

        if ($name === '' || $phone === '' || $idp === '') { fail('Please fill in all passenger details.'); }
        if (!in_array($seat, $SEATS, true)) { fail('Invalid seat selected.'); }
        if (!in_array($pm, $PAY_METHODS, true)) { fail('Invalid payment method.'); }

        $st = db()->prepare('SELECT * FROM schedules WHERE id = ?');
        $st->execute(array($sid));
        $s = $st->fetch();
        if (!$s) { fail('That bus schedule no longer exists.', 404); }

        $today = date('Y-m-d');
        $cnt = db()->prepare("SELECT COUNT(*) FROM bookings WHERE schedule_id = ? AND travel_date = ? AND status <> 'Canceled'");
        $cnt->execute(array($sid, $today));
        if ((int)$cnt->fetchColumn() >= (int)$s['seats_total']) { fail('Sorry, this bus is fully booked.', 409); }

        $amount = (int)$s['price_cfa'] + $SERVICE_FEE;
        $payStatus = ($pm === 'Terminal Cash') ? 'CASH PAID' : 'MOMO PAID';
        $routeText = $s['origin'] . " \u{2192} " . $s['destination'];
        $schedTime = date('d M Y') . ', ' . $s['departure_time'];

        $ref = null;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $n = newRefNumber($attempt);
            try {
                db()->beginTransaction();
                db()->prepare('INSERT INTO bookings
                    (booking_ref, user_id, schedule_id, passenger_name, phone, id_passport, route, agency,
                     schedule_time, seat_number, travel_class, amount_cfa, payment_method, payment_status,
                     status, transit_code, bus_id, travel_date, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                  ->execute(array('BR-' . $n, $u['id'], $sid, $name, $phone, $idp, $routeText, $s['agency'],
                      $schedTime, $seat, $s['travel_class'], $amount, $pm, $payStatus,
                      'Confirmed', $n . '-XJ-21', $s['bus_id'], $today, date('Y-m-d H:i:s')));
                if ($pm !== 'Terminal Cash') {
                    // Demo: the MoMo prompt in the page is simulated, so we just record the payment.
                    // To go live, call the MTN MoMo / Orange Money API here before saving.
                    db()->prepare('INSERT INTO payments (booking_ref, provider, phone, amount_cfa, status, created_at)
                                   VALUES (?,?,?,?,?,?)')
                      ->execute(array('BR-' . $n, $pm, $phone, $amount, 'SUCCESS (simulated)', date('Y-m-d H:i:s')));
                }
                db()->commit();
                $ref = 'BR-' . $n;
                break;
            } catch (PDOException $e) {
                if (db()->inTransaction()) { db()->rollBack(); }
                if (isDuplicate($e, 'uq_seat')) { fail('Sorry, seat ' . $seat . ' was just taken. Please choose another seat.', 409); }
                if (isDuplicate($e, null)) { continue; } // reference number clash - try another one
                throw $e;
            }
        }
        if ($ref === null) { fail('Could not create the booking. Please try again.', 500); }
        $rows = fetchBookings('WHERE b.booking_ref = ?', array($ref));
        out($rows[0], 201);
    }

    if ($route === 'my-bookings' && $method === 'GET') {
        $u = requireUser('customer');
        out(fetchBookings('WHERE b.user_id = ?', array($u['id'])));
    }

    // ===== admin =====
    if ($route === 'admin/login' && $method === 'POST') {
        $username = strtolower(field('username', 120));
        $pw = rawField('password');
        $st = db()->prepare("SELECT * FROM users WHERE identifier = ? AND role = 'admin'");
        $st->execute(array($username));
        $u = $st->fetch();
        if (!$u || !password_verify($pw, $u['password_hash'])) {
            usleep(400000);
            fail('Incorrect username or password.', 401);
        }
        out(array('token' => issueToken((int)$u['id'], 8), 'user' => publicUser($u)));
    }

    if ($route === 'admin/bookings' && $method === 'GET') {
        requireUser('admin');
        out(fetchBookings('', array()));
    }

    if ($route === 'admin/users' && $method === 'GET') {
        requireUser('admin');
        $st = db()->query("SELECT name, identifier, created_at FROM users WHERE role = 'customer' ORDER BY id DESC");
        out($st->fetchAll());
    }

    // counter sale at the Mile 17 terminal
    if ($route === 'admin/bookings' && $method === 'POST') {
        requireUser('admin');
        $name   = field('passenger_name', 120);
        $phone  = field('phone', 40);
        $rt     = field('route', 120);
        $agency = field('agency', 60);
        $amount = (int)field('amount_cfa', 9);
        if ($name === '' || $phone === '' || $rt === '' || $agency === '') { fail('Please fill in all counter booking fields.'); }
        if ($amount < 0 || $amount > 1000000) { fail('Invalid amount.'); }

        $ref = null;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $n = newRefNumber($attempt);
            try {
                db()->prepare('INSERT INTO bookings
                    (booking_ref, user_id, schedule_id, passenger_name, phone, id_passport, route, agency,
                     schedule_time, seat_number, travel_class, amount_cfa, payment_method, payment_status,
                     status, transit_code, bus_id, travel_date, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                  ->execute(array('BR-' . $n, null, null, $name, $phone, 'N/A', $rt, $agency,
                      date('d M Y, h:i A'), random_int(1, 20) . 'B', 'Standard Classic', $amount,
                      'Terminal Cash', 'CASH PAID', 'Confirmed', $n . '-CT-' . random_int(10, 99),
                      'BX-204', date('Y-m-d'), date('Y-m-d H:i:s')));
                $ref = 'BR-' . $n;
                break;
            } catch (PDOException $e) {
                if (isDuplicate($e, null)) { continue; }
                throw $e;
            }
        }
        if ($ref === null) { fail('Could not create the booking. Please try again.', 500); }
        $rows = fetchBookings('WHERE b.booking_ref = ?', array($ref));
        out($rows[0], 201);
    }

    // gate verification (typed code or scanned QR text)
    if ($route === 'admin/verify' && $method === 'POST') {
        requireUser('admin');
        $text = field('code', 300);
        if ($text === '') { out(array('status' => 'empty', 'booking' => null)); }
        $code = $text;
        if (strpos($text, '|') !== false) {
            $parts = explode('|', $text);
            $code = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : (isset($parts[1]) ? $parts[1] : $text);
        }
        $code = trim($code);
        $rows = fetchBookings('WHERE LOWER(b.transit_code) = LOWER(?) OR LOWER(b.booking_ref) = LOWER(?)',
                              array($code, $code));
        if (!$rows) { out(array('status' => 'invalid', 'booking' => null)); }
        $b = $rows[0];
        if ($b['status'] === 'Canceled' || $b['paymentStatus'] === 'FAILED') { out(array('status' => 'invalid', 'booking' => $b)); }
        if ($b['paymentStatus'] === 'PENDING') { out(array('status' => 'pending', 'booking' => $b)); }
        out(array('status' => 'valid', 'booking' => $b));
    }

    fail('Not found', 404);

} catch (Throwable $e) {
    $msg = 'Server error';
    if (DEBUG) { $msg .= ': ' . $e->getMessage(); }
    fail($msg, 500);
}
