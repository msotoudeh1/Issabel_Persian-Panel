<?php
// index.php - داشبورد جامع مانیتورینگ ایزابل
error_reporting(E_ALL);
ini_set('display_errors', 0);

date_default_timezone_set('Asia/Tehran');



// ==========================================
// ✅ API: دریافت لیست تماس‌های پارک شده
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_parked_calls') {
    header('Content-Type: application/json; charset=utf-8');
    
    $output = @shell_exec("asterisk -rx 'parking show' 2>&1");
    if (!$output || stripos($output, 'No such command') !== false) {
        echo json_encode(['error' => 'ماژول پارک در آستریسک فعال نیست یا تنظیم نشده است.']);
        exit;
    }

    $parked = [];
    $lots = [];
    $current_space = null;
    $current_lot = null;

    foreach (explode("\n", $output) as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // تشخیص هدر پارک لات
        if (preg_match('/^Parking lot:\s*(.+)$/i', $line, $m)) {
            $current_lot = trim($m[1]);
            if (!isset($lots[$current_lot])) $lots[$current_lot] = [];
            continue;
        }

        // تشخیص جایگاه‌ها
        if (preg_match('/^Parking Space\s+(\d+):\s+(idle|occupied)(.*)$/i', $line, $m)) {
            $space = $m[1];
            $status = strtolower($m[2]);
            $rest = trim($m[3]);

            // اگر جایگاه اشغال بود، اطلاعات تماس‌گیرنده را استخراج کن
            if ($status === 'occupied') {
                $caller = 'ناشناس';
                $duration = '0';
                
                // استخراج شماره تماس‌گیرنده
                if (preg_match('/Caller:\s*"([^"]*)"/i', $rest, $cm)) {
                    $caller = $cm[1];
                } elseif (preg_match('/Caller:\s*([^\s]+)/i', $rest, $cm)) {
                    $caller = $cm[1];
                }
                
                // استخراج مدت زمان
                if (preg_match('/\((\d+)\s*seconds?\)/i', $rest, $dm)) {
                    $duration = $dm[1];
                }

                $parked[] = [
                    'lot' => $current_lot,
                    'space' => $space,
                    'caller' => $caller,
                    'duration' => (int)$duration
                ];
            }
        }
    }

    echo json_encode(['parked_calls' => $parked], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// ✅ API: برداشتن تماس از پارک (Pickup)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'pickup_parked_call') {
    header('Content-Type: application/json; charset=utf-8');
    
    $space = isset($_GET['space']) ? trim($_GET['space']) : '';
    $ext = isset($_GET['ext']) ? trim($_GET['ext']) : '';

    if (empty($space) || empty($ext)) {
        echo json_encode(['success' => false, 'message' => 'شماره جایگاه پارک و داخلی الزامی است.']);
        exit;
    }

    // ارسال دستور Originate به Asterisk برای اتصال داخلی به تماس پارک شده
    // کانتکست from-internal و پارک لات پیش‌فرض 70 است
    $cmd = "asterisk -rx \"channel originate Local/{$ext}@from-internal extension {$space}@parkedcalls\" 2>&1";
    $output = @shell_exec($cmd);

    if (stripos($output, 'Unable') !== false || stripos($output, 'Error') !== false || stripos($output, 'No such') !== false) {
        echo json_encode(['success' => false, 'message' => 'خطا در برداشتن تماس: ' . $output]);
    } else {
        echo json_encode(['success' => true, 'message' => "تماس از جایگاه {$space} به داخلی {$ext} در حال انتقال است. گوشی خود را بردارید."]);
    }
    exit;
}

// ==========================================
// API آمار عملکرد داخلی‌ها
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_extension_stats') {
    header('Content-Type: application/json; charset=utf-8');

    $days = isset($_GET['days']) ? max(1, min(3650, intval($_GET['days']))) : 30;

    // ✅ استفاده از مقادیر موجود یا پیش‌فرض
    $use_db_host = (isset($db_host) && !empty($db_host)) ? $db_host : 'localhost';
    $use_db_user = (isset($db_user) && !empty($db_user)) ? $db_user : 'root';
    $use_db_pass = isset($db_pass) ? $db_pass : 'database_password';
    $use_db_ast  = (isset($db_name)     && !empty($db_name))     ? $db_name     : 'asterisk';
    $use_db_cdr  = (isset($db_cdr_name) && !empty($db_cdr_name)) ? $db_cdr_name : 'asteriskcdrdb';

    // دریافت نام داخلی‌ها (اگر ممکن بود)
    $ext_names = array();
    try {
        $pdo_ast = new PDO("mysql:host=$use_db_host;dbname=$use_db_ast;charset=utf8", $use_db_user, $use_db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
        $rows = $pdo_ast->query("SELECT extension, name FROM users")->fetchAll();
        foreach ($rows as $r) { $ext_names[$r['extension']] = $r['name']; }
    } catch (Exception $e) {
        // اگر نشد، ادامه بده بدون نام
    }

    $stats = array();

    try {
        $pdo_cdr = new PDO("mysql:host=$use_db_host;dbname=$use_db_cdr;charset=utf8", $use_db_user, $use_db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        // کوئری ۱: داخلی‌ها به عنوان تماس‌گیرنده
        $sql1 = "SELECT src as ext,
                        COUNT(*) as cnt,
                        SUM(billsec) as talk,
                        SUM(CASE WHEN disposition='ANSWERED' THEN 1 ELSE 0 END) as ans
                 FROM cdr
                 WHERE calldate >= DATE_SUB(NOW(), INTERVAL " . intval($days) . " DAY)
                   AND src REGEXP '^[0-9]{3,5}$'
                 GROUP BY src";
        $res1 = $pdo_cdr->query($sql1)->fetchAll();

        // کوئری ۲: داخلی‌ها به عنوان پاسخ‌دهنده
        $sql2 = "SELECT dst as ext,
                        COUNT(*) as cnt,
                        SUM(billsec) as talk,
                        SUM(CASE WHEN disposition='ANSWERED' THEN 1 ELSE 0 END) as ans
                 FROM cdr
                 WHERE calldate >= DATE_SUB(NOW(), INTERVAL " . intval($days) . " DAY)
                   AND dst REGEXP '^[0-9]{3,5}$'
                 GROUP BY dst";
        $res2 = $pdo_cdr->query($sql2)->fetchAll();

        // ادغام نتایج در PHP (بدون UNION پیچیده)
        foreach ($res1 as $r) {
            $ext = $r['ext'];
            if (!isset($stats[$ext])) $stats[$ext] = array('total' => 0, 'ans' => 0, 'talk' => 0);
            $stats[$ext]['total'] += (int)$r['cnt'];
            $stats[$ext]['ans']   += (int)$r['ans'];
            $stats[$ext]['talk']  += (int)$r['talk'];
        }
        foreach ($res2 as $r) {
            $ext = $r['ext'];
            if (!isset($stats[$ext])) $stats[$ext] = array('total' => 0, 'ans' => 0, 'talk' => 0);
            $stats[$ext]['total'] += (int)$r['cnt'];
            $stats[$ext]['ans']   += (int)$r['ans'];
            $stats[$ext]['talk']  += (int)$r['talk'];
        }

    } catch (Exception $e) {
        echo json_encode(array(
            'error' => 'خطای دیتابیس CDR: ' . $e->getMessage(),
            'db_used' => $use_db_cdr,
            'host_used' => $use_db_host
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ساخت لیست نهایی
    $list = array();
    foreach ($stats as $ext => $s) {
        $total = $s['total'];
        $ans = $s['ans'];
        $talk = $s['talk'];
        $missed = $total - $ans;
        $list[] = array(
            'ext' => $ext,
            'name' => isset($ext_names[$ext]) ? $ext_names[$ext] : 'داخلی ' . $ext,
            'total_calls' => $total,
            'answered' => $ans,
            'missed' => $missed,
            'total_talk' => $talk,
            'avg_talk' => $ans > 0 ? round($talk / $ans) : 0,
            'answer_rate' => $total > 0 ? round(($ans / $total) * 100, 1) : 0
        );
    }

    $by_calls = $list;
    usort($by_calls, function($a, $b) { return $b['total_calls'] - $a['total_calls']; });

    $by_answered = $list;
    usort($by_answered, function($a, $b) { return $b['answered'] - $a['answered']; });

    $by_talk = $list;
    usort($by_talk, function($a, $b) { return $b['total_talk'] - $a['total_talk']; });

    echo json_encode(array(
        'by_calls' => array_slice($by_calls, 0, 8),
        'by_answered' => array_slice($by_answered, 0, 8),
        'by_talk' => array_slice($by_talk, 0, 8),
        'days' => $days,
        'total_extensions' => count($list)
    ), JSON_UNESCAPED_UNICODE);
    exit;
}


// ==========================================
// مدیریت نشست و احراز هویت
// ==========================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('LOGIN_USERNAME', 'admin');
define('LOGIN_PASSWORD', 'login_password');

// ==========================================
// ✅ کش فایل‌محور سبک (بدون نیاز به Redis/APCu)
// ==========================================
function cache_dir() {
    static $d = null;
    if ($d !== null) return $d;
    $d = sys_get_temp_dir() . '/pbx_cache';
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d;
}
function cache_get($key, $ttl) {
    $f = cache_dir() . '/' . md5($key) . '.cache';
    if (!file_exists($f)) return null;
    if ((time() - filemtime($f)) > $ttl) { @unlink($f); return null; }
    $data = @file_get_contents($f);
    if ($data === false || $data === '') return null;
    return json_decode($data, true);
}
function cache_set($key, $value) {
    $f = cache_dir() . '/' . md5($key) . '.cache';
    @file_put_contents($f, json_encode($value), LOCK_EX);
}
function asterisk_exec($cmd, $ttl = 2) {
    $ck = 'ast_' . $cmd;
    $hit = cache_get($ck, $ttl);
    if ($hit !== null && isset($hit['out'])) return $hit['out'];
    $out = @shell_exec("asterisk -rx " . escapeshellarg($cmd) . " 2>&1");
    cache_set($ck, array('out' => $out));
    return $out;
}

$login_error = '';

if (isset($_POST['do_login'])) {
    $u = isset($_POST['username']) ? trim($_POST['username']) : '';
    $p = isset($_POST['password']) ? trim($_POST['password']) : '';
    if ($u === LOGIN_USERNAME && $p === LOGIN_PASSWORD) {
        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $u;
        session_regenerate_id(true);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        $login_error = 'نام کاربری یا رمز عبور اشتباه است.';
    }
}

if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

if (!$is_logged_in) {
	
	

    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ورود به سامانه مانیتورینگ</title>
        <script src="3.4.17.js"></script>
        <script>
            tailwind.config = {
                darkMode: 'class',
                theme: { extend: { fontFamily: { sans: ['Vazirmatn', 'sans-serif'] } } }
            }
        </script>
    <link href="/Vazirmatn-font-face.css" rel="stylesheet" />
    <link href="/all.min.css" rel="stylesheet" />     
        <style>
            body, input, button { font-family: 'Vazirmatn', sans-serif !important; }
        </style>
		    <!-- ✅ تقویم شمسی -->
	<link href="/persian-datepicker.min.css" rel="stylesheet" /> 
    <script src="/jquery-3.7.1.min.js"></script>
    <script src="/persian-date.min.js"></script>
    <script src="/persian-datepicker.min.js"></script>
    <script>
        $(document).ready(function() {
            $(".persian-datepicker").persianDatepicker({
                format: 'YYYY/MM/DD',
                autoClose: true,
                initialValue: false
            });
        });
    </script>
    </head>
    <body class="bg-gradient-to-br from-indigo-600 via-indigo-700 to-slate-900 min-h-screen flex items-center justify-center p-4">

        <div class="w-full max-w-md">
            <div class="bg-white dark:bg-slate-800 rounded-3xl shadow-2xl overflow-hidden">
                <div class="bg-gradient-to-l from-indigo-600 to-indigo-500 p-8 text-center">
                    <div class="w-20 h-20 bg-white/20 backdrop-blur rounded-2xl flex items-center justify-center text-white mx-auto mb-4 shadow-lg">
                        <i class="fa-solid fa-headset text-4xl"></i>
                    </div>
                    <h1 class="text-2xl font-black text-white mb-1">سامانه مانیتورینگ ایزابل</h1>
                    <p class="text-indigo-100 text-sm">برای ادامه وارد شوید</p>
                </div>

                <form method="post" class="p-8 space-y-5">
                    <?php if (!empty($login_error)): ?>
                        <div class="bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800 text-rose-700 dark:text-rose-300 text-xs font-bold p-3 rounded-xl flex items-center gap-2">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <span><?php echo htmlspecialchars($login_error); ?></span>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-2 block">
                            <i class="fa-solid fa-user text-indigo-500 mr-1"></i> نام کاربری
                        </label>
                        <div class="relative">
                            <input type="text" name="username" required autofocus
                                class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
                                placeholder="نام کاربری خود را وارد کنید">
                        </div>
                    </div>

                    <div>
                        <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-2 block">
                            <i class="fa-solid fa-lock text-indigo-500 mr-1"></i> رمز عبور
                        </label>
                        <div class="relative">
                            <input type="password" name="password" id="loginPassword" required
                                class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-4 py-3 pl-12 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
                                placeholder="رمز عبور خود را وارد کنید">
                            <button type="button" onclick="togglePasswordVisibility()" 
                                class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
                                <i id="passwordEye" class="fa-solid fa-eye text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="do_login" value="1"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-3 rounded-xl text-sm font-bold transition-all shadow-lg shadow-indigo-200 dark:shadow-none flex items-center justify-center gap-2">
                        <i class="fa-solid fa-right-to-bracket"></i>
                        <span>ورود به سامانه</span>
                    </button>
                </form>

                <div class="bg-slate-50 dark:bg-slate-700/50 px-8 py-4 text-center border-t border-slate-100 dark:border-slate-700">
                    <p class="text-[10px] text-slate-400 vazir-font">
                        <?php 
                            $months = array("فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند");
                            $days = array("یکشنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه", "پنج‌شنبه", "جمعه", "شنبه");
                            $g_y = date('Y'); $g_m = date('n'); $g_d = date('j');
                            $g_days_in_month = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
                            $j_days_in_month = array(31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29);
                            if (($g_y % 4 == 0 && $g_y % 100 != 0) || ($g_y % 400 == 0)) $g_days_in_month[1] = 29;
                            $gy = $g_y - 1600; $gm = $g_m - 1; $gd = $g_d - 1;
                            $g_day_no = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
                            for ($i = 0; $i < $gm; ++$i) $g_day_no += $g_days_in_month[$i];
                            $g_day_no += $gd;
                            $j_day_no = $g_day_no - 79;
                            $j_np = floor($j_day_no / 12053); $j_day_no %= 12053;
                            $jy = 979 + 33 * $j_np + 4 * floor($j_day_no / 1461); $j_day_no %= 1461;
                            if ($j_day_no >= 366) { $jy += floor(($j_day_no - 1) / 365); $j_day_no = ($j_day_no - 1) % 365; }
                            for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) $j_day_no -= $j_days_in_month[$i];
                            $jm = $i + 1; $jd = $j_day_no + 1;
                            echo $days[date('w')] . ' ' . (int)$jd . ' ' . $months[$jm - 1] . ' ' . $jy;
                        ?>
                    </p>
                </div>
            </div>

            <p class="text-center text-white/60 text-xs mt-6">
                <i class="fa-solid fa-shield-halved mr-1"></i>
                تمامی فعالیت‌ها در این سامانه ثبت می‌شود
            </p>
        </div>

        <script>
            function togglePasswordVisibility() {
                var input = document.getElementById('loginPassword');
                var eye = document.getElementById('passwordEye');
                if (input.type === 'password') {
                    input.type = 'text';
                    eye.className = 'fa-solid fa-eye-slash text-sm';
                } else {
                    input.type = 'password';
                    eye.className = 'fa-solid fa-eye text-sm';
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================
// تنظیمات دیتابیس
// ==========================================
$db_user = 'root';
$db_pass = 'database_password'; 
$db_host = 'localhost';
$db_name = 'asterisk';     
$db_cdr_name = 'asteriskcdrdb'; 

// ==========================================
// ✅ API 1: داده‌های Heatmap (ساعات شلوغی)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_heatmap_data') {
    header('Content-Type: application/json; charset=utf-8');
    $days = isset($_GET['days']) ? max(1, min(90, intval($_GET['days']))) : 30;
    
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
        
        $sql = "SELECT ((DAYOFWEEK(calldate) + 5) % 7) as day_idx, HOUR(calldate) as hr, COUNT(*) as cnt 
                FROM cdr WHERE calldate >= DATE_SUB(NOW(), INTERVAL $days DAY) GROUP BY day_idx, hr";
        $rows = $pdo->query($sql)->fetchAll();
        
        $data = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($rows as $r) { $data[(int)$r['day_idx']][(int)$r['hr']] = (int)$r['cnt']; }
        
        $series = array();
        $days_fa = array('شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه');
        for ($i = 0; $i < 7; $i++) {
            $row_data = array();
            for ($h = 0; $h < 24; $h++) { $row_data[] = array('x' => sprintf('%02d:00', $h), 'y' => $data[$i][$h]); }
            $series[] = array('name' => $days_fa[$i], 'data' => $row_data);
        }
        echo json_encode(array('series' => $series), JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { echo json_encode(array('error' => $e->getMessage())); }
    exit;
}

// ==========================================
// ✅ API 2: مقایسه دوره‌ها (این هفته با هفته قبل)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_period_comparison') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
        $get_stats = function($start, $end) use ($pdo) {
            $sql = "SELECT COUNT(*) as total, SUM(CASE WHEN disposition='ANSWERED' THEN 1 ELSE 0 END) as answered, SUM(CASE WHEN disposition!='ANSWERED' THEN 1 ELSE 0 END) as missed, SUM(billsec) as talk FROM cdr WHERE calldate >= :s AND calldate < :e";
            $stmt = $pdo->prepare($sql); $stmt->execute(array(':s' => $start, ':e' => $end)); return $stmt->fetch();
        };
        $curr = $get_stats(date('Y-m-d 00:00:00', strtotime('-6 days')), date('Y-m-d 23:59:59'));
        $prev = $get_stats(date('Y-m-d 00:00:00', strtotime('-13 days')), date('Y-m-d 23:59:59', strtotime('-7 days')));
        $calc = function($c, $p) { if ($p == 0) return $c > 0 ? 100 : 0; return round((($c - $p) / $p) * 100, 1); };
        echo json_encode(array(
            'current' => $curr, 'previous' => $prev,
            'changes' => array('total' => $calc($curr['total'], $prev['total']), 'answered' => $calc($curr['answered'], $prev['answered']), 'missed' => $calc($curr['missed'], $prev['missed']), 'talk' => $calc($curr['talk'], $prev['talk']))
        ), JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { echo json_encode(array('error' => $e->getMessage())); }
    exit;
}

/// ==========================================
// ✅ API 3: گزارش تماس‌های ورودی از دست رفته
// (فقط تماس‌های خارجی → داخلی که پاسخ داده نشدند)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_missed_calls') {
    header('Content-Type: application/json; charset=utf-8');
    $days = isset($_GET['days']) ? intval($_GET['days']) : 7;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 5; $offset = ($page - 1) * $limit;
    
    try {
        $pdo_cdr = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
        
        // ساخت جدول پیگیری در صورت نبود
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `pbx_missed_followups` (
                `number` VARCHAR(50) COLLATE utf8mb4_general_ci NOT NULL,
                `calldate` DATETIME NOT NULL,
                `status` VARCHAR(20) COLLATE utf8mb4_general_ci DEFAULT 'pending',
                `admin_user` VARCHAR(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
                PRIMARY KEY (`number`, `calldate`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
        } catch (Exception $e) {}
        
        // ✅ شماره واقعی تماس‌گیرنده:
        // - اگر cnum شماره خارجی باشد (نه ۳-۵ رقمی) → cnum
        // - وگرنه → src (چون در تماس ورودی، src هم شماره خارجی است)
        $real_caller_sql = "CASE
            WHEN c.cnum IS NULL OR TRIM(c.cnum) = '' THEN TRIM(c.src)
            WHEN TRIM(c.cnum) IN ('s', 'unknown', 'Anonymous', 'anonymous') THEN TRIM(c.src)
            WHEN TRIM(c.cnum) NOT REGEXP '^[0-9]{3,5}$' THEN TRIM(c.cnum)
            ELSE TRIM(c.src)
        END";
        
        // ✅ شرط اصلی: ورودی + پاسخ‌داده‌نشده + داخلی مقصد
        // - cnum باید خارجی باشد (یا خالی، تا از src استفاده شود)
        // - dst باید داخلی باشد (۳ تا ۵ رقم)
        // - src نباید مقدارهای سیستمی باشد
        $inbound_missed_condition = "(
            c.disposition != 'ANSWERED'
            AND (c.cnum IS NULL OR TRIM(c.cnum) = '' OR TRIM(c.cnum) NOT REGEXP '^[0-9]{3,5}$')
            AND TRIM(c.dst) REGEXP '^[0-9]{3,5}$'
            AND c.src IS NOT NULL
            AND TRIM(c.src) != ''
            AND c.src NOT IN ('s', 'unknown', 'Anonymous', 'anonymous', 'Unavailable', 'unavailable')
        )";
        
        // محاسبه تعداد کل
        $stmt_cnt = $pdo_cdr->query("SELECT COUNT(*) as c FROM cdr c 
            WHERE c.calldate >= DATE_SUB(NOW(), INTERVAL $days DAY) 
              AND $inbound_missed_condition");
        $cnt_row = $stmt_cnt->fetch();
        $total = $cnt_row ? (int)$cnt_row['c'] : 0;
        
        // ✅ دریافت رکوردها با LEFT JOIN روی جدول پیگیری
        $sql = "SELECT 
                    c.calldate, 
                    $real_caller_sql AS real_src, 
                    c.src AS original_src,
                    TRIM(c.dst) AS destination,
                    c.disposition, 
                    c.dcontext,
                    f.status as follow_status 
                FROM cdr c 
                LEFT JOIN $db_name.pbx_missed_followups f 
                    ON ($real_caller_sql) COLLATE utf8mb4_general_ci = f.number COLLATE utf8mb4_general_ci 
                    AND c.calldate = f.calldate
                WHERE c.calldate >= DATE_SUB(NOW(), INTERVAL $days DAY)
                  AND $inbound_missed_condition
                ORDER BY c.calldate DESC 
                LIMIT $limit OFFSET $offset";
        $rows = $pdo_cdr->query($sql)->fetchAll();
        
        $calls = array();
        foreach ($rows as $r) {
            $calls[] = array(
                'calldate'      => convertToJalali($r['calldate']), 
                'raw_date'      => $r['calldate'], 
                'src'           => trim($r['real_src']),      // شماره خارجی
                'dst'           => trim($r['destination']),   // داخلی که پاسخ نداد
                'disposition'   => $r['disposition'],         // NO ANSWER / BUSY / FAILED
                'dcontext'      => $r['dcontext'],
                'follow_status' => $r['follow_status'] ? $r['follow_status'] : 'pending'
            );
        }
        
        echo json_encode(array(
            'list'         => $calls, 
            'total_pages'  => max(1, ceil($total / $limit)), 
            'current_page' => $page,
            'total_records'=> $total
        ), JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { 
        echo json_encode(array('error' => $e->getMessage())); 
    }
    exit;
}

// ==========================================
// ✅ API جدید: جزئیات تماس‌های امروز برای کارت‌های وضعیت
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_today_call_details') {
    header('Content-Type: application/json; charset=utf-8');
    $type = isset($_GET['type']) ? $_GET['type'] : 'today';
    
    try {
        $pdo_cdr = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        $where = "DATE(calldate) = CURDATE()";
        
        // اعمال فیلترهای دقیقاً مشابه با آنچه در today_stats استفاده شده
        if ($type === 'internal') {
            $where .= " AND src REGEXP '^[0-9]{3,5}$' AND dst REGEXP '^[0-9]{3,5}$'";
        } elseif ($type === 'outbound') {
            $where .= " AND src REGEXP '^[0-9]{3,5}$' AND dst NOT REGEXP '^[0-9]{3,5}$'";
        } elseif ($type === 'inbound') {
            $where .= " AND src NOT REGEXP '^[0-9]{3,5}$' AND dst REGEXP '^[0-9]{3,5}$'";
        } elseif ($type === 'failed') {
            $where .= " AND disposition = 'FAILED'";
        } elseif ($type === 'missed') {
            $where .= " AND disposition = 'NO ANSWER'";
        } elseif ($type === 'answered') {
            $where .= " AND disposition = 'ANSWERED'";
        }
        
        // گرفتن حداکثر ۵۰ رکورد آخر برای جلوگیری از سنگین شدن
        $sql = "SELECT calldate, src, dst, disposition, billsec, duration 
                FROM cdr 
                WHERE $where 
                ORDER BY calldate DESC 
                LIMIT 50";
                
        $rows = $pdo_cdr->query($sql)->fetchAll();
        
        $calls = array();
        foreach ($rows as $r) {
            $calls[] = array(
                'calldate' => convertToJalali($r['calldate']),
                'src' => trim($r['src']),
                'dst' => trim($r['dst']),
                'is_answered' => ($r['disposition'] === 'ANSWERED'),
                'disposition' => ($r['disposition'] === 'ANSWERED') ? 'پاسخ داده شده' : 'ناموفق / بی‌پاسخ',
                'billsec' => $r['billsec']
            );
        }
        
        echo json_encode(array('list' => $calls), JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        echo json_encode(array('error' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ==========================================
// ✅ API 4: علامت‌گذاری پیگیری
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api']) && $_POST['api'] === 'mark_followup') {
    header('Content-Type: application/json; charset=utf-8');
    
    // اصلاح برای سازگاری با PHP 5.6 (جایگزینی ??)
    $num = isset($_POST['number']) ? $_POST['number'] : '';
    $date = isset($_POST['calldate']) ? $_POST['calldate'] : '';
    $user = isset($_SESSION['username']) ? $_SESSION['username'] : 'admin';
    
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $pdo->prepare("INSERT INTO pbx_missed_followups (number, calldate, status, admin_user) VALUES (:n, :c, 'done', :u) ON DUPLICATE KEY UPDATE status='done', admin_user=:u2")->execute(array(':n'=>$num, ':c'=>$date, ':u'=>$user, ':u2'=>$user));
        echo json_encode(array('success' => true));
    } catch (Exception $e) { echo json_encode(array('success' => false)); }
    exit;
}

// ==========================================
// ✅ API 5: لیست بازگرداندن تماس (با صفحه‌بندی)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_callback_list') {
    header('Content-Type: application/json; charset=utf-8');
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 8; // نمایش ۸ آیتم در هر صفحه
    $offset = ($page - 1) * $limit;
    
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
        
        // محاسبه تعداد کل برای صفحه‌بندی
        $count_sql = "SELECT COUNT(*) as c FROM (
                        SELECT src FROM cdr 
                        WHERE disposition != 'ANSWERED' AND calldate >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND src NOT REGEXP '^[0-9]{3,5}$' 
                        GROUP BY src HAVING COUNT(*) >= 2
                      ) as t";
        $total = $pdo->query($count_sql)->fetch()['c'];
        $total_pages = max(1, ceil($total / $limit));

        // دریافت فقط ۸ رکورد صفحه جاری
        $sql = "SELECT src, COUNT(*) as missed_count, MAX(calldate) as last_missed 
                FROM cdr WHERE disposition != 'ANSWERED' AND calldate >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND src NOT REGEXP '^[0-9]{3,5}$' 
                GROUP BY src HAVING missed_count >= 2 
                ORDER BY missed_count DESC 
                LIMIT $limit OFFSET $offset";
        $rows = $pdo->query($sql)->fetchAll();
        
        foreach ($rows as &$r) { $r['last_missed_fa'] = convertToJalali($r['last_missed']); }
        
        echo json_encode(array(
            'list' => $rows, 
            'total_pages' => $total_pages, 
            'current_page' => $page,
            'total_count' => $total // ✅ اضافه شدن تعداد کل
        ), JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) { echo json_encode(array('error' => $e->getMessage())); }
    exit;
}


if (file_exists('/etc/amportal.conf') && is_readable('/etc/amportal.conf')) {
    $conf_lines = @file('/etc/amportal.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($conf_lines) {
        foreach ($conf_lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                if ($key === 'AMPDBUSER') $db_user = $val;
                if ($key === 'AMPDBPASS') $db_pass = $val;
                if ($key === 'AMPDBHOST') $db_host = $val;
                if ($key === 'AMPDBNAME') $db_name = $val;
            }
        }
    }
}

// ==========================================
// توابع تبدیل تاریخ
// ==========================================
function gregorian_to_jalali($g_y, $g_m, $g_d) {
    $g_days_in_month = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    $j_days_in_month = array(31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29);
    if (($g_y % 4 == 0 && $g_y % 100 != 0) || ($g_y % 400 == 0)) {
        $g_days_in_month[1] = 29;
    }
    $gy = $g_y - 1600; $gm = $g_m - 1; $gd = $g_d - 1;
    $g_day_no = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
    for ($i = 0; $i < $gm; ++$i) $g_day_no += $g_days_in_month[$i];
    $g_day_no += $gd;
    $j_day_no = $g_day_no - 79;
    $j_np = floor($j_day_no / 12053); $j_day_no %= 12053;
    $jy = 979 + 33 * $j_np + 4 * floor($j_day_no / 1461); $j_day_no %= 1461;
    if ($j_day_no >= 366) { $jy += floor(($j_day_no - 1) / 365); $j_day_no = ($j_day_no - 1) % 365; }
    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) $j_day_no -= $j_days_in_month[$i];
    $jm = $i + 1; $jd = $j_day_no + 1;
    return array($jy, sprintf("%02d", $jm), sprintf("%02d", $jd));
}



function convertToJalali($datetime_str) {
    if (empty($datetime_str) || $datetime_str == '0000-00-00 00:00:00') return '-';
    $parts = explode(' ', $datetime_str);
    $date_parts = explode('-', $parts[0]);
    if (count($date_parts) < 3) return $datetime_str;
    $time_part = isset($parts[1]) ? $parts[1] : '';
    $j_date = gregorian_to_jalali($date_parts[0], $date_parts[1], $date_parts[2]);
    return $j_date[0] . '/' . $j_date[1] . '/' . $j_date[2] . ' ' . $time_part;
}

function getJalaliHeaderDate() {
    $j = gregorian_to_jalali(date('Y'), date('n'), date('j'));
    $months = array("فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند");
    $days = array("یکشنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه", "پنج‌شنبه", "جمعه", "شنبه");
    return $days[date('w')] . ' ' . (int)$j[2] . ' ' . $months[(int)$j[1] - 1] . ' ' . $j[0];
}


// ==========================================
// جدید  کمکی
// ==========================================


function jalali_to_gregorian($j_y, $j_m, $j_d) {
    $j_y = (int)$j_y; $j_m = (int)$j_m; $j_d = (int)$j_d;
    $jy = $j_y - 979; $jm = $j_m - 1; $jd = $j_d - 1;
    $j_day_no = 365 * $jy + floor($jy / 33) * 8 + floor(($jy % 33 + 3) / 4);
    for ($i = 0; $i < $jm; ++$i) {
        $j_day_no += ($i < 6) ? 31 : 30;
    }
    $j_day_no += $jd;
    $g_day_no = $j_day_no + 79;
    $gy = 1600 + 400 * floor($g_day_no / 146097);
    $g_day_no %= 146097;
    $leap = true;
    if ($g_day_no >= 36525) {
        $g_day_no--;
        $gy += 100 * floor($g_day_no / 36524);
        $g_day_no %= 36524;
        if ($g_day_no >= 365) $g_day_no++; else $leap = false;
    }
    $gy += 4 * floor($g_day_no / 1461);
    $g_day_no %= 1461;
    if ($g_day_no >= 366) {
        $leap = false;
        $g_day_no--;
        $gy += floor($g_day_no / 365);
        $g_day_no %= 365;
    }
    $g_days_in_month = array(31, ($leap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    for ($i = 0; $i < 12 && $g_day_no >= $g_days_in_month[$i]; $i++) {
        $g_day_no -= $g_days_in_month[$i];
    }
    $gm = $i + 1;
    $gd = $g_day_no + 1;
    return array($gy, sprintf('%02d', $gm), sprintf('%02d', $gd));
}

// ==========================================
// ✅ تابع واحد تشخیص نوع تماس (منبع حقیقت)
// انواع بازگشتی: 'internal' | 'inbound' | 'outbound' | 'queue'
// ==========================================
function classifyCall($src, $dst, $dcontext, $dstchannel = '', $lastapp = '') {
    $src = trim((string)$src);
    $dst = trim((string)$dst);
    $dcontext   = (string)$dcontext;
    $dstchannel = (string)$dstchannel;
    $lastapp    = (string)$lastapp;

    $src_is_ext = preg_match('/^\d{3,5}$/', $src);
    $dst_is_ext = preg_match('/^\d{3,5}$/', $dst);

    // ۱) صف
    if (stripos($dcontext, 'queue') !== false || stripos($lastapp, 'Queue') !== false) {
        return 'queue';
    }
    // ۲) داخلی به داخلی
    if ($src_is_ext && $dst_is_ext) {
        return 'internal';
    }
    // ۳) ورودی شهری
    if (in_array($dcontext, array('from-trunk', 'from-pstn', 'from-digital', 'ext-did'))
        || (strlen($src) > 4 && !preg_match('/^(from-internal|outbound-allroutes)/', $dcontext))) {
        return 'inbound';
    }
    // ۴) خروجی شهری
    if (in_array($dcontext, array('outbound-allroutes', 'from-outbound-rules', 'macro-dialout-trunk'))
        || strlen($dst) > 4
        || preg_match('/^(DAHDI|SIP|PJSIP|IAX2)\//i', $dstchannel)) {
        return 'outbound';
    }
    return 'internal';
}

// ==========================================
// توابع کمکی
// ==========================================
function prefixToMask($prefix) {
    $prefix = (int)$prefix;
    if ($prefix < 0 || $prefix > 32) return '';
    if ($prefix == 0) return '0.0.0.0';
    $mask = 0xFFFFFFFF << (32 - $prefix);
    return long2ip($mask);
}

function maskToPrefix($mask) {
    if (!filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
    $long = ip2long($mask);
    if ($long === false) return null;
    $bin = decbin($long);
    return substr_count($bin, '1');
}

// ==========================================
// ✅ جدید: گرفتن تمام route های سیستم
// ==========================================
function getAllRoutes() {
    $routes = array();
    $output = @shell_exec("/sbin/ip route show 2>/dev/null");
    if (!$output) {
        $output = @shell_exec("ip route show 2>/dev/null");
    }
    if (!$output) {
        $output = @shell_exec("route -n 2>/dev/null");
    }
    if ($output) {
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            if (strpos($line, 'Kernel IP routing table') !== false) continue;
            if (strpos($line, 'Destination') === 0) continue;
            $is_default = (strpos($line, 'default') === 0);
            $routes[] = array(
                'raw' => $line,
                'is_default' => $is_default
            );
        }
    }
    return $routes;
}

// ==========================================
// ✅ جدید: گرفتن route های یک اینترفیس خاص
// ==========================================
function getRoutesForInterface($iface, $all_routes) {
    $result = array();
    foreach ($all_routes as $route) {
        // route متعلق به این اینترفیس است اگر "dev <iface>" داشته باشد
        // یا اگر route به صورت route -n باشد، اگر خط آخرش با iface مطابقت داشته باشد
        if (preg_match('/\bdev\s+' . preg_quote($iface, '/') . '\b/i', $route['raw']) ||
            preg_match('/\b' . preg_quote($iface, '/') . '\s*$/i', $route['raw'])) {
            $result[] = $route;
        }
    }
    return $result;
}

function getSystemStats() {
    $info = array(
        'cpu' => array('usage' => 0, 'cores' => 0, 'model' => '', 'load' => '0 / 0 / 0'),
        'ram' => array('total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0),
        'disk' => array('total' => 0, 'used' => 0, 'free' => 0, 'percent' => 0, 'mount' => '/'),
        'hostname' => '',
        'uptime' => '',
        'os' => ''
    );

    $cores = @shell_exec("nproc 2>/dev/null");
    $info['cpu']['cores'] = (int)trim($cores);

    $model = @shell_exec("grep -m1 'model name' /proc/cpuinfo 2>/dev/null | cut -d: -f2");
    $info['cpu']['model'] = trim($model);
    if (empty($info['cpu']['model'])) {
        $model = @shell_exec("cat /proc/cpuinfo 2>/dev/null | grep -m1 'cpu model' | cut -d: -f2");
        $info['cpu']['model'] = trim($model);
    }

    $load = @shell_exec("cat /proc/loadavg 2>/dev/null");
    if ($load) {
        $parts = preg_split('/\s+/', trim($load));
        if (count($parts) >= 3) {
            $info['cpu']['load'] = $parts[0] . ' / ' . $parts[1] . ' / ' . $parts[2];
        }
    }

    $cache_file = sys_get_temp_dir() . '/ambient_cpu_cache.json';
    $stat_content = @file_get_contents('/proc/stat');
    if ($stat_content) {
        $lines = explode("\n", $stat_content);
        $cpu_line = '';
        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') === 0) { $cpu_line = trim($line); break; }
        }
        if (!empty($cpu_line)) {
            $parts = preg_split('/\s+/', $cpu_line);
            $current = array(
                'user' => isset($parts[1]) ? (int)$parts[1] : 0,
                'nice' => isset($parts[2]) ? (int)$parts[2] : 0,
                'system' => isset($parts[3]) ? (int)$parts[3] : 0,
                'idle' => isset($parts[4]) ? (int)$parts[4] : 0,
                'iowait' => isset($parts[5]) ? (int)$parts[5] : 0,
                'irq' => isset($parts[6]) ? (int)$parts[6] : 0,
                'softirq' => isset($parts[7]) ? (int)$parts[7] : 0,
                'steal' => isset($parts[8]) ? (int)$parts[8] : 0,
                'time' => time()
            );

            $usage = 0;
            if (file_exists($cache_file)) {
                $prev = @json_decode(@file_get_contents($cache_file), true);
                if ($prev && isset($prev['time']) && ($current['time'] - $prev['time']) < 60) {
                    $total_prev = $prev['user'] + $prev['nice'] + $prev['system'] + $prev['idle'] + $prev['iowait'] + $prev['irq'] + $prev['softirq'] + $prev['steal'];
                    $total_curr = $current['user'] + $current['nice'] + $current['system'] + $current['idle'] + $current['iowait'] + $current['irq'] + $current['softirq'] + $current['steal'];
                    $idleDiff = $current['idle'] - $prev['idle'];
                    $totalDiff = $total_curr - $total_prev;
                    if ($totalDiff > 0) {
                        $usage = round((1 - ($idleDiff / $totalDiff)) * 100, 1);
                        if ($usage < 0) $usage = 0;
                        if ($usage > 100) $usage = 100;
                    }
                }
            }
            @file_put_contents($cache_file, json_encode($current));
            $info['cpu']['usage'] = $usage;
        }
    }

    $meminfo = @file_get_contents('/proc/meminfo');
    if ($meminfo) {
        $mem = array();
        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $mem[$m[1]] = (int)$m[2];
            }
        }
        $total = isset($mem['MemTotal']) ? $mem['MemTotal'] : 0;
        $free_m = isset($mem['MemFree']) ? $mem['MemFree'] : 0;
        $buffers = isset($mem['Buffers']) ? $mem['Buffers'] : 0;
        $cached = isset($mem['Cached']) ? $mem['Cached'] : 0;
        $available = isset($mem['MemAvailable']) ? $mem['MemAvailable'] : ($free_m + $buffers + $cached);

        $used = $total - $available;
        $info['ram'] = array(
            'total' => round($total / 1024 / 1024, 2),
            'used' => round($used / 1024 / 1024, 2),
            'free' => round($available / 1024 / 1024, 2),
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0
        );
    }

    $df = @shell_exec("df -B1 / 2>/dev/null | tail -1");
    if ($df) {
        $parts = preg_split('/\s+/', trim($df));
        if (count($parts) >= 6) {
            $total = (int)$parts[1];
            $used = (int)$parts[2];
            $free = (int)$parts[3];
            $percent = (int)str_replace('%', '', $parts[4]);
            $info['disk'] = array(
                'total' => round($total / 1024 / 1024 / 1024, 2),
                'used' => round($used / 1024 / 1024 / 1024, 2),
                'free' => round($free / 1024 / 1024 / 1024, 2),
                'percent' => $percent,
                'mount' => isset($parts[5]) ? $parts[5] : '/'
            );
        }
    }

    $hostname = @shell_exec("hostname 2>/dev/null");
    $info['hostname'] = trim($hostname);

    if (file_exists('/etc/os-release')) {
        $os = @file_get_contents('/etc/os-release');
        if ($os && preg_match('/PRETTY_NAME\s*=\s*"([^"]+)"/', $os, $m)) {
            $info['os'] = $m[1];
        }
    }

    $uptime = @shell_exec("cat /proc/uptime 2>/dev/null");
    if ($uptime) {
        $up = (int)explode(' ', trim($uptime))[0];
        $days = floor($up / 86400);
        $hours = floor(($up % 86400) / 3600);
        $mins = floor(($up % 3600) / 60);
        $info['uptime'] = $days . ' روز، ' . $hours . ' ساعت، ' . $mins . ' دقیقه';
    }

    return $info;
}

// ==========================================
// گرفتن اطلاعات کارت‌های شبکه (✅ با routing)
// ==========================================
function getNetworkInfo() {
    $interfaces = array();

    // Gateways
    $gateways = array();
    $route_out = @shell_exec("ip route show default 2>/dev/null");
    if ($route_out) {
        foreach (explode("\n", $route_out) as $line) {
            if (preg_match('/default\s+via\s+([0-9\.]+)\s+dev\s+(\S+)/', $line, $m)) {
                $gateways[$m[2]] = $m[1];
            }
        }
    }
    if (empty($gateways)) {
        $route_out2 = @shell_exec("route -n 2>/dev/null | grep '^0.0.0.0'");
        if ($route_out2) {
            foreach (explode("\n", $route_out2) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 8) {
                    $gateways[$parts[7]] = $parts[1];
                }
            }
        }
    }

    // ✅ دریافت تمام route ها
    $all_routes = getAllRoutes();

    // DNS
    $dns_list_global = array();
    if (file_exists('/etc/resolv.conf')) {
        $resolv = @file_get_contents('/etc/resolv.conf');
        if ($resolv && preg_match_all('/^\s*nameserver\s+([^\s#]+)/m', $resolv, $mm)) {
            $dns_list_global = $mm[1];
        }
    }

    // لیست اینترفیس‌ها
    $iface_list = array();
    if (is_dir('/sys/class/net/')) {
        $dirs = @scandir('/sys/class/net/');
        if ($dirs) {
            foreach ($dirs as $d) {
                if ($d === '.' || $d === '..' || $d === 'lo') continue;
                $iface_list[] = $d;
            }
        }
    }

    if (empty($iface_list)) {
        $ip_link = @shell_exec("ip -o link show 2>/dev/null");
        if ($ip_link) {
            foreach (explode("\n", $ip_link) as $line) {
                if (preg_match('/^\d+:\s+([^:@]+)/', $line, $m)) {
                    $n = trim($m[1]);
                    if ($n !== 'lo') $iface_list[] = $n;
                }
            }
        }
    }

    foreach ($iface_list as $iface) {
        $mac = '';
        $mac_file = '/sys/class/net/' . $iface . '/address';
        if (file_exists($mac_file)) {
            $mac = trim(@file_get_contents($mac_file));
        }

        $state = 'unknown';
        $state_file = '/sys/class/net/' . $iface . '/operstate';
        if (file_exists($state_file)) {
            $state = trim(@file_get_contents($state_file));
        }

        $ip = ''; $prefix = '';
        $ip_out = @shell_exec("ip -4 addr show " . escapeshellarg($iface) . " 2>/dev/null");
        if ($ip_out && preg_match('/inet\s+([0-9\.]+)\/(\d+)/', $ip_out, $m)) {
            $ip = $m[1];
            $prefix = $m[2];
        }

        if (empty($ip)) {
            $ifconfig_out = @shell_exec("ifconfig " . escapeshellarg($iface) . " 2>/dev/null");
            if ($ifconfig_out && preg_match('/inet\s+(?:addr:)?([0-9\.]+)/', $ifconfig_out, $m)) {
                $ip = $m[1];
                if (preg_match('/Mask:(255\.[0-9\.]+)/', $ifconfig_out, $mm)) {
                    $prefix = maskToPrefix($mm[1]);
                }
            }
        }

        $subnet = '';
        if ($prefix !== '') {
            $subnet = prefixToMask((int)$prefix);
        }

        $bootproto = 'static';
        $onboot = 'yes';
        $dns_from_file = array();
        $ifcfg_candidates = array(
            "/etc/sysconfig/network-scripts/ifcfg-" . $iface,
            "/etc/sysconfig/network/ifcfg-" . $iface,
            "/etc/network/interfaces.d/" . $iface
        );
        foreach ($ifcfg_candidates as $ifcfg_file) {
            if (file_exists($ifcfg_file) && is_readable($ifcfg_file)) {
                $content = @file_get_contents($ifcfg_file);
                if ($content) {
                    if (preg_match('/BOOTPROTO\s*=\s*["\']?(\w+)/i', $content, $m)) $bootproto = strtolower($m[1]);
                    if (preg_match('/ONBOOT\s*=\s*["\']?(\w+)/i', $content, $m)) $onboot = strtolower($m[1]);
                    if (preg_match_all('/DNS\d*\s*=\s*["\']?([^\s"\']+)/i', $content, $mm2)) {
                        $dns_from_file = $mm2[1];
                    }
                }
                break;
            }
        }

        $dns_list = !empty($dns_from_file) ? $dns_from_file : $dns_list_global;

        // ✅ route های مخصوص این اینترفیس
        $iface_routes = getRoutesForInterface($iface, $all_routes);

        $interfaces[] = array(
            'name' => $iface,
            'ip' => $ip,
            'subnet' => $subnet,
            'prefix' => $prefix,
            'gateway' => isset($gateways[$iface]) ? $gateways[$iface] : '',
            'dns' => implode(', ', array_slice($dns_list, 0, 3)),
            'dns_primary' => isset($dns_list[0]) ? $dns_list[0] : '',
            'dns_secondary' => isset($dns_list[1]) ? $dns_list[1] : '',
            'mac' => $mac,
            'status' => $state,
            'bootproto' => $bootproto,
            'onboot' => $onboot,
            'routes' => $iface_routes
        );
    }

    $hostname = @shell_exec("hostname 2>/dev/null");
    return array(
        'hostname' => trim($hostname),
        'interfaces' => $interfaces
    );
}

function setNetworkConfig($iface, $ip, $subnet, $gateway, $dns1, $dns2, $hostname) {
    ob_start(); // جلوگیری از خراب شدن JSON

    try {
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $iface)) {
            ob_end_clean();
            return array('success' => false, 'message' => 'نام کارت شبکه نامعتبر است.');
        }

        $prefix = maskToPrefix($subnet);
        if ($prefix === null) {
            ob_end_clean();
            return array('success' => false, 'message' => 'Subnet Mask نامعتبر است.');
        }

        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            ob_end_clean();
            return array('success' => false, 'message' => 'آدرس IP نامعتبر است.');
        }

        if (!empty($gateway) && !filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            ob_end_clean();
            return array('success' => false, 'message' => 'Gateway نامعتبر است.');
        }

        if (!empty($dns1) && !filter_var($dns1, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            ob_end_clean();
            return array('success' => false, 'message' => 'DNS اول نامعتبر است.');
        }
        if (!empty($dns2) && !filter_var($dns2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            ob_end_clean();
            return array('success' => false, 'message' => 'DNS دوم نامعتبر است.');
        }

        $ifcfg_dir = "/etc/sysconfig/network-scripts";
        $ifcfg_file = $ifcfg_dir . "/ifcfg-" . $iface;

        if (file_exists($ifcfg_file)) {
            @copy($ifcfg_file, $ifcfg_file . '.bak.' . date('YmdHis'));
        }

        $content = "# Generated by Dashboard - " . date('Y-m-d H:i:s') . "\n";
        $content .= "DEVICE=" . $iface . "\n";
        $content .= "TYPE=Ethernet\n";
        $content .= "BOOTPROTO=none\n";
        $content .= "ONBOOT=yes\n";
        $content .= "IPADDR=" . $ip . "\n";
        $content .= "PREFIX=" . $prefix . "\n";
        $content .= "NETMASK=" . $subnet . "\n";
        if (!empty($gateway)) { $content .= "GATEWAY=" . $gateway . "\n"; }
        if (!empty($dns1)) { $content .= "DNS1=" . $dns1 . "\n"; }
        if (!empty($dns2)) { $content .= "DNS2=" . $dns2 . "\n"; }
        $content .= "DEFROUTE=yes\n";
        $content .= "IPV4_FAILURE_FATAL=no\n";
        $content .= "NM_CONTROLLED=yes\n";

        $write_result = @file_put_contents($ifcfg_file, $content);
        if ($write_result === false) {
            $esc_content = escapeshellarg($content);
            @shell_exec("echo $esc_content | sudo tee " . escapeshellarg($ifcfg_file) . " > /dev/null 2>&1");
        }

        if (!empty($dns1)) {
            $resolv = "# Generated by Dashboard\n";
            $resolv .= "nameserver " . $dns1 . "\n";
            if (!empty($dns2)) { $resolv .= "nameserver " . $dns2 . "\n"; }
            @file_put_contents('/etc/resolv.conf', $resolv);
        }

        if (!empty($hostname) && preg_match('/^[a-zA-Z0-9\.\-]+$/', $hostname)) {
            @shell_exec("sudo -n hostnamectl set-hostname " . escapeshellarg($hostname) . " 2>&1");
            @shell_exec("hostname " . escapeshellarg($hostname) . " 2>&1");
            @file_put_contents('/etc/hostname', $hostname . "\n");

            $hosts = @file_get_contents('/etc/hosts');
            if ($hosts) {
                if (preg_match('/^127\.0\.1\.1\s+/m', $hosts)) {
                    $hosts = preg_replace('/^127\.0\.1\.1\s+.*$/m', '127.0.1.1 ' . $hostname, $hosts);
                } else {
                    $hosts .= "\n127.0.1.1 " . $hostname . "\n";
                }
                @file_put_contents('/etc/hosts', $hosts);
            }
        }

        // ✅ ارسال درخواست به Cron Job (بدون نیاز به nmcli)
        $trigger_file = '/tmp/pbx_network_change.txt';
        $file_content = "IFACE=\"" . $iface . "\"\nIP=\"" . $ip . "\"\nPREFIX=\"" . $prefix . "\"\nGATEWAY=\"" . $gateway . "\"\nDNS1=\"" . $dns1 . "\"\nDNS2=\"" . $dns2 . "\"\nHOSTNAME=\"" . $hostname . "\"\n";
        
        if (@file_put_contents($trigger_file, $file_content) === false) {
            ob_end_clean();
            return array('success' => false, 'message' => 'خطا: دسترسی به مسیر /tmp وجود ندارد.');
        }
        
        @shell_exec("sudo -n /usr/local/bin/apply_network.sh > /dev/null 2>&1 &");
        @shell_exec("asterisk -rx 'sip reload' 2>/dev/null");
        @shell_exec("asterisk -rx 'pjsip reload' 2>/dev/null");

        ob_end_clean();
        return array(
            'success' => true,
            'message' => 'تنظیمات با موفقیت ثبت شد و حداکثر تا ۱ دقیقه آینده توسط پس‌زمینه اعمال می‌شود.',
            'output' => 'Settings saved.'
        );

    } catch (Throwable $e) {
        ob_end_clean();
        return array(
            'success' => false,
            'message' => 'خطای داخلی PHP: ' . $e->getMessage(),
            'output' => 'File: ' . $e->getFile() . ' Line: ' . $e->getLine()
        );
    }
}

// ==========================================
// API Endpoints
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_system_stats') {
    header('Content-Type: application/json; charset=utf-8');
    $cached = cache_get('system_stats', 2);
    if ($cached !== null) { echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
    $data = getSystemStats();
    cache_set('system_stats', $data);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'get_network_config') {
    header('Content-Type: application/json; charset=utf-8');
    $cached = cache_get('network_config', 30);  // ۳۰ ثانیه کافی است
    if ($cached !== null) { echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }
    $data = getNetworkInfo();
    cache_set('network_config', $data);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// API ذخیره تنظیمات شبکه (با نمایش خطا)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api']) && $_POST['api'] === 'save_network_config') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $result = setNetworkConfig(
            isset($_POST['iface']) ? trim($_POST['iface']) : '',
            isset($_POST['ip']) ? trim($_POST['ip']) : '',
            isset($_POST['subnet']) ? trim($_POST['subnet']) : '',
            isset($_POST['gateway']) ? trim($_POST['gateway']) : '',
            isset($_POST['dns1']) ? trim($_POST['dns1']) : '',
            isset($_POST['dns2']) ? trim($_POST['dns2']) : '',
            isset($_POST['hostname']) ? trim($_POST['hostname']) : ''
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(array(
            'success' => false, 
            'message' => 'خطای کشنده PHP: ' . $e->getMessage() . ' در خط ' . $e->getLine()
        ), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==========================================
// ✅ API: تعداد تماس‌های پاسخ داده شده هر داخلی (تفکیک شده)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_extension_answered_counts') {
    header('Content-Type: application/json; charset=utf-8');

    $req_year = isset($_GET['year']) ? trim($_GET['year']) : 'all';
    $counts = array();

    // ۱) خواندن لیست داخلی‌ها
    try {
        $pdo_ast = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
        $rows = $pdo_ast->query("SELECT extension, name FROM users ORDER BY extension ASC")->fetchAll();
        foreach ($rows as $r) {
            $counts[$r['extension']] = array(
                'ext'      => $r['extension'],
                'name'     => $r['name'],
                'today'    => 0,
                'week'     => 0,
                'month'    => 0,
                'year'     => 0, // مجموع کل
                'inbound'  => 0, // ورودی
                'outbound' => 0, // خروجی
                'internal' => 0  // داخلی
            );
        }
    } catch (Exception $e) {
        echo json_encode(array('error' => 'خطای دیتابیس اصلی: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ۲) شمارش بر اساس بازه انتخابی
    try {
        $pdo_cdr = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        if ($req_year === 'all') {
            $start_date = '1970-01-01 00:00:00';
            $end_date = date('Y-m-d H:i:s');
            $year_label = 'کل (از ابتدا)';
        } else {
            $j_year = (int)$req_year;
            $g_start = jalali_to_gregorian($j_year, 1, 1);
            $g_next  = jalali_to_gregorian($j_year + 1, 1, 1);
            $start_date = sprintf('%04d-%02d-%02d 00:00:00', $g_start[0], $g_start[1], $g_start[2]);
            $end_date   = sprintf('%04d-%02d-%02d 00:00:00', $g_next[0], $g_next[1], $g_next[2]);
            $year_label = 'سال ' . $req_year;
        }

        // تاریخ‌های امروز، ۷ روز، ماه جاری
        $today_start_str = date('Y-m-d 00:00:00');
        $week_start_str  = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $j_today = gregorian_to_jalali(date('Y'), date('n'), date('j'));
        $j_month = (int)$j_today[1];
        $j_year_curr = (int)$j_today[0];
        $g_m_start = jalali_to_gregorian($j_year_curr, $j_month, 1);
        $month_start_str = sprintf('%04d-%02d-%02d 00:00:00', $g_m_start[0], $g_m_start[1], $g_m_start[2]);

        // کوئری ۱: تماس‌های ورودی و داخلی (مقصد = داخلی)
        $sql1 = "SELECT dst AS ext,
                    SUM(CASE WHEN src REGEXP '^[0-9]{3,5}$' THEN 1 ELSE 0 END) AS internal_cnt,
                    SUM(CASE WHEN src NOT REGEXP '^[0-9]{3,5}$' THEN 1 ELSE 0 END) AS inbound_cnt,
                    SUM(CASE WHEN calldate >= :today THEN 1 ELSE 0 END) AS today_cnt,
                    SUM(CASE WHEN calldate >= :week  THEN 1 ELSE 0 END) AS week_cnt,
                    SUM(CASE WHEN calldate >= :month THEN 1 ELSE 0 END) AS month_cnt
                 FROM cdr
                 WHERE disposition = 'ANSWERED'
                   AND dst REGEXP '^[0-9]{3,5}$'
                   AND calldate >= :start AND calldate < :end
                 GROUP BY dst";
        $stmt1 = $pdo_cdr->prepare($sql1);
        $stmt1->execute(array(
            ':today' => $today_start_str,
            ':week'  => $week_start_str,
            ':month' => $month_start_str,
            ':start' => $start_date,
            ':end'   => $end_date
        ));
        $rows1 = $stmt1->fetchAll();

        foreach ($rows1 as $r) {
            if (isset($counts[$r['ext']])) {
                $counts[$r['ext']]['internal'] = (int)$r['internal_cnt'];
                $counts[$r['ext']]['inbound']  = (int)$r['inbound_cnt'];
                $counts[$r['ext']]['today']   += (int)$r['today_cnt'];
                $counts[$r['ext']]['week']    += (int)$r['week_cnt'];
                $counts[$r['ext']]['month']   += (int)$r['month_cnt'];
            }
        }

        // کوئری ۲: تماس‌های خروجی (مبدا = داخلی و مقصد خارجی)
        $sql2 = "SELECT src AS ext,
                    COUNT(*) AS outbound_cnt,
                    SUM(CASE WHEN calldate >= :today THEN 1 ELSE 0 END) AS out_today_cnt,
                    SUM(CASE WHEN calldate >= :week  THEN 1 ELSE 0 END) AS out_week_cnt,
                    SUM(CASE WHEN calldate >= :month THEN 1 ELSE 0 END) AS out_month_cnt
                 FROM cdr
                 WHERE disposition = 'ANSWERED'
                   AND src REGEXP '^[0-9]{3,5}$'
                   AND dst NOT REGEXP '^[0-9]{3,5}$'
                   AND calldate BETWEEN :start AND :end
                 GROUP BY src";
        $stmt2 = $pdo_cdr->prepare($sql2);
        $stmt2->execute(array(
            ':today' => $today_start_str,
            ':week'  => $week_start_str,
            ':month' => $month_start_str,
            ':start' => $start_date,
            ':end'   => $end_date
        ));
        $rows2 = $stmt2->fetchAll();

        foreach ($rows2 as $r) {
            if (isset($counts[$r['ext']])) {
                $counts[$r['ext']]['outbound'] = (int)$r['outbound_cnt'];
                $counts[$r['ext']]['today']   += (int)$r['out_today_cnt'];
                $counts[$r['ext']]['week']    += (int)$r['out_week_cnt'];
                $counts[$r['ext']]['month']   += (int)$r['out_month_cnt'];
            }
        }

        // محاسبه مجموع کل برای هر داخلی
        foreach ($counts as &$c) {
            $c['year'] = $c['inbound'] + $c['outbound'] + $c['internal'];
        }
        unset($c);

    } catch (Exception $e) {
        echo json_encode(array('error' => 'خطای دیتابیس CDR: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $list = array_values($counts);
    usort($list, function($a, $b) { return $b['year'] - $a['year']; });

    echo json_encode(array(
        'list'  => $list,
        'total' => count($list),
        'year_label' => isset($year_label) ? $year_label : 'کل'
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// ==========================================
// ✅ API: لیست تماس‌های یک داخلی خاص (اصلاح شده و دقیق)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_extension_calls') {
    header('Content-Type: application/json; charset=utf-8');

    $ext = isset($_GET['ext']) ? trim($_GET['ext']) : '';
    $year = isset($_GET['year']) ? trim($_GET['year']) : '';
    $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    
    $limit = 15;
    $offset = ($page - 1) * $limit;

    if (empty($ext)) {
        echo json_encode(array('error' => 'شماره داخلی نامعتبر است.'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ✅ منطق یکپارچه برای بازه زمانی (پشتیبانی از سال شمسی و کل زمان‌ها)
    if ($year !== '') {
        if ($year === 'all') {
            $time_condition = "1=1";
        } else {
            $j_year = (int)$year;
            $g_start = jalali_to_gregorian($j_year, 1, 1);
            $g_next  = jalali_to_gregorian($j_year + 1, 1, 1);
            $start_date = sprintf('%04d-%02d-%02d 00:00:00', $g_start[0], $g_start[1], $g_start[2]);
            $end_date   = sprintf('%04d-%02d-%02d 00:00:00', $g_next[0], $g_next[1], $g_next[2]);
            $time_condition = "calldate >= '$start_date' AND calldate < '$end_date'";
        }
    } else {
        if ($days == -1 || $days >= 3650) {
            $time_condition = "1=1";
        } elseif ($days <= 1) {
            $time_condition = "calldate >= '" . date('Y-m-d 00:00:00') . "' AND calldate <= '" . date('Y-m-d 23:59:59') . "'";
        } else {
            $start_date = date('Y-m-d 00:00:00', strtotime("-$days days"));
            $end_date = date('Y-m-d 23:59:59');
            $time_condition = "calldate >= '$start_date' AND calldate <= '$end_date'";
        }
    }

    try {
        $pdo_cdr = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        // ۱. گرفتن تعداد کل رکوردها
        $count_sql = "SELECT COUNT(*) as total FROM cdr 
                      WHERE (src = :ext OR dst = :ext) 
                      AND $time_condition";
        $stmt_count = $pdo_cdr->prepare($count_sql);
        $stmt_count->execute(array(':ext' => $ext));
        $total_records = (int)$stmt_count->fetch()['total'];
        $total_pages = ceil($total_records / $limit);
        if ($total_pages < 1) $total_pages = 1;

        // ۲. محاسبه آمار کلی، مبداهای برتر و مقصدهای برتر
        $stats = array(
            'answered' => 0, 'failed' => 0, 'total_talk' => 0,
            'top_src' => array(), 'top_dst' => array()
        );

        // ✅ آمار پاسخ داده شده ONLY برای تماس‌های ورودی (دقیقا همخوان با جدول)
        $stats_stmt = $pdo_cdr->prepare("SELECT 
            SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN disposition != 'ANSWERED' THEN 1 ELSE 0 END) as failed,
            SUM(billsec) as total_talk
            FROM cdr WHERE (src = :e1 OR dst = :e2) AND $time_condition");
        $stats_stmt->execute(array(':e1' => $ext, ':e2' => $ext));
        $s_row = $stats_stmt->fetch();
        if ($s_row) {
            $stats['answered'] = (int)$s_row['answered'];
            $stats['failed'] = (int)$s_row['failed'];
            $stats['total_talk'] = (int)$s_row['total_talk'];
        }

        // بیشترین شماره‌های مبدا (کسانی که به این داخلی زنگ زدند)
        $ts_stmt = $pdo_cdr->prepare("SELECT src, COUNT(*) as cnt FROM cdr WHERE dst = :e1 AND src != :e2 AND $time_condition GROUP BY src ORDER BY cnt DESC LIMIT 6");
        $ts_stmt->execute(array(':e1' => $ext, ':e2' => $ext));
        $stats['top_src'] = $ts_stmt->fetchAll();

        // بیشترین شماره‌های مقصد (کسانی که این داخلی به آن‌ها زنگ زده)
        $td_stmt = $pdo_cdr->prepare("SELECT dst, COUNT(*) as cnt FROM cdr WHERE src = :e1 AND dst != :e2 AND $time_condition GROUP BY dst ORDER BY cnt DESC LIMIT 6");
        $td_stmt->execute(array(':e1' => $ext, ':e2' => $ext));
        $stats['top_dst'] = $td_stmt->fetchAll();

        // ۳. واکشی رکوردهای صفحه جاری
        $sql = "SELECT calldate, src, dst, disposition, billsec, channel, dstchannel, dcontext
                FROM cdr
                WHERE (src = :ext OR dst = :ext)
                  AND $time_condition
                ORDER BY calldate DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $pdo_cdr->prepare($sql);
        $stmt->execute(array(':ext' => $ext));
        $rows = $stmt->fetchAll();

        $calls = array();
        foreach ($rows as $r) {
            $src = trim($r['src']);
            $dst = trim($r['dst']);
            $dcontext = $r['dcontext'];
            $dstchannel = $r['dstchannel'];

            $call_type_raw = classifyCall($src, $dst, $dcontext, $dstchannel);
            $type_map = array(
                'internal' => 'داخلی',
                'inbound'  => 'ورودی',
                'outbound' => 'خروجی',
                'queue'    => 'صف'
            );
            $type = isset($type_map[$call_type_raw]) ? $type_map[$call_type_raw] : 'داخلی';

            $calls[] = array(
                'calldate' => convertToJalali($r['calldate']),
                'src' => $src,
                'dst' => $dst,
                'type' => $type,
                'is_answered' => $r['disposition'] === 'ANSWERED',
                'disposition' => ($r['disposition'] === 'ANSWERED') ? 'پاسخ داده شده' : 'ناموفق',
                'billsec' => $r['billsec']
            );
        }

        echo json_encode(array(
            'calls' => $calls,
            'stats' => $stats,
            'total_records' => $total_records,
            'total_pages' => $total_pages,
            'current_page' => $page
        ), JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        echo json_encode(array('error' => 'خطای دیتابیس: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
        exit;
    }
}


// ==========================================
// ✅ API جدید: نمودار توزیع سالانه (12 ماه شمسی)
// ✅ با همان منطق دقیق ستون «نوع تماس» و «خط/ترانک» در جدول CDR
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_yearly_chart') {
    header('Content-Type: application/json; charset=utf-8');

    $months_fa = array("فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند");

    $j_today = gregorian_to_jalali(date('Y'), date('n'), date('j'));
    $j_year = isset($_GET['year']) ? intval($_GET['year']) : (int)$j_today[0];
    $j_current_month = (int)$j_today[1];
    $is_current_year = ($j_year === (int)$j_today[0]);
    $max_month = $is_current_year ? $j_current_month : 12;

    // ✅ کش ۵ دقیقه‌ای
    $cache_key = 'yearly_' . $j_year;
    $cached = cache_get($cache_key, 300);
    if ($cached !== null) { echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }

    $inbound_data  = array_fill(0, 12, 0);
    $outbound_data = array_fill(0, 12, 0);
    $total_in  = 0;
    $total_out = 0;

    try {
        $pdo_y = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        // بازه‌ی میلادی سال شمسی (با شروع سال بعد برای جلوگیری از مشکل کبیسه)
        $g_start = jalali_to_gregorian($j_year, 1, 1);
        $g_next  = jalali_to_gregorian($j_year + 1, 1, 1);
        $start_str = sprintf('%04d-%02d-%02d 00:00:00', $g_start[0], $g_start[1], $g_start[2]);
        $end_str   = sprintf('%04d-%02d-%02d 00:00:00', $g_next[0], $g_next[1], $g_next[2]);
        if ($is_current_year) $end_str = date('Y-m-d 23:59:59');

        // ✅ تجمیع در SQL — حداکثر ~۳۶۵ ردیف برمی‌گردد، نه میلیون
        $sql = "SELECT DATE(calldate) AS d,
                    SUM(CASE
                        WHEN dcontext IN ('from-trunk','from-pstn','from-digital','ext-did')
                          OR (LENGTH(src) > 4 AND dcontext NOT REGEXP '^(from-internal|outbound-allroutes)')
                        THEN 1 ELSE 0 END) AS inbound_cnt,
                    SUM(CASE
                        WHEN (dcontext IN ('outbound-allroutes','from-outbound-rules','macro-dialout-trunk')
                              OR LENGTH(dst) > 4
                              OR dstchannel REGEXP '^(DAHDI|SIP|PJSIP|IAX2)/')
                          AND NOT (dcontext IN ('from-trunk','from-pstn','from-digital','ext-did')
                                   OR (LENGTH(src) > 4 AND dcontext NOT REGEXP '^(from-internal|outbound-allroutes)'))
                        THEN 1 ELSE 0 END) AS outbound_cnt
                FROM cdr
                WHERE calldate >= :st AND calldate < :et
                  AND NOT (dcontext LIKE '%queue%' OR lastapp = 'Queue')
                  AND NOT (src REGEXP '^[0-9]{3,5}$' AND dst REGEXP '^[0-9]{3,5}$')
                GROUP BY DATE(calldate)
                ORDER BY d ASC";
        $stmt = $pdo_y->prepare($sql);
        $stmt->execute(array(':st' => $start_str, ':et' => $end_str));

        foreach ($stmt->fetchAll() as $r) {
            $parts = explode('-', $r['d']);
            if (count($parts) < 3) continue;
            $j = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
            $jm = (int)$j[1];
            if ($jm < 1 || $jm > 12) continue;
            $inbound_data[$jm - 1]  += (int)$r['inbound_cnt'];
            $outbound_data[$jm - 1] += (int)$r['outbound_cnt'];
            $total_in  += (int)$r['inbound_cnt'];
            $total_out += (int)$r['outbound_cnt'];
        }
    } catch (Exception $e) {
        echo json_encode(array('error' => 'خطای دیتابیس: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $labels = array();
    $inbound_slice = array();
    $outbound_slice = array();
    for ($m = 1; $m <= $max_month; $m++) {
        $labels[] = $months_fa[$m - 1];
        $inbound_slice[] = $inbound_data[$m - 1];
        $outbound_slice[] = $outbound_data[$m - 1];
    }

    $response = array(
        'labels' => $labels,
        'inbound' => $inbound_slice,
        'outbound' => $outbound_slice,
        'year' => $j_year,
        'total_inbound' => $total_in,
        'total_outbound' => $total_out
    );
    cache_set($cache_key, $response);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}


// ==========================================
// ✅ API تحلیل ترانک‌ها در طول سال شمسی
// (نسخه مستقل - بدون وابستگی به jalali_to_gregorian)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_trunk_yearly_chart') {
    header('Content-Type: application/json; charset=utf-8');

    $months_fa = array("فروردین","اردیبهشت","خرداد","تیر","مرداد","شهریور","مهر","آبان","آذر","دی","بهمن","اسفند");

    $j_today = gregorian_to_jalali(date('Y'), date('n'), date('j'));
    $j_year = isset($_GET['year']) ? intval($_GET['year']) : (int)$j_today[0];
    $j_current_month = (int)$j_today[1];
    $is_current_year = ($j_year === (int)$j_today[0]);
    $max_month = $is_current_year ? $j_current_month : 12;

    $cache_key = 'trunk_yearly_' . $j_year;
    $cached = cache_get($cache_key, 300);
    if ($cached !== null) { echo json_encode($cached, JSON_UNESCAPED_UNICODE); exit; }

    try {
        $pdo_ty = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        $g_start = jalali_to_gregorian($j_year, 1, 1);
        $g_next  = jalali_to_gregorian($j_year + 1, 1, 1);
        $start_str = sprintf('%04d-%02d-%02d 00:00:00', $g_start[0], $g_start[1], $g_start[2]);
        $end_str   = sprintf('%04d-%02d-%02d 00:00:00', $g_next[0], $g_next[1], $g_next[2]);
        if ($is_current_year) $end_str = date('Y-m-d 23:59:59');

        // ✅ تجمیع در SQL روی (روز، جهت، نام ترانک)
        // استخراج نام ترانک از channel/dstchannel با SUBSTRING_INDEX
        // منطق: TECH/trunk-xxxx → trunk  |  TECH/trunk/something → trunk
        $extract_trunk = "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(%s, '/', 2), '/', -1), '-', 1)";

        $sql = "SELECT
                    DATE(calldate) AS d,
                    CASE
                        WHEN dcontext IN ('from-trunk','from-pstn','from-digital','ext-did')
                          OR (LENGTH(src) > 4 AND dcontext NOT REGEXP '^(from-internal|outbound-allroutes)')
                        THEN 'inbound' ELSE 'outbound'
                    END AS direction,
                    CASE
                        WHEN dcontext IN ('from-trunk','from-pstn','from-digital','ext-did')
                          OR (LENGTH(src) > 4 AND dcontext NOT REGEXP '^(from-internal|outbound-allroutes)')
                        THEN COALESCE(NULLIF(" . sprintf($extract_trunk, 'channel') . ", ''), dcontext)
                        ELSE COALESCE(NULLIF(" . sprintf($extract_trunk, 'dstchannel') . ", ''), 'Trunk')
                    END AS trunk,
                    COUNT(*) AS cnt
                FROM cdr
                WHERE calldate >= :st AND calldate < :et
                  AND NOT (dcontext LIKE '%queue%' OR lastapp = 'Queue')
                  AND NOT (src REGEXP '^[0-9]{3,5}$' AND dst REGEXP '^[0-9]{3,5}$')
                  AND (
                    dcontext IN ('from-trunk','from-pstn','from-digital','ext-did')
                    OR (LENGTH(src) > 4 AND dcontext NOT REGEXP '^(from-internal|outbound-allroutes)')
                    OR dcontext IN ('outbound-allroutes','from-outbound-rules','macro-dialout-trunk')
                    OR LENGTH(dst) > 4
                    OR dstchannel REGEXP '^(DAHDI|SIP|PJSIP|IAX2)/'
                  )
                GROUP BY d, direction, trunk
                ORDER BY d ASC";

        $stmt = $pdo_ty->prepare($sql);
        $stmt->execute(array(':st' => $start_str, ':et' => $end_str));

        $trunk_stats = array();
        foreach ($stmt->fetchAll() as $r) {
            $parts = explode('-', $r['d']);
            if (count($parts) < 3) continue;
            $j = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
            $jm = (int)$j[1];
            if ($jm < 1 || $jm > 12 || $jm > $max_month) continue;

            $name = $r['trunk'] ?: 'نامشخص';
            $dir  = ($r['direction'] === 'inbound') ? 'inbound' : 'outbound';
            $cnt  = (int)$r['cnt'];

            if (!isset($trunk_stats[$name])) {
                $trunk_stats[$name] = array(
                    'inbound'  => array_fill(0, 12, 0),
                    'outbound' => array_fill(0, 12, 0),
                    'total'    => 0
                );
            }
            $trunk_stats[$name][$dir][$jm - 1] += $cnt;
            $trunk_stats[$name]['total'] += $cnt;
        }

        $trunks_list = array();
        foreach ($trunk_stats as $name => $data) {
            $trunks_list[] = array(
                'name' => $name,
                'inbound'  => array_slice($data['inbound'], 0, $max_month),
                'outbound' => array_slice($data['outbound'], 0, $max_month),
                'total' => $data['total']
            );
        }
        usort($trunks_list, function($a, $b) { return $b['total'] - $a['total']; });

        $labels = array();
        for ($m = 1; $m <= $max_month; $m++) $labels[] = $months_fa[$m - 1];

        $response = array(
            'labels' => $labels,
            'trunks' => $trunks_list,
            'year' => $j_year,
            'total_trunks' => count($trunks_list)
        );
        cache_set($cache_key, $response);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(array('error' => 'خطای دیتابیس: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==========================================
// ✅ مدیریت لیست سیاه: افزودن
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api']) && $_POST['api'] === 'add_to_blacklist') {
    header('Content-Type: application/json; charset=utf-8');

    $number = isset($_POST['number']) ? trim($_POST['number']) : '';
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

    if (!preg_match('/^\+?[0-9]{3,20}$/', $number)) {
        echo json_encode(array('success' => false, 'message' => 'شماره نامعتبر است (فقط رقم و در صورت نیاز + اول شماره).'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($reason === '') $reason = 'Blocked from Dashboard';
    // ستون description در FreePBX معمولاً ۵۰ کاراکتری است
    $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 50, 'UTF-8') : substr($reason, 0, 50);

    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        $check = $pdo->prepare("SELECT number FROM blacklist WHERE number = :num");
        $check->execute(array(':num' => $number));
        if ($check->fetch()) {
            echo json_encode(array('success' => false, 'message' => 'این شماره قبلاً در لیست سیاه قرار دارد.'), JSON_UNESCAPED_UNICODE);
            exit;
        }

        $ins = $pdo->prepare("INSERT INTO blacklist (number, description) VALUES (:num, :descr)");
        $ins->execute(array(':num' => $number, ':descr' => $reason));

        // ثبت در AstDB تا مسدودسازی واقعاً توسط Asterisk اعمال شود
        @shell_exec("asterisk -rx " . escapeshellarg('database put blacklist ' . $number . ' 1') . " 2>&1");

        echo json_encode(array('success' => true, 'message' => 'شماره ' . $number . ' با موفقیت مسدود شد.'), JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(array('success' => false, 'message' => 'خطای دیتابیس: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==========================================
// ✅ مدیریت لیست سیاه: حذف
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api']) && $_POST['api'] === 'remove_from_blacklist') {
    header('Content-Type: application/json; charset=utf-8');

    $number = isset($_POST['number']) ? trim($_POST['number']) : '';
    if (!preg_match('/^\+?[0-9]{3,20}$/', $number)) {
        echo json_encode(array('success' => false, 'message' => 'شماره نامعتبر است.'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
        $del = $pdo->prepare("DELETE FROM blacklist WHERE number = :num");
        $del->execute(array(':num' => $number));

        @shell_exec("asterisk -rx " . escapeshellarg('database del blacklist ' . $number) . " 2>&1");

        echo json_encode(array('success' => true, 'message' => 'شماره ' . $number . ' از لیست سیاه حذف شد.'), JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(array('success' => false, 'message' => 'خطای دیتابیس: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==========================================
// ✅ مدیریت لیست سیاه: دریافت لیست (با جستجو و صفحه‌بندی)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_blacklist') {
    header('Content-Type: application/json; charset=utf-8');

    $page   = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $limit  = 10;
    $offset = ($page - 1) * $limit;

    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        // تلاش برای ساخت جدول در صورت نبودن (بی‌صدا)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `blacklist` (
                `number` VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL,
                `description` VARCHAR(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                PRIMARY KEY (`number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e) {
            // اگر اجازه ساخت نداشتیم، ادامه بده — احتمالاً از قبل هست
        }

        $where  = '';
        $params = array();
        if ($search !== '') {
            $where = " WHERE number LIKE :s OR description LIKE :s";
            $params[':s'] = '%' . $search . '%';
        }

        $cnt = $pdo->prepare("SELECT COUNT(*) AS total FROM blacklist" . $where);
        $cnt->execute($params);
        $row = $cnt->fetch();
        $total = $row ? (int)$row['total'] : 0;
        $total_pages = max(1, (int)ceil($total / $limit));

        $stmt = $pdo->prepare("SELECT number, description FROM blacklist" . $where . " ORDER BY number ASC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
        $stmt->execute($params);

        echo json_encode(array(
            'list' => $stmt->fetchAll(),
            'total' => $total,
            'total_pages' => $total_pages,
            'current_page' => $page
        ), JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(array('error' => 'خطای دیتابیس: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
    }
    exit;
}
// ==========================================
// ✅ API جدید: تماس کلیک‌ای (Originate Call)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'originate_call') {
    header('Content-Type: application/json; charset=utf-8');
    
    $ext = isset($_GET['ext']) ? trim($_GET['ext']) : '';
    $dest = isset($_GET['dest']) ? trim($_GET['dest']) : '';
    
    if (!preg_match('/^[0-9*#+]+$/', $ext) || !preg_match('/^[0-9*#+]+$/', $dest)) {
        echo json_encode(array('success' => false, 'message' => 'شماره‌های وارد شده نامعتبر هستند.'), JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $cmd = "asterisk -rx 'channel originate Local/" . $ext . "@from-internal extension " . $dest . "@custom-webdialer' 2>&1";
        $output = @shell_exec($cmd);
        
        if (stripos($output, 'Unable') !== false || stripos($output, 'Error') !== false || stripos($output, 'No such') !== false) {
            echo json_encode(array('success' => false, 'message' => 'خطا در تماس: ' . $output), JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(array('success' => true, 'message' => 'تماس به داخلی ' . $ext . ' ارسال شد. گوشی خود را بردارید.'), JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        echo json_encode(array('success' => false, 'message' => 'خطای سیستم: ' . $e->getMessage()), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==========================================
// ✅ API جدید: عملکرد اپراتورها (اصلاح شده و دقیق)
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_operator_performance') {
    header('Content-Type: application/json; charset=utf-8');
    
    $period = isset($_GET['period']) ? $_GET['period'] : 'today';
    
    // ✅ استفاده از تاریخ PHP برای جلوگیری از اختلاف Timezone دیتابیس
    $today_start = date('Y-m-d 00:00:00');
    $today_end   = date('Y-m-d 23:59:59');
    
    if ($period === 'week') {
        $start = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $time_condition = "calldate >= '$start' AND calldate <= '$today_end'";
    } elseif ($period === 'month') {
        $start = date('Y-m-d 00:00:00', strtotime('-29 days'));
        $time_condition = "calldate >= '$start' AND calldate <= '$today_end'";
    } elseif ($period === 'year') {
        $start = date('Y-m-d 00:00:00', strtotime('-364 days'));
        $time_condition = "calldate >= '$start' AND calldate <= '$today_end'";
    } elseif ($period === 'all') {
        $time_condition = "1=1";
    } else {
        $time_condition = "calldate >= '$today_start' AND calldate <= '$today_end'";
    }

    try {
        // دریافت لیست کاربران از دیتابیس استریسک
        $pdo_ast = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
        $users = $pdo_ast->query("SELECT extension, name FROM users ORDER BY extension ASC")->fetchAll();

        // اتصال به دیتابیس CDR
        $pdo_cdr = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        // ✅ آمار تماس‌های ورودی/داخلی (اصلاح توازن اعداد)
        // قرار دادن تماس‌های CANCEL و FAILED در ستون Rejected تا مجموع اعداد با Offered برابر شود
        $sql_in = "SELECT dst AS ext,
            COUNT(*) as offered,
            SUM(CASE WHEN disposition='ANSWERED' THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN disposition NOT IN ('ANSWERED', 'BUSY', 'NO ANSWER') THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN disposition='BUSY' THEN 1 ELSE 0 END) as busy,
            SUM(CASE WHEN disposition='NO ANSWER' THEN 1 ELSE 0 END) as no_answer,
            SUM(billsec) as talk_time,
            SUM(duration - billsec) as ring_time
            FROM cdr WHERE $time_condition AND dst REGEXP '^[0-9]{3,5}$' GROUP BY dst";
        $inbound_stats = array();
        foreach ($pdo_cdr->query($sql_in)->fetchAll() as $row) { $inbound_stats[$row['ext']] = $row; }

        // آمار تماس‌های خروجی (به عنوان مبدا)
        $sql_out = "SELECT src AS ext,
            COUNT(*) as outbound_calls
            FROM cdr WHERE $time_condition AND src REGEXP '^[0-9]{3,5}$' AND dst NOT REGEXP '^[0-9]{3,5}$' GROUP BY src";
        $outbound_stats = array();
        foreach ($pdo_cdr->query($sql_out)->fetchAll() as $row) { $outbound_stats[$row['ext']] = $row; }

        $result = array();
        $elapsed_today = time() - strtotime('today');

        foreach ($users as $u) {
            $ext = $u['extension'];
            $in = isset($inbound_stats[$ext]) ? $inbound_stats[$ext] : null;
            $out = isset($outbound_stats[$ext]) ? $outbound_stats[$ext] : null;

            $offered = $in ? (int)$in['offered'] : 0;
            $answered = $in ? (int)$in['answered'] : 0;
            $rejected = $in ? (int)$in['rejected'] : 0;
            $busy = $in ? (int)$in['busy'] : 0;
            $no_answer = $in ? (int)$in['no_answer'] : 0;
            $talk_time = $in ? (int)$in['talk_time'] : 0;
            $ring_time = $in ? (int)$in['ring_time'] : 0;
            $outbound = $out ? (int)$out['outbound_calls'] : 0;

            $answer_rate = $offered > 0 ? round(($answered / $offered) * 100) : 0;
            $avg_talk = $answered > 0 ? round($talk_time / $answered) : 0;

            $online_time = $talk_time + $ring_time;
            if ($online_time > $elapsed_today) $online_time = $elapsed_today;
            $offline_time = 0;

            $result[] = array(
                'ext' => $ext,
                'name' => $u['name'],
                'offered' => $offered,
                'answered' => $answered,
                'rejected' => $rejected,
                'busy' => $busy,
                'no_answer' => $no_answer,
                'outbound' => $outbound,
                'answer_rate' => $answer_rate,
                'talk_time' => $talk_time,
                'avg_talk' => $avg_talk,
                'ring_time' => $ring_time,
                'online_time' => $online_time,
                'offline_time' => $offline_time
            );
        }

        echo json_encode(array('data' => $result), JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        echo json_encode(array('error' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ==========================================
// API اصلی
// ==========================================
if (isset($_GET['api']) && $_GET['api'] === 'get_status') {
    header('Content-Type: application/json; charset=utf-8');

    // ✅ کش پاسخ کامل بر اساس تمام پارامترهای فیلتر
    $cache_key = 'status_' . md5(serialize(array(
        'page'   => isset($_GET['page']) ? intval($_GET['page']) : 1,
        'search' => isset($_GET['search']) ? $_GET['search'] : '',
        'start'  => isset($_GET['start_date']) ? $_GET['start_date'] : '',
        'end'    => isset($_GET['end_date']) ? $_GET['end_date'] : '',
        'type'   => isset($_GET['filter_type']) ? $_GET['filter_type'] : '',
        'status' => isset($_GET['filter_status']) ? $_GET['filter_status'] : '',
        'audio'  => isset($_GET['only_audio']) ? $_GET['only_audio'] : '0',
        'stats'  => isset($_GET['stats_period']) ? $_GET['stats_period'] : 'today'
    )));
    $cached = cache_get($cache_key, 3);
    if ($cached !== null) {
        echo json_encode($cached, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 15;
    $offset = ($page - 1) * $limit;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    $response = array(
        'error' => null,
        'cdr_error' => null,
        'extensions' => array(),
        'queues' => array(),
        'trunks' => array(),
        'cdrs' => array(),
        'stats' => array('total' => 0, 'answered' => 0, 'no_answer' => 0, 'avg_duration' => 0),
        'hourly_chart' => array('labels' => array(), 'values' => array()),
        'monthly_chart' => array('labels' => array(), 'values' => array()),
        'pagination' => array('current_page' => $page, 'total_pages' => 1, 'total_records' => 0)
    );

    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));
    } catch (Exception $e) {
        $response['error'] = 'خطای اتصال به دیتابیس اصلی: ' . $e->getMessage();
    }

    $active_calls = array();
    $active_channels_output = asterisk_exec('core show channels concise');
	    // ✅ گرفتن تعداد کل تماس‌های همزمان
    $total_active_calls = 0;
    $call_out = asterisk_exec('core show calls');
    if ($call_out && preg_match('/(\d+)\s+active\s+calls?/i', $call_out, $m)) {
        $total_active_calls = (int)$m[1];
    } else if ($active_channels_output) {
        // اگر دستور بالا جواب نداد، تعداد خطوط کانال‌های فعال را می‌شماریم
        $lines = explode("\n", trim($active_channels_output));
        $total_active_calls = count($lines) - 1; 
    }
    $response['active_calls'] = max(0, $total_active_calls);
    if ($active_channels_output) {
        $lines = explode("\n", trim($active_channels_output));
        
        // ✅ مرحله ۱: ساخت نقشه از تمام کانال‌ها برای پیدا کردن لینک مقصد
        $channel_map = array();
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $c = explode("!", $line);
            if (count($c) >= 12) {
                $channel_map[$c[0]] = $c;
            }
        }

        // ✅ مرحله ۲: پردازش تماس‌های فعال
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $cols = explode("!", $line);
            if (count($cols) >= 12) {
                $channel = $cols[0];
                $exten = $cols[2];
                $app_data = isset($cols[6]) ? $cols[6] : '';
                $caller_id = $cols[7];
                $state = $cols[4];
                $duration = $cols[11];

                if (preg_match('/^(SIP|PJSIP|IAX2|DAHDI)\/([^\-]+)/i', $channel, $matches)) {
                    $dev_id = $matches[2];
                    
                    $real_dst = $exten;
                    if (in_array(strtolower($real_dst), array('s', 'i', 'h', 't')) || empty($real_dst)) {
                        // اگر کانال متصل به این تماس در نقشه وجود داشت، از آن استفاده کن
                        if (isset($channel_map[$app_data])) {
                            $linked = $channel_map[$app_data];
                            $linked_exten = $linked[2];
                            $linked_app_data = isset($linked[6]) ? $linked[6] : '';
                            
                            if (!in_array(strtolower($linked_exten), array('s', 'i', 'h', 't')) && !empty($linked_exten)) {
                                $real_dst = $linked_exten;
                            } elseif (preg_match('/(?:SIP|PJSIP|DAHDI|IAX2)\/(?:[a-zA-Z0-9_-]+\/)?([0-9]{4,})/i', $linked_app_data, $m)) {
                                $real_dst = $m[1];
                            }
                        } else {
                            // در غیر این صورت با Regex از app_data یا channel استخراج کن (حداقل ۴ رقم)
                            if (preg_match('/(?:SIP|PJSIP|DAHDI|IAX2)\/(?:[a-zA-Z0-9_-]+\/)?([0-9]{4,})/i', $app_data, $m)) {
                                $real_dst = $m[1];
                            } elseif (preg_match('/(?:SIP|PJSIP|DAHDI|IAX2)\/(?:[a-zA-Z0-9_-]+\/)?([0-9]{4,})/i', $channel, $m)) {
                                $real_dst = $m[1];
                            }
                        }
                    }
                    
                    if ($real_dst == 's' || empty($real_dst)) {
                        $real_dst = 'ترانک';
                    }

                    if (!isset($active_calls[$dev_id])) $active_calls[$dev_id] = array();
                    $active_calls[$dev_id][] = array(
                        'channel' => $channel,
                        'caller_id' => $caller_id ? $caller_id : 'ناشناس',
                        'destination' => $real_dst,
                        'state' => ($state == 'Up') ? 'در حال مکالمه' : 'در حال زنگ خوردن',
                        'duration' => $duration . ' ثانیه'
                    );
                }
            }
        }
    }

    // ✅ ساخت لیست جزئیات تماس‌های فعال برای نمایش در نمودار
    $response['active_calls_details'] = array();
    if ($active_channels_output) {
        $lines = explode("\n", trim($active_channels_output));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $cols = explode("!", $line);
            if (count($cols) >= 12) {
                $channel_name = $cols[0];
                $exten = $cols[2];
                $app_data = isset($cols[6]) ? $cols[6] : '';
                $caller_id = isset($cols[7]) ? $cols[7] : '';

                // ۱. استخراج مبدا (اگر CallerID خالی بود، از نام کانال دربیار)
                $src = $caller_id;
                if (preg_match('/<(\d+)>/', $src, $m)) { 
                    $src = $m[1]; // اگر فرمت "Name" <Number> بود
                } elseif (empty($src) || !preg_match('/\d/', $src)) {
                    if (preg_match('/(?:SIP|PJSIP|IAX2|DAHDI|Local)\/([a-zA-Z0-9_-]+)/i', $channel_name, $m)) {
                        $src = $m[1]; // استخراج شماره از نام کانال
                    }
                }
                if (empty($src) || $src == 's') $src = 'ناشناس';

                // ۲. استخراج مقصد (اگر Exten برابر s بود، از AppData دربیار)
                $dst = $exten;
                if (in_array(strtolower($dst), array('s', 'i', 'h', 't')) || empty($dst)) {
                    if (preg_match('/(?:SIP|PJSIP|DAHDI|IAX2)\/(?:[a-zA-Z0-9_-]+\/)?(\d+)/i', $app_data, $m)) {
                        $dst = $m[1];
                    } elseif (preg_match('/(?:SIP|PJSIP|DAHDI|IAX2)\/(?:[a-zA-Z0-9_-]+\/)?(\d+)/i', $channel_name, $m)) {
                        $dst = $m[1];
                    }
                }
                if ($dst == 's' || empty($dst)) {
                    $dst = 'ترانک';
                }

                $response['active_calls_details'][] = array(
                    'src' => $src,
                    'dst' => $dst
                );
            }
        }
    }
	
     $sip_peers = asterisk_exec('sip show peers', 3);
    $pjsip_contacts = asterisk_exec('pjsip show contacts', 3);
    $pjsip_endpoints_list = asterisk_exec('pjsip show endpoints', 5);
    
    $online_extensions = array();
    $ext_ip_map = array();
    $ext_drivers = array();

    // ۱. شناسایی SIP ها (چه آنلاین و چه آفلاین)
    if ($sip_peers) {
        $lines = explode("\n", $sip_peers);
        foreach ($lines as $line) {
            if (preg_match('/^(\d+)\/(\d+)\s+([0-9\.]+)\s+.*?(OK|Unmonitored)/i', $line, $m)) {
                $ext_num = $m[1];
                $online_extensions[$ext_num] = true;
                $ext_ip_map[$ext_num] = $m[3];
                $ext_drivers[$ext_num] = 'SIP';
            } elseif (preg_match('/^(\d+)\//i', $line, $m)) {
                $ext_num = $m[1];
                $ext_drivers[$ext_num] = 'SIP';
            }
        }
    }

    // ۲. شناسایی PJSIP ها (این دستور حتی آفلاین‌ها را هم لیست می‌کند)
    if ($pjsip_endpoints_list) {
        preg_match_all('/Endpoint:\s+([^\/\s]+)/i', $pjsip_endpoints_list, $ep_matches);
        foreach ($ep_matches[1] as $ep_id) {
            if (preg_match('/^[0-9]{3,5}$/', $ep_id)) {
                $ext_drivers[$ep_id] = 'PJSIP'; // اولویت با PJSIP
            }
        }
    }

    // ۳. وضعیت آنلاین بودن و IP (برای PJSIP ها)
    if ($pjsip_contacts) {
        preg_match_all('/Contact:\s+(\d+)\/sip:[^@]+@([0-9\.]+):\d+(?:;[^\s]+)?\s+(Avail|NonQual|Unknown|Unavailable)/i', $pjsip_contacts, $pjsip_matches, PREG_SET_ORDER);
        foreach ($pjsip_matches as $pm) {
            $ext_num = $pm[1];
            $online_extensions[$ext_num] = true;
            $ext_ip_map[$ext_num] = $pm[2];
            $ext_drivers[$ext_num] = 'PJSIP';
        }
    }

    $trunk_names_list = array();
    
    if (isset($pdo)) {
        try {
            // ۴. دریافت رمزهای SIP
            $sip_secrets = array();
            try {
                $sip_rows = $pdo->query("SELECT id, data FROM sip WHERE keyword = 'secret' AND data IS NOT NULL AND data != ''")->fetchAll();
                foreach ($sip_rows as $sr) { $sip_secrets[$sr['id']] = $sr['data']; }
            } catch (Exception $e) {}

            // ۵. دریافت رمزهای PJSIP (جدول ps_auths در فری‌پی‌بی‌ایکس جدید)
            $pjsip_secrets = array();
            try {
                $pjsip_rows = $pdo->query("SELECT id, password AS data FROM ps_auths WHERE auth_type = 'userpass'")->fetchAll();
                foreach ($pjsip_rows as $pr) { $pjsip_secrets[$pr['id']] = $pr['data']; }
            } catch (Exception $e) {
                try {
                    $pjsip_rows = $pdo->query("SELECT id, data FROM pjsip WHERE keyword = 'password'")->fetchAll();
                    foreach ($pjsip_rows as $pr) { $pjsip_secrets[$pr['id']] = $pr['data']; }
                } catch (Exception $e) {}
            }

            // ۶. واکشی لیست داخلی‌ها از دیتابیس
            $db_exts = $pdo->query("SELECT extension, name FROM users ORDER BY extension ASC")->fetchAll();
            foreach ($db_exts as $ext) {
                $id = $ext['extension'];
                $calls = isset($active_calls[$id]) ? $active_calls[$id] : array();
                $ip_address = isset($ext_ip_map[$id]) ? $ext_ip_map[$id] : 'غیرفعال / نامشخص';

                $secret = '';
                if (isset($pjsip_secrets[$id]) && !empty($pjsip_secrets[$id])) {
                    $secret = $pjsip_secrets[$id];
                } elseif (isset($sip_secrets[$id]) && !empty($sip_secrets[$id])) {
                    $secret = $sip_secrets[$id];
                }

                // ۷. تعیین نهایی درایور (اولویت با خروجی آستریسک است)
                $driver = 'نامشخص';
                if (isset($ext_drivers[$id])) {
                    $driver = $ext_drivers[$id];
                } elseif (isset($pjsip_secrets[$id])) {
                    $driver = 'PJSIP';
                } elseif (isset($sip_secrets[$id])) {
                    $driver = 'SIP';
                } else {
                    $driver = 'SIP'; // پیش‌فرض
                }

                $response['extensions'][] = array(
                    'id' => $id,
                    'name' => $ext['name'],
                    'ip' => $ip_address,
                    'secret' => $secret,
                    'driver' => $driver,
                    'is_online' => isset($online_extensions[$id]),
                    'is_busy' => !empty($calls),
                    'calls' => $calls
                );
            }
        } catch (Exception $e) {}
    
    

            try {
            $queue_details = array();
            $queue_names = array();
            $db_queues = $pdo->query("SELECT extension, descr FROM queues_config ORDER BY extension ASC")->fetchAll();
            foreach ($db_queues as $q) { $queue_names[$q['extension']] = $q['descr']; }

            $queue_show = asterisk_exec('queue show', 3);
            if ($queue_show) {
                $lines = explode("\n", $queue_show);
                $current_q = null;
                foreach ($lines as $line) {
                    $line = trim($line);
                    
                    // ۱. شناسایی هدر صف
                    if (preg_match('/^(\d+)\s+has\s+(\d+)\s+calls/i', $line, $q_matches)) {
                        $q_id = $q_matches[1];
                        $waiting = (int)$q_matches[2];
                        $completed = 0; $abandoned = 0;
                        
                        if (preg_match('/C:(\d+),\s*A:(\d+)/i', $line, $stat_m)) {
                            $completed = (int)$stat_m[1];
                            $abandoned = (int)$stat_m[2];
                        }
                        
                        $current_q = $q_id;
                        $queue_details[$current_q] = array(
                            'id' => $q_id,
                            'name' => isset($queue_names[$q_id]) ? $queue_names[$q_id] : 'صف ' . $q_id,
                            'waiting' => $waiting,
                            'agents_count' => 0,
                            'agents_ready' => 0,
                            'agents_busy' => 0,
                            'agents_paused' => 0,
                            'completed' => $completed,
                            'abandoned' => $abandoned,
                            'agents_list' => array() 
                        );
                    } 
                    // ۲. شناسایی خطوط اپراتورها
                    elseif ($current_q && preg_match('/(?:Agent|SIP|PJSIP|Local|IAX2|DAHDI)\//i', $line)) {
                        
                        // استخراج دقیق شماره داخلی
                        $agent_name = '';
                        if (preg_match('/(?:Agent|SIP|PJSIP|Local|IAX2|DAHDI)\/([a-zA-Z0-9_-]+)/i', $line, $m)) {
                            $agent_name = $m[1];
                        } else {
                            continue; 
                        }

                        // ✅ تشخیص وضعیت با جستجوی ساده (بسیار مقاوم‌تر)
                        $status_str = 'نامشخص';
                        $lower_line = strtolower($line);
                        
                        if (strpos($lower_line, 'not in use') !== false) {
                            $status_str = 'آزاد';
                        } elseif (strpos($lower_line, 'in use') !== false || strpos($lower_line, 'busy') !== false || strpos($lower_line, 'ringing') !== false) {
                            $status_str = 'مشغول';
                        } elseif (strpos($lower_line, 'unavailable') !== false || strpos($lower_line, 'invalid') !== false) {
                            $status_str = 'آفلاین';
                        } elseif (strpos($lower_line, 'paused') !== false) {
                            $status_str = 'مکث';
                        } elseif (strpos($lower_line, 'rejected') !== false) {
                            $status_str = 'رد شده';
                        }

                        // استخراج تعداد تماس‌های پاسخ داده شده
                        $calls_taken = 0;
                        if (preg_match('/has taken (\d+) calls/i', $line, $m)) {
                            $calls_taken = (int)$m[1];
                        }

                        // جلوگیری از ثبت تکراری
                        if (!isset($queue_details[$current_q]['agents_list'][$agent_name])) {
                            $queue_details[$current_q]['agents_count']++;
                            if ($status_str === 'آزاد') $queue_details[$current_q]['agents_ready']++;
                            elseif ($status_str === 'مشغول') $queue_details[$current_q]['agents_busy']++;
                            elseif ($status_str === 'مکث') $queue_details[$current_q]['agents_paused']++;

                            $queue_details[$current_q]['agents_list'][$agent_name] = array(
                                'agent' => $agent_name,
                                'status' => $status_str,
                                'calls' => $calls_taken
                            );
                        }
                    }
                }
            }
            
            // تبدیل آرایه انجمنی به لیست معمولی برای خروجی JSON
            foreach ($queue_details as &$q_data) {
                $q_data['agents_list'] = array_values($q_data['agents_list']);
            }
            unset($q_data);

            foreach ($queue_details as $q_data) { $response['queues'][] = $q_data; }
        } catch (Exception $e) {}

        try {
            // ✅ دریافت وضعیت ثبت‌نام ترانک‌های PJSIP فقط یک بار در خارج از حلقه (جهت جلوگیری از کندی و تایم‌اوت)
            $pjsip_registrations = asterisk_exec('pjsip show registrations', 5);

            $db_trunks = $pdo->query("SELECT trunkid, name, tech FROM trunks WHERE disabled != 'on' OR disabled IS NULL")->fetchAll();
            foreach ($db_trunks as $trunk) {
                $t_name = $trunk['name'];
                $t_tech = strtoupper($trunk['tech']);
                $trunk_names_list[] = strtolower($t_name);
                
                $is_online = true; // پیش‌فرض: آنلاین
                
                if ($t_tech === 'SIP') {
                    $is_online = ($sip_peers && strpos($sip_peers, $t_name) !== false && strpos($sip_peers, "OK") !== false);
                } elseif ($t_tech === 'PJSIP') {
                    // ✅ بررسی وضعیت PJSIP با استفاده از دیتای کش‌شده
                    if ($pjsip_registrations && stripos($pjsip_registrations, $t_name) !== false) {
                        // اگر نام ترانک در لیست بود، بررسی میکنیم که رجیستر شده باشد
                        $lines = explode("\n", $pjsip_registrations);
                        $is_online = false; // فرض میکنیم آفلاین است
                        foreach ($lines as $line) {
                            if (stripos($line, $t_name) !== false && stripos($line, 'Registered') !== false) {
                                $is_online = true;
                                break;
                            }
                        }
                    } else {
                        // اگر در لیست ثبت‌نام‌ها نبود (مثلاً ترانک‌های IP-based)، آنلاین در نظر گرفته می‌شود
                        $is_online = true; 
                    }
                }
                
                $calls = isset($active_calls[$t_name]) ? $active_calls[$t_name] : array();
                $response['trunks'][] = array(
                    'id' => $trunk['trunkid'],
                    'name' => $t_name,
                    'tech' => $t_tech,
                    'is_online' => $is_online,
                    'is_busy' => !empty($calls),
                    'calls' => $calls
                );
            }
        } catch (Exception $e) {}
    }

    try {
        $pdo_cdr = new PDO("mysql:host=$db_host;dbname=$db_cdr_name;charset=utf8", $db_user, $db_pass, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ));

        $where_clauses = array();
        $params = array();

        // ✅ فیلتر جستجوی متنی
        if (!empty($search)) {
            $where_clauses[] = "(calldate LIKE :s OR src LIKE :s OR dst LIKE :s OR dcontext LIKE :s OR channel LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }

        // ✅ فیلتر تاریخ شروع (شمسی به میلادی)
        if (!empty($_GET['start_date'])) {
            $j_s = explode('/', $_GET['start_date']);
            if (count($j_s) == 3) {
                $g_s = jalali_to_gregorian($j_s[0], $j_s[1], $j_s[2]);
                $start_dt = sprintf('%04d-%02d-%02d 00:00:00', $g_s[0], $g_s[1], $g_s[2]);
                $where_clauses[] = "calldate >= :start_dt";
                $params[':start_dt'] = $start_dt;
            }
        }

        // ✅ فیلتر تاریخ پایان (شمسی به میلادی)
        if (!empty($_GET['end_date'])) {
            $j_e = explode('/', $_GET['end_date']);
            if (count($j_e) == 3) {
                $g_e = jalali_to_gregorian($j_e[0], $j_e[1], $j_e[2]);
                $end_dt = sprintf('%04d-%02d-%02d 23:59:59', $g_e[0], $g_e[1], $g_e[2]);
                $where_clauses[] = "calldate <= :end_dt";
                $params[':end_dt'] = $end_dt;
            }
        }
		
		        // ✅ فیلتر وضعیت تماس (Status)
        if (!empty($_GET['filter_status'])) {
            if ($_GET['filter_status'] === 'answered') {
                $where_clauses[] = "disposition = 'ANSWERED'";
            } elseif ($_GET['filter_status'] === 'unanswered') {
                $where_clauses[] = "disposition != 'ANSWERED'";
            }
        }

        // ✅ فیلتر نوع تماس (Call Type)
        if (!empty($_GET['filter_type'])) {
            $type = $_GET['filter_type'];
            if ($type === 'internal') {
                $where_clauses[] = "(src REGEXP '^[0-9]{3,5}$' AND dst REGEXP '^[0-9]{3,5}$') AND dcontext NOT LIKE '%queue%'";
            } elseif ($type === 'inbound') {
                $where_clauses[] = "((dcontext IN ('from-trunk', 'from-pstn', 'from-digital', 'ext-did', 'ext-queues', 'from-queue') OR (LENGTH(src) > 4 AND dcontext NOT REGEXP '^(from-internal|outbound-allroutes)')) AND NOT (src REGEXP '^[0-9]{3,5}$' AND dst REGEXP '^[0-9]{3,5}$'))";
            } elseif ($type === 'outbound') {
                $where_clauses[] = "((dcontext IN ('outbound-allroutes', 'from-outbound-rules', 'macro-dialout-trunk') OR LENGTH(dst) > 4 OR dstchannel REGEXP '^(DAHDI|SIP|PJSIP|IAX2)\/') AND NOT (src REGEXP '^[0-9]{3,5}$' AND dst REGEXP '^[0-9]{3,5}$') AND NOT (dcontext IN ('from-trunk', 'from-pstn', 'from-digital', 'ext-did') OR (LENGTH(src) > 4 AND dcontext NOT REGEXP '^(from-internal|outbound-allroutes)')))";
            } elseif ($type === 'queue') { // ✅ اضافه شدن فیلتر صف
                $where_clauses[] = "(dcontext LIKE '%queue%' OR lastapp = 'Queue')";
            }
        }
		

        // ✅ فیلتر فقط تماس‌های دارای ضبط صدا (بدون linkedid)
        if (isset($_GET['only_audio']) && $_GET['only_audio'] == '1') {
            $where_clauses[] = "(recordingfile != '' AND recordingfile IS NOT NULL)";
        }

        $where_sql = count($where_clauses) > 0 ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

        $stats_query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN disposition != 'ANSWERED' THEN 1 ELSE 0 END) as no_answer,
            IFNULL(AVG(billsec), 0) as avg_duration
            FROM cdr" . $where_sql;
        $stmt_stats = $pdo_cdr->prepare($stats_query);
        $stmt_stats->execute($params);
        $st_res = $stmt_stats->fetch();
		
        // ✅ جدید: محاسبه آمار اختصاصی بر اساس بازه زمانی انتخاب‌شده
        $stats_period = isset($_GET['stats_period']) ? $_GET['stats_period'] : 'today';
        $time_condition = "DATE(calldate) = CURDATE()"; // پیش‌فرض: امروز

        if ($stats_period === 'week') {
            $time_condition = "calldate >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif ($stats_period === 'month') {
            $time_condition = "calldate >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        } elseif ($stats_period === 'year') {
            $time_condition = "calldate >= DATE_SUB(NOW(), INTERVAL 365 DAY)";
        } elseif ($stats_period === 'all') {
            $time_condition = "1=1";
        }
        $today_sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered,
            SUM(CASE WHEN disposition = 'NO ANSWER' THEN 1 ELSE 0 END) as missed,
            SUM(CASE WHEN disposition = 'BUSY' THEN 1 ELSE 0 END) as busy,
            SUM(CASE WHEN disposition = 'FAILED' THEN 1 ELSE 0 END) as failed,
            SUM(billsec) as talk_time,
            SUM(CASE WHEN src REGEXP '^[0-9]{3,5}$' AND dst REGEXP '^[0-9]{3,5}$' THEN 1 ELSE 0 END) as internal_calls,
            SUM(CASE WHEN src REGEXP '^[0-9]{3,5}$' AND dst NOT REGEXP '^[0-9]{3,5}$' THEN 1 ELSE 0 END) as outbound_calls,
            SUM(CASE WHEN src NOT REGEXP '^[0-9]{3,5}$' AND dst REGEXP '^[0-9]{3,5}$' THEN 1 ELSE 0 END) as inbound_calls
            FROM cdr WHERE $time_condition";
        $stmt_today = $pdo_cdr->query($today_sql);
        $today_data = $stmt_today->fetch();
        
        $response['today_stats'] = array(
            'total' => (int)$today_data['total'],
            'answered' => (int)$today_data['answered'],
            'missed' => (int)$today_data['missed'],
            'busy' => (int)$today_data['busy'],
            'failed' => (int)$today_data['failed'],
            'talk_time' => (int)$today_data['talk_time'],
            'internal' => (int)$today_data['internal_calls'],
            'outbound' => (int)$today_data['outbound_calls'],
            'inbound' => (int)$today_data['inbound_calls']
        );
		

        $response['stats'] = array(
            'total' => (int)$st_res['total'],
            'answered' => (int)$st_res['answered'],
            'no_answer' => (int)$st_res['no_answer'],
            'avg_duration' => round((float)$st_res['avg_duration'])
        );

        $chart_hourly = "SELECT HOUR(calldate) as hr, COUNT(*) as cnt FROM cdr
                 WHERE calldate >= CURDATE() AND calldate < CURDATE() + INTERVAL 1 DAY
                 GROUP BY HOUR(calldate) ORDER BY hr ASC";
        $stmt_h = $pdo_cdr->query($chart_hourly);
        $h_rows = $stmt_h->fetchAll();
        $hours_map = array_fill(0, 24, 0);
        foreach ($h_rows as $hr) { $hours_map[$hr['hr']] = (int)$hr['cnt']; }
        $response['hourly_chart']['labels'] = array_map(function($h){ return sprintf("%02d:00", $h); }, array_keys($hours_map));
        $response['hourly_chart']['values'] = array_values($hours_map);

        $chart_monthly = "SELECT DATE(calldate) as d, COUNT(*) as cnt FROM cdr WHERE calldate >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(calldate) ORDER BY d ASC";
        $stmt_m = $pdo_cdr->query($chart_monthly);
        $m_rows = $stmt_m->fetchAll();
        $m_labels = array(); $m_values = array();
        foreach ($m_rows as $mr) {
            $m_labels[] = convertToJalali($mr['d'] . ' 00:00:00');
            $m_values[] = (int)$mr['cnt'];
        }
        $response['monthly_chart']['labels'] = $m_labels;
        $response['monthly_chart']['values'] = $m_values;

        $total_records = $response['stats']['total'];
        $total_pages = ceil($total_records / $limit);
        if ($total_pages < 1) $total_pages = 1;

        $response['pagination'] = array(
            'current_page' => $page,
            'total_pages' => (int)$total_pages,
            'total_records' => (int)$total_records
        );

        $cdr_query = "SELECT calldate, src, dst, dcontext, disposition, duration, billsec, channel, dstchannel, recordingfile, lastapp, lastdata,
                             '' as agent_recording 
                      FROM cdr" . $where_sql . " 
                      ORDER BY calldate DESC 
                      LIMIT $limit OFFSET $offset";
        
        $stmt_cdr = $pdo_cdr->prepare($cdr_query);
        $stmt_cdr->execute($params);
        $db_cdrs = $stmt_cdr->fetchAll();

        foreach ($db_cdrs as $cdr) {
            $src = trim($cdr['src']);
            $dst = trim($cdr['dst']);
            $dcontext = $cdr['dcontext'];
            $channel = $cdr['channel'];
            $dstchannel = $cdr['dstchannel'];
            $lastapp = isset($cdr['lastapp']) ? $cdr['lastapp'] : '';
            $lastdata = isset($cdr['lastdata']) ? $cdr['lastdata'] : '';

            $src_is_ext = preg_match('/^\d{3,5}$/', $src);
            $dst_is_ext = preg_match('/^\d{3,5}$/', $dst);
            $is_queue = (stripos($dcontext, 'queue') !== false || stripos($lastapp, 'Queue') !== false);

            // ✅ منبع واحد تشخیص نوع (تابع classifyCall)
            $call_type = classifyCall($src, $dst, $dcontext, $dstchannel, $lastapp);

            $detected_trunk = '-';
            if ($call_type === 'inbound' || $call_type === 'queue') {
                if (preg_match('/^(SIP|PJSIP|DAHDI|IAX2)\/([^\/-]+)/i', $channel, $t_matches)) {
                    $detected_trunk = $t_matches[2];
                } else {
                    $detected_trunk = $dcontext;
                }
            } elseif ($call_type === 'outbound') {
                if (preg_match('/^(SIP|PJSIP|DAHDI|IAX2)\/([^\/-]+)/i', $dstchannel, $t_matches)) {
                    $detected_trunk = $t_matches[2];
                } else {
                    $detected_trunk = 'Trunk';
                }
            }

            $destination = $dst;
            // جلوگیری از تغییر مقصد در تماس‌های صف
            if ($call_type === 'internal' && !$is_queue && !empty($dstchannel)) {
                preg_match('/\/(\d{3,5})[-@]/', $dstchannel, $matches);
                if (isset($matches[1])) {
                    $destination = $matches[1];
                }
            }

            $caller_ext = '-';
            if ($call_type === 'outbound') {
                if (preg_match('/^(SIP|PJSIP|DAHDI|IAX2|Local)\/(\d{3,6})/i', $channel, $cm)) {
                    $caller_ext = $cm[2];
                }
            } elseif ($call_type === 'internal' && $src_is_ext) {
                $caller_ext = $src;
            } elseif (($call_type === 'inbound' || $call_type === 'queue') && $dst_is_ext) {
                $caller_ext = $dst;
            }

            // ✅ تعیین برچسب نوع تماس
            if ($call_type === 'queue') $type = 'صف (Queue)';
            elseif ($call_type === 'inbound') $type = 'ورودی شهری';
            elseif ($call_type === 'outbound') $type = 'خروجی شهری';
            else $type = 'داخلی';

            // ✅ پیدا کردن فایل صوتی (اگر رکورد خود صف خالی بود، از رکورد اپراتور می‌خوانیم)
            $rec_file = !empty($cdr['recordingfile']) ? $cdr['recordingfile'] : '';
            if (empty($rec_file) && !empty($cdr['agent_recording'])) {
                $rec_file = $cdr['agent_recording'];
            }

            $response['cdrs'][] = array(
                'calldate' => convertToJalali($cdr['calldate']),
                'src' => $src,
                'dst' => $destination,
                'caller_ext' => $caller_ext,
                'trunk_name' => $detected_trunk,
                'disposition' => ($cdr['disposition'] === 'ANSWERED') ? 'پاسخ داده شده' : 'ناموفق / بی‌پاسخ',
                'is_answered' => ($cdr['disposition'] === 'ANSWERED'),
                'duration' => $cdr['duration'],
                'billsec' => $cdr['billsec'],
                'type' => $type,
                'recordingfile' => $rec_file,
                'has_audio' => !empty($rec_file)
            );
        }
    } catch (Exception $e) { 
        $response['cdr_error'] = 'خطای دریافت CDR: ' . $e->getMessage(); 
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد مانیتورینگ ایزابل</title>
    <script src="3.4.17.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Vazirmatn', 'sans-serif'] } } }
        }
    </script>
    <link href="Vazirmatn-font-face.css" rel="stylesheet" />
  	 <link href="/all.min.css" rel="stylesheet" />
	<script src="/apexcharts.min.js"></script>
	<link href="/apexcharts.css" rel="stylesheet" />
    <script src="/xlsx.full.min.js"></script>
    <style>
        body, table, td, th, input, button { font-family: 'Vazirmatn', sans-serif !important; }
        .vazir-font { font-family: 'Vazirmatn', sans-serif !important; }
    </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900 text-slate-800 dark:text-slate-100 min-h-screen p-4 md:p-6 transition-colors duration-300 pb-28">

<!-- Header Styles -->
<style>
    .ambient-header {
        position: relative;
        z-index: 40;
        background: 
            radial-gradient(ellipse at top right, rgba(163, 168, 121, 0.15) 0%, transparent 55%),
            radial-gradient(ellipse at bottom left, rgba(107, 125, 63, 0.10) 0%, transparent 50%),
            linear-gradient(135deg, #fdfbf5 0%, #f7f2e7 50%, #f2ebda 100%);
        border: 1px solid rgba(107, 125, 63, 0.18);
        border-radius: 20px;
        box-shadow: 
            0 1px 2px rgba(107, 125, 63, 0.04),
            0 4px 16px rgba(107, 125, 63, 0.06),
            0 12px 40px -8px rgba(107, 125, 63, 0.08),
            inset 0 1px 0 rgba(255, 255, 255, 0.9);
    }
    .ambient-glow-wrapper {
        position: absolute;
        inset: 0;
        border-radius: 20px;
        overflow: hidden;
        pointer-events: none;
        z-index: 0;
    }
    .ambient-glow-wrapper::before {
        content: '';
        position: absolute;
        top: -50%; right: -10%;
        width: 320px; height: 320px;
        background: radial-gradient(circle, rgba(163, 168, 121, 0.18) 0%, transparent 65%);
        filter: blur(40px);
        animation: ambientFloat 12s ease-in-out infinite;
    }
    .ambient-glow-wrapper::after {
        content: '';
        position: absolute;
        bottom: -60%; left: 5%;
        width: 280px; height: 280px;
        background: radial-gradient(circle, rgba(107, 125, 63, 0.12) 0%, transparent 70%);
        filter: blur(50px);
        animation: ambientFloat 15s ease-in-out infinite reverse;
    }
    @keyframes ambientFloat {
        0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.7; }
        50% { transform: translate(-15px, 15px) scale(1.08); opacity: 1; }
    }
    .ambient-header-content { position: relative; z-index: 2; }
    .ambient-logo {
        background: linear-gradient(135deg, #8b9a5b 0%, #6b7d3f 50%, #556b2f 100%);
        box-shadow: 0 4px 12px rgba(107, 125, 63, 0.30), 0 8px 24px -4px rgba(107, 125, 63, 0.20), inset 0 1px 0 rgba(255, 255, 255, 0.25);
        border: 1px solid rgba(85, 107, 47, 0.35);
    }
    .ambient-chip {
        height: 44px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 0 14px;
        background: linear-gradient(145deg, #fdfbf5 0%, #f5efe0 100%);
        border: 1px solid rgba(107, 125, 63, 0.20);
        border-radius: 12px;
        color: #556b2f;
        box-shadow: 0 1px 2px rgba(107, 125, 63, 0.05), inset 0 1px 0 rgba(255, 255, 255, 0.85);
        transition: all 0.3s ease;
        white-space: nowrap;
    }
    .ambient-chip:hover {
        border-color: rgba(107, 125, 63, 0.35);
        box-shadow: 0 2px 8px rgba(107, 125, 63, 0.10), inset 0 1px 0 rgba(255, 255, 255, 0.85);
    }
    .ambient-chip-live {
        background: linear-gradient(145deg, #f4f6ec 0%, #e8ecd6 100%);
        border-color: rgba(139, 154, 91, 0.30);
        color: #556b2f;
    }
    .ambient-dot-live { position: relative; display: inline-flex; width: 10px; height: 10px; }
    .ambient-dot-live::before {
        content: ''; position: absolute; inset: 0; border-radius: 50%;
        background: #8b9a5b; animation: ambientPulse 2s ease-in-out infinite;
    }
    .ambient-dot-live::after {
        content: ''; position: absolute; inset: 0; border-radius: 50%;
        background: #6b7d3f; box-shadow: 0 0 8px rgba(107, 125, 63, 0.6);
    }
    @keyframes ambientPulse {
        0%, 100% { transform: scale(1); opacity: 0.8; }
        50% { transform: scale(1.9); opacity: 0; }
    }
    .ambient-hamburger {
        width: 44px; height: 44px;
        display: inline-flex; align-items: center; justify-content: center;
        background: linear-gradient(145deg, #fdfbf5 0%, #f0e9d6 100%);
        border: 1px solid rgba(107, 125, 63, 0.22);
        border-radius: 12px;
        color: #556b2f;
        box-shadow: 0 1px 2px rgba(107, 125, 63, 0.05), inset 0 1px 0 rgba(255, 255, 255, 0.9);
        transition: all 0.25s ease;
        cursor: pointer;
    }
    .ambient-hamburger:hover {
        background: linear-gradient(145deg, #f5efe0 0%, #e8ecd6 100%);
        border-color: rgba(107, 125, 63, 0.38);
        box-shadow: 0 4px 12px rgba(107, 125, 63, 0.14), inset 0 1px 0 rgba(255, 255, 255, 0.9);
        transform: translateY(-1px);
    }
    .ambient-hamburger:active {
        transform: translateY(0);
        box-shadow: 0 1px 2px rgba(107, 125, 63, 0.10), inset 0 2px 4px rgba(107, 125, 63, 0.06);
    }
    .ambient-menu {
        background: linear-gradient(160deg, #fdfbf5 0%, #f5efe0 100%);
        border: 1px solid rgba(107, 125, 63, 0.20);
        border-radius: 16px;
        box-shadow: 0 4px 16px rgba(107, 125, 63, 0.10), 0 16px 48px -8px rgba(107, 125, 63, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.9);
        overflow: hidden;
        z-index: 9999 !important;
    }
    .ambient-menu-header {
        background: linear-gradient(135deg, #8b9a5b 0%, #6b7d3f 55%, #556b2f 100%);
        padding: 14px 16px;
        color: #fdfbf5;
        position: relative;
        overflow: hidden;
    }
    .ambient-menu-header::before {
        content: ''; position: absolute; top: -50%; right: -20%;
        width: 160px; height: 160px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.18) 0%, transparent 65%);
        filter: blur(20px);
    }
    .ambient-menu-item {
        display: flex; align-items: center; gap: 12px;
        padding: 12px 16px; width: 100%; text-align: right;
        color: #4a5326; font-weight: 700; font-size: 13px;
        transition: all 0.25s ease;
        border-bottom: 1px solid rgba(107, 125, 63, 0.10);
        background: transparent; cursor: pointer; text-decoration: none;
    }
    .ambient-menu-item:last-child { border-bottom: none; }
    .ambient-menu-item:hover {
        background: linear-gradient(90deg, rgba(163, 168, 121, 0.14) 0%, rgba(163, 168, 121, 0.04) 100%);
    }
    .ambient-menu-item:hover .ambient-menu-icon {
        background: linear-gradient(135deg, #fdfbf5 0%, #e8ecd6 100%);
        border-color: rgba(107, 125, 63, 0.35);
    }
    .ambient-menu-icon {
        width: 34px; height: 34px;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #fdfbf5 0%, #f0e9d6 100%);
        border: 1px solid rgba(107, 125, 63, 0.18);
        border-radius: 10px;
        color: #6b7d3f;
        transition: all 0.25s ease;
        flex-shrink: 0;
    }
    .ambient-menu-item.danger { color: #a94442; }
    .ambient-menu-item.danger:hover {
        background: linear-gradient(90deg, rgba(169, 68, 66, 0.08) 0%, rgba(169, 68, 66, 0.02) 100%);
    }
    .ambient-menu-item.danger:hover .ambient-menu-icon {
        background: linear-gradient(135deg, #fdf5f0 0%, #f5e0da 100%);
        border-color: rgba(169, 68, 66, 0.35);
        color: #a94442;
    }
    .ambient-menu-item.danger .ambient-menu-icon { color: #a94442; }
    .ambient-title { color: #3d4a1e; letter-spacing: -0.3px; }
    .ambient-subtitle { color: #8b9163; }

    /* کارت شبکه */
    .net-card {
        background: linear-gradient(145deg, #fdfbf5 0%, #f7f2e7 100%);
        border: 1px solid rgba(107, 125, 63, 0.18);
        border-radius: 12px;
        padding: 12px;
        transition: all 0.25s ease;
    }
    .net-card:hover {
        border-color: rgba(107, 125, 63, 0.35);
        box-shadow: 0 3px 12px rgba(107, 125, 63, 0.10);
    }
    .net-card.down {
        background: linear-gradient(145deg, #fdf5f5 0%, #f7e7e7 100%);
        border-color: rgba(169, 68, 66, 0.20);
    }
    .net-card-status {
        width: 8px; height: 8px; border-radius: 50%;
        background: #6b7d3f;
        box-shadow: 0 0 8px rgba(107, 125, 63, 0.6);
        display: inline-block;
    }
    .net-card-status.down {
        background: #a94442;
        box-shadow: 0 0 8px rgba(169, 68, 66, 0.6);
    }
    .net-info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 0;
        font-size: 11px;
        border-bottom: 1px dashed rgba(107, 125, 63, 0.10);
    }
    .net-info-row:last-child { border-bottom: none; }
    .net-info-label { color: #8b9163; font-weight: 600; }
    .net-info-value {
        color: #4a5326;
        font-weight: 700;
        font-family: 'Courier New', monospace;
        direction: ltr;
        font-size: 11px;
    }
    .edit-net-btn {
        background: linear-gradient(135deg, #8b9a5b 0%, #6b7d3f 100%);
        color: #fdfbf5;
        border: none;
        padding: 5px 10px;
        border-radius: 7px;
        font-size: 10px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        box-shadow: 0 2px 6px rgba(107, 125, 63, 0.20);
    }
    .edit-net-btn:hover {
        background: linear-gradient(135deg, #6b7d3f 0%, #556b2f 100%);
        box-shadow: 0 4px 12px rgba(107, 125, 63, 0.30);
        transform: translateY(-1px);
    }

    /* ✅ استایل جدید برای بخش routes داخل هر کارت */
    .net-routes-block {
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed rgba(107, 125, 63, 0.20);
    }
    .net-routes-title {
        font-size: 10px;
        font-weight: 800;
        color: #6b7d3f;
        display: flex;
        align-items: center;
        gap: 5px;
        margin-bottom: 5px;
        letter-spacing: 0.2px;
    }
    .net-routes-list {
        display: flex;
        flex-direction: column;
        gap: 3px;
        max-height: 130px;
        overflow-y: auto;
    }
    .net-route-item {
        font-family: 'Courier New', monospace;
        font-size: 9.5px;
        direction: ltr;
        padding: 4px 7px;
        border-radius: 5px;
        color: #4a5326;
        background: rgba(107, 125, 63, 0.05);
        border: 1px solid rgba(107, 125, 63, 0.10);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .net-route-item.is-default {
        background: linear-gradient(90deg, rgba(139, 154, 91, 0.18) 0%, rgba(139, 154, 91, 0.06) 100%);
        border-color: rgba(107, 125, 63, 0.28);
        font-weight: 700;
    }

    /* منابع سیستم */
    .sys-mini-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
    }
    @media (min-width: 640px) {
        .sys-mini-grid { grid-template-columns: repeat(4, 1fr); }
    }
    .sys-mini {
        background: linear-gradient(145deg, #fdfbf5 0%, #f7f2e7 100%);
        border: 1px solid rgba(107, 125, 63, 0.15);
        border-radius: 10px;
        padding: 8px 10px;
        transition: all 0.2s ease;
    }
    .sys-mini:hover {
        border-color: rgba(107, 125, 63, 0.32);
        box-shadow: 0 2px 8px rgba(107, 125, 63, 0.08);
    }
    .sys-mini-head {
        display: flex;
        align-items: center;
        gap: 5px;
        margin-bottom: 6px;
    }
    .sys-mini-icon {
        width: 18px;
        height: 18px;
        border-radius: 5px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 9px;
        flex-shrink: 0;
    }
    .sys-mini-label {
        font-size: 9px;
        font-weight: 800;
        color: #6b7d3f;
        letter-spacing: 0.3px;
    }
    .sys-mini-percent {
        margin-right: auto;
        font-size: 12px;
        font-weight: 900;
        color: #3d4a1e;
    }
    .sys-mini-bar {
        height: 4px;
        background: rgba(107, 125, 63, 0.10);
        border-radius: 999px;
        overflow: hidden;
        margin-bottom: 5px;
    }
    .sys-mini-bar-fill {
        height: 100%;
        border-radius: 999px;
        transition: width 0.5s ease;
    }
    .sys-mini-meta {
        font-size: 9px;
        color: #8b9163;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        gap: 4px;
    }
    .sys-mini-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 7px 10px;
        background: linear-gradient(145deg, #f4f6ec 0%, #e8ecd6 100%);
        border: 1px solid rgba(107, 125, 63, 0.15);
        border-radius: 10px;
        margin-bottom: 10px;
        font-size: 10px;
        color: #556b2f;
        font-weight: 700;
    }

    /* Collapse */
    .collapse-btn {
        width: 30px;
        height: 30px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(145deg, #fdfbf5 0%, #f0e9d6 100%);
        border: 1px solid rgba(107, 125, 63, 0.22);
        border-radius: 9px;
        color: #556b2f;
        cursor: pointer;
        transition: all 0.25s ease;
        flex-shrink: 0;
    }
    .collapse-btn:hover {
        background: linear-gradient(145deg, #f5efe0 0%, #e8ecd6 100%);
        border-color: rgba(107, 125, 63, 0.38);
        color: #3d4a1e;
    }
    .collapse-btn i { transition: transform 0.3s ease; }
    .collapse-body {
        overflow: hidden;
        transition: max-height 0.4s ease, opacity 0.3s ease, margin 0.3s ease;
        max-height: 3000px;
        opacity: 1;
    }
    .collapse-body.collapsed {
        max-height: 0 !important;
        opacity: 0;
        margin-top: 0 !important;
        padding-top: 0 !important;
        padding-bottom: 0 !important;
    }
	
	.net-card {
    cursor: pointer;
}
.net-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(107, 125, 63, 0.15);
}
.net-card:active {
    transform: translateY(0);
}

</style>

<!-- Header -->
<header class="ambient-header p-4 md:px-6 md:py-5 mb-6">
    <div class="ambient-glow-wrapper"></div>
    <div class="ambient-header-content flex flex-col lg:flex-row justify-between items-center gap-4">
        <div class="flex items-center gap-3.5">
            <div class="ambient-logo w-12 h-12 rounded-2xl flex items-center justify-center text-white shrink-0">
                <i class="fa-solid fa-headset text-xl"></i>
            </div>
            <div class="leading-tight">
                <h1 class="ambient-title text-lg md:text-xl font-black"> داشبورد گزارش تماسها و مکالمات VoipTel Pro</h1>
                <p class="ambient-subtitle text-xs mt-1 font-medium">مدیریت تماس‌ها، صف‌ها و آمار ارتباطی</p>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap justify-center">
            <div class="ambient-chip">
                <i class="fa-regular fa-calendar text-sm" style="color:#6b7d3f;"></i>
                <span class="text-xs font-bold vazir-font"><?php echo getJalaliHeaderDate(); ?></span>
            </div>

            <div class="ambient-chip ambient-chip-live">
                <span class="ambient-dot-live"></span>
                <span class="text-xs font-black">زنده ( رفرش در هر 2 ثانیه )</span>
            </div>

            <div class="relative" style="z-index: 9998;">
                <button onclick="toggleHamburgerMenu(event)" class="ambient-hamburger" title="منوی کاربری">
                    <i class="fa-solid fa-bars text-base"></i>
                </button>

                <div id="hamburgerMenu" class="hidden absolute left-0 mt-3 w-64 ambient-menu">
                    <div class="ambient-menu-header">
                        <div class="relative z-10 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background: rgba(253, 251, 245, 0.18); backdrop-filter: blur(8px); border: 1px solid rgba(253, 251, 245, 0.25);">
                                <i class="fa-solid fa-user-shield text-lg"></i>
                            </div>
                            <div class="leading-tight">
                                <div class="text-[10px] opacity-80 font-medium">کاربر جاری</div>
                                <div class="font-black text-sm mt-0.5"><?php echo htmlspecialchars(isset($_SESSION['username']) ? $_SESSION['username'] : 'admin'); ?></div>
                            </div>
                        </div>
                    </div>

                    <button onclick="toggleDarkMode()" class="ambient-menu-item">
                        <span class="ambient-menu-icon">
                            <i id="themeIcon" class="fa-solid fa-moon text-sm"></i>
                        </span>
                        <span id="themeLabel">حالت شب</span>
                    </button>
<button onclick="openBlacklistModal()" class="ambient-menu-item">
    <span class="ambient-menu-icon">
        <i class="fa-solid fa-ban text-sm"></i>
    </span>
    <span>مدیریت لیست سیاه</span>
</button>
                    <a href="?logout=1" onclick="return confirm('آیا از خروج از سامانه اطمینان دارید؟')" class="ambient-menu-item danger">
                        <span class="ambient-menu-icon">
                            <i class="fa-solid fa-right-from-bracket text-sm"></i>
                        </span>
                        <span>خروج از سامانه</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

    <main class="space-y-8">
               <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- بخش اول: وضعیت داخلی‌ها (اکستنشن‌ها) -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <section class="bg-white dark:bg-slate-800 p-5 md:p-6 rounded-3xl border border-slate-100 dark:border-slate-700 shadow-sm space-y-5">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 dark:bg-indigo-900/30 text-indigo-500 flex items-center justify-center">
                        <i class="fa-solid fa-phone-volume text-lg"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 dark:text-white">وضعیت داخلی‌ها</h2>
                        <p class="text-[11px] text-slate-400 mt-0.5">ثبت دستگاه و وضعیت لحظه‌ای تماس داخلی‌های قابل مشاهده</p>
                    </div>
                </div>
                <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800 text-emerald-600 dark:text-emerald-400 text-[11px] font-bold">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    داده زنده فعال
                </div>
            </div>

            <div id="ext-status-cards" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                <!-- کارت‌ها با جاوااسکریپت پر می‌شوند -->
            </div>
        </section>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- بخش دوم: وضعیت تماس‌ها -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <section class="bg-white dark:bg-slate-800 p-5 md:p-6 rounded-3xl border border-slate-100 dark:border-slate-700 shadow-sm space-y-5">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 dark:bg-indigo-900/30 text-indigo-500 flex items-center justify-center">
                        <i class="fa-solid fa-phone text-lg"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 dark:text-white">وضعیت تماس‌ها</h2>
                        <p class="text-[11px] text-slate-400 mt-0.5">تماس‌های جاری و آمار ثبت‌شده امروز</p>
                    </div>
                </div>
                <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800 text-emerald-600 dark:text-emerald-400 text-[11px] font-bold">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    داده زنده و سوابق فعال
                </div>
            </div>

            <div id="call-status-cards" class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <!-- کارت‌ها با جاوااسکریپت پر می‌شوند -->
            </div>
        </section>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- بخش سوم: عملکرد امروز مجموعه -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <section class="bg-white dark:bg-slate-800 p-5 md:p-6 rounded-3xl border border-slate-100 dark:border-slate-700 shadow-sm space-y-5">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 dark:bg-indigo-900/30 text-indigo-500 flex items-center justify-center">
                        <i class="fa-solid fa-chart-simple text-lg"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 dark:text-white">عملکرد امروز مجموعه</h2>
                        <p class="text-[11px] text-slate-400 mt-0.5">خلاصه تماس‌های امروز همه اپراتورها</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <div class="flex items-center bg-slate-100 dark:bg-slate-700/50 p-1 rounded-xl">
<button onclick="setStatsPeriod('year', this)" class="stats-period-btn px-3 py-1.5 rounded-lg text-xs font-bold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-all">سالانه</button>
<button onclick="setStatsPeriod('month', this)" class="stats-period-btn px-3 py-1.5 rounded-lg text-xs font-bold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-all">ماهانه</button>
<button onclick="setStatsPeriod('week', this)" class="stats-period-btn px-3 py-1.5 rounded-lg text-xs font-bold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-all">هفتگی</button>
<button onclick="setStatsPeriod('today', this)" class="stats-period-btn px-3 py-1.5 rounded-lg text-xs font-bold bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm transition-all">روزانه</button>
                    </div>
                </div>
            </div>

            <div id="today-stats-cards" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <!-- کارت‌ها با جاوااسکریپت پر می‌شوند -->
            </div>
        </section>
        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- بخش چهارم: عملکرد اپراتورها -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <section class="bg-white dark:bg-slate-800 p-5 md:p-6 rounded-3xl border border-slate-100 dark:border-slate-700 shadow-sm space-y-5">
            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-3 pb-3 border-b border-slate-100 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 dark:bg-indigo-900/30 text-indigo-500 flex items-center justify-center">
                        <i class="fa-solid fa-users text-lg"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 dark:text-white">عملکرد اپراتورها</h2>
                        <p class="text-[11px] text-slate-400 mt-0.5"><span id="op-count">۰</span> اپراتور در این گزارش</p>
                    </div>
                </div>
                
                <div class="flex items-center gap-3 flex-wrap">
                    <!-- ✅ دکمه‌های فیلتر زمانی عملکرد اپراتورها -->
                    <div class="flex items-center bg-slate-100 dark:bg-slate-800/50 p-1 rounded-xl">
                        <button onclick="setOpPeriod('today', this)" class="op-period-btn px-3 py-1.5 rounded-lg text-xs font-bold bg-white dark:bg-slate-700 text-indigo-600 dark:text-indigo-400 shadow-sm transition-all">روزانه</button>
                        <button onclick="setOpPeriod('week', this)" class="op-period-btn px-3 py-1.5 rounded-lg text-xs font-bold text-slate-500 dark:text-slate-400 hover:text-slate-700 transition-all">هفتگی</button>
                        <button onclick="setOpPeriod('month', this)" class="op-period-btn px-3 py-1.5 rounded-lg text-xs font-bold text-slate-500 dark:text-slate-400 hover:text-slate-700 transition-all">ماهانه</button>
                        <button onclick="setOpPeriod('year', this)" class="op-period-btn px-3 py-1.5 rounded-lg text-xs font-bold text-slate-500 dark:text-slate-400 hover:text-slate-700 transition-all">سالانه</button>
                    </div>

                    <!-- تب‌ها -->
                    <div class="flex items-center bg-slate-50 dark:bg-slate-900/50 p-1 rounded-xl border border-slate-200 dark:border-slate-700 overflow-x-auto max-w-full">
                        <button onclick="switchOpTab('calls', this)" class="op-tab-btn px-4 py-1.5 rounded-lg text-xs font-bold bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm whitespace-nowrap transition-all">تماس‌ها (Calls)</button>
                        <button onclick="switchOpTab('time', this)" class="op-tab-btn px-4 py-1.5 rounded-lg text-xs font-bold text-slate-500 hover:text-slate-700 whitespace-nowrap transition-all">زمان و حضور (Time)</button>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-right text-sm">
                    <thead id="op-table-head" class="text-[11px] font-bold text-slate-500 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
                        <!-- هدر توسط جاوااسکریپت پر می‌شود -->
                    </thead>
                    <tbody id="op-table-body" class="divide-y divide-slate-100 dark:divide-slate-700/50">
                        <!-- ردیف‌ها توسط جاوااسکریپت پر می‌شود -->
                    </tbody>
                </table>
				
				        <div class="overflow-x-auto">
            <table class="w-full text-right text-sm">
                <thead id="op-table-head" class="text-[11px] font-bold text-slate-500 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700"></thead>
                <tbody id="op-table-body" class="divide-y divide-slate-100 dark:divide-slate-700/50"></tbody>
            </table>
        </div>
        
        <!-- ✅ اضافه شدن کادر صفحه‌بندی اپراتورها -->
        <div id="op-pagination-container" class="flex justify-between items-center mt-4 pt-3 border-t border-slate-100 dark:border-slate-700"></div>
		
            </div>
        </section>
		
        <!-- ✅ نمودار زنده تماس‌های همزمان -->
        <section class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
            <h2 class="text-base font-extrabold text-slate-800 dark:text-white mb-4 flex items-center gap-2">
                <i class="fa-solid fa-chart-line text-rose-500"></i>
                <span>ترافیک زنده تماس‌های همزمان</span>
                <div class="relative group mr-auto">
                    <span id="live-call-badge" class="text-[11px] font-bold bg-rose-100 dark:bg-rose-900/40 text-rose-600 dark:text-rose-300 px-2 py-1 rounded-md animate-pulse cursor-help">
                        در حال دریافت...
                    </span>
                    <!-- ✅ کادر Tooltip (تغییر موقعیت به سمت راست و افزایش z-index) -->
                    <div id="live-call-tooltip" class="hidden group-hover:block absolute z-[100] top-full mt-2 left-0 bg-slate-900 text-white text-xs rounded-xl p-3 shadow-2xl border border-slate-700 w-64">
                        <div class="text-center text-slate-400 text-[11px]">در حال دریافت لیست...</div>
                    </div>
                </div>
            </h2>
            <div id="liveConcurrentChart" class="w-full h-48"></div>
        </section>
		
        <!-- ✅ آمار عملکرد داخلی‌ها -->
        <section class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-slate-100 dark:border-slate-700">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-ranking-star text-amber-500 text-lg"></i>
                    <h2 class="text-base font-extrabold text-slate-800 dark:text-white">آمار عملکرد داخلی‌ها</h2>
                    <span id="ext-stats-period" class="text-[10px] font-bold bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 px-2 py-0.5 rounded-md">۳۰ روز اخیر</span>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <div class="flex bg-slate-100 dark:bg-slate-700 rounded-lg p-0.5">
                        <button onclick="setExtStatsPeriod(7, this)" class="ext-period-btn text-[10px] font-bold px-2.5 py-1 rounded-md text-slate-500 dark:text-slate-400 transition-all">۷ روز</button>
                        <button onclick="setExtStatsPeriod(30, this)" class="ext-period-btn text-[10px] font-bold px-2.5 py-1 rounded-md bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm transition-all">۳۰ روز</button>
                        <button onclick="setExtStatsPeriod(90, this)" class="ext-period-btn text-[10px] font-bold px-2.5 py-1 rounded-md text-slate-500 dark:text-slate-400 transition-all">۹۰ روز</button>
                    </div>
                    <div class="flex bg-slate-100 dark:bg-slate-700 rounded-lg p-0.5">
                        <button onclick="setExtStatsTab('calls', this)" class="ext-tab-btn text-[10px] font-bold px-2.5 py-1 rounded-md bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 shadow-sm transition-all">
                            <i class="fa-solid fa-phone text-[9px] mr-0.5"></i> تماس
                        </button>
                        <button onclick="setExtStatsTab('answered', this)" class="ext-tab-btn text-[10px] font-bold px-2.5 py-1 rounded-md text-slate-500 dark:text-slate-400 transition-all">
                            <i class="fa-solid fa-phone-volume text-[9px] mr-0.5"></i> پاسخ
                        </button>
                        <button onclick="setExtStatsTab('talk', this)" class="ext-tab-btn text-[10px] font-bold px-2.5 py-1 rounded-md text-slate-500 dark:text-slate-400 transition-all">
                            <i class="fa-solid fa-clock text-[9px] mr-0.5"></i> مکالمه
                        </button>
                    </div>
                </div>
            </div>
            <div id="ext-stats-container" class="p-3 md:p-4">
                <div class="text-center py-6 text-slate-400 text-xs">در حال دریافت اطلاعات...</div>
            </div>
        </section>
		
		
<!-- ✅ نمودار توزیع سالانه -->
<section class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 class="text-base font-extrabold text-slate-800 dark:text-white flex items-center gap-2">
            <i class="fa-solid fa-chart-area text-amber-500"></i>
            <span>توزیع سالانه تماس‌های شهری</span>
            <span id="yearly-year-label" class="text-[10px] font-bold bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 px-2 py-0.5 rounded-md">-</span>
        </h2>
        <div class="flex items-center gap-3 text-[10px] font-bold flex-wrap">
            <div class="flex items-center gap-1.5">
                <span style="width:10px;height:10px;border-radius:50%;background:#eab308;display:inline-block;box-shadow:0 0 6px rgba(234,179,8,0.5);"></span>
                <span class="text-slate-600 dark:text-slate-300 vazir-font">ورودی شهری</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span style="width:10px;height:10px;border-radius:50%;background:#94a3b8;display:inline-block;box-shadow:0 0 6px rgba(148,163,184,0.5);"></span>
                <span class="text-slate-600 dark:text-slate-300 vazir-font">خروجی شهری</span>
            </div>
            <!-- ✅ انتخاب سال -->
            <select id="yearlyYearSelect" onchange="fetchYearlyChart()"
                class="bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-200 font-bold rounded-xl px-2.5 py-1.5 text-[11px] focus:outline-none focus:ring-2 focus:ring-indigo-500 vazir-font cursor-pointer">
                <?php 
                    $j_today_php = gregorian_to_jalali(date('Y'), date('n'), date('j'));
                    $current_jy = (int)$j_today_php[0];
                    $persian_digits_map = array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹');
                    for ($y = $current_jy; $y >= $current_jy - 5; $y--):
                        $y_fa = str_replace(array('0','1','2','3','4','5','6','7','8','9'), $persian_digits_map, (string)$y);
                ?>
                    <option value="<?php echo $y; ?>" <?php echo ($y === $current_jy) ? 'selected' : ''; ?>>
                        سال <?php echo $y_fa; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
    </div>
    <div id="yearlyChart" class="w-full h-72"></div>
</section>

<!-- ✅ نمودار تحلیل ترانک‌ها -->
<?php
// ✅ تعریف متغیرهای لازم برای انتخابگر سال (اگر قبلاً تعریف نشده باشند)
if (!isset($current_jy)) {
    $j_today_php = gregorian_to_jalali(date('Y'), date('n'), date('j'));
    $current_jy = (int)$j_today_php[0];
}
if (!isset($persian_digits_map)) {
    $persian_digits_map = array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹');
}
?>
<section class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 class="text-base font-extrabold text-slate-800 dark:text-white flex items-center gap-2">
            <i class="fa-solid fa-network-wired text-emerald-500"></i>
            <span>تحلیل ترانک‌ها در طول سال</span>
            <span id="trunk-chart-info" class="text-[10px] font-bold bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 px-2 py-0.5 rounded-md">-</span>
        </h2>
        <div class="flex items-center gap-2 flex-wrap">
            <!-- ✅ حالت نمایش -->
            <select id="trunkChartModeSelect" onchange="renderTrunkYearlyChart(lastTrunkData)"
                class="bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-200 font-bold rounded-xl px-2.5 py-1.5 text-[11px] focus:outline-none focus:ring-2 focus:ring-indigo-500 vazir-font cursor-pointer">
                <option value="total">مجموع تماس‌ها</option>
                <option value="inbound">فقط ورودی</option>
                <option value="outbound">فقط خروجی</option>
            </select>
            <!-- ✅ انتخاب سال -->
            <select id="trunkChartYearSelect" onchange="fetchTrunkYearlyChart()"
                class="bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-200 font-bold rounded-xl px-2.5 py-1.5 text-[11px] focus:outline-none focus:ring-2 focus:ring-indigo-500 vazir-font cursor-pointer">
                <?php 
                    for ($y = $current_jy; $y >= $current_jy - 5; $y--):
                        $y_fa = str_replace(array('0','1','2','3','4','5','6','7','8','9'), $persian_digits_map, (string)$y);
                ?>
                    <option value="<?php echo $y; ?>" <?php echo ($y === $current_jy) ? 'selected' : ''; ?>>
                        سال <?php echo $y_fa; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
    </div>
    <div id="trunkYearlyChart" class="w-full h-80"></div>
    <div id="trunk-chart-footer" class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700 text-[10px] text-slate-400 text-center vazir-font">
        در حال دریافت اطلاعات...
    </div>
</section>

<!-- ✅ بخش جدید: پارک تماس -->
<section class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
    <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-700">
        <div class="flex items-center gap-2.5">
            <i class="fa-solid fa-square-parking text-blue-500 text-lg"></i>
            <h2 class="text-lg font-extrabold text-slate-800 dark:text-white">تماس‌های پارک شده (Parking)</h2>
        </div>
        <button onclick="fetchParkedCalls()" class="text-xs font-bold text-blue-500 hover:text-blue-700 bg-blue-50 dark:bg-blue-900/30 px-3 py-1.5 rounded-lg transition-colors">
            <i class="fa-solid fa-rotate"></i> بروزرسانی دستی
        </button>
    </div>
    
    <!-- نوار انتخاب داخلی برای برداشتن تماس -->
    <div class="mb-4 bg-slate-50 dark:bg-slate-700/50 p-3 rounded-xl border border-slate-200 dark:border-slate-600 flex items-center gap-3">
        <label class="text-xs font-bold text-slate-600 dark:text-slate-300 whitespace-nowrap">داخلی شما (برای برداشتن تماس):</label>
        <input type="text" id="parkPickupExt" placeholder="مثلا 1001" class="w-32 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded-lg px-3 py-1.5 text-sm font-black text-center focus:outline-none focus:ring-2 focus:ring-blue-500" dir="ltr">
    </div>

    <div id="parked-calls-container" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
        <div class="col-span-full text-center py-6 text-slate-400 text-xs">در حال دریافت اطلاعات...</div>
    </div>
</section>

        <!-- نمودارهای آماری -->
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
                <h2 class="text-base font-extrabold text-slate-800 dark:text-white mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-clock text-indigo-500"></i>
                    <span>توزیع ساعتی تماس‌های امروز</span>
                </h2>
                <div id="hourlyChart" class="w-full h-64"></div>
            </div>

            <div class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
                <h2 class="text-base font-extrabold text-slate-800 dark:text-white mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-chart-column text-emerald-500"></i>
                    <span>توزیع روزانه تماس‌ها (۳۰ روز اخیر)</span>
                </h2>
                <div id="monthlyChart" class="w-full h-64"></div>
            </div>
        </section>


<!-- ✅ بخش جدید ۱: Heatmap -->
<section class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-extrabold text-slate-800 dark:text-white flex items-center gap-2">
            <i class="fa-solid fa-fire text-orange-500"></i>
            <span>ساعات شلوغی (Heatmap)</span>
        </h2>
        <select id="heatmapDays" onchange="fetchHeatmap()" class="bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-xs rounded-xl px-2.5 py-1.5">
            <option value="7">۷ روز اخیر</option>
            <option value="30" selected>۳۰ روز اخیر</option>
            <option value="90">۹۰ روز اخیر</option>
        </select>
    </div>
    <div id="heatmapChart" class="w-full h-72"></div>
</section>

<!-- ✅ بخش جدید ۲: مقایسه دوره‌ها -->
<section class="bg-white dark:bg-slate-800 p-5 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
    <h2 class="text-base font-extrabold text-slate-800 dark:text-white mb-4 flex items-center gap-2">
        <i class="fa-solid fa-chart-line text-indigo-500"></i>
        <span>مقایسه با هفته گذشته</span>
    </h2>
    <div id="period-comparison-container" class="grid grid-cols-2 md:grid-cols-4 gap-3"></div>
</section>

<!-- ✅ بخش جدید ۳: گزارش تماس‌های از دست رفته -->
<section class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
    <h2 class="text-base font-extrabold text-slate-800 dark:text-white mb-4 flex items-center gap-2">
        <i class="fa-solid fa-phone-slash text-rose-500"></i>
        <span>گزارش تماس‌های از دست رفته (Missed)</span>
    </h2>
    <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
<thead class="text-[11px] font-bold text-slate-500 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700">
    <tr>
        <th class="px-4 py-3">تاریخ</th>
        <th class="px-4 py-3">شماره تماس‌گیرنده</th>
        <th class="px-4 py-3 text-center">داخلی مقصد</th>
        <th class="px-4 py-3">وضعیت پیگیری</th>
        <th class="px-4 py-3 text-center">عملیات</th>
    </tr>
</thead>
            <tbody id="missed-calls-body" class="divide-y divide-slate-100 dark:divide-slate-700/50"></tbody>
        </table>
    </div>
    <div id="missed-pagination" class="flex justify-between items-center mt-4"></div>
</section>

<!-- ✅ بخش جدید ۴: لیست بازگرداندن تماس -->
<section class="bg-white dark:bg-slate-800 p-6 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
<div class="flex items-center justify-between mb-4">
    <h2 class="text-base font-extrabold text-slate-800 dark:text-white flex items-center gap-2">
        <i class="fa-solid fa-list-check text-amber-500"></i>
        <span>لیست بازگرداندن تماس (شماره‌های پرتکرار)</span>
    </h2>
    <!-- ✅ نشانگر تعداد کل شماره‌ها در سمت چپ -->
    <span id="callback-total-count" class="bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 text-xs font-bold px-2.5 py-1 rounded-lg border border-amber-200 dark:border-amber-800 shadow-sm vazir-font">
        در حال دریافت...
    </span>
</div>
    <div id="callback-list-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3"></div>
    <!-- ✅ نوار صفحه‌بندی اضافه شد -->
    <div id="callback-pagination" class="flex justify-between items-center mt-4 pt-3 border-t border-slate-100 dark:border-slate-700"></div>
</section>

        <!-- کارت‌های شبکه + منابع سرور -->
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <div class="bg-white dark:bg-slate-800 p-5 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
                <div class="flex items-center justify-between mb-3 pb-3 border-b border-slate-100 dark:border-slate-700">
                    <div class="flex items-center gap-2.5">
                        <i class="fa-solid fa-network-wired text-indigo-600 dark:text-indigo-400 text-lg"></i>
                        <h2 class="text-base font-extrabold text-slate-800 dark:text-white">کارت‌های شبکه</h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <div id="net-hostname-display" class="text-xs font-bold text-slate-500 dark:text-slate-400 vazir-font" dir="ltr">-</div>
                        <button type="button" class="collapse-btn" onclick="toggleCollapse('netCardBody', this)" title="باز/بسته کردن">
                            <i class="fa-solid fa-minus text-xs"></i>
                        </button>
                    </div>
                </div>
                <div id="netCardBody" class="collapse-body">
                    <div id="network-cards-container" class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-[440px] overflow-y-auto pl-1">
                        <div class="col-span-full text-center py-6 text-slate-400 text-xs">در حال دریافت اطلاعات...</div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 p-5 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
                <div class="flex items-center justify-between mb-3 pb-3 border-b border-slate-100 dark:border-slate-700">
                    <div class="flex items-center gap-2.5">
                        <i class="fa-solid fa-microchip text-indigo-600 dark:text-indigo-400 text-lg"></i>
                        <h2 class="text-base font-extrabold text-slate-800 dark:text-white">منابع سرور</h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <div id="sys-os-display" class="text-[10px] font-bold text-slate-500 dark:text-slate-400 vazir-font">-</div>
                        <button type="button" class="collapse-btn" onclick="toggleCollapse('sysCardBody', this)" title="باز/بسته کردن">
                            <i class="fa-solid fa-minus text-xs"></i>
                        </button>
                    </div>
                </div>
                <div id="sysCardBody" class="collapse-body">
                    <div class="sys-mini-header" dir="ltr">
                        <span class="vazir-font" dir="rtl">
                            <i class="fa-solid fa-server text-[10px] mr-1"></i>
                            <span id="sys-mini-hostname">-</span>
                        </span>
                        <span class="vazir-font" dir="rtl">
                            <i class="fa-regular fa-clock text-[10px] mr-1"></i>
                            <span id="sys-mini-uptime">-</span>
                        </span>
                    </div>
                    <div id="system-stats-container" class="sys-mini-grid">
                        <div class="col-span-full text-center py-6 text-slate-400 text-xs">در حال دریافت اطلاعات...</div>
                    </div>
                </div>
            </div>

        </section>

        <!-- بخش داخلی‌ها -->
        <section class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4 pb-3 border-b border-slate-100 dark:border-slate-700">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-users text-indigo-600 dark:text-indigo-400 text-lg"></i>
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-white">وضعیت داخلی‌ها</h2>
                </div>
                <div class="flex items-center gap-3">
                    <!-- ✅ اضافه شدن باکس جستجو -->
                    <input type="text" id="extSearchInput" oninput="renderExtensions(currentData.extensions || [])" placeholder="جستجوی داخلی..." class="bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-1.5 text-xs focus:outline-none vazir-font w-32 md:w-48">
                    <div id="ext-stats" class="text-xs font-bold text-slate-500 dark:text-slate-400 vazir-font">در حال دریافت...</div>
                </div>
            </div>
            <!-- ✅ اضافه شدن max-h و overflow-y-auto برای اسکرول داخلی -->
            <div id="extensions-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-3 max-h-[350px] overflow-y-auto p-1"></div>
        </section>
        <!-- ✅ کادر جدید: تعداد تماس‌های پاسخ داده شده هر داخلی -->
        <section class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4 pb-3 border-b border-slate-100 dark:border-slate-700">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-phone-volume text-emerald-600 dark:text-emerald-400 text-lg"></i>
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-white">تعداد تماس‌های پاسخ داده شده هر داخلی</h2>
                </div>
                <div class="flex items-center gap-2">
                    <select id="extAnsweredYearSelect" onchange="fetchExtensionAnsweredCounts()" class="bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-200 font-bold rounded-xl px-2.5 py-1.5 text-[11px] focus:outline-none focus:ring-2 focus:ring-indigo-500 vazir-font cursor-pointer">
                        <option value="all">کل زمان‌ها</option>
                        <?php 
                            $j_today_php2 = gregorian_to_jalali(date('Y'), date('n'), date('j'));
                            $current_jy2 = (int)$j_today_php2[0];
                            for ($y = $current_jy2; $y >= $current_jy2 - 5; $y--):
                                $y_fa = str_replace(array('0','1','2','3','4','5','6','7','8','9'), $persian_digits_map, (string)$y);
                        ?>
                            <option value="<?php echo $y; ?>">سال <?php echo $y_fa; ?></option>
                        <?php endfor; ?>
                    </select>
                    <div id="ext-answered-stats" class="text-xs font-bold text-slate-500 dark:text-slate-400 vazir-font">در حال دریافت...</div>
                </div>
            </div>
            <div id="ext-answered-container" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-3"></div>
        </section>
		
        <!-- بخش صف‌ها -->
        <section class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-700">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-layer-group text-indigo-600 dark:text-indigo-400 text-lg"></i>
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-white">وضعیت صف‌های تماس (Queues)</h2>
                </div>
                <div id="queue-stats" class="text-xs font-bold text-slate-500 dark:text-slate-400 vazir-font">در حال دریافت...</div>
            </div>
            <div id="queues-container" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4"></div>
        </section>

        <!-- بخش ترانک‌ها -->
        <section class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-700">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-network-wired text-indigo-600 dark:text-indigo-400 text-lg"></i>
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-white">وضعیت ترانک‌ها</h2>
                </div>
                <div id="trunk-stats" class="text-xs font-bold text-slate-500 dark:text-slate-400 vazir-font">در حال دریافت...</div>
            </div>
            <div id="trunks-container" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4"></div>
        </section>

        <!-- گزارشات CDR -->
        <section class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-200 dark:border-slate-700">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 pb-4 border-b border-slate-100 dark:border-slate-700">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-list-check text-indigo-600 dark:text-indigo-400 text-lg"></i>
                    <h2 class="text-lg font-extrabold text-slate-800 dark:text-white">آخرین تماس‌ها (CDR)</h2>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <!-- باکس جستجوی متنی -->
                    <div class="relative w-48 md:w-64">
                        <input type="text" id="searchInput" oninput="handleSearch()" placeholder="جستجو (شماره، ترانک)..." class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2 pr-8 text-xs focus:outline-none vazir-font">
                        <i class="fa-solid fa-magnifying-glass absolute right-2.5 top-2.5 text-slate-400 text-xs"></i>
                    </div>
                    

<!-- ✅ فیلتر جدید ۱: نوع تماس (اصلاح شده) -->
<select id="cdrFilterType" onchange="fetchLiveData(1)" class="bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-2.5 py-2 text-xs focus:outline-none vazir-font text-slate-700 dark:text-slate-200">
    <option value="">همه تماس‌ها</option>
    <option value="inbound">ورودی شهری</option>
    <option value="outbound">خروجی شهری</option>
    <option value="internal">داخلی</option>
    <option value="queue">صف (Queue)</option>
</select>

                    <!-- ✅ فیلتر جدید ۲: وضعیت تماس -->
                    <select id="cdrFilterStatus" onchange="fetchLiveData(1)" class="bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-2.5 py-2 text-xs focus:outline-none vazir-font text-slate-700 dark:text-slate-200">
                        <option value="">همه وضعیت‌ها</option>
                        <option value="answered">پاسخ داده شده</option>
                        <option value="unanswered">بی‌پاسخ / ناموفق</option>
                    </select>

                    <!-- فیلتر تاریخ شمسی -->
                    <input type="text" id="cdrStartDate" placeholder="از تاریخ (1403/01/01)" onchange="fetchLiveData(1)" class="persian-datepicker w-32 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2 text-xs focus:outline-none vazir-font text-center" dir="ltr">
                    <span class="text-slate-400 text-xs">-</span>
                    <input type="text" id="cdrEndDate" placeholder="تا تاریخ" onchange="fetchLiveData(1)" class="persian-datepicker w-32 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2 text-xs focus:outline-none vazir-font text-center" dir="ltr">
                    
                    <!-- چک‌باکس فقط تماس‌های ضبط شده -->
                    <label class="flex items-center gap-1.5 cursor-pointer bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2">
                        <input type="checkbox" id="cdrOnlyAudio" onchange="fetchLiveData(1)" class="w-3.5 h-3.5 text-indigo-600 rounded focus:ring-indigo-500">
                        <span class="text-[10px] font-bold text-slate-600 dark:text-slate-300 vazir-font">فقط دارای ضبط</span>
                    </label>
                    
                    <!-- دکمه اکسل -->
                    <button onclick="exportToExcel()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold flex items-center gap-1.5 transition-colors shadow-sm">
                        <i class="fa-solid fa-file-excel"></i>
                        <span>اکسل</span>
                    </button>
                </div>
            </div>

            <div class="hidden md:block overflow-x-auto">
                <table class="w-full text-sm text-right text-slate-600 dark:text-slate-300">
                    <thead class="text-xs text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 font-bold border-b border-slate-200 dark:border-slate-700">
                        <tr>
                            <th scope="col" class="px-4 py-3">تاریخ و زمان (شمسی)</th>
                            <th scope="col" class="px-4 py-3">نوع تماس</th>
                            <th scope="col" class="px-4 py-3">خط / ترانک</th>
                            <th scope="col" class="px-4 py-3">داخلی تماس‌گیرنده</th>
                            <th scope="col" class="px-4 py-3">مبدا (Caller ID)</th>
                            <th scope="col" class="px-4 py-3">مقصد</th>
                            <th scope="col" class="px-4 py-3">مدت مکالمه</th>
                            <th scope="col" class="px-4 py-3 text-center">ضبط مکالمه</th>
                            <th scope="col" class="px-4 py-3 text-center">وضعیت</th>
                        </tr>
                    </thead>
                    <tbody id="cdr-container" class="divide-y divide-slate-100 dark:divide-slate-700"></tbody>
                </table>
            </div>

            <div id="cdr-mobile-container" class="md:hidden space-y-3"></div>

            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-6 pt-4 border-t border-slate-100 dark:border-slate-700">
                <div id="cdr-stats" class="text-xs font-bold text-slate-500 dark:text-slate-400 vazir-font">در حال دریافت...</div>
                <div id="pagination-container" class="flex items-center gap-1.5 dir-ltr"></div>
            </div>
        </section>
    </main>

    <!-- نوار پخش‌کننده صوت -->
    <div id="stickyAudioPlayer" class="fixed bottom-0 inset-x-0 bg-slate-900/90 backdrop-blur-md text-white p-3 border-t border-slate-700 z-50 transform translate-y-full transition-transform duration-300 flex items-center justify-between gap-4 shadow-2xl">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center animate-pulse">
                <i class="fa-solid fa-music text-lg"></i>
            </div>
            <div>
                <div class="text-xs font-bold text-slate-300">پخش زنده فایل صوت</div>
                <div id="playerFileName" class="text-[10px] text-slate-400 vazir-font">audio.wav</div>
            </div>
        </div>
        <audio id="audioPlayer" controls class="w-full max-w-md h-10"></audio>
        <button onclick="closeStickyPlayer()" class="text-slate-400 hover:text-white p-2">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>

    <!-- Modal لیست داخلی‌ها -->
    <div id="extListModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[70] flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-md w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="extListCard">
            <div class="bg-indigo-600 text-white p-4 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-users"></i>
                    <h3 class="font-bold text-sm" id="extListTitle">لیست داخلی‌ها</h3>
                </div>
                <button onclick="closeExtListModal()" class="text-indigo-200 hover:text-white transition-colors">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            
            <!-- ✅ ساختار اصلاح شده: لیست و صفحه‌بندی از هم جدا شدند -->
            <div class="flex flex-col max-h-[60vh]">
                <div class="p-5 overflow-y-auto" id="extListBody">
                    <!-- لیست داخلی‌ها اینجا قرار می‌گیرد -->
                </div>
                <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-700 flex justify-between items-center bg-slate-50 dark:bg-slate-800/50 shrink-0" id="extListPagination"></div>
            </div>
        </div>
    </div>
    <!-- Modal اطلاعات داخلی -->
    <div id="infoModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-sm w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="infoCard">
            <div class="bg-indigo-600 text-white p-4 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-circle-info"></i>
                    <h3 class="font-bold text-sm" id="infoTitle">اطلاعات دستگاه داخلی</h3>
                </div>
                <button onclick="closeInfoModal()" class="text-indigo-200 hover:text-white transition-colors">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="p-5 space-y-3 text-sm" id="infoBody"></div>
        </div>
    </div>

    <!-- Modal مکالمه زنده -->
    <div id="callModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-md w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="modalCard">
            <div class="bg-slate-900 text-white p-4 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-phone-volume text-emerald-400 animate-pulse"></i>
                    <h3 class="font-bold text-sm" id="modalTitle">جزئیات مکالمه زنده</h3>
                </div>
                <button onclick="closeModal()" class="text-slate-400 hover:text-white transition-colors">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="p-5" id="modalBody"></div>
        </div>
    </div>

    <!-- Modal تماس کلیک‌ای -->
    <div id="webDialerModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[70] flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-sm w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="webDialerCard">
            <div class="bg-emerald-600 text-white p-4 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-phone-volume"></i>
                    <h3 class="font-bold text-sm">تماس کلیک‌ای (Web Dialer)</h3>
                </div>
                <button onclick="closeWebDialerModal()" class="text-emerald-200 hover:text-white transition-colors">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="p-5 space-y-4">
                <div class="text-xs text-slate-500 dark:text-slate-400 leading-6 text-center">
                    شماره مقصد: <span id="dialerDestNumber" class="font-black text-slate-800 dark:text-white dir-ltr inline-block" style="direction:ltr;"></span>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-1 block">داخلی خود را وارد کنید:</label>
                    <input type="text" id="dialerExtInput" placeholder="مثلا 1001" 
                        class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2.5 text-center text-lg font-black vazir-font focus:outline-none focus:ring-2 focus:ring-emerald-500" dir="ltr">
                </div>
                <div id="dialerResult" class="text-xs font-bold hidden text-center p-2 rounded-lg"></div>
                <div class="flex gap-2">
                    <button onclick="performWebCall()" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white py-2.5 rounded-xl text-sm font-bold transition-colors shadow-sm">
                        <i class="fa-solid fa-phone mr-1"></i> برقراری تماس
                    </button>
                    <button onclick="closeWebDialerModal()" class="px-4 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 py-2.5 rounded-xl text-sm font-bold">
                        انصراف
                    </button>
                </div>
            </div>
        </div>
    </div>
	
    <!-- Modal شنود -->
    <div id="spyModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[60] flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-sm w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="spyCard">
            <div class="bg-rose-600 text-white p-4 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-headphones"></i>
                    <h3 class="font-bold text-sm">شنود زنده مکالمه</h3>
                </div>
                <button onclick="closeSpyDialog()" class="text-rose-200 hover:text-white transition-colors">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="p-5 space-y-4">
                <div class="text-xs text-slate-500 dark:text-slate-400 leading-6">
                    شماره داخلی خود را وارد کنید تا تماس روی گوشی شما زنگ بخورد.
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-1 block">داخلی مدیر (Spy):</label>
                    <input type="text" id="spyAdminExt" placeholder="مثلا 1001" 
                        class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2.5 text-sm vazir-font focus:outline-none focus:ring-2 focus:ring-rose-500">
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-2 block">حالت شنود:</label>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="spyMode" value="q" class="peer hidden" checked>
                            <div class="text-center p-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-600 peer-checked:border-rose-500 peer-checked:bg-rose-50 transition-all text-slate-500">
                                <i class="fa-solid fa-headphones text-sm block mb-1"></i>
                                <div class="text-[10px] font-bold">فقط شنود</div>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="spyMode" value="qW" class="peer hidden">
                            <div class="text-center p-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-600 peer-checked:border-rose-500 peer-checked:bg-rose-50 transition-all text-slate-500">
                                <i class="fa-solid fa-comment-dots text-sm block mb-1"></i>
                                <div class="text-[10px] font-bold">نجوا</div>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="spyMode" value="qB" class="peer hidden">
                            <div class="text-center p-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-600 peer-checked:border-rose-500 peer-checked:bg-rose-50 transition-all text-slate-500">
                                <i class="fa-solid fa-phone-volume text-sm block mb-1"></i>
                                <div class="text-[10px] font-bold">سه‌طرفه</div>
                            </div>
                        </label>
                    </div>
                </div>
                <div id="spyResult" class="text-xs font-bold hidden"></div>
                <div class="flex gap-2">
                    <button onclick="performSpy()" class="flex-1 bg-rose-600 hover:bg-rose-700 text-white py-2.5 rounded-xl text-sm font-bold transition-colors">
                        <i class="fa-solid fa-phone-volume mr-1"></i> شروع شنود
                    </button>
                    <button onclick="closeSpyDialog()" class="px-4 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 py-2.5 rounded-xl text-sm font-bold">
                        انصراف
                    </button>
                </div>
            </div>
        </div>
    </div>

<!-- Modal اطلاعات کارت شبکه -->
<div id="netInfoModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[65] flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-lg w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="netInfoCard">
        <div class="p-4 flex justify-between items-center" style="background: linear-gradient(135deg, #8b9a5b 0%, #6b7d3f 55%, #556b2f 100%); color: #fdfbf5;">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-network-wired"></i>
                <h3 class="font-bold text-sm" id="netInfoTitle">اطلاعات کارت شبکه</h3>
            </div>
            <button onclick="closeNetInfoModal()" class="opacity-80 hover:opacity-100 transition-opacity">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        <div class="p-5 max-h-[65vh] overflow-y-auto" id="netInfoBody"></div>
        <div class="px-5 pb-5 pt-4 flex gap-2 border-t border-slate-100 dark:border-slate-700">
            <button onclick="editFromInfoModal()" class="flex-1 text-white py-2.5 rounded-xl text-sm font-bold transition-all shadow-md flex items-center justify-center gap-2" style="background: linear-gradient(135deg, #8b9a5b 0%, #6b7d3f 100%);">
                <i class="fa-solid fa-pen-to-square"></i>
                <span>ویرایش تنظیمات</span>
            </button>
            <button onclick="closeNetInfoModal()" class="px-4 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 py-2.5 rounded-xl text-sm font-bold">
                بستن
            </button>
        </div>
    </div>
</div>

    <!-- Modal ویرایش کارت شبکه -->
    <div id="netEditModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[70] flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-lg w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="netEditCard">
            <div class="p-4 flex justify-between items-center" style="background: linear-gradient(135deg, #8b9a5b 0%, #6b7d3f 55%, #556b2f 100%); color: #fdfbf5;">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <h3 class="font-bold text-sm" id="netEditTitle">ویرایش تنظیمات کارت شبکه</h3>
                </div>
                <button onclick="closeNetEditModal()" class="opacity-80 hover:opacity-100 transition-opacity">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <div class="p-5 space-y-4 max-h-[70vh] overflow-y-auto">
                <div class="bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-bold p-3 rounded-xl flex items-start gap-2">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                    <span>هشدار: تغییر IP ممکن است اتصال SSH شما را قطع کند.</span>
                </div>

                <input type="hidden" id="netEditIface">

                <div>
                    <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-1 block">
                        <i class="fa-solid fa-globe text-indigo-500 mr-1"></i> آدرس IP
                    </label>
                    <input type="text" id="netEditIp" dir="ltr" placeholder="192.168.1.100"
                        class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-1 block">
                            <i class="fa-solid fa-mask text-indigo-500 mr-1"></i> Subnet Mask
                        </label>
                        <input type="text" id="netEditSubnet" dir="ltr" placeholder="255.255.255.0"
                            class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-1 block">
                            <i class="fa-solid fa-route text-indigo-500 mr-1"></i> Gateway
                        </label>
                        <input type="text" id="netEditGateway" dir="ltr" placeholder="192.168.1.1"
                            class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-1 block">
                            <i class="fa-solid fa-server text-indigo-500 mr-1"></i> DNS اول
                        </label>
                        <input type="text" id="netEditDns1" dir="ltr" placeholder="8.8.8.8"
                            class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-1 block">
                            <i class="fa-solid fa-server text-indigo-500 mr-1"></i> DNS دوم
                        </label>
                        <input type="text" id="netEditDns2" dir="ltr" placeholder="8.8.4.4"
                            class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                    </div>
                </div>

                <div>
                    <label class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-1 block">
                        <i class="fa-solid fa-computer text-indigo-500 mr-1"></i> نام هاست (Hostname)
                    </label>
                    <input type="text" id="netEditHostname" dir="ltr" placeholder="pbx-server"
                        class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                </div>

                <div id="netEditResult" class="text-xs font-bold hidden"></div>

                <div class="flex gap-2 pt-2 border-t border-slate-100 dark:border-slate-700">
                    <button onclick="saveNetworkConfig()" id="netSaveBtn" class="flex-1 text-white py-2.5 rounded-xl text-sm font-bold transition-all shadow-md flex items-center justify-center gap-2" style="background: linear-gradient(135deg, #8b9a5b 0%, #6b7d3f 100%);">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>ذخیره و اعمال تنظیمات</span>
                    </button>
                    <button onclick="closeNetEditModal()" class="px-4 bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 py-2.5 rounded-xl text-sm font-bold">
                        انصراف
                    </button>
                </div>
            </div>
        </div>
    </div>
	
	    <!-- Modal لیست اپراتورهای صف -->
    <div id="queueAgentsModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[60] flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-md w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="queueAgentsCard">
            <div class="bg-indigo-600 text-white p-4 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-users"></i>
                    <h3 class="font-bold text-sm" id="queueAgentsTitle">لیست اپراتورهای صف</h3>
                </div>
                <button onclick="closeQueueAgentsModal()" class="text-indigo-200 hover:text-white transition-colors">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="p-5 max-h-[60vh] overflow-y-auto" id="queueAgentsBody">
                <!-- لیست اپراتورها اینجا قرار می‌گیرد -->
            </div>
            <div class="px-5 pb-5 pt-4 border-t border-slate-100 dark:border-slate-700 text-center">
                <button onclick="closeQueueAgentsModal()" class="w-full bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 py-2.5 rounded-xl text-sm font-bold hover:bg-slate-300 dark:hover:bg-slate-600 transition-colors">
                    بستن
                </button>
            </div>
        </div>
    </div>
	
	    <!-- Modal لیست تماس‌های داخلی -->
    <div id="extCallsModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[60] flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
        <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-5xl w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="extCallsCard">
            <div class="bg-indigo-600 text-white p-4 flex justify-between items-center">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-list-check"></i>
                    <h3 class="font-bold text-sm" id="extCallsTitle">تماس‌های داخلی</h3>
                </div>
                <button onclick="closeExtCallsModal()" class="text-indigo-200 hover:text-white transition-colors">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>
            <div class="p-5 max-h-[70vh] overflow-y-auto" id="extCallsBody">
                <!-- لیست تماس‌ها اینجا قرار می‌گیرد -->
            </div>
        </div>
    </div>
	

<!-- Modal مدیریت لیست سیاه -->
<div id="blacklistModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[75] flex items-center justify-center hidden opacity-0 transition-opacity duration-200">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-2xl w-full mx-4 shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="blacklistCard">
        <div class="bg-rose-600 text-white p-4 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-ban"></i>
                <h3 class="font-bold text-sm">مدیریت لیست سیاه</h3>
                <span id="blacklistTotal" class="text-[10px] bg-white/20 px-2 py-0.5 rounded-md font-bold">۰</span>
            </div>
            <button onclick="closeBlacklistModal()" class="text-rose-200 hover:text-white transition-colors">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <div class="p-5 space-y-4">
            <!-- فرم افزودن -->
            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2">
                <input type="text" id="blNewNumber" dir="ltr" placeholder="شماره (مثلا 09121234567)"
                    class="sm:col-span-4 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-rose-500 vazir-font">
                <input type="text" id="blNewReason" maxlength="50" placeholder="دلیل مسدودسازی (اختیاری)"
                    class="sm:col-span-6 bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-rose-500 vazir-font">
                <button onclick="addBlacklistFromModal()" class="sm:col-span-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl px-3 py-2 text-xs font-bold transition-colors">
                    <i class="fa-solid fa-plus"></i> افزودن
                </button>
            </div>
            <div id="blResult" class="text-xs font-bold hidden"></div>

            <!-- جستجو -->
            <div class="relative">
                <input type="text" id="blSearchInput" oninput="handleBlacklistSearch()" placeholder="جستجو در شماره یا دلیل..."
                    class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2 pr-8 text-xs focus:outline-none vazir-font">
                <i class="fa-solid fa-magnifying-glass absolute right-2.5 top-2.5 text-slate-400 text-xs"></i>
            </div>

            <!-- لیست -->
            <div class="max-h-[40vh] overflow-y-auto border border-slate-200 dark:border-slate-700 rounded-xl">
                <table class="w-full text-xs text-right text-slate-600 dark:text-slate-300">
                    <thead class="text-[10px] text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-700/50 sticky top-0">
                        <tr>
                            <th class="px-3 py-2.5">شماره</th>
                            <th class="px-3 py-2.5">دلیل مسدودسازی</th>
                            <th class="px-3 py-2.5 text-center">عملیات</th>
                        </tr>
                    </thead>
                    <tbody id="blacklistBody" class="divide-y divide-slate-100 dark:divide-slate-700"></tbody>
                </table>
            </div>

            <div id="blacklistPagination" class="flex justify-between items-center"></div>
        </div>
    </div>
</div>

    <script>
	var trunkYearlyChartInstance = null;
    var lastTrunkData = null;
	    var yearlyChartInstance = null;
		        var currentStatsPeriod = 'today';
        var currentPage = 1;
        var searchTimeout = null;
        var currentData = { extensions: [], queues: [], trunks: [], cdrs: [], pagination: {}, stats: {} };
        var hourlyChartInstance = null;
        var monthlyChartInstance = null;
        var currentNetworkData = { hostname: '', interfaces: [] };

        function toPersianNum(str) {
            var p = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
            return String(str).replace(/[0-9]/g, function(d) { return p[d]; });
        }

        // Collapse
        function toggleCollapse(bodyId, btn) {
            var body = document.getElementById(bodyId);
            if (!body) return;
            var isCollapsed = body.classList.toggle('collapsed');
            var icon = btn.querySelector('i');
            if (icon) {
                icon.className = isCollapsed ? 'fa-solid fa-plus text-xs' : 'fa-solid fa-minus text-xs';
            }
            try {
                var states = JSON.parse(localStorage.getItem('collapseStates') || '{}');
                states[bodyId] = isCollapsed ? 'collapsed' : 'open';
                localStorage.setItem('collapseStates', JSON.stringify(states));
            } catch(e) {}
        }

// ==============================================
// ✅ توابع جدید (Heatmap, Compare, Missed, Callback)
// ==============================================
var heatmapInstance = null;
function fetchHeatmap() {
    var days = document.getElementById('heatmapDays').value;
    fetch('?api=get_heatmap_data&days=' + days).then(r => r.json()).then(data => {
        if (data.error) return;
        if (heatmapInstance) { heatmapInstance.updateOptions({ series: data.series }); }
        else {
            heatmapInstance = new ApexCharts(document.querySelector("#heatmapChart"), {
                series: data.series,
                chart: { type: 'heatmap', height: 280, toolbar: { show: false }, fontFamily: 'Vazirmatn' },
                dataLabels: { enabled: false },
                colors: ['#e2e8f0', '#fde047', '#f97316', '#dc2626'],
                xaxis: { labels: { style: { fontSize: '10px' } } },
                yaxis: { labels: { style: { fontSize: '11px' } } },
                theme: { mode: document.documentElement.classList.contains('dark') ? 'dark' : 'light' },
                tooltip: { y: { formatter: function(val) { return toPersianNum(val) + ' تماس'; } } }
            });
            heatmapInstance.render();
        }
    });
}

function fetchPeriodComparison() {
    fetch('?api=get_period_comparison').then(r => r.json()).then(data => {
        if (data.error) return;
        var c = data.current, p = data.previous, ch = data.changes;
        function card(title, curr, prev, change, icon, isMissed) {
            var isUp = change > 0;
            var color = isMissed ? (isUp ? 'text-rose-500' : 'text-emerald-500') : (isUp ? 'text-emerald-500' : 'text-rose-500');
            var arrow = isUp ? 'fa-arrow-up' : 'fa-arrow-down';
            if (change === 0) { arrow = 'fa-minus'; color = 'text-slate-400'; }
            return `<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-4 flex flex-col justify-between shadow-sm">
                <div class="flex items-center justify-between mb-2"><span class="text-xs font-bold text-slate-500">${title}</span><i class="fa-solid ${icon} text-slate-400"></i></div>
                <div class="flex items-end justify-between">
                    <div><div class="text-2xl font-black text-slate-800 dark:text-white">${toPersianNum(curr)}</div><div class="text-[10px] text-slate-400 mt-1">هفته قبل: ${toPersianNum(prev)}</div></div>
                    <div class="text-xs font-bold ${color} flex items-center gap-1" dir="ltr"><span>${toPersianNum(Math.abs(change))}%</span><i class="fa-solid ${arrow}"></i></div>
                </div></div>`;
        }
        document.getElementById('period-comparison-container').innerHTML = 
            card('کل تماس‌ها', c.total, p.total, ch.total, 'fa-phone', false) +
            card('پاسخ داده شده', c.answered, p.answered, ch.answered, 'fa-check-circle', false) +
            card('از دست رفته', c.missed, p.missed, ch.missed, 'fa-phone-slash', true) +
            card('مدت مکالمه (دقیقه)', Math.round(c.talk/60), Math.round(p.talk/60), ch.talk, 'fa-clock', false);
    });
}

var missedCurrentPage = 1;
function fetchMissedCalls(page) {
    missedCurrentPage = page || 1;
    fetch('?api=get_missed_calls&page=' + missedCurrentPage).then(r => r.json()).then(data => {
        var tbody = document.getElementById('missed-calls-body');
        var pag = document.getElementById('missed-pagination');
        
        if (data.error) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-6 text-rose-500 font-bold">خطا: ' + data.error + '</td></tr>';
            pag.innerHTML = '';
            return;
        }

        if (!data.list || data.list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-6 text-slate-400">هیچ تماس ورودی پاسخ‌داده‌نشده‌ای وجود ندارد.</td></tr>';
            pag.innerHTML = ''; 
            return;
        }
        
        var html = '';
        data.list.forEach(item => {
            var isDone = item.follow_status === 'done';
            var badge = isDone 
                ? '<span class="bg-emerald-100 text-emerald-700 px-2 py-1 rounded text-xs font-bold"><i class="fa-solid fa-check mr-1"></i>پیگیری شد</span>' 
                : '<span class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-xs font-bold"><i class="fa-solid fa-clock mr-1"></i>در انتظار</span>';

            // ✅ ستون داخلی مقصد
            var dstBadge = (item.dst && item.dst !== '') 
                ? '<span class="bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 px-3 py-1 rounded-lg text-sm font-black vazir-font">' + toPersianNum(item.dst) + '</span>'
                : '<span class="text-slate-400 text-xs">—</span>';

            // ✅ نمایش دلیل پاسخ‌ندادن
            var reason = '';
            if (item.disposition === 'NO ANSWER') reason = '<span class="text-[10px] text-amber-600 dark:text-amber-400 block mt-1"><i class="fa-solid fa-phone-slash text-[9px] mr-1"></i>بی‌پاسخ</span>';
            else if (item.disposition === 'BUSY') reason = '<span class="text-[10px] text-rose-600 dark:text-rose-400 block mt-1"><i class="fa-solid fa-phone-slash text-[9px] mr-1"></i>مشغول</span>';
            else if (item.disposition === 'FAILED') reason = '<span class="text-[10px] text-rose-600 dark:text-rose-400 block mt-1"><i class="fa-solid fa-xmark text-[9px] mr-1"></i>ناموفق</span>';

            html += `<tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30">
                <td class="px-4 py-3 text-xs font-mono" dir="ltr">${item.calldate}</td>
                <td class="px-4 py-3">
                    <div class="font-bold text-sm" dir="ltr">${toPersianNum(item.src)}</div>
                    ${reason}
                </td>
                <td class="px-4 py-3 text-center">${dstBadge}</td>
                <td class="px-4 py-3">${badge}</td>
                <td class="px-4 py-3 text-center space-x-1 space-x-reverse">
                    <button onclick="openWebDialer('${item.src}')" class="bg-emerald-100 text-emerald-700 px-2 py-1 rounded text-xs font-bold hover:bg-emerald-200 transition" title="تماس مجدد با ${item.src}">
                        <i class="fa-solid fa-phone"></i>
                    </button>
                    ${!isDone ? `<button onclick="markFollowup('${item.src}', '${item.raw_date}')" class="bg-indigo-100 text-indigo-700 px-2 py-1 rounded text-xs font-bold hover:bg-indigo-200 transition" title="علامت‌گذاری به عنوان پیگیری شده">
                        <i class="fa-solid fa-check"></i>
                    </button>` : ''}
                </td></tr>`;
        });
        tbody.innerHTML = html;
        if (data.total_pages > 1) {
            var p = `<div class="text-[10px] text-slate-400 font-bold">صفحه ${toPersianNum(data.current_page)} از ${toPersianNum(data.total_pages)} — مجموع ${toPersianNum(data.total_records || 0)} تماس</div><div class="flex gap-1">`;
            if (data.current_page > 1) p += `<button onclick="fetchMissedCalls(${data.current_page - 1})" class="w-8 h-8 rounded-lg border bg-white text-xs"><i class="fa-solid fa-chevron-right"></i></button>`;
            if (data.current_page < data.total_pages) p += `<button onclick="fetchMissedCalls(${data.current_page + 1})" class="w-8 h-8 rounded-lg border bg-white text-xs"><i class="fa-solid fa-chevron-left"></i></button>`;
            p += `</div>`; pag.innerHTML = p;
        } else { 
            pag.innerHTML = '<div class="text-[10px] text-slate-400 font-bold">مجموع ' + toPersianNum(data.total_records || 0) + ' تماس</div>'; 
        }
    });
}



function markFollowup(number, calldate) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.onload = function() { try { var res = JSON.parse(xhr.responseText); if (res.success) fetchMissedCalls(missedCurrentPage); } catch(e) {} };
    xhr.send('api=mark_followup&number=' + encodeURIComponent(number) + '&calldate=' + encodeURIComponent(calldate));
}

var callbackCurrentPage = 1;
function fetchCallbackList(page) {
    callbackCurrentPage = page || 1;
    fetch('?api=get_callback_list&page=' + callbackCurrentPage).then(r => r.json()).then(data => {
        var container = document.getElementById('callback-list-container');
        var pag = document.getElementById('callback-pagination');
        var totalBadge = document.getElementById('callback-total-count'); // ✅ المان جدید
        
        if (data.error) {
            if(totalBadge) totalBadge.innerText = 'خطا';
            container.innerHTML = '<div class="col-span-full text-center py-4 text-rose-500 text-xs font-bold">خطا در دریافت اطلاعات</div>'; 
            pag.innerHTML = '';
            return;
        }

        if (!data.list || data.list.length === 0) {
            if(totalBadge) totalBadge.innerText = '۰ شماره';
            container.innerHTML = '<div class="col-span-full text-center py-4 text-slate-400 text-xs">لیست پیگیری خالی است.</div>'; 
            pag.innerHTML = '';
            return;
        }

        // ✅ نمایش تعداد کل شماره‌های پرتکرار در هدر
        if(totalBadge) {
            totalBadge.innerText = toPersianNum(data.total_count || 0) + ' شماره';
        }
        
        var html = '';
        data.list.forEach(item => {
            html += `<div class="bg-slate-50 dark:bg-slate-700/50 p-4 rounded-xl border border-slate-200 dark:border-slate-600 flex flex-col justify-between">
                <div class="flex justify-between items-start mb-2">
                    <div class="font-black text-lg text-slate-800 dark:text-white" dir="ltr">${toPersianNum(item.src)}</div>
                    <span class="bg-rose-100 text-rose-700 px-2 py-0.5 rounded text-xs font-bold">${toPersianNum(item.missed_count)} بی‌پاسخ</span>
                </div>
                <div class="text-[10px] text-slate-500 mb-3">آخرین تماس: ${item.last_missed_fa}</div>
                <button onclick="openWebDialer('${item.src}')" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded-lg text-xs font-bold transition flex items-center justify-center gap-1"><i class="fa-solid fa-phone"></i> تماس مجدد</button>
            </div>`;
        });
        container.innerHTML = html;

        // ✅ ساخت دکمه‌های صفحه‌بندی
        if (data.total_pages > 1) {
            var p = `<div class="text-[11px] text-slate-500 font-bold">صفحه ${toPersianNum(data.current_page)} از ${toPersianNum(data.total_pages)}</div><div class="flex gap-1">`;
            if (data.current_page > 1) {
                p += `<button onclick="fetchCallbackList(${data.current_page - 1})" class="w-8 h-8 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 flex items-center justify-center text-xs hover:bg-slate-100 dark:hover:bg-slate-600 transition"><i class="fa-solid fa-chevron-right"></i></button>`;
            }
            if (data.current_page < data.total_pages) {
                p += `<button onclick="fetchCallbackList(${data.current_page + 1})" class="w-8 h-8 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 flex items-center justify-center text-xs hover:bg-slate-100 dark:hover:bg-slate-600 transition"><i class="fa-solid fa-chevron-left"></i></button>`;
            }
            p += `</div>`;
            pag.innerHTML = p;
        } else {
            pag.innerHTML = '';
        }
    });
}

        document.addEventListener('DOMContentLoaded', function() {
            try {
                var states = JSON.parse(localStorage.getItem('collapseStates') || '{}');
                for (var id in states) {
                    if (states[id] === 'collapsed') {
                        var body = document.getElementById(id);
                        if (body) {
                            body.classList.add('collapsed');
                            var btn = body.parentElement.querySelector('.collapse-btn');
                            if (btn) {
                                var icon = btn.querySelector('i');
                                if (icon) icon.className = 'fa-solid fa-plus text-xs';
                            }
                        }
                    }
                }
            } catch(e) {}
        });

        // منوی همبرگری
        function toggleHamburgerMenu(event) {
            if (event) event.stopPropagation();
            document.getElementById('hamburgerMenu').classList.toggle('hidden');
        }
        document.addEventListener('click', function(event) {
            var menu = document.getElementById('hamburgerMenu');
            var button = event.target.closest('button[onclick="toggleHamburgerMenu(event)"]');
            if (!menu || menu.classList.contains('hidden')) return;
            if (!menu.contains(event.target) && !button) menu.classList.add('hidden');
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var menu = document.getElementById('hamburgerMenu');
                if (menu) menu.classList.add('hidden');
				        var blModal = document.getElementById('blacklistModal');
        if (blModal && !blModal.classList.contains('hidden')) closeBlacklistModal();
            }
        });

        function updateThemeUI() {
            var isDark = document.documentElement.classList.contains('dark');
            var icon = document.getElementById('themeIcon');
            var label = document.getElementById('themeLabel');
            if (icon) icon.className = isDark ? 'fa-solid fa-sun text-sm' : 'fa-solid fa-moon text-sm';
            if (label) label.innerText = isDark ? 'حالت روز' : 'حالت شب';
        }
        function toggleDarkMode() {
            var isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeUI();
        }
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
        document.addEventListener('DOMContentLoaded', updateThemeUI);
        updateThemeUI();

                // ==============================================
        // ✅ نمودار زنده تماس‌های همزمان (با جزئیات در Tooltip)
        // ==============================================
        var liveCallsChartInstance = null;
        var liveCallsData = []; 
        var maxDataPoints = 30; 

                function updateLiveCallsChart(callCount, callsDetails) {
            var now = new Date();
            var timeStr = now.getHours().toString().padStart(2, '0') + ':' +
                          now.getMinutes().toString().padStart(2, '0') + ':' +
                          now.getSeconds().toString().padStart(2, '0');

            // ✅ ذخیره تعداد و جزئیات تماس‌ها
            liveCallsData.push({ 
                x: timeStr, 
                y: callCount, 
                calls: callsDetails || [] 
            });
            
            if (liveCallsData.length > maxDataPoints) {
                liveCallsData.shift(); 
            }

            var badge = document.getElementById('live-call-badge');
            var tooltip = document.getElementById('live-call-tooltip');
            
            if (badge) {
                badge.innerText = 'هم‌اکنون: ' + toPersianNum(callCount) + ' تماس';
                if (callCount > 0) {
                    badge.className = 'text-[10px] font-bold bg-rose-500 text-white px-2 py-1 rounded-md animate-pulse cursor-help';
                } else {
                    badge.className = 'text-[10px] font-bold bg-emerald-500 text-white px-2 py-1 rounded-md cursor-help';
                }
            }

            // ✅ ساخت لیست شماره‌ها برای نمایش در Tooltip
            if (tooltip) {
                var tHtml = '';
                if (callsDetails && callsDetails.length > 0) {
                    tHtml += '<div class="font-bold border-b border-slate-600 pb-1.5 mb-2 text-[10px] text-slate-300 flex justify-between"><span>لیست تماس‌های همزمان:</span><span class="text-rose-400">' + toPersianNum(callCount) + ' تماس</span></div>';
                    tHtml += '<div style="max-height:150px; overflow-y:auto; direction:ltr; text-align:left;" class="space-y-1">';
                    
                    callsDetails.forEach(function(c) {
                        tHtml += '<div class="font-mono text-[11px] border-b border-slate-700/50 pb-1 mb-1">' +
                                    '<span style="color:#34d399; font-weight:bold;">' + toPersianNum(c.src) + '</span>' +
                                    '<span style="color:#94a3b8; margin: 0 4px;">←</span>' +
                                    '<span style="color:#fbbf24; font-weight:bold;">' + toPersianNum(c.dst) + '</span>' +
                                 '</div>';
                    });
                    tHtml += '</div>';
                } else {
                    tHtml = '<div class="text-center text-slate-400 text-[11px] py-2">هیچ تماسی در جریان نیست.</div>';
                }
                tooltip.innerHTML = tHtml;
            }

            var options = {
                chart: {
                    type: 'area',
                    height: 192,
                    animations: { enabled: true, easing: 'linear', dynamicAnimation: { speed: 1000 } },
                    toolbar: { show: false },
                    background: 'transparent'
                },
                series: [{ name: 'تماس همزمان', data: liveCallsData }],
                xaxis: { type: 'category', labels: { style: { colors: '#94a3b8', fontSize: '10px' } } },
                yaxis: { labels: { style: { colors: '#94a3b8' } }, min: 0, forceNiceScale: true },
                stroke: { curve: 'smooth', width: 2.5 },
                colors: ['#f43f5e'],
                fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
                dataLabels: { enabled: false },
                theme: { mode: document.documentElement.classList.contains('dark') ? 'dark' : 'light' },
                
                // ✅ Tooltip اصلاح شده (خواندن مستقیم از liveCallsData)
                tooltip: {
                    shared: false,
                    intersect: true,
                    custom: function({ series, seriesIndex, dataPointIndex, w }) {
                        // ← خواندن مستقیم از آرایه اصلی (نه از ApexCharts)
                        var data = liveCallsData[dataPointIndex];
                        if (!data) return '';
                        
                        var calls = data.calls || [];
                        var count = data.y;
                        
                        var html = '<div class="p-3 text-xs vazir-font" dir="rtl" style="background:#1e293b;color:#fff;border-radius:10px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.5);min-width:220px;border:1px solid #334155;">';
                        html += '<div class="font-bold border-b border-slate-600 pb-1.5 mb-2 flex justify-between"><span>' + data.x + '</span><span class="text-rose-400">' + toPersianNum(count) + ' تماس</span></div>';
                        
                        if (calls.length === 0) {
                            html += '<div class="text-slate-400 text-center text-[11px]">هیچ تماسی برقرار نیست.</div>';
                        } else {
                            html += '<div style="max-height:150px; overflow-y:auto; direction:ltr; text-align:left;">';
                            calls.forEach(function(c) {
                                html += '<div style="margin-bottom:4px; padding-bottom:3px; border-bottom:1px dashed #475569; font-family:monospace; font-size:11px;">' +
                                            '<span style="color:#34d399; font-weight:bold;">' + toPersianNum(c.src) + '</span>' +
                                            '<span style="color:#94a3b8; margin: 0 4px;">←</span>' +
                                            '<span style="color:#fbbf24; font-weight:bold;">' + toPersianNum(c.dst) + '</span>' +
                                        '</div>';
                            });
                            html += '</div>';
                        }
                        html += '</div>';
                        return html;
                    }
                }
            };

            if (liveCallsChartInstance) {
                liveCallsChartInstance.updateOptions(options);
                liveCallsChartInstance.updateSeries([{ name: 'تماس همزمان', data: liveCallsData }]);
            } else {
                liveCallsChartInstance = new ApexCharts(document.querySelector("#liveConcurrentChart"), options);
                liveCallsChartInstance.render();
            }
        }
		
        function fetchLiveData(page) {
            if (page) currentPage = page;
            var searchVal = encodeURIComponent(document.getElementById('searchInput').value.trim());
            var startDate = document.getElementById('cdrStartDate').value.trim();
            var endDate = document.getElementById('cdrEndDate').value.trim();
            var onlyAudio = document.getElementById('cdrOnlyAudio').checked ? '1' : '0';
            
            var filterType = document.getElementById('cdrFilterType').value;
            var filterStatus = document.getElementById('cdrFilterStatus').value;
            
            var xhr = new XMLHttpRequest();
            // ✅ تغییر در این خط: اضافه شدن stats_period
            xhr.open('GET', '?api=get_status&page=' + currentPage + '&search=' + searchVal + 
                           '&start_date=' + startDate + '&end_date=' + endDate + 
                           '&only_audio=' + onlyAudio + 
                           '&filter_type=' + filterType + 
                           '&filter_status=' + filterStatus + 
                           '&stats_period=' + currentStatsPeriod, true); 
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        currentData = data;
						updateLiveCallsChart(data.active_calls || 0, data.active_calls_details || []);
                        renderTodayStats(data.today_stats, data.stats); // این خط جایگزین renderStats(data.stats) میشه
                        renderHourlyChart(data.hourly_chart);
                        renderMonthlyChart(data.monthly_chart);
                        renderExtensions(data.extensions || []);
                        renderQueues(data.queues || []);
                        renderTrunks(data.trunks || []);
                        renderCDRs(data.cdrs || [], data.cdr_error);
                        renderPagination(data.pagination);
                    } catch(e) {
                        console.error("API Error:", xhr.responseText);
                    }
                }
            };
            xhr.send();
        }

        // منابع سیستم
        function fetchSystemStats() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '?api=get_system_stats', true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        renderSystemStats(data);
                    } catch(e) {}
                }
            };
            xhr.send();
        }

        function getBarColor(percent) {
            if (percent < 50) return 'linear-gradient(90deg, #8b9a5b 0%, #6b7d3f 100%)';
            if (percent < 75) return 'linear-gradient(90deg, #c2a14d 0%, #a8842a 100%)';
            return 'linear-gradient(90deg, #c97064 0%, #a94442 100%)';
        }

        function renderSystemStats(data) {
            var container = document.getElementById('system-stats-container');
            if (!container) return;

            document.getElementById('sys-os-display').innerText = data.os || '-';
            document.getElementById('sys-mini-hostname').innerText = data.hostname || '-';
            document.getElementById('sys-mini-uptime').innerText = data.uptime || '-';

            var cpu = data.cpu || {};
            var ram = data.ram || {};
            var disk = data.disk || {};

            var cpuPct = Math.min(100, cpu.usage || 0);
            var ramPct = Math.min(100, ram.percent || 0);
            var diskPct = Math.min(100, disk.percent || 0);

            var html = '';

            html += '<div class="sys-mini">' +
                '<div class="sys-mini-head">' +
                    '<span class="sys-mini-icon" style="background:linear-gradient(135deg,#e8ecd6 0%,#d4dcc0 100%);color:#556b2f;">' +
                        '<i class="fa-solid fa-microchip"></i>' +
                    '</span>' +
                    '<span class="sys-mini-label">CPU</span>' +
                    '<span class="sys-mini-percent">' + toPersianNum(cpuPct) + '٪</span>' +
                '</div>' +
                '<div class="sys-mini-bar">' +
                    '<div class="sys-mini-bar-fill" style="width:' + cpuPct + '%;background:' + getBarColor(cpuPct) + ';"></div>' +
                '</div>' +
                '<div class="sys-mini-meta">' +
                    '<span>' + toPersianNum(cpu.cores || 0) + ' هسته</span>' +
                    '<span style="direction:ltr;">' + (cpu.load ? cpu.load.split(' / ')[0] : '0') + '</span>' +
                '</div>' +
            '</div>';

            html += '<div class="sys-mini">' +
                '<div class="sys-mini-head">' +
                    '<span class="sys-mini-icon" style="background:linear-gradient(135deg,#d6e4f0 0%,#bfd4e8 100%);color:#3b5e85;">' +
                        '<i class="fa-solid fa-memory"></i>' +
                    '</span>' +
                    '<span class="sys-mini-label">RAM</span>' +
                    '<span class="sys-mini-percent">' + toPersianNum(ramPct) + '٪</span>' +
                '</div>' +
                '<div class="sys-mini-bar">' +
                    '<div class="sys-mini-bar-fill" style="width:' + ramPct + '%;background:' + getBarColor(ramPct) + ';"></div>' +
                '</div>' +
                '<div class="sys-mini-meta">' +
                    '<span>' + toPersianNum(ram.used || 0) + ' GB</span>' +
                    '<span>' + toPersianNum(ram.total || 0) + ' GB</span>' +
                '</div>' +
            '</div>';

            html += '<div class="sys-mini">' +
                '<div class="sys-mini-head">' +
                    '<span class="sys-mini-icon" style="background:linear-gradient(135deg,#f0e4d6 0%,#e8d4bf 100%);color:#8b6b3f;">' +
                        '<i class="fa-solid fa-hard-drive"></i>' +
                    '</span>' +
                    '<span class="sys-mini-label">DISK</span>' +
                    '<span class="sys-mini-percent">' + toPersianNum(diskPct) + '٪</span>' +
                '</div>' +
                '<div class="sys-mini-bar">' +
                    '<div class="sys-mini-bar-fill" style="width:' + diskPct + '%;background:' + getBarColor(diskPct) + ';"></div>' +
                '</div>' +
                '<div class="sys-mini-meta">' +
                    '<span>' + toPersianNum(disk.used || 0) + ' GB</span>' +
                    '<span>' + toPersianNum(disk.total || 0) + ' GB</span>' +
                '</div>' +
            '</div>';

            html += '<div class="sys-mini">' +
                '<div class="sys-mini-head">' +
                    '<span class="sys-mini-icon" style="background:linear-gradient(135deg,#ede6f0 0%,#dccfe0 100%);color:#6b4a7d;">' +
                        '<i class="fa-solid fa-gauge-high"></i>' +
                    '</span>' +
                    '<span class="sys-mini-label">LOAD</span>' +
                    '<span class="sys-mini-percent" style="direction:ltr;font-size:10px;">' + (cpu.load || '0') + '</span>' +
                '</div>' +
                '<div class="sys-mini-meta" style="margin-top:4px;">' +
                    '<span>1m / 5m / 15m</span>' +
                '</div>' +
            '</div>';

            container.innerHTML = html;
        }

        // کارت‌های شبکه
        function fetchNetworkConfig() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '?api=get_network_config', true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        currentNetworkData = data;
                        renderNetworkCards(data);
                    } catch(e) {}
                }
            };
            xhr.send();
        }

function renderNetworkCards(data) {
    var container = document.getElementById('network-cards-container');
    if (!container) return;

    document.getElementById('net-hostname-display').innerText = data.hostname || '-';

    var ifaces = data.interfaces || [];
    if (ifaces.length === 0) {
        container.innerHTML = '<div class="col-span-full text-center py-6 text-slate-400 text-xs">هیچ کارت شبکه‌ای یافت نشد.</div>';
        return;
    }

    var html = '';
    for (var i = 0; i < ifaces.length; i++) {
        var ifc = ifaces[i];
        var isUp = (ifc.status === 'up' || ifc.status === 'unknown');
        var statusText = isUp ? 'فعال' : 'قطع';
        var statusClass = isUp ? '' : 'down';
        var routesCount = (ifc.routes || []).length;
        var ipDisplay = ifc.ip ? (ifc.ip + (ifc.prefix ? '/' + ifc.prefix : '')) : 'بدون IP';

        html += '<div class="net-card ' + (isUp ? '' : 'down') + '" ' +
                    'onclick="openNetInfoModal(\'' + ifc.name + '\')" ' +
                    'style="cursor:pointer;" ' +
                    'title="برای مشاهده جزئیات کلیک کنید">' +

                    '<div class="flex items-center justify-between mb-2 pb-2" style="border-bottom: 1px solid rgba(107, 125, 63, 0.15);">' +
                        '<div class="flex items-center gap-2">' +
                            '<span class="net-card-status ' + statusClass + '"></span>' +
                            '<span class="text-sm font-black" style="color: #3d4a1e;">' + ifc.name + '</span>' +
                        '</div>' +
                        '<span class="text-[10px] font-bold px-2 py-0.5 rounded-md" style="background: rgba(107,125,63,0.12); color:#556b2f;">' + statusText + '</span>' +
                    '</div>' +

                    '<div class="text-[11px] font-bold" style="color:#4a5326;direction:ltr;text-align:right;font-family:monospace;">' + ipDisplay + '</div>' +

                    '<div class="flex items-center justify-between mt-2.5 text-[9px] text-slate-400">' +
                        '<span><i class="fa-solid fa-route mr-0.5"></i>' + toPersianNum(routesCount) + ' مسیر</span>' +
                        '<span style="color:#6b7d3f;font-weight:800;"><i class="fa-solid fa-circle-info mr-0.5"></i>جزئیات</span>' +
                    '</div>' +
                '</div>';
    }
    container.innerHTML = html;
}

        function openNetEditModal(ifaceName) {
            var ifc = null;
            for (var i = 0; i < currentNetworkData.interfaces.length; i++) {
                if (currentNetworkData.interfaces[i].name === ifaceName) {
                    ifc = currentNetworkData.interfaces[i];
                    break;
                }
            }
            if (!ifc) return;

            document.getElementById('netEditTitle').innerText = 'ویرایش تنظیمات ' + ifaceName;
            document.getElementById('netEditIface').value = ifaceName;
            document.getElementById('netEditIp').value = ifc.ip || '';
            document.getElementById('netEditSubnet').value = ifc.subnet || '';
            document.getElementById('netEditGateway').value = ifc.gateway || '';
            document.getElementById('netEditDns1').value = ifc.dns_primary || '';
            document.getElementById('netEditDns2').value = ifc.dns_secondary || '';
            document.getElementById('netEditHostname').value = currentNetworkData.hostname || '';

            var resultBox = document.getElementById('netEditResult');
            resultBox.classList.add('hidden');

            var modal = document.getElementById('netEditModal');
            var card = document.getElementById('netEditCard');
            modal.classList.remove('hidden');
            setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);
        }

        function closeNetEditModal() {
            var modal = document.getElementById('netEditModal');
            var card = document.getElementById('netEditCard');
            modal.classList.add('opacity-0'); card.classList.add('scale-95');
            setTimeout(function() { modal.classList.add('hidden'); }, 200);
        }

        function saveNetworkConfig() {
            var btn = document.getElementById('netSaveBtn');
            var resultBox = document.getElementById('netEditResult');

            var iface = document.getElementById('netEditIface').value;
            var ip = document.getElementById('netEditIp').value.trim();
            var subnet = document.getElementById('netEditSubnet').value.trim();
            var gateway = document.getElementById('netEditGateway').value.trim();
            var dns1 = document.getElementById('netEditDns1').value.trim();
            var dns2 = document.getElementById('netEditDns2').value.trim();
            var hostname = document.getElementById('netEditHostname').value.trim();

            if (!ip || !subnet) {
                resultBox.className = 'text-xs font-bold text-rose-600 bg-rose-50 p-2.5 rounded-lg';
                resultBox.innerText = 'آدرس IP و Subnet Mask اجباری است.';
                resultBox.classList.remove('hidden');
                return;
            }

            if (!confirm('آیا از اعمال تغییرات مطمئن هستید؟ ممکن است اتصال شما قطع شود.')) return;

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>در حال اعمال...</span>';

            resultBox.className = 'text-xs font-bold text-indigo-600 bg-indigo-50 p-2.5 rounded-lg';
            resultBox.innerText = 'در حال اعمال تنظیمات...';
            resultBox.classList.remove('hidden');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            xhr.onload = function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i><span>ذخیره و اعمال تنظیمات</span>';

                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        resultBox.className = 'text-xs font-bold text-emerald-700 bg-emerald-50 p-2.5 rounded-lg';
                        resultBox.innerText = resp.message || 'تنظیمات اعمال شد.';
                        if (resp.output) {
                            var out = resp.output.replace(/</g, '&lt;').substring(0, 300);
                            resultBox.innerHTML += '<div style="margin-top:6px;font-family:monospace;font-size:10px;opacity:0.8;" dir="ltr">' + out + '</div>';
                        }
                        setTimeout(function() {
                            fetchNetworkConfig();
                            fetchSystemStats();
                        }, 1500);
                    } else {
                        resultBox.className = 'text-xs font-bold text-rose-600 bg-rose-50 p-2.5 rounded-lg';
                        resultBox.innerText = resp.message || 'خطا در اعمال تنظیمات.';
                    }
                } catch(e) {
                    resultBox.className = 'text-xs font-bold text-rose-600 bg-rose-50 p-2.5 rounded-lg';
                    resultBox.innerHTML = 'پاسخ نامعتبر از سرور. متن خطا:<br><div style="font-family:monospace;font-size:10px;direction:ltr;text-align:left;max-height:100px;overflow:auto;background:#fff;padding:5px;border-radius:5px;margin-top:5px;color:#000;">' + xhr.responseText.replace(/</g, '&lt;') + '</div>';
                }
                resultBox.classList.remove('hidden');
            };
            xhr.onerror = function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i><span>ذخیره و اعمال تنظیمات</span>';
                resultBox.className = 'text-xs font-bold text-rose-600 bg-rose-50 p-2.5 rounded-lg';
                resultBox.innerText = 'خطای شبکه.';
                resultBox.classList.remove('hidden');
            };

            var params = 'api=save_network_config' +
                '&iface=' + encodeURIComponent(iface) +
                '&ip=' + encodeURIComponent(ip) +
                '&subnet=' + encodeURIComponent(subnet) +
                '&gateway=' + encodeURIComponent(gateway) +
                '&dns1=' + encodeURIComponent(dns1) +
                '&dns2=' + encodeURIComponent(dns2) +
                '&hostname=' + encodeURIComponent(hostname);
            xhr.send(params);
        }

// ==============================================
// ✅ مودال اطلاعات کارت شبکه
// ==============================================
var netInfoCurrentIface = '';

function openNetInfoModal(ifaceName) {
    var ifc = null;
    for (var i = 0; i < currentNetworkData.interfaces.length; i++) {
        if (currentNetworkData.interfaces[i].name === ifaceName) {
            ifc = currentNetworkData.interfaces[i];
            break;
        }
    }
    if (!ifc) return;

    netInfoCurrentIface = ifaceName;
    document.getElementById('netInfoTitle').innerText = 'اطلاعات کارت شبکه: ' + ifaceName;

    var isUp = (ifc.status === 'up' || ifc.status === 'unknown');
    var statusBadge = isUp
        ? '<span class="bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 px-2.5 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fa-solid fa-circle-check text-[10px]"></i> فعال (UP)</span>'
        : '<span class="bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 px-2.5 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fa-solid fa-circle-xmark text-[10px]"></i> قطع (DOWN)</span>';

    var bodyHtml = '<div class="space-y-3 font-medium text-xs">' +
        '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700"><span class="text-slate-500">نام اینترفیس:</span><span class="font-black vazir-font">' + ifc.name + '</span></div>' +
        '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700"><span class="text-slate-500">وضعیت اتصال:</span>' + statusBadge + '</div>' +
        '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700"><span class="text-slate-500">آدرس IP:</span><span class="font-bold vazir-font" dir="ltr" style="font-family:monospace;">' + (ifc.ip || '-') + (ifc.prefix ? '/' + ifc.prefix : '') + '</span></div>' +
        '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700"><span class="text-slate-500">Subnet Mask:</span><span class="font-bold vazir-font" dir="ltr" style="font-family:monospace;">' + (ifc.subnet || '-') + '</span></div>' +
        '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700"><span class="text-slate-500">Gateway:</span><span class="font-bold vazir-font" dir="ltr" style="font-family:monospace;">' + (ifc.gateway || '-') + '</span></div>' +
        '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700"><span class="text-slate-500">DNS Servers:</span><span class="font-bold vazir-font" dir="ltr" style="font-family:monospace;font-size:10px;">' + (ifc.dns || '-') + '</span></div>' +
        '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700"><span class="text-slate-500">MAC Address:</span><span class="font-bold vazir-font" dir="ltr" style="font-family:monospace;font-size:10px;">' + (ifc.mac || '-') + '</span></div>' +
        '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700"><span class="text-slate-500">Boot Proto:</span><span class="font-bold vazir-font">' + (ifc.bootproto || '-') + '</span></div>' +
        '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700"><span class="text-slate-500">On Boot:</span><span class="font-bold vazir-font">' + (ifc.onboot || '-') + '</span></div>';

    // Routes
    var routes = ifc.routes || [];
    if (routes.length > 0) {
        bodyHtml += '<div class="pt-2">' +
            '<div class="flex items-center gap-2 mb-2">' +
                '<i class="fa-solid fa-route text-[10px]" style="color:#6b7d3f;"></i>' +
                '<span class="text-xs font-black" style="color:#556b2f;">مسیرها (Routes)</span>' +
                '<span class="text-[9px] font-bold px-1.5 py-0.5 rounded" style="background:rgba(107,125,63,0.15);color:#556b2f;">' + toPersianNum(routes.length) + '</span>' +
            '</div>' +
            '<div class="space-y-1.5">';

        for (var r = 0; r < routes.length; r++) {
            var route = routes[r];
            var routeStyle = 'font-family:monospace;font-size:9.5px;padding:5px 8px;border-radius:6px;direction:ltr;text-align:left;background:rgba(107,125,63,0.05);border:1px solid rgba(107,125,63,0.12);word-break:break-all;';
            if (route.is_default) {
                routeStyle += 'font-weight:800;background:rgba(139,154,91,0.15);border-color:rgba(107,125,63,0.28);';
            }
            bodyHtml += '<div style="' + routeStyle + '">' +
                (route.is_default ? '<i class="fa-solid fa-star" style="color:#8b9a5b;font-size:8px;"></i> ' : '') +
                route.raw.replace(/</g, '&lt;').replace(/>/g, '&gt;') +
            '</div>';
        }
        bodyHtml += '</div></div>';
    } else {
        bodyHtml += '<div class="pt-2 text-[10px] text-slate-400 text-center">هیچ مسیری برای این اینترفیس یافت نشد.</div>';
    }

    bodyHtml += '</div>';
    document.getElementById('netInfoBody').innerHTML = bodyHtml;

    var modal = document.getElementById('netInfoModal');
    var card = document.getElementById('netInfoCard');
    modal.classList.remove('hidden');
    setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);
}

function closeNetInfoModal() {
    var modal = document.getElementById('netInfoModal');
    var card = document.getElementById('netInfoCard');
    modal.classList.add('opacity-0'); card.classList.add('scale-95');
    setTimeout(function() { modal.classList.add('hidden'); }, 200);
}

function editFromInfoModal() {
    var ifaceName = netInfoCurrentIface;
    closeNetInfoModal();
    setTimeout(function() {
        if (typeof openNetEditModal === 'function') openNetEditModal(ifaceName);
    }, 250);
}

        // رندر بخش عملکرد امروز
        function renderTodayStats(today, global) {
            if (!today) today = { total:0, answered:0, missed:0, busy:0, failed:0, talk_time:0 };
            var answerRate = today.total > 0 ? Math.round((today.answered / today.total) * 100) : 0;
            var avgTalk = today.answered > 0 ? Math.round(today.talk_time / today.answered) : 0;
            var busyNoAnswer = today.busy + today.missed;

            var html = '';

            // کارت ۱: تماس دریافتی
            html += `
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-4 flex flex-col justify-between">
                <div class="flex items-start justify-between mb-4">
                    <div class="text-xs font-bold text-slate-600 dark:text-slate-300">تماس دریافتی</div>
                    <div class="w-8 h-8 rounded-xl bg-purple-50 text-purple-500 flex items-center justify-center"><i class="fa-solid fa-phone"></i></div>
                </div>
                <div class="text-center space-y-1">
                    <div class="text-2xl font-black text-slate-800 dark:text-white vazir-font">${toPersianNum(today.total)}</div>
                    <div class="text-[10px] text-slate-400">تماس از صف</div>
                </div>
            </div>`;

            // کارت ۲: پاسخ داده شده
            html += `
            <div class="bg-white dark:bg-slate-800 border border-emerald-200 dark:border-emerald-800/50 rounded-2xl p-4 flex flex-col justify-between relative overflow-hidden">
                <div class="absolute inset-0 bg-emerald-50/30 dark:bg-emerald-900/10"></div>
                <div class="relative flex items-start justify-between mb-4">
                    <div class="text-xs font-bold text-slate-600 dark:text-slate-300">پاسخ داده شده</div>
                    <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center"><i class="fa-solid fa-check"></i></div>
                </div>
                <div class="relative text-center space-y-1">
                    <div class="text-2xl font-black text-slate-800 dark:text-white vazir-font">${toPersianNum(today.answered)}</div>
                    <div class="text-[10px] text-slate-400">${toPersianNum(answerRate)}٪ نرخ پاسخ</div>
                </div>
            </div>`;

            // کارت ۳: رد شده
            html += `
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-4 flex flex-col justify-between">
                <div class="flex items-start justify-between mb-4">
                    <div class="text-xs font-bold text-slate-600 dark:text-slate-300">رد شده</div>
                    <div class="w-8 h-8 rounded-xl bg-slate-50 text-slate-500 flex items-center justify-center"><i class="fa-solid fa-ban"></i></div>
                </div>
                <div class="text-center space-y-1">
                    <div class="text-2xl font-black text-slate-800 dark:text-white vazir-font">${toPersianNum(today.failed)}</div>
                    <div class="text-[10px] text-slate-400">تماس رد شده توسط اپراتور</div>
                </div>
            </div>`;

            // کارت ۴: مشغول و بی‌پاسخ
            html += `
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-4 flex flex-col justify-between">
                <div class="flex items-start justify-between mb-4">
                    <div class="text-xs font-bold text-slate-600 dark:text-slate-300">مشغول و بی‌پاسخ</div>
                    <div class="w-8 h-8 rounded-xl bg-orange-50 text-orange-500 flex items-center justify-center"><i class="fa-solid fa-phone-slash"></i></div>
                </div>
                <div class="text-center space-y-1">
                    <div class="text-2xl font-black text-slate-800 dark:text-white vazir-font">${toPersianNum(busyNoAnswer)}</div>
                    <div class="text-[10px] text-slate-400">مشغول / بدون پاسخ</div>
                </div>
            </div>`;

            // کارت ۵: مدت مکالمه
            html += `
            <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-4 flex flex-col justify-between">
                <div class="flex items-start justify-between mb-4">
                    <div class="text-xs font-bold text-slate-600 dark:text-slate-300">مدت مکالمه</div>
                    <div class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-500 flex items-center justify-center"><i class="fa-regular fa-clock"></i></div>
                </div>
                <div class="text-center space-y-1">
                    <div class="text-xl font-black text-slate-800 dark:text-white vazir-font" dir="rtl">${toPersianNum(avgTalk)} <span class="text-xs font-normal text-slate-400">ثانیه</span></div>
                    <div class="text-[10px] text-slate-400">میانگین - در هر تماس</div>
                </div>
            </div>`;

            document.getElementById('today-stats-cards').innerHTML = html;
            
            // رندر بخش تماس‌ها
            renderCallStatusCards(today);
            // رندر بخش داخلی‌ها
            renderExtStatusCards(currentData.extensions || []);
        }

// رندر کارت‌های وضعیت تماس‌ها
function renderCallStatusCards(today) {
    var html = '';
    
    // ✅ همه کارت‌ها حالا action و clickable دارند
    var cards = [
        { title: 'کل تماس امروز (Today)',     value: today.total,           icon: 'fa-clock',        bg: 'bg-purple-50', text: 'text-purple-500', color: 'bg-purple-400', action: 'today' },
        { title: 'تماس‌های داخلی',             value: today.internal || 0,   icon: 'fa-phone',        bg: 'bg-slate-100', text: 'text-slate-500',  color: 'bg-slate-400', action: 'internal' },
        { title: 'تماس‌های خروجی',             value: today.outbound || 0,   icon: 'fa-arrow-up',     bg: 'bg-pink-50',   text: 'text-pink-500',   color: 'bg-pink-400', action: 'outbound' },
        { title: 'تماس‌های ورودی',             value: today.inbound || 0,    icon: 'fa-arrow-down',   bg: 'bg-sky-50',    text: 'text-sky-500',    color: 'bg-sky-400', action: 'inbound' },
        { title: 'تماس‌های فعال',               value: currentData.active_calls || 0, icon: 'fa-wave-square', bg: 'bg-indigo-50', text: 'text-indigo-500', color: 'bg-indigo-400', action: 'active' },
        { title: 'ناموفق (Failed)',            value: today.failed,          icon: 'fa-circle-xmark', bg: 'bg-red-50',    text: 'text-red-500',    color: 'bg-red-400', action: 'failed' },
        { title: 'از دست رفته (Missed)',       value: today.missed,          icon: 'fa-phone-slash',  bg: 'bg-amber-50',  text: 'text-amber-500',  color: 'bg-amber-400', action: 'missed' },
        { title: 'پاسخ داده شده (Answered)',   value: today.answered,        icon: 'fa-check-circle', bg: 'bg-emerald-50', text: 'text-emerald-500', color: 'bg-emerald-400', action: 'answered' }
    ];

    cards.forEach(c => {
        html += `
        <div onclick="openCallStatusModal('${c.action}')" class="relative bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 rounded-2xl p-4 flex flex-col items-center justify-center text-center cursor-pointer hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-700 transition-all transform hover:-translate-y-1">
            <div class="absolute top-3 left-3 w-1.5 h-1.5 rounded-full ${c.color}"></div>
            <div class="text-[11px] font-bold text-slate-500 mb-2">${c.title}</div>
            <div class="w-10 h-10 rounded-full ${c.bg} ${c.text} flex items-center justify-center mb-2">
                <i class="fa-solid ${c.icon} text-base"></i>
            </div>
            <div class="text-xl font-black text-slate-800 dark:text-white vazir-font">${toPersianNum(c.value)}</div>
        </div>`;
    });
    document.getElementById('call-status-cards').innerHTML = html;
}

// ✅ تابع جدید برای باز کردن مودال جزئیات تمام کارت‌ها
function openCallStatusModal(type) {
    var modal = document.getElementById('extListModal');
    var card = document.getElementById('extListCard');
    var body = document.getElementById('extListBody');
    var pagContainer = document.getElementById('extListPagination');
    pagContainer.innerHTML = ''; // حذف صفحه‌بندی در این مودال
    
    var titles = {
        'today': 'تمام تماس‌های امروز',
        'internal': 'تماس‌های داخلی امروز',
        'outbound': 'تماس‌های خروجی امروز',
        'inbound': 'تماس‌های ورودی امروز',
        'active': 'تماس‌های فعال (هم‌اکنون)',
        'failed': 'تماس‌های ناموفق امروز',
        'missed': 'تماس‌های از دست رفته امروز',
        'answered': 'تماس‌های پاسخ داده شده امروز'
    };
    
    document.getElementById('extListTitle').innerText = titles[type] || 'جزئیات تماس‌ها';
    
    modal.classList.remove('hidden');
    setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);

    // اگر کارت «فعال» بود، از دیتای زنده استفاده می‌کنیم نه CDR
    if (type === 'active') {
        var activeDetails = currentData.active_calls_details || [];
        if (activeDetails.length === 0) {
            body.innerHTML = '<div class="text-center text-slate-400 text-xs py-6">هیچ تماس فعالی در حال حاضر وجود ندارد.</div>';
            return;
        }
        var html = '<div class="space-y-2">';
        activeDetails.forEach(item => {
            html += `<div class="bg-slate-50 dark:bg-slate-700/50 p-3 rounded-xl border border-slate-200 dark:border-slate-600 flex justify-between items-center">
                <div>
                    <div class="text-xs text-slate-500 mb-1">در حال تماس:</div>
                    <div class="font-bold text-sm text-slate-800 dark:text-white" dir="ltr">${toPersianNum(item.src)} <i class="fa-solid fa-arrow-left text-slate-400 mx-1"></i> ${toPersianNum(item.dst)}</div>
                </div>
            </div>`;
        });
        html += '</div>';
        body.innerHTML = html;
        return;
    }

    // برای بقیه کارت‌ها از CDR استفاده می‌کنیم
    body.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs"><i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i><br>در حال دریافت اطلاعات...</div>';

    fetch('?api=get_today_call_details&type=' + type)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                body.innerHTML = `<div class="text-rose-500 text-center text-xs font-bold py-4">خطا: ${data.error}</div>`;
                return;
            }
            var list = data.list || [];
            if (list.length === 0) {
                body.innerHTML = '<div class="text-center text-slate-400 text-xs py-6">هیچ تماسی برای این بخش در امروز ثبت نشده است.</div>';
                return;
            }
            
            var html = '<div class="space-y-2">';
            list.forEach(item => {
                var statusBadge = item.is_answered 
                    ? '<span class="bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded text-[10px] font-bold"><i class="fa-solid fa-check"></i> پاسخ</span>'
                    : '<span class="bg-rose-100 text-rose-700 px-2 py-0.5 rounded text-[10px] font-bold"><i class="fa-solid fa-xmark"></i> ناموفق</span>';

                html += `<div class="bg-slate-50 dark:bg-slate-700/50 p-3 rounded-xl border border-slate-200 dark:border-slate-600 flex justify-between items-center">
                    <div>
                        <div class="font-bold text-sm text-slate-800 dark:text-white" dir="ltr">${toPersianNum(item.src)} <i class="fa-solid fa-arrow-left text-slate-400 mx-1"></i> ${toPersianNum(item.dst)}</div>
                        <div class="text-[10px] text-slate-500 mt-1">${item.calldate} | مدت: ${toPersianNum(item.billsec)} ثانیه</div>
                    </div>
                    <div>${statusBadge}</div>
                </div>`;
            });
            html += '</div>';
            body.innerHTML = html;
        })
        .catch(e => {
            body.innerHTML = '<div class="text-rose-500 text-center text-xs font-bold py-4">خطا در ارتباط با سرور</div>';
        });
}
        function renderExtStatusCards(exts) {
            var total = exts.length;
            var connected = 0, disconnected = 0, busy = 0, ringing = 0, in_call = 0, dnd = 0;

            exts.forEach(e => {
                if (e.is_online) connected++; else disconnected++;
                if (e.is_busy) {
                    busy++;
                    var isTalking = e.calls && e.calls.some(c => c.state === 'در حال مکالمه');
                    if (isTalking) in_call++; else ringing++;
                }
            });

            // ✅ اضافه شدن action و clickable به کارت‌های مورد نظر
            var cards = [
                { title: 'کل داخلی‌ها', value: total, icon: 'fa-phone', bg: 'bg-purple-50', text: 'text-purple-500', color: 'bg-purple-400', clickable: true, action: 'all' },
                { title: 'دستگاه متصل', value: connected, icon: 'fa-phone', bg: 'bg-emerald-50', text: 'text-emerald-500', color: 'bg-emerald-400', clickable: true, action: 'connected' },
                { title: 'دستگاه قطع', value: disconnected, icon: 'fa-phone-slash', bg: 'bg-slate-100', text: 'text-slate-500', color: 'bg-slate-400', clickable: true, action: 'disconnected' },
                { title: 'مشغول (Busy)', value: busy, icon: 'fa-phone-slash', bg: 'bg-rose-50', text: 'text-rose-500', color: 'bg-rose-400', clickable: false },
{ title: 'در حال زنگ (Ring)', value: ringing, icon: 'fa-bell', bg: 'bg-amber-50', text: 'text-amber-500', color: 'bg-amber-400', clickable: false, customClick: "showActiveCallDetails('ringing')" },
{ title: 'در تماس (In Call)', value: in_call, icon: 'fa-wave-square', bg: 'bg-sky-50', text: 'text-sky-500', color: 'bg-sky-400', clickable: false, customClick: "showActiveCallDetails('in_call')" },
                { title: 'مراحل شنود (DND)', value: 0, icon: 'fa-phone-slash', bg: 'bg-fuchsia-50', text: 'text-fuchsia-500', color: 'bg-fuchsia-400', clickable: false }
            ];

            var html = '';
            cards.forEach(c => {
                // ✅ اگر کارت کلیک‌پذیر باشد، کلاس‌ها و onclick اضافه می‌شود
var clickAttr = c.customClick ? `onclick="${c.customClick}"` : (c.clickable ? `onclick="openExtListModal('${c.action}')"` : '');
var cursorClass = (c.customClick || c.clickable) ? 'cursor-pointer hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-700 transition-all transform hover:-translate-y-1' : '';
                
                html += `
                <div ${clickAttr} class="relative bg-white dark:bg-slate-800 border border-slate-100 dark:border-slate-700 rounded-2xl p-4 flex flex-col items-center justify-center text-center ${cursorClass}">
                    <div class="absolute top-3 left-3 w-1.5 h-1.5 rounded-full ${c.color}"></div>
                    <div class="text-[11px] font-bold text-slate-500 mb-2">${c.title}</div>
                    <div class="w-10 h-10 rounded-full ${c.bg} ${c.text} flex items-center justify-center mb-2">
                        <i class="fa-solid ${c.icon} text-base"></i>
                    </div>
                    <div class="text-xl font-black text-slate-800 dark:text-white vazir-font">${toPersianNum(c.value)}</div>
                </div>`;
            });
            document.getElementById('ext-status-cards').innerHTML = html;
        }

// ==============================================
// ✅ نمایش جزئیات تماس‌های Ring / In Call (بدون دخالت در توابع مشترک)
// ==============================================
function showActiveCallDetails(type) {
    var exts = (currentData.extensions || []);
    var targetState = (type === 'ringing') ? 'در حال زنگ خوردن' : 'در حال مکالمه';
    var title = (type === 'ringing') ? 'تماس‌های در حال زنگ خوردن' : 'تماس‌های در حال مکالمه';

    var items = [];
    exts.forEach(function(e) {
        if (!e.is_busy || !e.calls) return;
        e.calls.forEach(function(c) {
            if (c.state === targetState) {
                items.push({ ext: e.id, name: e.name, call: c });
            }
        });
    });

    document.getElementById('extListTitle').innerText = title + ' (' + toPersianNum(items.length) + ' مورد)';
    var body = document.getElementById('extListBody');
    var pagContainer = document.getElementById('extListPagination');
    pagContainer.innerHTML = '';

    if (items.length === 0) {
        body.innerHTML = '<div class="text-center text-slate-400 text-xs py-6">هیچ تماسی در این وضعیت وجود ندارد.</div>';
    } else {
        var stateBg    = (type === 'ringing') ? 'bg-amber-500' : 'bg-sky-500';
        var stateBadge = (type === 'ringing')
            ? 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300'
            : 'bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300';

        var html = '<div class="space-y-2">';
        items.forEach(function(item) {
            var call = item.call;
            html +=
            '<div class="bg-slate-50 dark:bg-slate-700/50 p-3 rounded-xl border border-slate-100 dark:border-slate-600">' +
                '<div class="flex items-center justify-between mb-2 pb-2 border-b border-slate-200 dark:border-slate-600">' +
                    '<div class="flex items-center gap-2 min-w-0">' +
                        '<span class="w-2 h-2 rounded-full ' + stateBg + ' animate-pulse flex-shrink-0"></span>' +
                        '<span class="font-black text-sm text-slate-800 dark:text-white vazir-font">' + toPersianNum(item.ext) + '</span>' +
                        '<span class="text-[10px] text-slate-500 dark:text-slate-400 truncate">' + (item.name || 'بدون نام') + '</span>' +
                    '</div>' +
                    '<span class="text-[9px] font-bold px-2 py-0.5 rounded whitespace-nowrap ' + stateBadge + '">' + targetState + '</span>' +
                '</div>' +
                '<div class="grid grid-cols-2 gap-2 text-[11px]">' +
                    '<div class="bg-white dark:bg-slate-800 p-2 rounded-lg border border-slate-100 dark:border-slate-700">' +
                        '<div class="text-slate-400 text-[9px] mb-1"><i class="fa-solid fa-arrow-down-left mr-0.5 text-emerald-500"></i> مبدا (Caller ID)</div>' +
                        '<div class="font-bold text-emerald-600 dark:text-emerald-400 vazir-font" dir="ltr">' + toPersianNum(call.caller_id || 'ناشناس') + '</div>' +
                    '</div>' +
                    '<div class="bg-white dark:bg-slate-800 p-2 rounded-lg border border-slate-100 dark:border-slate-700">' +
                        '<div class="text-slate-400 text-[9px] mb-1"><i class="fa-solid fa-arrow-up-right mr-0.5 text-amber-500"></i> مقصد (Destination)</div>' +
                        '<div class="font-bold text-amber-600 dark:text-amber-400 vazir-font" dir="ltr">' + toPersianNum(call.destination || 'نامشخص') + '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="mt-2 text-[10px] text-slate-500 flex justify-between items-center gap-2">' +
                    '<span class="whitespace-nowrap"><i class="fa-regular fa-clock mr-0.5"></i> مدت: ' + call.duration + '</span>' +
                    '<span class="font-mono text-[9px] text-slate-400 truncate" dir="ltr" title="' + call.channel + '">' + call.channel + '</span>' +
                '</div>' +
            '</div>';
        });
        html += '</div>';
        body.innerHTML = html;
    }

    var modal = document.getElementById('extListModal');
    var card  = document.getElementById('extListCard');
    modal.classList.remove('hidden');
    setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);
}

        function renderHourlyChart(cData) {
            if (!cData || !cData.labels) return;
            var options = {
                chart: { type: 'area', height: 240, toolbar: { show: false }, background: 'transparent' },
                series: [{ name: 'تعداد تماس', data: cData.values }],
                xaxis: { categories: cData.labels, labels: { style: { colors: '#94a3b8' } } },
                yaxis: { labels: { style: { colors: '#94a3b8' } } },
                stroke: { curve: 'smooth', width: 2 },
                colors: ['#6366f1'],
                fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
                dataLabels: { enabled: false },
                theme: { mode: document.documentElement.classList.contains('dark') ? 'dark' : 'light' }
            };
            if (hourlyChartInstance) hourlyChartInstance.updateOptions(options);
            else {
                hourlyChartInstance = new ApexCharts(document.querySelector("#hourlyChart"), options);
                hourlyChartInstance.render();
            }
        }

        function renderMonthlyChart(cData) {
            if (!cData || !cData.labels) return;
            var options = {
                chart: { type: 'bar', height: 240, toolbar: { show: false }, background: 'transparent' },
                series: [{ name: 'مجموع تماس‌ها', data: cData.values }],
                xaxis: { categories: cData.labels, labels: { style: { colors: '#94a3b8', fontSize: '10px' } } },
                yaxis: { labels: { style: { colors: '#94a3b8' } } },
                colors: ['#10b981'],
                plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
                dataLabels: { enabled: false },
                theme: { mode: document.documentElement.classList.contains('dark') ? 'dark' : 'light' }
            };
            if (monthlyChartInstance) monthlyChartInstance.updateOptions(options);
            else {
                monthlyChartInstance = new ApexCharts(document.querySelector("#monthlyChart"), options);
                monthlyChartInstance.render();
            }
        }
		
		function renderYearlyChart(cData) {
    if (!cData) return;
    var labels = cData.labels || [];
    var inbound = cData.inbound || [];
    var outbound = cData.outbound || [];

    document.getElementById('yearly-year-label').innerText = 'سال ' + toPersianNum(cData.year || '') + ' | ورودی: ' + toPersianNum(cData.total_inbound || 0) + ' | خروجی: ' + toPersianNum(cData.total_outbound || 0);

    var options = {
        chart: { 
            type: 'area', 
            height: 280, 
            toolbar: { show: false }, 
            background: 'transparent',
            fontFamily: 'Vazirmatn, sans-serif'
        },
        series: [
            { name: 'ورودی شهری', data: inbound },
            { name: 'خروجی شهری', data: outbound }
        ],
        xaxis: { 
            categories: labels, 
            labels: { style: { colors: '#94a3b8', fontSize: '11px' } } 
        },
        yaxis: { labels: { style: { colors: '#94a3b8' } } },
        stroke: { curve: 'smooth', width: 2 },
        colors: ['#eab308', '#94a3b8'],
        fill: { 
            type: 'gradient', 
            gradient: { 
                shadeIntensity: 1,
                opacityFrom: 0.45, 
                opacityTo: 0.05,
                stops: [0, 90, 100]
            } 
        },
        dataLabels: { enabled: false },
        markers: { size: 4, strokeWidth: 2, hover: { size: 6 } },
        legend: { show: false },
        theme: { mode: document.documentElement.classList.contains('dark') ? 'dark' : 'light' },
        tooltip: {
            y: { formatter: function(val) { return toPersianNum(val) + ' تماس'; } }
        }
    };

    if (yearlyChartInstance) {
        yearlyChartInstance.updateOptions(options);
    } else {
        yearlyChartInstance = new ApexCharts(document.querySelector("#yearlyChart"), options);
        yearlyChartInstance.render();
    }
}

function fetchYearlyChart() {
    var yearSelect = document.getElementById('yearlyYearSelect');
    var year = yearSelect ? yearSelect.value : '';
    var url = '?api=get_yearly_chart' + (year ? '&year=' + year : '');

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (!data.error) {
                    renderYearlyChart(data);
                }
            } catch(e) {}
        }
    };
    xhr.send();
}

// ==============================================
// ✅ تحلیل ترانک‌ها در طول سال
// ==============================================
var trunkColorPalette = [
    '#eab308', '#3b82f6', '#10b981', '#f43f5e',
    '#8b5cf6', '#f97316', '#06b6d4', '#84cc16',
    '#ec4899', '#14b8a6', '#a855f7', '#ef4444'
];

function fetchTrunkYearlyChart() {
    var yearSelect = document.getElementById('trunkChartYearSelect');
    var year = yearSelect ? yearSelect.value : '';
    var url = '?api=get_trunk_yearly_chart' + (year ? '&year=' + year : '');

    var footer = document.getElementById('trunk-chart-footer');
    footer.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> در حال دریافت اطلاعات...';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.error) {
                    footer.innerHTML = '<span style="color:#e11d48;">' + data.error + '</span>';
                    return;
                }
                lastTrunkData = data;
                renderTrunkYearlyChart(data);
            } catch(e) {
                // ✅ اگر پاسخ JSON نبود، محتوای خام را نشان بده تا باگ مشخص شود
                footer.innerHTML = '<span style="color:#e11d48;font-weight:800;">' +
                    '<i class="fa-solid fa-triangle-exclamation mr-1"></i>خطا در پردازش پاسخ سرور' +
                    '</span>' +
                    '<div style="font-family:monospace;font-size:9px;direction:ltr;text-align:left;' +
                    'margin-top:6px;opacity:0.75;max-height:80px;overflow:auto;padding:6px;' +
                    'background:rgba(0,0,0,0.05);border-radius:6px;">' +
                    xhr.responseText.substring(0, 600).replace(/</g, '&lt;') +
                    '</div>';
            }
        } else {
            footer.innerHTML = '<span style="color:#e11d48;">خطای HTTP: ' + xhr.status + '</span>';
        }
    };
    xhr.onerror = function() {
        footer.innerHTML = '<span style="color:#e11d48;">خطای شبکه - اتصال به سرور برقرار نشد</span>';
    };
    xhr.send();
}

function renderTrunkYearlyChart(data) {
    if (!data || !data.trunks) return;

    var modeSelect = document.getElementById('trunkChartModeSelect');
    var mode = modeSelect ? modeSelect.value : 'total';

    document.getElementById('trunk-chart-info').innerText =
        'سال ' + toPersianNum(data.year || '') + ' | ' + toPersianNum(data.total_trunks || 0) + ' ترانک';

    var footer = document.getElementById('trunk-chart-footer');
    var series = [];
    var colors = [];

    if (data.trunks.length === 0) {
        footer.innerText = 'هیچ تماس شهری برای این سال ثبت نشده است.';
        if (trunkYearlyChartInstance) {
            trunkYearlyChartInstance.destroy();
            trunkYearlyChartInstance = null;
        }
        return;
    }

    // ساخت سری‌ها بر اساس حالت انتخاب‌شده
    for (var i = 0; i < data.trunks.length; i++) {
        var t = data.trunks[i];
        var values = [];
        if (mode === 'total') {
            for (var m = 0; m < t.inbound.length; m++) {
                values.push(t.inbound[m] + t.outbound[m]);
            }
        } else if (mode === 'inbound') {
            values = t.inbound.slice();
        } else {
            values = t.outbound.slice();
        }

        var seriesName = t.name;
        if (mode === 'total') {
            seriesName += ' (کل: ' + t.total + ')';
        }

        series.push({ name: seriesName, data: values });
        colors.push(trunkColorPalette[i % trunkColorPalette.length]);
    }

    // محاسبه مجموع کل و پرکارترین ترانک
    var grandTotal = 0;
    var busiest = '';
    var busiestCount = 0;
    for (var j = 0; j < data.trunks.length; j++) {
        grandTotal += data.trunks[j].total;
        if (data.trunks[j].total > busiestCount) {
            busiestCount = data.trunks[j].total;
            busiest = data.trunks[j].name;
        }
    }

    var modeLabel = (mode === 'total') ? 'کل' : (mode === 'inbound' ? 'ورودی' : 'خروجی');
    footer.innerHTML = '<i class="fa-solid fa-circle-info mr-1"></i>' +
        'مجموع ' + modeLabel + ': ' + toPersianNum(grandTotal) + ' تماس | ' +
        'پرکارترین ترانک: <span style="color:#6b7d3f;font-weight:800;">' + busiest + '</span> (' + toPersianNum(busiestCount) + ') | ' +
        'برای مخفی/نمایش هر ترانک روی نام آن در پایین نمودار کلیک کنید';

    var options = {
        chart: {
            type: 'area',
            height: 320,
            toolbar: { show: false },
            background: 'transparent',
            fontFamily: 'Vazirmatn, sans-serif'
        },
        series: series,
        xaxis: {
            categories: data.labels,
            labels: { style: { colors: '#94a3b8', fontSize: '11px' } }
        },
        yaxis: { labels: { style: { colors: '#94a3b8' } } },
        stroke: { curve: 'smooth', width: 2 },
        colors: colors,
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.35,
                opacityTo: 0.03,
                stops: [0, 90, 100]
            }
        },
        dataLabels: { enabled: false },
        markers: { size: 3, strokeWidth: 2, hover: { size: 6 } },
        legend: {
            show: true,
            position: 'bottom',
            horizontalAlign: 'center',
            fontSize: '11px',
            fontWeight: 700,
            labels: { colors: '#64748b' },
            markers: { width: 10, height: 10, radius: 3 },
            itemMargin: { horizontal: 8, vertical: 4 }
        },
        theme: { mode: document.documentElement.classList.contains('dark') ? 'dark' : 'light' },
        tooltip: {
            y: { formatter: function(val) { return toPersianNum(val) + ' تماس'; } }
        }
    };

    if (trunkYearlyChartInstance) {
        trunkYearlyChartInstance.destroy();
    }
    trunkYearlyChartInstance = new ApexCharts(document.querySelector("#trunkYearlyChart"), options);
    trunkYearlyChartInstance.render();
}


                function renderExtensions(exts) {
            var container = document.getElementById('extensions-container');
            var searchInput = document.getElementById('extSearchInput');
            var searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
            
            // ✅ دریافت لیست ستاره‌دارها از حافظه مرورگر
            var favorites = JSON.parse(localStorage.getItem('favExts') || '[]');

            var onlineCount = 0; 
            var html = '';
            var displayedCount = 0;

            // ✅ مرتب‌سازی: ستاره‌دارها اول، بقیه بعد
            var sortedExts = exts.slice().sort(function(a, b) {
                var aFav = favorites.includes(String(a.id)) ? 1 : 0;
                var bFav = favorites.includes(String(b.id)) ? 1 : 0;
                if (aFav !== bFav) return bFav - aFav;
                return String(a.id).localeCompare(String(b.id), undefined, {numeric: true});
            });

            for (var i = 0; i < sortedExts.length; i++) {
                var ext = sortedExts[i];
                if (ext.is_online) onlineCount++;

                var extIdStr = String(ext.id).toLowerCase();
                var extNameStr = ext.name ? String(ext.name).toLowerCase() : '';
                var matchSearch = (extIdStr.includes(searchTerm)) || 
                                  (extNameStr.includes(searchTerm));
                
                if (searchTerm !== '' && !matchSearch) {
                    continue; 
                }
                displayedCount++;

                var isFav = favorites.includes(String(ext.id));
                var starIconClass = '';
                
                // ✅ تعیین رنگ ستاره بر اساس آنلاین یا آفلاین بودن
                if (ext.is_online) {
                    // اگر داخلی آنلاین است (پس‌زمینه سبز)
                    starIconClass = isFav ? 'fa-solid fa-star text-yellow-300' : 'fa-regular fa-star text-white opacity-80 hover:opacity-100';
                } else {
                    // اگر داخلی آفلاین است (پس‌زمینه خاکستری)
                    starIconClass = isFav ? 'fa-solid fa-star text-amber-500' : 'fa-regular fa-star text-slate-400 opacity-80 hover:opacity-100';
                }
                var starBtnTitle = isFav ? 'حذف از مورد علاقه' : 'افزودن به مورد علاقه';

            var bgClass = ext.is_online ? 'bg-emerald-500 text-white shadow-md border-emerald-600' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-600';
            var busyIcon = ext.is_busy ? '<button onclick="openModal(\'ext\', \'' + ext.id + '\')" class="absolute -top-2 -left-2 bg-amber-500 text-white w-6 h-6 rounded-full flex items-center justify-center text-[10px] shadow-lg animate-bounce border-2 border-white"><i class="fa-solid fa-phone-flip"></i></button>' : '';
            
            // ✅ بج نوع درایور
            var driverBadge = '';
            if (ext.driver && ext.driver !== 'نامشخص') {
                var badgeBg = ext.is_online ? 'bg-white/20 text-white' : 'bg-slate-300 dark:bg-slate-600 text-slate-700 dark:text-slate-300';
                driverBadge = '<span class="text-[9px] font-bold px-1.5 py-0.5 rounded mr-1 ' + badgeBg + '">' + ext.driver + '</span>';
            }

            html += '<div class="relative rounded-xl p-3 border transition-all ' + bgClass + '">' +
                    busyIcon +
                    '<div class="flex items-center justify-between mb-1">' +
                        '<div class="flex items-center">' +
                            '<span class="font-black text-base vazir-font">' + ext.id + '</span>' +
                            driverBadge +
                        '</div>' +
                        '<div class="flex items-center gap-1.5">' +
                                // ✅ دکمه ستاره
                                '<button onclick="toggleFavoriteExt(\'' + ext.id + '\')" class="opacity-80 hover:opacity-100 transition-opacity" title="' + starBtnTitle + '"><i class="' + starIconClass + ' text-xs"></i></button>' +
                                '<button onclick="openInfoModal(\'' + ext.id + '\')" class="opacity-70 hover:opacity-100 transition-opacity"><i class="fa-solid fa-circle-info text-xs"></i></button>' +
                                '<span class="w-2 h-2 rounded-full ' + (ext.is_online ? 'bg-white animate-pulse' : 'bg-slate-400') + '"></span>' +
                            '</div>' +
                        '</div>' +
                        '<div class="text-xs truncate font-medium ' + (ext.is_online ? 'text-emerald-100' : 'text-slate-500 dark:text-slate-400') + '">' + (ext.name || 'بدون نام') + '</div>' +
                    '</div>';
            }
            
            container.innerHTML = html;
            
            if (searchTerm !== '') {
                document.getElementById('ext-stats').innerText = displayedCount + ' یافت شد (' + onlineCount + ' آنلاین کل)';
            } else {
                document.getElementById('ext-stats').innerText = onlineCount + ' آنلاین از ' + exts.length + ' داخلی';
            }
        }

        // ==============================================
        // ✅ تابع ستاره‌دار کردن داخلی‌ها
        // ==============================================
        function toggleFavoriteExt(extId) {
            var favorites = JSON.parse(localStorage.getItem('favExts') || '[]');
            var strId = String(extId);
            
            var index = favorites.indexOf(strId);
            if (index > -1) {
                favorites.splice(index, 1); // اگر بود، حذف کن
            } else {
                favorites.push(strId); // اگر نبود، اضافه کن
            }
            
            // ذخیره در حافظه مرورگر
            localStorage.setItem('favExts', JSON.stringify(favorites));
            
            // رفرش فوری لیست داخلی‌ها بدون درخواست به سرور
            if (currentData && currentData.extensions) {
                renderExtensions(currentData.extensions);
            }
        }
		
        function openInfoModal(extId) {
    var ext = currentData.extensions.find(function(e) { return e.id == extId; });
    if (!ext) return;
    document.getElementById('infoTitle').innerText = 'اطلاعات داخلی ' + ext.id;
    var statusBadge = ext.is_online 
        ? '<span class="text-emerald-600 dark:text-emerald-400 font-bold">آنلاین (Registered)</span>' 
        : '<span class="text-rose-500 font-bold">آفلاین (Unreachable)</span>';

    // ✅ بخش رمز داخلی
    var secretValue = (ext.secret && ext.secret.length > 0) ? ext.secret : '-';
    var secretId = 'secret_' + ext.id;
    var secretHtml = '<div class="flex justify-between items-center border-b pb-2 border-slate-100 dark:border-slate-700">' +
            '<span>رمز داخلی (Secret):</span>' +
            '<div class="flex items-center gap-2">' +
                '<span id="' + secretId + '" class="font-bold vazir-font text-indigo-600 dark:text-indigo-400" ' +
                    'style="font-family:monospace;letter-spacing:1px;direction:ltr;" ' +
                    'data-secret="' + secretValue.replace(/"/g, '&quot;') + '" ' +
                    'data-visible="0">••••••</span>' +
                '<button type="button" onclick="toggleSecretVisibility(\'' + secretId + '\')" ' +
                    'class="text-slate-400 hover:text-indigo-600 transition-colors text-xs" title="نمایش / مخفی کردن">' +
                    '<i class="fa-solid fa-eye"></i>' +
                '</button>' +
                '<button type="button" onclick="copySecret(\'' + secretId + '\', this)" ' +
                    'class="text-slate-400 hover:text-emerald-600 transition-colors text-xs" title="کپی">' +
                    '<i class="fa-solid fa-copy"></i>' +
                '</button>' +
            '</div>' +
        '</div>';

    var bodyHtml = '<div class="space-y-3 font-medium">' +
        '<div class="flex justify-between border-b pb-2 border-slate-100 dark:border-slate-700"><span>نام داخلی:</span><span class="font-bold">' + (ext.name || '-') + '</span></div>' +
        '<div class="flex justify-between border-b pb-2 border-slate-100 dark:border-slate-700"><span>شماره داخلی:</span><span class="font-bold vazir-font">' + ext.id + '</span></div>' +
        '<div class="flex justify-between border-b pb-2 border-slate-100 dark:border-slate-700"><span>نوع درایور:</span><span class="font-bold text-indigo-600 dark:text-indigo-400">' + (ext.driver || 'نامشخص') + '</span></div>' +
        '<div class="flex justify-between border-b pb-2 border-slate-100 dark:border-slate-700"><span>آدرس IP دستگاه:</span><span class="font-bold vazir-font text-indigo-600 dark:text-indigo-400" dir="ltr">' + ext.ip + '</span></div>' +
        secretHtml +
        '<div class="flex justify-between"><span>وضعیت اتصال:</span>' + statusBadge + '</div>' +
    '</div>';

    document.getElementById('infoBody').innerHTML = bodyHtml;
    var modal = document.getElementById('infoModal');
    var card = document.getElementById('infoCard');
    modal.classList.remove('hidden');
    setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);
}

        function closeInfoModal() {
            var modal = document.getElementById('infoModal');
            var card = document.getElementById('infoCard');
            modal.classList.add('opacity-0'); card.classList.add('scale-95');
            setTimeout(function() { modal.classList.add('hidden'); }, 200);
        }
		
		// ✅ نمایش / مخفی کردن رمز داخلی
function toggleSecretVisibility(secretId) {
    var el = document.getElementById(secretId);
    if (!el) return;
    var isVisible = el.getAttribute('data-visible') === '1';
    var real = el.getAttribute('data-secret') || '';
    var btn = el.parentElement.querySelector('button i');

    if (isVisible) {
        el.innerText = '••••••';
        el.setAttribute('data-visible', '0');
        if (btn) btn.className = 'fa-solid fa-eye';
    } else {
        el.innerText = real;
        el.setAttribute('data-visible', '1');
        if (btn) btn.className = 'fa-solid fa-eye-slash';
    }
}

// ✅ کپی رمز در کلیپ‌بورد
function copySecret(secretId, btn) {
    var el = document.getElementById(secretId);
    if (!el) return;
    var real = el.getAttribute('data-secret') || '';
    if (!real || real === '-') return;

    var done = function() {
        var icon = btn.querySelector('i');
        if (icon) {
            var old = icon.className;
            icon.className = 'fa-solid fa-check';
            btn.classList.add('text-emerald-600');
            setTimeout(function() {
                icon.className = old;
                btn.classList.remove('text-emerald-600');
            }, 1200);
        }
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(real).then(done).catch(function() { fallbackCopy(real, done); });
    } else {
        fallbackCopy(real, done);
    }
}

function fallbackCopy(text, cb) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); if (cb) cb(); } catch(e) {}
    document.body.removeChild(ta);
}


        function renderQueues(queues) {
            var container = document.getElementById('queues-container');
            var html = '';
            if (!queues || queues.length === 0) {
                container.innerHTML = '<div class="col-span-full text-center py-4 text-slate-400 text-xs">هیچ صف تماسی پیکربندی نشده است.</div>';
                document.getElementById('queue-stats').innerText = '۰ صف فعال';
                return;
            }
            for (var i = 0; i < queues.length; i++) {
                var q = queues[i];
                var hasWaiting = q.waiting > 0;
                var badgeClass = hasWaiting ? 'bg-amber-500 text-white animate-pulse' : 'bg-slate-200 dark:bg-slate-600 text-slate-700 dark:text-slate-200';
                
                var agentsReady = q.agents_ready || 0;
                var agentsBusy = q.agents_busy || 0;

                html += '<div class="bg-slate-50 dark:bg-slate-700/50 rounded-xl p-4 border border-slate-200 dark:border-slate-600 shadow-sm transition-all flex flex-col justify-between">' +
                        '<div>' +
                            '<div class="flex items-center justify-between mb-3">' +
                                '<div>' +
                                    '<span class="font-extrabold text-sm text-slate-800 dark:text-white">' + q.name + '</span>' +
                                    '<span class="mr-2 text-xs vazir-font text-slate-400">(' + toPersianNum(q.id) + ')</span>' +
                                '</div>' +
                                '<span class="px-2.5 py-1 rounded-full text-xs font-bold vazir-font ' + badgeClass + '">' + toPersianNum(q.waiting) + ' تماس</span>' +
                            '</div>' +
                            
                            '<div class="grid grid-cols-2 gap-2 text-center text-xs font-medium pt-2 border-t border-slate-200 dark:border-slate-600">' +
                                '<div class="bg-white dark:bg-slate-800 p-2 rounded-lg border border-slate-100 dark:border-slate-700">' +
                                    '<div class="text-slate-400 text-[10px]">وضعیت اپراتورها</div>' +
                                    '<div class="mt-1 flex justify-center gap-1.5 text-[10px] font-bold vazir-font">' +
                                        '<span class="text-emerald-600" title="آزاد">' + toPersianNum(agentsReady) + ' آزاد</span> | ' +
                                        '<span class="text-amber-600" title="مشغول">' + toPersianNum(agentsBusy) + ' مشغول</span>' +
                                    '</div>' +
                                '</div>' +
                                
                                '<div class="bg-white dark:bg-slate-800 p-2 rounded-lg border border-slate-100 dark:border-slate-700">' +
                                    '<div class="text-slate-400 text-[10px]">آمار تماس‌ها</div>' +
                                    '<div class="mt-1 flex justify-center gap-1.5 text-[10px] font-bold vazir-font">' +
                                        '<span class="text-indigo-600" title="پاسخ داده شده">' + toPersianNum(q.completed) + ' پاسخ</span> | ' +
                                        '<span class="text-rose-600" title="رهاشده">' + toPersianNum(q.abandoned) + ' رهاشده</span>' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        // ✅ دکمه جدید مشاهده اپراتورها
                        '<button onclick="openQueueAgentsModal(\'' + q.id + '\')" class="mt-3 w-full bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:hover:bg-indigo-900/50 text-indigo-600 dark:text-indigo-300 py-2 rounded-lg text-[11px] font-bold transition-colors flex items-center justify-center gap-1.5">' +
                            '<i class="fa-solid fa-users"></i> مشاهده لیست اپراتورها (' + toPersianNum(q.agents_count) + ' نفر)' +
                        '</button>' +
                    '</div>';
            }
            container.innerHTML = html;
            document.getElementById('queue-stats').innerText = toPersianNum(queues.length) + ' صف پیکربندی‌شده';
        }

        // ==============================================
        // ✅ توابع مربوط به Modal اپراتورهای صف
        // ==============================================
                function openQueueAgentsModal(qId) {
            var q = null;
            for (var i = 0; i < currentData.queues.length; i++) {
                if (currentData.queues[i].id == qId) {
                    q = currentData.queues[i];
                    break;
                }
            }
            if (!q) return;

            document.getElementById('queueAgentsTitle').innerText = 'اپراتورهای صف ' + q.name + ' (' + q.id + ')';
            var body = document.getElementById('queueAgentsBody');
            
            var agents = q.agents_list || [];
            if (agents.length === 0) {
                body.innerHTML = '<div class="text-center text-slate-400 text-xs py-4">هیچ اپراتوری در این صف تعریف نشده است.</div>';
            } else {
                var html = '<div class="space-y-2">';
                for (var j = 0; j < agents.length; j++) {
                    var a = agents[j];
                    
                    var statusBadge = '';
                    if (a.status === 'آزاد') {
                        statusBadge = '<span class="bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 text-[10px] font-bold px-2 py-1 rounded-md">آزاد</span>';
                    } else if (a.status === 'مشغول') {
                        statusBadge = '<span class="bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 text-[10px] font-bold px-2 py-1 rounded-md animate-pulse">مشغول</span>';
                    } else if (a.status === 'مکث') {
                        statusBadge = '<span class="bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 text-[10px] font-bold px-2 py-1 rounded-md">مکث</span>';
                    } else {
                        statusBadge = '<span class="bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 text-[10px] font-bold px-2 py-1 rounded-md">' + a.status + '</span>';
                    }

                    // ✅ اضافه شدن بخش نمایش تعداد تماس‌ها
                    html += '<div class="flex items-center justify-between bg-slate-50 dark:bg-slate-700/50 p-3 rounded-xl border border-slate-100 dark:border-slate-600">' +
                                '<div class="flex items-center gap-3">' +
                                    '<div class="w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">' +
                                        '<i class="fa-solid fa-headset text-xs"></i>' +
                                    '</div>' +
                                    '<div>' +
                                        '<div class="font-black text-sm text-slate-700 dark:text-slate-200 vazir-font">' + toPersianNum(a.agent) + '</div>' +
                                        '<div class="text-[10px] text-slate-500 mt-0.5">پاسخ داده: <span class="font-bold text-emerald-600">' + toPersianNum(a.calls || 0) + ' تماس</span></div>' +
                                    '</div>' +
                                '</div>' +
                                '<div>' + statusBadge + '</div>' +
                            '</div>';
                }
                html += '</div>';
                body.innerHTML = html;
            }

            var modal = document.getElementById('queueAgentsModal');
            var card = document.getElementById('queueAgentsCard');
            modal.classList.remove('hidden');
            setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);
        }

        function closeQueueAgentsModal() {
            var modal = document.getElementById('queueAgentsModal');
            var card = document.getElementById('queueAgentsCard');
            modal.classList.add('opacity-0'); card.classList.add('scale-95');
            setTimeout(function() { modal.classList.add('hidden'); }, 200);
        }
        function renderTrunks(trunks) {
            var container = document.getElementById('trunks-container');
            var onlineCount = 0; var html = '';
            for (var i = 0; i < trunks.length; i++) {
                var trunk = trunks[i];
                if (trunk.is_online) onlineCount++;
                var bgClass = trunk.is_online ? 'bg-emerald-500 text-white shadow-md border-emerald-600' : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-600';
                var busyIcon = trunk.is_busy ? '<button onclick="openModal(\'trunk\', \'' + trunk.id + '\')" class="bg-amber-500 text-white px-2 py-1 rounded text-xs font-bold animate-pulse"><i class="fa-solid fa-phone"></i> فعال</button>' : '';

                html += '<div class="rounded-xl p-4 border flex items-center justify-between transition-all ' + bgClass + '">' +
                        '<div><div class="flex items-center gap-2"><span class="font-bold text-base">' + trunk.name + '</span><span class="text-[10px] uppercase font-black px-1.5 py-0.5 rounded bg-black/20">' + trunk.tech + '</span></div>' +
                        '<div class="text-xs mt-1 opacity-80">' + (trunk.is_online ? 'فعال' : 'قطع') + '</div></div>' +
                        '<div>' + busyIcon + '</div></div>';
            }
            container.innerHTML = html;
            document.getElementById('trunk-stats').innerText = onlineCount + ' آنلاین از ' + trunks.length + ' ترانک';
        }

            function renderCDRs(cdrs, error) {
    var container = document.getElementById('cdr-container');
    var mobContainer = document.getElementById('cdr-mobile-container');
    
    if (error) {
        container.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-rose-500 font-bold">' + error + '</td></tr>';
        mobContainer.innerHTML = '<div class="text-center py-4 text-rose-500 text-xs font-bold">' + error + '</div>';
        return;
    }
    if (!cdrs || cdrs.length === 0) {
        container.innerHTML = '<tr><td colspan="9" class="text-center py-4 text-slate-400">هیچ رکوردی یافت نشد.</td></tr>';
        mobContainer.innerHTML = '<div class="text-center py-4 text-slate-400 text-xs">هیچ رکوردی یافت نشد.</div>';
        return;
    }

    var html = ''; var mobHtml = '';
    for (var i = 0; i < cdrs.length; i++) {
        var cdr = cdrs[i];
        
        // ✅ تعیین رنگ و بج برای انواع تماس (داخلی، ورودی، خروجی، صف)
        var typeBadge = '<span class="bg-slate-100 dark:bg-slate-700 px-2 py-0.5 rounded text-xs font-bold">' + cdr.type + '</span>';
        if (cdr.type.indexOf('ورودی') !== -1) typeBadge = '<span class="bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded text-xs font-bold">' + cdr.type + '</span>';
        if (cdr.type.indexOf('خروجی') !== -1) typeBadge = '<span class="bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded text-xs font-bold">' + cdr.type + '</span>';
        if (cdr.type.indexOf('صف') !== -1) typeBadge = '<span class="bg-pink-100 dark:bg-pink-900/50 text-pink-700 dark:text-pink-300 px-2 py-0.5 rounded text-xs font-bold"><i class="fa-solid fa-layer-group text-[9px] mr-1"></i>' + cdr.type + '</span>';

        var trunkBadge = '<span class="bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded text-xs font-mono font-bold">' + cdr.trunk_name + '</span>';
        if (cdr.trunk_name !== '-') {
            trunkBadge = '<span class="bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 px-2 py-0.5 rounded text-xs font-mono font-bold">' + cdr.trunk_name + '</span>';
        }

        var callerExtBadge = '<span class="text-slate-300 dark:text-slate-600 text-xs">-</span>';
        if (cdr.caller_ext && cdr.caller_ext !== '-') {
            callerExtBadge = '<span class="bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800 px-2 py-0.5 rounded text-xs font-black vazir-font">' + toPersianNum(cdr.caller_ext) + '</span>';
        }

        var statusBadge = cdr.is_answered
            ? '<span class="bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 px-2.5 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fa-solid fa-check text-[10px]"></i> ' + cdr.disposition + '</span>'
            : '<span class="bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 px-2.5 py-1 rounded-full text-xs font-bold inline-flex items-center gap-1"><i class="fa-solid fa-xmark text-[10px]"></i> ' + cdr.disposition + '</span>';

        // ✅ بخش اصلاح شده: اضافه شدن دکمه دانلود در کنار پخش
        var audioBtn = '';
        if (cdr.has_audio) {
            audioBtn = '<div class="flex items-center justify-center gap-1.5">' +
                '<button onclick="playAudio(\'' + encodeURIComponent(cdr.recordingfile) + '\')" class="bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300 px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-100 transition-colors" title="پخش صوت"><i class="fa-solid fa-play text-[10px]"></i> پخش</button>' +
                '<a href="download.php?file=' + encodeURIComponent(cdr.recordingfile) + '" class="bg-emerald-50 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300 px-2.5 py-1 rounded-lg text-xs font-bold inline-flex items-center gap-1 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 transition-colors" title="دانلود فایل"><i class="fa-solid fa-download text-[10px]"></i></a>' +
            '</div>';
        } else {
            audioBtn = '<span class="text-slate-300 dark:text-slate-600 text-xs">-</span>';
        }

        html += '<tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors">' +
            '<td class="px-4 py-3 text-xs vazir-font" dir="ltr" style="text-align: right;">' + cdr.calldate + '</td>' +
            '<td class="px-4 py-3 font-bold text-xs">' + typeBadge + '</td>' +
            '<td class="px-4 py-3 text-xs font-bold">' + trunkBadge + '</td>' +
            '<td class="px-4 py-3 text-center">' + callerExtBadge + '</td>' +
            '<td class="px-4 py-3 font-bold vazir-font"><div class="flex items-center justify-center gap-1">' + toPersianNum(cdr.src) + (cdr.src.length > 4 && cdr.type !== 'داخلی' ? '<button onclick="blockNumber(\'' + cdr.src + '\')" class="text-rose-400 hover:text-rose-600 transition" title="مسدود کردن این شماره"><i class="fa-solid fa-ban text-xs"></i></button>' : '') + '</div></td>' +
            '<td class="px-4 py-3 font-bold vazir-font"><div class="flex items-center justify-center gap-1">' + toPersianNum(cdr.dst) + '<button onclick="openWebDialer(\'' + cdr.dst + '\')" class="text-emerald-500 hover:text-emerald-700 transition" title="تماس با این شماره"><i class="fa-solid fa-phone text-xs"></i></button></div></td>' +
            '<td class="px-4 py-3 text-xs vazir-font">' + cdr.billsec + ' ثانیه</td>' +
            '<td class="px-4 py-3 text-center">' + audioBtn + '</td>' +
            '<td class="px-4 py-3 text-center">' + statusBadge + '</td>' +
        '</tr>';

        mobHtml += '<div class="bg-slate-50 dark:bg-slate-700/40 p-3 rounded-xl border border-slate-200 dark:border-slate-600 space-y-2 text-xs">' +
            '<div class="flex justify-between"><span>تاریخ:</span><span class="vazir-font">' + cdr.calldate + '</span></div>' +
            '<div class="flex justify-between items-center"><span>خط / ترانک:</span>' + trunkBadge + '</div>' +
            '<div class="flex justify-between items-center"><span>داخلی تماس‌گیرنده:</span>' + callerExtBadge + '</div>' +
            '<div class="flex justify-between"><span>مبدا / مقصد:</span><span class="vazir-font font-bold">' + toPersianNum(cdr.src) + ' -> ' + toPersianNum(cdr.dst) + '</span></div>' +
            '<div class="flex justify-between items-center"><span>نوع و وضعیت:</span><div>' + typeBadge + ' ' + statusBadge + '</div></div>' +
            '<div class="flex justify-between items-center pt-2 border-t border-slate-200 dark:border-slate-600"><span>صدا:</span>' + audioBtn + '</div>' +
        '</div>';
    }
    container.innerHTML = html;
    mobContainer.innerHTML = mobHtml;
}
        function playAudio(filePath) {
            var player = document.getElementById('audioPlayer');
            var stick = document.getElementById('stickyAudioPlayer');
            document.getElementById('playerFileName').innerText = decodeURIComponent(filePath).split('/').pop();
            player.src = 'play.php?file=' + filePath;
            stick.classList.remove('translate-y-full');
            player.play();
        }

        function closeStickyPlayer() {
            var player = document.getElementById('audioPlayer');
            player.pause();
            document.getElementById('stickyAudioPlayer').classList.add('translate-y-full');
        }

        function renderPagination(p) {
            var container = document.getElementById('pagination-container');
            if (!p || p.total_records === 0) { container.innerHTML = ''; return; }
            document.getElementById('cdr-stats').innerText = 'نمایش ' + p.total_records + ' تماس (صفحه ' + p.current_page + ' از ' + p.total_pages + ')';
            var html = '';
            if (p.current_page > 1) html += '<button onclick="fetchLiveData(' + (p.current_page - 1) + ')" class="w-8 h-8 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex items-center justify-center text-xs"><i class="fa-solid fa-chevron-left"></i></button>';
            for (var i = Math.max(1, p.current_page - 2); i <= Math.min(p.total_pages, p.current_page + 2); i++) {
                var activeClass = (i === p.current_page) ? 'bg-indigo-600 text-white font-black' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300';
                html += '<button onclick="fetchLiveData(' + i + ')" class="w-8 h-8 rounded-lg text-xs ' + activeClass + '">' + i + '</button>';
            }
            if (p.current_page < p.total_pages) html += '<button onclick="fetchLiveData(' + (p.current_page + 1) + ')" class="w-8 h-8 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex items-center justify-center text-xs"><i class="fa-solid fa-chevron-right"></i></button>';
            container.innerHTML = html;
        }

        function handleSearch() { clearTimeout(searchTimeout); searchTimeout = setTimeout(function(){ fetchLiveData(1); }, 400); }

                function exportToExcel() {
            var searchVal = encodeURIComponent(document.getElementById('searchInput').value.trim());
            var startDate = document.getElementById('cdrStartDate').value.trim();
            var endDate = document.getElementById('cdrEndDate').value.trim();
            var onlyAudio = document.getElementById('cdrOnlyAudio').checked ? '1' : '0';
            var filterType = document.getElementById('cdrFilterType').value;
            var filterStatus = document.getElementById('cdrFilterStatus').value;
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '?api=get_status&page=1&search=' + searchVal + 
                           '&start_date=' + startDate + '&end_date=' + endDate + 
                           '&only_audio=' + onlyAudio + 
                           '&filter_type=' + filterType + 
                           '&filter_status=' + filterStatus, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (!data.cdrs || data.cdrs.length === 0) return alert('داده‌ای برای خروجی وجود ندارد.');
                        var dataToExport = data.cdrs.map(function(c) {
                            return { 'تاریخ': c.calldate, 'نوع تماس': c.type, 'خط/ترانک': c.trunk_name, 'داخلی تماس‌گیرنده': c.caller_ext, 'مبدا': c.src, 'مقصد': c.dst, 'مدت (ثانیه)': c.billsec, 'وضعیت': c.disposition };
                        });
                        var ws = XLSX.utils.json_to_sheet(dataToExport);
                        var wb = XLSX.utils.book_new();
                        XLSX.utils.book_append_sheet(wb, ws, "CDR Reports");
                        XLSX.writeFile(wb, "CDR_Report.xlsx");
                    } catch(e) {}
                }
            };
            xhr.send();
        }

        function openModal(type, id) {
            var modal = document.getElementById('callModal');
            var modalCard = document.getElementById('modalCard');
            var modalTitle = document.getElementById('modalTitle');
            var modalBody = document.getElementById('modalBody');

            var item = null;
            var list = (type === 'ext') ? currentData.extensions : currentData.trunks;
            for (var i = 0; i < list.length; i++) { if (list[i].id == id) { item = list[i]; break; } }
            if (!item || !item.calls || item.calls.length === 0) return;

            modalTitle.innerText = 'تماس‌های فعال (' + (item.name || item.id) + ')';
            var callsHtml = '';
            for (var j = 0; j < item.calls.length; j++) {
                var call = item.calls[j];
                var isActiveCall = (call.state === 'در حال مکالمه');
                var spyBtn = '';
                if (isActiveCall) {
                    spyBtn = '<button onclick="openSpyDialog(\'' + encodeURIComponent(call.channel) + '\', \'' + encodeURIComponent(item.name || item.id) + '\')" ' +
                             'class="mt-3 w-full bg-rose-600 hover:bg-rose-700 text-white px-3 py-2 rounded-lg text-xs font-bold flex items-center justify-center gap-1.5 transition-colors shadow-sm">' +
                             '<i class="fa-solid fa-headphones"></i><span>شنود زنده مکالمه (Spy)</span></button>';
                } else {
                    spyBtn = '<button disabled class="mt-3 w-full bg-slate-300 dark:bg-slate-600 text-slate-500 dark:text-slate-400 px-3 py-2 rounded-lg text-xs font-bold flex items-center justify-center gap-1.5 cursor-not-allowed">' +
                             '<i class="fa-solid fa-headphones"></i><span>شنود (فقط در حال مکالمه)</span></button>';
                }

                callsHtml += '<div class="bg-slate-50 dark:bg-slate-700/50 p-3 rounded-xl border border-slate-200 dark:border-slate-600 space-y-2 text-xs mb-2">' +
                    '<div class="flex justify-between"><span class="text-slate-400">تماس‌گیرنده:</span><span class="font-bold vazir-font">' + call.caller_id + '</span></div>' +
                    '<div class="flex justify-between"><span class="text-slate-400">کانال:</span><span class="vazir-font text-[10px]" dir="ltr">' + call.channel + '</span></div>' +
                    '<div class="flex justify-between"><span class="text-slate-400">مقصد:</span><span class="vazir-font">' + call.destination + '</span></div>' +
                    '<div class="flex justify-between"><span class="text-slate-400">وضعیت:</span><span class="font-bold text-indigo-600 dark:text-indigo-400">' + call.state + '</span></div>' +
                    '<div class="flex justify-between"><span class="text-slate-400">مدت:</span><span class="font-bold text-indigo-600 dark:text-indigo-400 vazir-font">' + call.duration + '</span></div>' +
                    spyBtn +
                '</div>';
            }
            modalBody.innerHTML = callsHtml;
            modal.classList.remove('hidden');
            setTimeout(function() { modal.classList.remove('opacity-0'); modalCard.classList.remove('scale-95'); }, 10);
        }

        function closeModal() {
            var modal = document.getElementById('callModal');
            var modalCard = document.getElementById('modalCard');
            modal.classList.add('opacity-0'); modalCard.classList.add('scale-95');
            setTimeout(function() { modal.classList.add('hidden'); }, 200);
        }

        var spyTargetChannel = '';

        function openSpyDialog(channelEncoded, targetName) {
            spyTargetChannel = channelEncoded;
            var resultBox = document.getElementById('spyResult');
            if (resultBox) resultBox.classList.add('hidden');
            
            var modal = document.getElementById('spyModal');
            var card = document.getElementById('spyCard');
            modal.classList.remove('hidden');
            setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);
            
            var savedExt = localStorage.getItem('spyAdminExt');
            if (savedExt) document.getElementById('spyAdminExt').value = savedExt;
            
            var savedMode = localStorage.getItem('spyMode') || 'q';
            var radios = document.getElementsByName('spyMode');
            for (var i = 0; i < radios.length; i++) {
                radios[i].checked = (radios[i].value === savedMode);
            }
        }

        function closeSpyDialog() {
            var modal = document.getElementById('spyModal');
            var card = document.getElementById('spyCard');
            modal.classList.add('opacity-0'); card.classList.add('scale-95');
            setTimeout(function() { modal.classList.add('hidden'); }, 200);
        }

        function performSpy() {
            var adminExt = document.getElementById('spyAdminExt').value.trim();
            var resultBox = document.getElementById('spyResult');
            if (!adminExt) {
                resultBox.className = 'text-xs font-bold text-rose-500 bg-rose-50 dark:bg-rose-900/30 p-2.5 rounded-lg';
                resultBox.innerText = 'لطفاً شماره داخلی خود را وارد کنید.';
                resultBox.classList.remove('hidden');
                return;
            }

            var mode = 'q';
            var radios = document.getElementsByName('spyMode');
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) { mode = radios[i].value; break; }
            }

            localStorage.setItem('spyAdminExt', adminExt);
            localStorage.setItem('spyMode', mode);

            resultBox.className = 'text-xs font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 p-2.5 rounded-lg';
            resultBox.innerText = 'در حال ارسال درخواست شنود...';
            resultBox.classList.remove('hidden');

            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'spy.php?channel=' + spyTargetChannel + 
                            '&admin_ext=' + encodeURIComponent(adminExt) + 
                            '&mode=' + encodeURIComponent(mode), true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var msg = xhr.responseText.trim();
                    if (msg.indexOf('خطا') === 0 || msg.indexOf('Error') === 0) {
                        resultBox.className = 'text-xs font-bold text-rose-500 bg-rose-50 dark:bg-rose-900/30 p-2.5 rounded-lg';
                    } else {
                        resultBox.className = 'text-xs font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 p-2.5 rounded-lg';
                    }
                    resultBox.innerHTML = msg.replace(/\n/g, '<br>');
                } else {
                    resultBox.className = 'text-xs font-bold text-rose-500 bg-rose-50 dark:bg-rose-900/30 p-2.5 rounded-lg';
                    resultBox.innerText = 'خطا در ارتباط با سرور.';
                }
            };
            xhr.onerror = function() {
                resultBox.className = 'text-xs font-bold text-rose-500 bg-rose-50 dark:bg-rose-900/30 p-2.5 rounded-lg';
                resultBox.innerText = 'خطای شبکه.';
            };
            xhr.send();
        }

        // ==============================================
        // ✅ آمار عملکرد داخلی‌ها
        // ==============================================
        var extStatsData = { by_calls: [], by_answered: [], by_talk: [], days: 30 };
        var currentExtTab = 'calls';
        var currentExtPeriod = 30;

        function setExtStatsPeriod(days, btn) {
            currentExtPeriod = days;
            // بروزرسانی دکمه‌های period
            var btns = document.querySelectorAll('.ext-period-btn');
            btns.forEach(function(b) {
                b.classList.remove('bg-white', 'dark:bg-slate-800', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm');
                b.classList.add('text-slate-500', 'dark:text-slate-400');
            });
            btn.classList.remove('text-slate-500', 'dark:text-slate-400');
            btn.classList.add('bg-white', 'dark:bg-slate-800', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm');
            // برچسب
            var lbl = document.getElementById('ext-stats-period');
            if (lbl) lbl.innerText = toPersianNum(days) + ' روز اخیر';
            fetchExtensionStats();
        }

        function setExtStatsTab(tab, btn) {
            currentExtTab = tab;
            var btns = document.querySelectorAll('.ext-tab-btn');
            btns.forEach(function(b) {
                b.classList.remove('bg-white', 'dark:bg-slate-800', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm');
                b.classList.add('text-slate-500', 'dark:text-slate-400');
            });
            btn.classList.remove('text-slate-500', 'dark:text-slate-400');
            btn.classList.add('bg-white', 'dark:bg-slate-800', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm');
            renderExtStats();
        }

        function fetchExtensionStats() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '?api=get_extension_stats&days=' + currentExtPeriod, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.error) {
                            document.getElementById('ext-stats-container').innerHTML = '<div class="text-center py-4 text-rose-500 text-xs font-bold">' + data.error + '</div>';
                            return;
                        }
                        extStatsData = data;
                        renderExtStats();
                    } catch(e) {}
                }
            };
            xhr.send();
        }

        function formatTalkTime(seconds) {
            seconds = parseInt(seconds) || 0;
            if (seconds < 60) return toPersianNum(seconds) + ' ث';
            var m = Math.floor(seconds / 60);
            if (m < 60) return toPersianNum(m) + ' د';
            var h = Math.floor(m / 60);
            var rm = m % 60;
            return toPersianNum(h) + ' س' + (rm > 0 ? ' ' + toPersianNum(rm) + ' د' : '');
        }

        function getMedalStyle(rank) {
            if (rank === 0) return { bg: 'linear-gradient(135deg, #f5c518 0%, #d4a017 100%)', color: '#fff', label: '۱' };
            if (rank === 1) return { bg: 'linear-gradient(135deg, #c0c0c0 0%, #9a9a9a 100%)', color: '#fff', label: '۲' };
            if (rank === 2) return { bg: 'linear-gradient(135deg, #cd7f32 0%, #a05e1f 100%)', color: '#fff', label: '۳' };
            return { bg: 'rgba(107,125,63,0.10)', color: '#6b7d3f', label: toPersianNum(rank + 1) };
        }

                function renderExtStats() {
            var container = document.getElementById('ext-stats-container');
            if (!container) return;

            var data = extStatsData[currentExtTab === 'calls' ? 'by_calls' : (currentExtTab === 'answered' ? 'by_answered' : 'by_talk')] || [];

            if (data.length === 0) {
                container.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs">هیچ داده‌ای برای نمایش وجود ندارد.</div>';
                return;
            }

            var maxVal = 1;
            for (var i = 0; i < data.length; i++) {
                var v = (currentExtTab === 'calls') ? data[i].total_calls :
                        (currentExtTab === 'answered' ? data[i].answered : data[i].total_talk);
                if (v > maxVal) maxVal = v;
            }

            var html = '<div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2">';
            for (var j = 0; j < data.length; j++) {
                var row = data[j];
                var medal = getMedalStyle(j);
                var value, unit, barColor;
                if (currentExtTab === 'calls') {
                    value = row.total_calls;
                    unit = 'تماس';
                    barColor = 'linear-gradient(90deg, #8b9a5b 0%, #6b7d3f 100%)';
                } else if (currentExtTab === 'answered') {
                    value = row.answered;
                    unit = 'پاسخ';
                    barColor = 'linear-gradient(90deg, #10b981 0%, #047857 100%)';
                } else {
                    value = row.total_talk;
                    unit = '';
                    barColor = 'linear-gradient(90deg, #6366f1 0%, #4338ca 100%)';
                }

                var pct = maxVal > 0 ? Math.max(6, Math.round((value / maxVal) * 100)) : 6;

                var valueLabel;
                if (currentExtTab === 'talk') {
                    valueLabel = formatTalkTime(value);
                } else {
                    valueLabel = toPersianNum(value) + ' <span class="text-[9px] text-slate-400 font-medium">' + unit + '</span>';
                }

                var extraTitle = 'کل: ' + row.total_calls + ' | پاسخ: ' + row.answered + ' | بی‌پاسخ: ' + row.missed + ' | نرخ پاسخ: ' + row.answer_rate + '٪ | میانگین: ' + row.avg_talk + ' ثانیه';

                // ✅ اضافه شدن onclick و کلاس hover برای کلیک‌پذیر شدن
                html += '<div class="flex items-center gap-2.5 py-1 px-2 cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-700/50 rounded-lg transition-colors" ' +
                    'onclick="openExtCallsModal(\'' + row.ext + '\', \'' + (row.name || '') + '\')" ' +
                    'title="' + extraTitle + ' (برای مشاهده جزئیات کلیک کنید)">' +
                    
                    '<div class="w-6 h-6 rounded-lg flex items-center justify-center text-[10px] font-black flex-shrink-0" ' +
                        'style="background:' + medal.bg + ';color:' + medal.color + ';">' + medal.label + '</div>' +
                    '<div class="flex-1 min-w-0">' +
                        '<div class="flex items-center justify-between gap-2 mb-1">' +
                            '<div class="flex items-center gap-1.5 min-w-0">' +
                                '<span class="font-black text-xs text-slate-800 dark:text-white vazir-font">' + toPersianNum(row.ext) + '</span>' +
                                '<span class="text-[10px] text-slate-400 truncate">' + (row.name || '').substring(0, 20) + '</span>' +
                            '</div>' +
                            '<span class="text-xs font-black text-slate-700 dark:text-slate-200 vazir-font whitespace-nowrap">' + valueLabel + '</span>' +
                        '</div>' +
                        '<div class="h-1.5 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">' +
                            '<div class="h-full rounded-full transition-all" style="width:' + pct + '%;background:' + barColor + ';"></div>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            }
            html += '</div>';

            html += '<div class="flex items-center justify-between gap-3 mt-3 pt-3 border-t border-slate-100 dark:border-slate-700 text-[10px] text-slate-400">' +
                '<span><i class="fa-solid fa-circle-info mr-1"></i>برای مشاهده جزئیات هر داخلی روی آن کلیک کنید</span>' +
                '<span>بر اساس ' + toPersianNum(extStatsData.days || 30) + ' روز اخیر</span>' +
            '</div>';

            container.innerHTML = html;
        }

                // ==============================================
        // ✅ تعداد تماس‌های پاسخ داده شده هر داخلی
        // ==============================================
        function fetchExtensionAnsweredCounts() {
            var yearSelect = document.getElementById('extAnsweredYearSelect');
            var year = yearSelect ? yearSelect.value : 'all';
            
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '?api=get_extension_answered_counts&year=' + year, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.error) {
                            document.getElementById('ext-answered-container').innerHTML =
                                '<div class="col-span-full text-center py-4 text-rose-500 text-xs font-bold">' + data.error + '</div>';
                            return;
                        }
                        renderExtensionAnsweredCounts(data);
                    } catch(e) {
                        console.error('API Error:', xhr.responseText);
                    }
                }
            };
            xhr.send();
        }

                    function renderExtensionAnsweredCounts(data) {
            var container = document.getElementById('ext-answered-container');
            if (!container) return;
            var list = data.list || [];
            
            if (list.length === 0) {
                container.innerHTML = '<div class="col-span-full text-center py-6 text-slate-400 text-xs">هیچ داده‌ای برای نمایش وجود ندارد.</div>';
                document.getElementById('ext-answered-stats').innerText = '۰ داخلی';
                return;
            }

            // دریافت سال انتخاب‌شده از منوی کشویی بالای بخش
            var currentSelectedYear = document.getElementById('extAnsweredYearSelect').value;

            var totalAll = 0;
            var html = '';
            
            for (var i = 0; i < list.length; i++) {
                var ext = list[i];
                totalAll += ext.year;

                var bgClass = ext.year > 0
                    ? 'bg-emerald-50 dark:bg-emerald-900/20 text-slate-700 dark:text-slate-200 border-emerald-200 dark:border-emerald-800'
                    : 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-600';

                // تمیز کردن نام برای جلوگیری از خطای جاوااسکریپت
                var safeName = (ext.name || 'بدون نام').replace(/'/g, "\\'").replace(/"/g, '&quot;');

                // ✅ استفاده از Template Literal برای جلوگیری از بهم ریختن HTML و متن دکمه
                html += `
                <div class="relative rounded-xl p-3 border transition-all flex flex-col justify-between ${bgClass}">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="font-black text-base vazir-font">${toPersianNum(ext.ext)}</span>
                            <i class="fa-solid fa-phone-volume text-xs opacity-60"></i>
                        </div>
                        <div class="text-xs truncate font-medium text-slate-500 dark:text-slate-400 mb-2">${ext.name || 'بدون نام'}</div>
                        
                        <div class="space-y-1 text-[10px] font-bold">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-400">امروز</span>
                                <span class="text-emerald-600 dark:text-emerald-400 font-black">${toPersianNum(ext.today)}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-400">۷ روز</span>
                                <span class="text-blue-600 dark:text-blue-400 font-black">${toPersianNum(ext.week)}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-400">ماه</span>
                                <span class="text-indigo-600 dark:text-indigo-400 font-black">${toPersianNum(ext.month)}</span>
                            </div>
                            
                            <div class="pt-1 mt-1 border-t border-slate-200/60 dark:border-slate-600/60 space-y-1">
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-400">ورودی</span>
                                    <span class="text-purple-600 dark:text-purple-400 font-black">${toPersianNum(ext.inbound)}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-400">خروجی</span>
                                    <span class="text-rose-600 dark:text-rose-400 font-black">${toPersianNum(ext.outbound)}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-400">داخلی</span>
                                    <span class="text-slate-500 dark:text-slate-400 font-black">${toPersianNum(ext.internal)}</span>
                                </div>
                                <div class="flex justify-between items-center pt-1 border-t border-slate-200/60 dark:border-slate-600/60">
                                    <span class="text-slate-600 dark:text-slate-300 font-black">کل</span>
                                    <span class="text-amber-600 dark:text-amber-400 font-black text-xs">${toPersianNum(ext.year)}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- دکمه مشاهده عملکرد با متن تضمین شده -->
                    <button onclick="openExtCallsModal('${ext.ext}', '${safeName}', '${currentSelectedYear}')" 
                        class="mt-3 w-full bg-white hover:bg-slate-50 dark:bg-slate-800 dark:hover:bg-slate-700 text-indigo-600 dark:text-indigo-400 border border-slate-200 dark:border-slate-600 py-2 rounded-lg text-[11px] font-bold transition-colors flex items-center justify-center gap-1.5 shadow-sm">
                        <i class="fa-solid fa-chart-line text-xs"></i>
                        <span>مشاهده عملکرد</span>
                    </button>
                </div>`;
            }
            container.innerHTML = html;
            
            document.getElementById('ext-answered-stats').innerText =
                toPersianNum(list.length) + ' داخلی | مجموع کل تماس‌ها: ' + toPersianNum(totalAll);
        }
		
		
		        // ==============================================
        // ✅ توابع مربوط به Modal تماس‌های داخلی (با صفحه‌بندی)
        // ==============================================
        var extModalCurrentExt = '';
        var extModalCurrentPage = 1;
        var extModalCurrentDays = 30;
        var extModalCurrentYear = '';

function openExtCallsModal(extId, extName, param) {
    extModalCurrentExt = extId;
    extModalCurrentPage = 1;
    
    var timeLabel = '';
    // اگر مقدار ارسال شده سال بود (مثل all یا 1403)
    if (typeof param === 'string' && (param === 'all' || param.length === 4)) {
        extModalCurrentYear = param;
        extModalCurrentDays = 0;
        timeLabel = (param === 'all') ? ' (کل زمان‌ها)' : ' (سال ' + param + ')';
    } else {
        // اگر مقدار ارسال شده تعداد روز بود
        extModalCurrentDays = param || currentExtPeriod;
        extModalCurrentYear = '';
        if (extModalCurrentDays >= 3650 || extModalCurrentDays == -1) timeLabel = ' (کل زمان‌ها)';
        else if (extModalCurrentDays >= 365) timeLabel = ' (سال اخیر)';
        else if (extModalCurrentDays >= 30) timeLabel = ' (۳۰ روز اخیر)';
        else if (extModalCurrentDays >= 7) timeLabel = ' (۷ روز اخیر)';
        else timeLabel = ' (امروز)';
    }
            
    document.getElementById('extCallsTitle').innerText = 'تماس‌های داخلی ' + extId + ' (' + (extName || 'بدون نام') + ')' + timeLabel;
            
    var modal = document.getElementById('extCallsModal');
    var card = document.getElementById('extCallsCard');
    modal.classList.remove('hidden');
    setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);

    fetchExtCallsModalData(1);
}

function getOpPeriodDays() {
    if (opCurrentPeriod === 'today') return 1;
    if (opCurrentPeriod === 'week')  return 7;
    if (opCurrentPeriod === 'month') return 30;
    if (opCurrentPeriod === 'year')  return 365;
    return 3650; // all
}

        function fetchExtCallsModalData(page) {
            extModalCurrentPage = page;
            document.getElementById('extCallsBody').innerHTML = '<div class="text-center py-8 text-slate-400 text-xs"><i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i><br>در حال دریافت اطلاعات...</div>';

            // ✅ ارسال پارامتر سال در صورت وجود، در غیر این صورت تعداد روز
            var url = '?api=get_extension_calls&ext=' + encodeURIComponent(extModalCurrentExt);
            if (extModalCurrentYear !== '') {
                url += '&year=' + encodeURIComponent(extModalCurrentYear);
            } else {
                url += '&days=' + extModalCurrentDays;
            }
            url += '&page=' + page;

            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.error) {
                            document.getElementById('extCallsBody').innerHTML = '<div class="text-center text-rose-500 font-bold text-xs py-4">' + data.error + '</div>';
                            return;
                        }
                        renderExtCallsModal(data);
                    } catch(e) {
                        console.error(xhr.responseText);
                        document.getElementById('extCallsBody').innerHTML = '<div class="text-center text-rose-500 font-bold text-xs py-4">خطا در پردازش پاسخ سرور</div>';
                    }
                }
            };
            xhr.send();
        }

                function renderExtCallsModal(data) {
            var body = document.getElementById('extCallsBody');
            var calls = data.calls || [];
            
            var html = '';

            // ✅ ۱. ساخت بخش آمار در بالای مدال
            if (data.stats) {
                var s = data.stats;
                
                // ساخت لیست مبداهای برتر
                var topSrcHtml = '';
                if (s.top_src && s.top_src.length > 0) {
                    s.top_src.forEach(function(item) {
                        topSrcHtml += '<div class="flex justify-between items-center text-[11px] mb-1.5 font-bold"><span class="vazir-font text-slate-700 dark:text-slate-300" dir="ltr">' + toPersianNum(item.src) + '</span> <span class="text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 px-1.5 rounded">' + toPersianNum(item.cnt) + ' بار</span></div>';
                    });
                } else {
                    topSrcHtml = '<div class="text-[10px] text-slate-400 text-center mt-2">داده‌ای یافت نشد</div>';
                }

                // ساخت لیست مقصدهای برتر
                var topDstHtml = '';
                if (s.top_dst && s.top_dst.length > 0) {
                    s.top_dst.forEach(function(item) {
                        topDstHtml += '<div class="flex justify-between items-center text-[11px] mb-1.5 font-bold"><span class="vazir-font text-slate-700 dark:text-slate-300" dir="ltr">' + toPersianNum(item.dst) + '</span> <span class="text-emerald-600 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/30 px-1.5 rounded">' + toPersianNum(item.cnt) + ' بار</span></div>';
                    });
                } else {
                    topDstHtml = '<div class="text-[10px] text-slate-400 text-center mt-2">داده‌ای یافت نشد</div>';
                }

                html += '<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-5">' +
                    // کارت آمار کلی
                    '<div class="bg-slate-50 dark:bg-slate-700/40 rounded-xl p-3 border border-slate-100 dark:border-slate-600 shadow-sm">' +
                        '<div class="text-[10px] font-black text-slate-400 mb-2 border-b border-slate-200 dark:border-slate-600 pb-1"><i class="fa-solid fa-chart-pie mr-1 text-indigo-500"></i> آمار کلی</div>' +
                        '<div class="flex justify-between text-xs mb-1.5"><span class="text-slate-500">پاسخ داده:</span> <span class="font-bold text-emerald-600 dark:text-emerald-400">' + toPersianNum(s.answered) + '</span></div>' +
                        '<div class="flex justify-between text-xs mb-1.5"><span class="text-slate-500">بی‌پاسخ / ناموفق:</span> <span class="font-bold text-rose-500">' + toPersianNum(s.failed) + '</span></div>' +
                        '<div class="flex justify-between text-xs"><span class="text-slate-500">کل زمان مکالمه:</span> <span class="font-bold text-indigo-600 dark:text-indigo-400">' + formatTalkTime(s.total_talk) + '</span></div>' +
                    '</div>' +
                    // کارت بیشترین مبدا
                    '<div class="bg-slate-50 dark:bg-slate-700/40 rounded-xl p-3 border border-slate-100 dark:border-slate-600 shadow-sm">' +
                        '<div class="text-[10px] font-black text-slate-400 mb-2 border-b border-slate-200 dark:border-slate-600 pb-1"><i class="fa-solid fa-arrow-down-left mr-1 text-purple-500"></i> بیشترین تماس‌گیرنده (مبدا)</div>' +
                        topSrcHtml +
                    '</div>' +
                    // کارت بیشترین مقصد
                    '<div class="bg-slate-50 dark:bg-slate-700/40 rounded-xl p-3 border border-slate-100 dark:border-slate-600 shadow-sm">' +
                        '<div class="text-[10px] font-black text-slate-400 mb-2 border-b border-slate-200 dark:border-slate-600 pb-1"><i class="fa-solid fa-arrow-up-right mr-1 text-rose-500"></i> بیشترین تماس‌گرفته (مقصد)</div>' +
                        topDstHtml +
                    '</div>' +
                '</div>';
            }

            // ✅ ۲. ساخت جدول تماس‌ها
            if (calls.length === 0) {
                html += '<div class="text-center text-slate-400 text-xs py-8">هیچ تماسی برای این داخلی در بازه انتخابی یافت نشد.</div>';
                body.innerHTML = html;
                return;
            }

            html += '<div class="overflow-x-auto border border-slate-200 dark:border-slate-700 rounded-xl"><table class="w-full text-xs text-right text-slate-600 dark:text-slate-300"><thead class="text-[10px] text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">' +
                '<tr><th class="px-3 py-3">تاریخ و زمان</th><th class="px-3 py-3">نوع تماس</th><th class="px-3 py-3">مبدا</th><th class="px-3 py-3">مقصد</th><th class="px-3 py-3">مدت مکالمه</th><th class="px-3 py-3 text-center">وضعیت</th></tr></thead><tbody>';

            for (var i = 0; i < calls.length; i++) {
                var c = calls[i];
                
                var typeBadge = '<span class="bg-slate-200 dark:bg-slate-600 px-2 py-0.5 rounded text-[10px] font-bold">' + c.type + '</span>';
                if (c.type === 'ورودی') typeBadge = '<span class="bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded text-[10px] font-bold">' + c.type + '</span>';
                if (c.type === 'خروجی') typeBadge = '<span class="bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 px-2 py-0.5 rounded text-[10px] font-bold">' + c.type + '</span>';

                var status = c.is_answered 
                    ? '<span class="text-emerald-600 dark:text-emerald-400 font-bold"><i class="fa-solid fa-check text-[9px]"></i> ' + c.disposition + '</span>' 
                    : '<span class="text-rose-500 font-bold"><i class="fa-solid fa-xmark text-[9px]"></i> ' + c.disposition + '</span>';

                html += '<tr class="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors">' +
                    '<td class="px-3 py-2.5 vazir-font" dir="ltr" style="text-align:right;">' + c.calldate + '</td>' +
                    '<td class="px-3 py-2.5">' + typeBadge + '</td>' +
                    '<td class="px-3 py-2.5 font-bold vazir-font">' + toPersianNum(c.src) + '</td>' +
                    '<td class="px-3 py-2.5 font-bold vazir-font">' + toPersianNum(c.dst) + '</td>' +
                    '<td class="px-3 py-2.5 vazir-font">' + toPersianNum(c.billsec) + ' ثانیه</td>' +
                    '<td class="px-3 py-2.5 text-center">' + status + '</td>' +
                '</tr>';
            }
            html += '</tbody></table></div>';

            // ✅ ۳. بخش صفحه‌بندی
            if (data.total_pages > 1) {
                html += '<div class="flex items-center justify-between mt-4">' +
                    '<div class="text-[10px] text-slate-500 font-bold">' +
                        'نمایش ' + toPersianNum(data.total_records) + ' تماس (صفحه ' + toPersianNum(data.current_page) + ' از ' + toPersianNum(data.total_pages) + ')' +
                    '</div>' +
                    '<div class="flex items-center gap-1 dir-ltr">';
                
                if (data.current_page > 1) {
                    html += '<button onclick="fetchExtCallsModalData(' + (data.current_page - 1) + ')" class="w-7 h-7 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex items-center justify-center text-xs hover:bg-slate-50"><i class="fa-solid fa-chevron-left"></i></button>';
                }
                
                for (var p = Math.max(1, data.current_page - 2); p <= Math.min(data.total_pages, data.current_page + 2); p++) {
                    var activeClass = (p === data.current_page) ? 'bg-indigo-600 text-white font-black border-indigo-600' : 'bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50';
                    html += '<button onclick="fetchExtCallsModalData(' + p + ')" class="w-7 h-7 rounded-lg text-[10px] ' + activeClass + '">' + toPersianNum(p) + '</button>';
                }
                
                if (data.current_page < data.total_pages) {
                    html += '<button onclick="fetchExtCallsModalData(' + (data.current_page + 1) + ')" class="w-7 h-7 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex items-center justify-center text-xs hover:bg-slate-50"><i class="fa-solid fa-chevron-right"></i></button>';
                }
                
                html += '</div></div>';
            } else {
                html += '<div class="text-center mt-3 pt-3 border-t border-slate-100 dark:border-slate-700 text-[10px] text-slate-400">' + toPersianNum(data.total_records) + ' تماس یافت شد</div>';
            }

            body.innerHTML = html;
        }

        function closeExtCallsModal() {
            var modal = document.getElementById('extCallsModal');
            var card = document.getElementById('extCallsCard');
            modal.classList.add('opacity-0'); card.classList.add('scale-95');
            setTimeout(function() { modal.classList.add('hidden'); }, 200);
        }

function escapeHtml(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// مسدود کردن از جدول CDR (با پرسیدن دلیل)
function blockNumber(number) {
    var reason = prompt('دلیل مسدود کردن شماره ' + number + ' را وارد کنید (اختیاری):', '');
    if (reason === null) return; // انصراف

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            alert(res.success ? res.message : ('خطا: ' + res.message));
            if (res.success) fetchLiveData(currentPage);
        } catch(e) {
            alert('خطا در پردازش پاسخ سرور.');
        }
    };
    xhr.send('api=add_to_blacklist&number=' + encodeURIComponent(number) + '&reason=' + encodeURIComponent(reason));
}



// ==============================================
// ✅ مدیریت لیست سیاه
// ==============================================
var blCurrentPage = 1;
var blSearchTimeout = null;

function openBlacklistModal() {
    var menu = document.getElementById('hamburgerMenu');
    if (menu) menu.classList.add('hidden');

    document.getElementById('blResult').classList.add('hidden');
    document.getElementById('blSearchInput').value = '';
    var modal = document.getElementById('blacklistModal');
    var card = document.getElementById('blacklistCard');
    modal.classList.remove('hidden');
    setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);
    fetchBlacklist(1);
}

function closeBlacklistModal() {
    var modal = document.getElementById('blacklistModal');
    var card = document.getElementById('blacklistCard');
    modal.classList.add('opacity-0'); card.classList.add('scale-95');
    setTimeout(function() { modal.classList.add('hidden'); }, 200);
}

function handleBlacklistSearch() {
    clearTimeout(blSearchTimeout);
    blSearchTimeout = setTimeout(function() { fetchBlacklist(1); }, 400);
}

function showBlResult(ok, msg) {
    var box = document.getElementById('blResult');
    box.className = 'text-xs font-bold p-2.5 rounded-lg ' +
        (ok ? 'text-emerald-700 bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-300'
            : 'text-rose-600 bg-rose-50 dark:bg-rose-900/30 dark:text-rose-300');
    box.innerText = msg;
}

function fetchBlacklist(page) {
    blCurrentPage = page || 1;
    var search = encodeURIComponent(document.getElementById('blSearchInput').value.trim());
    var body = document.getElementById('blacklistBody');
    body.innerHTML = '<tr><td colspan="3" class="text-center py-6 text-slate-400">در حال دریافت...</td></tr>';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '?api=get_blacklist&page=' + blCurrentPage + '&search=' + search, true);
    xhr.onload = function() {
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.error) {
                body.innerHTML = '<tr><td colspan="3" class="text-center py-6 text-rose-500 font-bold">' + escapeHtml(data.error) + '</td></tr>';
                return;
            }
            renderBlacklist(data);
        } catch(e) {
            body.innerHTML = '<tr><td colspan="3" class="text-center py-6 text-rose-500 font-bold">خطا در پردازش پاسخ سرور</td></tr>';
        }
    };
    xhr.send();
}

function renderBlacklist(data) {
    var body = document.getElementById('blacklistBody');
    var list = data.list || [];
    document.getElementById('blacklistTotal').innerText = toPersianNum(data.total || 0) + ' شماره';

    if (list.length === 0) {
        body.innerHTML = '<tr><td colspan="3" class="text-center py-6 text-slate-400">هیچ شماره‌ای در لیست سیاه یافت نشد.</td></tr>';
    } else {
        var html = '';
        for (var i = 0; i < list.length; i++) {
            var it = list[i];
            html += '<tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors">' +
                '<td class="px-3 py-2.5 font-bold vazir-font" dir="ltr" style="text-align:right;">' + escapeHtml(it.number) + '</td>' +
                '<td class="px-3 py-2.5 text-slate-500 dark:text-slate-400">' + escapeHtml(it.description || '-') + '</td>' +
                '<td class="px-3 py-2.5 text-center">' +
                    '<button data-number="' + escapeHtml(it.number) + '" onclick="removeBlacklist(this.dataset.number)" ' +
                        'class="bg-emerald-50 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 px-2.5 py-1 rounded-lg text-[10px] font-bold transition-colors">' +
                        '<i class="fa-solid fa-unlock mr-1"></i>حذف از لیست' +
                    '</button>' +
                '</td>' +
            '</tr>';
        }
        body.innerHTML = html;
    }

    var pag = document.getElementById('blacklistPagination');
    if (data.total_pages > 1) {
        var p = '<div class="text-[10px] text-slate-400 font-bold">صفحه ' + toPersianNum(data.current_page) + ' از ' + toPersianNum(data.total_pages) + '</div><div class="flex gap-1">';
        if (data.current_page > 1) {
            p += '<button onclick="fetchBlacklist(' + (data.current_page - 1) + ')" class="w-7 h-7 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 flex items-center justify-center text-xs"><i class="fa-solid fa-chevron-right"></i></button>';
        }
        if (data.current_page < data.total_pages) {
            p += '<button onclick="fetchBlacklist(' + (data.current_page + 1) + ')" class="w-7 h-7 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 flex items-center justify-center text-xs"><i class="fa-solid fa-chevron-left"></i></button>';
        }
        p += '</div>';
        pag.innerHTML = p;
    } else {
        pag.innerHTML = '';
    }
}

function addBlacklistFromModal() {
    var number = document.getElementById('blNewNumber').value.trim();
    var reason = document.getElementById('blNewReason').value.trim();
    if (!number) { showBlResult(false, 'شماره را وارد کنید.'); return; }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            showBlResult(res.success, res.message);
            if (res.success) {
                document.getElementById('blNewNumber').value = '';
                document.getElementById('blNewReason').value = '';
                fetchBlacklist(1);
            }
        } catch(e) { showBlResult(false, 'خطا در پردازش پاسخ سرور.'); }
    };
    xhr.send('api=add_to_blacklist&number=' + encodeURIComponent(number) + '&reason=' + encodeURIComponent(reason));
}

function removeBlacklist(number) {
    if (!confirm('شماره ' + number + ' از لیست سیاه حذف شود؟\nتماس‌های این شماره دوباره برقرار خواهند شد.')) return;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            showBlResult(res.success, res.message);
            if (res.success) fetchBlacklist(blCurrentPage);
        } catch(e) { showBlResult(false, 'خطا در پردازش پاسخ سرور.'); }
    };
    xhr.send('api=remove_from_blacklist&number=' + encodeURIComponent(number));
}
		
		        // ==============================================
        // ✅ توابع تماس کلیک‌ای (Web Dialer)
        // ==============================================
        var currentDialDest = '';

        function openWebDialer(destNumber) {
            // اگر شماره داخلی بود، نیازی به زنگ زدن نیست
            if (destNumber.length <= 4 && destNumber !== '-') {
                alert('برای تماس با داخلی‌ها، از گوشی خود استفاده کنید.');
                return;
            }

            currentDialDest = destNumber;
            document.getElementById('dialerDestNumber').innerText = destNumber;
            
            var resultBox = document.getElementById('dialerResult');
            resultBox.classList.add('hidden');

            // بازیابی داخلی قبلی از حافظه مرورگر
            var savedExt = localStorage.getItem('webDialerExt');
            if (savedExt) document.getElementById('dialerExtInput').value = savedExt;

            var modal = document.getElementById('webDialerModal');
            var card = document.getElementById('webDialerCard');
            modal.classList.remove('hidden');
            setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);
            
            // فوکوس روی باکس داخلی
            setTimeout(function() { document.getElementById('dialerExtInput').focus(); }, 300);
        }

        function closeWebDialerModal() {
            var modal = document.getElementById('webDialerModal');
            var card = document.getElementById('webDialerCard');
            modal.classList.add('opacity-0'); card.classList.add('scale-95');
            setTimeout(function() { modal.classList.add('hidden'); }, 200);
        }

        function performWebCall() {
            var ext = document.getElementById('dialerExtInput').value.trim();
            var resultBox = document.getElementById('dialerResult');

            if (!ext) {
                resultBox.className = 'text-xs font-bold text-rose-500 bg-rose-50 dark:bg-rose-900/30 p-2.5 rounded-lg';
                resultBox.innerText = 'لطفاً شماره داخلی خود را وارد کنید.';
                resultBox.classList.remove('hidden');
                return;
            }

            // ذخیره داخلی برای استفاده‌های بعدی
            localStorage.setItem('webDialerExt', ext);

            resultBox.className = 'text-xs font-bold text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 p-2.5 rounded-lg';
            resultBox.innerText = 'در حال ارسال دستور تماس...';
            resultBox.classList.remove('hidden');

            var xhr = new XMLHttpRequest();
            xhr.open('GET', '?api=originate_call&ext=' + encodeURIComponent(ext) + '&dest=' + encodeURIComponent(currentDialDest), true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            resultBox.className = 'text-xs font-bold text-emerald-600 bg-emerald-50 dark:bg-emerald-900/30 p-2.5 rounded-lg';
                            resultBox.innerHTML = '<i class="fa-solid fa-phone-volume animate-pulse mr-1"></i> ' + res.message;
                        } else {
                            resultBox.className = 'text-xs font-bold text-rose-500 bg-rose-50 dark:bg-rose-900/30 p-2.5 rounded-lg';
                            resultBox.innerText = res.message;
                        }
                    } catch(e) {
                        resultBox.className = 'text-xs font-bold text-rose-500 bg-rose-50 dark:bg-rose-900/30 p-2.5 rounded-lg';
                        resultBox.innerText = 'خطا در پردازش پاسخ سرور.';
                    }
                }
            };
            xhr.send();
        }
	

// ✅ setInterval که وقتی تب مخفی است اجرا نمی‌شود
function safeInterval(fn, ms) {
    return setInterval(function() {
        if (document.hidden) return;
        try { fn(); } catch(e) { console.warn('interval error:', e); }
    }, ms);
}
	
// ==============================================
// ✅ راه‌اندازی اولیه و اینتروال‌ها (بعد از آماده شدن DOM)
// ==============================================
document.addEventListener('DOMContentLoaded', function() {
    safeInterval(function() { fetchYearlyChart(); }, 120000);
    safeInterval(function() { fetchTrunkYearlyChart(); }, 300000);
    safeInterval(function() { fetchLiveData(currentPage); }, 5000);
    safeInterval(function() { fetchSystemStats(); }, 4000);
    safeInterval(function() { fetchNetworkConfig(); }, 15000);
    safeInterval(function() { fetchExtensionStats(); }, 60000);
    safeInterval(function() { fetchExtensionAnsweredCounts(); }, 60000);
    safeInterval(function() { fetchOperatorPerformance(); }, 30000);
	   // ✅ اضافه کردن اینتروال‌های جدید
    safeInterval(function() { fetchHeatmap(); }, 300000);          // هر ۵ دقیقه
    safeInterval(function() { fetchPeriodComparison(); }, 60000);  // هر ۱ دقیقه
    safeInterval(function() { fetchMissedCalls(1); }, 60000);      // هر ۱ دقیقه
    safeInterval(function() { fetchCallbackList(); }, 120000);     // هر ۲ دقیقه
	safeInterval(function() { fetchParkedCalls(); }, 5000); // هر ۵ ثانیه بررسی پارک

    // اجرای اولیه
    if (!document.hidden) {
        fetchYearlyChart();
        fetchOperatorPerformance();
        fetchLiveData(1);
        fetchSystemStats();
        fetchNetworkConfig();
        fetchExtensionStats();
        fetchExtensionAnsweredCounts();
		        fetchHeatmap();
        fetchPeriodComparison();
        fetchMissedCalls(1);
        fetchCallbackList();
		fetchParkedCalls(); // اجرای اولیه
    }
});

document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        fetchLiveData(currentPage);
        fetchSystemStats();
    }
});


		
		        // ✅ تابع تغییر بازه زمانی آمار
        function setStatsPeriod(period, btnElement) {
            currentStatsPeriod = period;
            
            // غیرفعال کردن استایل همه دکمه‌ها
            var allBtns = document.querySelectorAll('.stats-period-btn');
            allBtns.forEach(function(b) {
                b.classList.remove('bg-white', 'dark:bg-slate-800', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm', 'bg-indigo-600', 'text-white');
                if(period === 'all') {
                    b.classList.add('bg-slate-100', 'dark:bg-slate-700', 'text-slate-600', 'dark:text-slate-300');
                } else {
                    b.classList.add('text-slate-500', 'dark:text-slate-400');
                }
            });

            // فعال کردن استایل دکمه کلیک شده
            if(period === 'all') {
                btnElement.classList.remove('bg-slate-100', 'dark:bg-slate-700', 'text-slate-600', 'dark:text-slate-300');
                btnElement.classList.add('bg-indigo-600', 'text-white');
            } else {
                btnElement.classList.remove('text-slate-500', 'dark:text-slate-400');
                btnElement.classList.add('bg-white', 'dark:bg-slate-800', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm');
            }

            // دریافت مجدد داده‌ها از سرور
            fetchLiveData(1); 
			            fetchOperatorPerformance(); // آپدیت عملکرد اپراتورها با تغییر بازه
        }
		
		 // ==============================================
        // ✅ صفحه‌بندی مدال داخلی‌ها
        // ==============================================
        var extListCurrentPage = 1;
        var extListFilteredData = [];
        var extListItemsPerPage = 5;

        function openExtListModal(type) {
            var exts = currentData.extensions || [];
            var title = '';

            if (type === 'all') {
                extListFilteredData = exts;
                title = 'کل داخلی‌ها';
            } else if (type === 'connected') {
                extListFilteredData = exts.filter(e => e.is_online);
                title = 'دستگاه‌های متصل';
            } else if (type === 'disconnected') {
                extListFilteredData = exts.filter(e => !e.is_online);
                title = 'دستگاه‌های قطع';
            }

            document.getElementById('extListTitle').innerText = title + ' (' + toPersianNum(extListFilteredData.length) + ' مورد)';
            extListCurrentPage = 1;
            renderExtListModalBody();

            var modal = document.getElementById('extListModal');
            var card = document.getElementById('extListCard');
            modal.classList.remove('hidden');
            setTimeout(function() { modal.classList.remove('opacity-0'); card.classList.remove('scale-95'); }, 10);
        }

        function renderExtListModalBody() {
            var body = document.getElementById('extListBody');
            var pagContainer = document.getElementById('extListPagination');
            var totalItems = extListFilteredData.length;

            if (totalItems === 0) {
                body.innerHTML = '<div class="text-center text-slate-400 text-xs py-4">هیچ داخلی در این وضعیت وجود ندارد.</div>';
                pagContainer.innerHTML = '';
                return;
            }

            var totalPages = Math.ceil(totalItems / extListItemsPerPage);
            if (extListCurrentPage > totalPages) extListCurrentPage = totalPages;
            
            var startIndex = (extListCurrentPage - 1) * extListItemsPerPage;
            var endIndex = Math.min(startIndex + extListItemsPerPage, totalItems);
            var pageData = extListFilteredData.slice(startIndex, endIndex);

            var html = '<div class="grid grid-cols-2 gap-2">';
            pageData.forEach(e => {
                var statusDot = e.is_online ? '<span class="w-2 h-2 rounded-full bg-emerald-500"></span>' : '<span class="w-2 h-2 rounded-full bg-rose-500"></span>';
                var statusText = e.is_online ? 'متصل' : 'قطع';
                var statusColor = e.is_online ? 'text-emerald-600' : 'text-rose-600';
                
                html += `
                <div onclick="closeExtListModal(); openInfoModal('${e.id}');" class="cursor-pointer bg-slate-50 dark:bg-slate-700/50 hover:bg-slate-100 dark:hover:bg-slate-700 p-2.5 rounded-xl border border-slate-100 dark:border-slate-600 flex flex-col items-center justify-center gap-1 transition-colors">
                    <div class="flex items-center gap-1.5">
                        ${statusDot}
                        <span class="font-black text-sm text-slate-800 dark:text-white vazir-font">${toPersianNum(e.id)}</span>
                    </div>
                    <div class="text-[10px] text-slate-500 dark:text-slate-400 truncate w-full text-center" title="${e.name || 'بدون نام'}">${e.name || 'بدون نام'}</div>
                    <div class="text-[9px] font-bold ${statusColor}">${statusText}</div>
                </div>`;
            });
            html += '</div>';
            body.innerHTML = html;

            // ساخت دکمه‌های صفحه‌بندی
            if (totalPages > 1) {
                var pagHtml = `<div class="text-[10px] text-slate-400 font-bold">صفحه ${toPersianNum(extListCurrentPage)} از ${toPersianNum(totalPages)}</div>
                               <div class="flex gap-1">`;
                if (extListCurrentPage > 1) {
                    pagHtml += `<button onclick="changeExtListPage(${extListCurrentPage - 1})" class="w-7 h-7 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 flex items-center justify-center text-xs hover:bg-slate-100 dark:hover:bg-slate-600 transition"><i class="fa-solid fa-chevron-right"></i></button>`;
                }
                if (extListCurrentPage < totalPages) {
                    pagHtml += `<button onclick="changeExtListPage(${extListCurrentPage + 1})" class="w-7 h-7 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 flex items-center justify-center text-xs hover:bg-slate-100 dark:hover:bg-slate-600 transition"><i class="fa-solid fa-chevron-left"></i></button>`;
                }
                pagHtml += `</div>`;
                pagContainer.innerHTML = pagHtml;
            } else {
                pagContainer.innerHTML = '';
            }
        }

        function changeExtListPage(page) {
            extListCurrentPage = page;
            renderExtListModalBody();
        }

        function closeExtListModal() {
            var modal = document.getElementById('extListModal');
            var card = document.getElementById('extListCard');
            modal.classList.add('opacity-0'); card.classList.add('scale-95');
            setTimeout(function() { modal.classList.add('hidden'); }, 200);
        }


        // ==============================================
        // ✅ فیلتر زمانی عملکرد اپراتورها
        // ==============================================
        var opCurrentPeriod = 'today';

        function setOpPeriod(period, btnElement) {
            opCurrentPeriod = period;
            
            // تغییر استایل دکمه‌ها
            var allBtns = document.querySelectorAll('.op-period-btn');
            allBtns.forEach(function(b) {
                b.classList.remove('bg-white', 'dark:bg-slate-700', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm');
                b.classList.add('text-slate-500', 'dark:text-slate-400');
            });
            btnElement.classList.remove('text-slate-500', 'dark:text-slate-400');
            btnElement.classList.add('bg-white', 'dark:bg-slate-700', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm');
            
            // دریافت مجدد اطلاعات
            fetchOperatorPerformance();
        }

	        // ==============================================
        // ✅ توابع بخش عملکرد اپراتورها (با صفحه‌بندی)
        // ==============================================
        var currentOpTab = 'calls';
        var operatorData = [];
        var opCurrentPage = 1;
        var opItemsPerPage = 5; // ✅ تعداد ردیف در هر صفحه

        function fetchOperatorPerformance() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '?api=get_operator_performance&period=' + opCurrentPeriod, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.error) {
                            document.getElementById('op-table-body').innerHTML = '<tr><td colspan="8" class="text-center py-4 text-rose-500 font-bold">' + res.error + '</td></tr>';
                            return;
                        }
                        operatorData = res.data || [];
                        document.getElementById('op-count').innerText = toPersianNum(operatorData.length);
                        opCurrentPage = 1; // با هر بار دریافت جدید، به صفحه اول برگرد
                        renderOperatorTable();
                    } catch(e) {}
                }
            };
            xhr.send();
        }

        function switchOpTab(tab, btn) {
            currentOpTab = tab;
            opCurrentPage = 1; // با تغییر تب، به صفحه اول برگرد
            var btns = document.querySelectorAll('.op-tab-btn');
            btns.forEach(function(b) {
                b.classList.remove('bg-white', 'dark:bg-slate-800', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm');
                b.classList.add('text-slate-500');
            });
            btn.classList.remove('text-slate-500');
            btn.classList.add('bg-white', 'dark:bg-slate-800', 'text-indigo-600', 'dark:text-indigo-400', 'shadow-sm');
            renderOperatorTable();
        }

        function formatSeconds(sec) {
            sec = parseInt(sec) || 0;
            if (sec < 60) return toPersianNum(sec) + ' ث';
            var h = Math.floor(sec / 3600);
            var m = Math.floor((sec % 3600) / 60);
            var s = sec % 60;
            var str = '';
            if (h > 0) str += toPersianNum(h) + ' س ';
            if (m > 0) str += toPersianNum(m) + ' د ';
            if (h === 0 && s > 0) str += toPersianNum(s) + ' ث';
            return str.trim() || '۰';
        }

        function changeOpPage(page) {
            opCurrentPage = page;
            renderOperatorTable();
        }

        function renderOperatorTable() {
            var thead = document.getElementById('op-table-head');
            var tbody = document.getElementById('op-table-body');
            var pagContainer = document.getElementById('op-pagination-container');
            
            if (operatorData.length === 0) {
                thead.innerHTML = '';
                tbody.innerHTML = '<tr><td colspan="8" class="text-center py-6 text-slate-400">داده‌ای برای نمایش وجود ندارد.</td></tr>';
                pagContainer.innerHTML = '';
                return;
            }

            // ✅ محاسبات صفحه‌بندی
            var totalItems = operatorData.length;
            var totalPages = Math.ceil(totalItems / opItemsPerPage);
            if (opCurrentPage > totalPages) opCurrentPage = totalPages;
            
            var startIndex = (opCurrentPage - 1) * opItemsPerPage;
            var endIndex = Math.min(startIndex + opItemsPerPage, totalItems);
            var pageData = operatorData.slice(startIndex, endIndex);

            var headHtml = '';
            var bodyHtml = '';

            if (currentOpTab === 'calls') {
                headHtml = `<tr>
                    <th class="px-4 py-3 text-right">اپراتور</th>
                    <th class="px-4 py-3 text-center">زنگ خورده<br><span class="text-[9px] font-normal">Offered</span></th>
                    <th class="px-4 py-3 text-center">پاسخ داده<br><span class="text-[9px] font-normal">Answered</span></th>
                    <th class="px-4 py-3 text-center">رد شده<br><span class="text-[9px] font-normal">Rejected</span></th>
                    <th class="px-4 py-3 text-center">مشغول<br><span class="text-[9px] font-normal">Busy</span></th>
                    <th class="px-4 py-3 text-center">بی‌پاسخ<br><span class="text-[9px] font-normal">No answer</span></th>
                    <th class="px-4 py-3 text-center">نرخ پاسخ<br><span class="text-[9px] font-normal">Answer rate</span></th>
                    <th class="px-4 py-3 text-center">تماس خروجی<br><span class="text-[9px] font-normal">Outbound</span></th>
                </tr>`;

                pageData.forEach(op => {
                    var barWidth = op.answer_rate;
                    var barColor = barWidth > 80 ? 'bg-emerald-500' : (barWidth > 50 ? 'bg-amber-400' : 'bg-indigo-500');
                    
                    bodyHtml += `<tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                        <td class="px-4 py-3 text-right">
                            <div class="font-bold text-slate-800 dark:text-white">${op.name || 'بدون نام'}</div>
                            <!-- ✅ فونت مونو حذف شد -->
                            <div class="text-[13px] font-bold text-slate-600 dark:text-slate-400 mt-1">${toPersianNum(op.ext)}</div>
                        </td>
<td class="px-4 py-3 text-center font-bold text-slate-600 dark:text-slate-300 vazir-font cursor-pointer hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded transition-colors" onclick="openExtCallsModal('${op.ext}', '${(op.name || '').replace(/'/g, "&#39;")}', ${getOpPeriodDays()})" title="مشاهده جزئیات">${toPersianNum(op.offered)}</td>
<td class="px-4 py-3 text-center font-bold text-emerald-600 dark:text-emerald-400 vazir-font cursor-pointer hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded transition-colors" onclick="openExtCallsModal('${op.ext}', '${(op.name || '').replace(/'/g, "&#39;")}', ${getOpPeriodDays()})" title="مشاهده جزئیات">${toPersianNum(op.answered)}</td>
<td class="px-4 py-3 text-center font-bold text-rose-600 dark:text-rose-400 vazir-font cursor-pointer hover:bg-rose-50 dark:hover:bg-rose-900/20 rounded transition-colors" onclick="openExtCallsModal('${op.ext}', '${(op.name || '').replace(/'/g, "&#39;")}', ${getOpPeriodDays()})" title="مشاهده جزئیات">${toPersianNum(op.rejected)}</td>
<td class="px-4 py-3 text-center font-bold text-amber-600 dark:text-amber-400 vazir-font cursor-pointer hover:bg-amber-50 dark:hover:bg-amber-900/20 rounded transition-colors" onclick="openExtCallsModal('${op.ext}', '${(op.name || '').replace(/'/g, "&#39;")}', ${getOpPeriodDays()})" title="مشاهده جزئیات">${toPersianNum(op.busy)}</td>
<td class="px-4 py-3 text-center font-bold text-slate-600 dark:text-slate-300 vazir-font cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-700/40 rounded transition-colors" onclick="openExtCallsModal('${op.ext}', '${(op.name || '').replace(/'/g, "&#39;")}', ${getOpPeriodDays()})" title="مشاهده جزئیات">${toPersianNum(op.no_answer)}</td>
<td class="px-4 py-3 text-center font-bold text-purple-600 dark:text-purple-400 vazir-font cursor-pointer hover:bg-purple-50 dark:hover:bg-purple-900/20 rounded transition-colors" onclick="openExtCallsModal('${op.ext}', '${(op.name || '').replace(/'/g, "&#39;")}', ${getOpPeriodDays()})" title="مشاهده جزئیات">${toPersianNum(op.outbound)}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2 justify-center">
                                <span class="text-xs font-bold text-slate-700 dark:text-slate-200 vazir-font w-8 text-left">${toPersianNum(barWidth)}٪</span>
                                <div class="w-16 h-1.5 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
                                    <div class="h-full ${barColor} rounded-full" style="width: ${barWidth}%"></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center font-bold text-purple-600 dark:text-purple-400 vazir-font">${toPersianNum(op.outbound)}</td>
                    </tr>`;
                });
            } 
            else if (currentOpTab === 'time') {
                headHtml = `<tr>
                    <th class="px-4 py-3 text-right">اپراتور</th>
                    <th class="px-4 py-3 text-center">مدت مکالمه<br><span class="text-[9px] font-normal">Talk Time</span></th>
                    <th class="px-4 py-3 text-center">میانگین مکالمه<br><span class="text-[9px] font-normal">Avg Talk</span></th>
                    <th class="px-4 py-3 text-center">مدت زنگ خوردن<br><span class="text-[9px] font-normal">Ring Time</span></th>
                </tr>`;

                pageData.forEach(op => {
                    bodyHtml += `<tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                        <td class="px-4 py-3 text-right">
                            <div class="font-bold text-slate-800 dark:text-white">${op.name || 'بدون نام'}</div>
                            <!-- ✅ فونت مونو حذف شد -->
                            <div class="text-[13px] font-bold text-slate-600 dark:text-slate-400 mt-1">${toPersianNum(op.ext)}</div>
                        </td>
<td class="px-4 py-3 text-center font-bold text-indigo-600 dark:text-indigo-400 vazir-font cursor-pointer hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded transition-colors" onclick="openExtCallsModal('${op.ext}', '${(op.name || '').replace(/'/g, "&#39;")}', ${getOpPeriodDays()})" title="مشاهده جزئیات">${formatSeconds(op.talk_time)}</td>
<td class="px-4 py-3 text-center font-bold text-blue-600 dark:text-blue-400 vazir-font cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded transition-colors" onclick="openExtCallsModal('${op.ext}', '${(op.name || '').replace(/'/g, "&#39;")}', ${getOpPeriodDays()})" title="مشاهده جزئیات">${formatSeconds(op.avg_talk)}</td>
                        <td class="px-4 py-3 text-center font-bold text-amber-600 dark:text-amber-400 vazir-font">${formatSeconds(op.ring_time)}</td>
                    </tr>`;
                });
            }
            else {
                headHtml = `<tr><th class="px-4 py-3 text-center">اطلاعات</th></tr>`;
                bodyHtml = `<tr><td class="text-center py-6 text-slate-400 text-xs">این تب هنوز پیاده‌سازی نشده است.</td></tr>`;
            }

            thead.innerHTML = headHtml;
            tbody.innerHTML = bodyHtml;

            // ✅ ساخت دکمه‌های صفحه‌بندی
            if (totalPages > 1) {
                var pagHtml = `<div class="text-[11px] text-slate-400 font-bold">صفحه ${toPersianNum(opCurrentPage)} از ${toPersianNum(totalPages)}</div>
                               <div class="flex gap-1">`;
                if (opCurrentPage > 1) {
                    pagHtml += `<button onclick="changeOpPage(${opCurrentPage - 1})" class="w-8 h-8 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 flex items-center justify-center text-xs hover:bg-slate-100 dark:hover:bg-slate-600 transition"><i class="fa-solid fa-chevron-right"></i></button>`;
                }
                if (opCurrentPage < totalPages) {
                    pagHtml += `<button onclick="changeOpPage(${opCurrentPage + 1})" class="w-8 h-8 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 flex items-center justify-center text-xs hover:bg-slate-100 dark:hover:bg-slate-600 transition"><i class="fa-solid fa-chevron-left"></i></button>`;
                }
                pagHtml += `</div>`;
                pagContainer.innerHTML = pagHtml;
            } else {
                pagContainer.innerHTML = '';
            }
        }
		
		// ==============================================
// ✅ توابع تلفن تحت وب (Web Phone)
// ==============================================
var wpDest = '';

function toggleWebPhone() {
    var panel = document.getElementById('webPhonePanel');
    if (panel.classList.contains('hidden')) {
        panel.classList.remove('hidden');
        // انیمیشن باز شدن
        setTimeout(function() {
            panel.classList.remove('scale-95', 'opacity-0');
            panel.classList.add('scale-100', 'opacity-100');
        }, 10);
        
        // بازیابی داخلی ذخیره شده
        var savedExt = localStorage.getItem('webPhoneExt');
        if (savedExt) document.getElementById('wpExtInput').value = savedExt;
        document.getElementById('wpStatus').classList.add('hidden');
    } else {
        // انیمیشن بسته شدن
        panel.classList.remove('scale-100', 'opacity-100');
        panel.classList.add('scale-95', 'opacity-0');
        setTimeout(function() {
            panel.classList.add('hidden');
        }, 300);
    }
}

function wpPress(num) {
    wpDest += num;
    document.getElementById('wpDestInput').value = wpDest;
}

function wpBackspace() {
    wpDest = wpDest.slice(0, -1);
    document.getElementById('wpDestInput').value = wpDest;
}

function wpClear() {
    wpDest = '';
    document.getElementById('wpDestInput').value = '';
}

function callFromWebPhone() {
    var ext = document.getElementById('wpExtInput').value.trim();
    var dest = document.getElementById('wpDestInput').value.trim();
    var statusBox = document.getElementById('wpStatus');
    var btn = document.getElementById('wpCallBtn');

    if (!ext) {
        statusBox.className = 'text-xs font-bold text-center p-2 rounded-lg text-rose-500 bg-rose-50 dark:bg-rose-900/30';
        statusBox.innerText = 'لطفاً شماره داخلی خود را وارد کنید.';
        statusBox.classList.remove('hidden');
        return;
    }

    if (!dest) {
        statusBox.className = 'text-xs font-bold text-center p-2 rounded-lg text-rose-500 bg-rose-50 dark:bg-rose-900/30';
        statusBox.innerText = 'لطفاً شماره مقصد را شماره‌گیری کنید.';
        statusBox.classList.remove('hidden');
        return;
    }

    // ذخیره داخلی برای استفاده‌های بعدی
    localStorage.setItem('webPhoneExt', ext);

    // تغییر وضعیت دکمه به حالت انتظار
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>در حال ارسال...</span>';
    btn.classList.replace('bg-emerald-600', 'bg-slate-400');

    statusBox.className = 'text-xs font-bold text-center p-2 rounded-lg text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30';
    statusBox.innerText = 'در حال برقراری تماس...';
    statusBox.classList.remove('hidden');

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '?api=originate_call&ext=' + encodeURIComponent(ext) + '&dest=' + encodeURIComponent(dest), true);
    xhr.onload = function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-phone"></i><span>برقراری تماس</span>';
        btn.classList.replace('bg-slate-400', 'bg-emerald-600');

        if (xhr.status === 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    statusBox.className = 'text-xs font-bold text-center p-2 rounded-lg text-emerald-600 bg-emerald-50 dark:bg-emerald-900/30';
                    statusBox.innerHTML = '<i class="fa-solid fa-check-circle mr-1"></i> ' + res.message;
                    // پاک کردن صفحه بعد از تماس موفق
                    wpClear(); 
                } else {
                    statusBox.className = 'text-xs font-bold text-center p-2 rounded-lg text-rose-500 bg-rose-50 dark:bg-rose-900/30';
                    statusBox.innerText = res.message;
                }
            } catch(e) {
                statusBox.className = 'text-xs font-bold text-center p-2 rounded-lg text-rose-500 bg-rose-50 dark:bg-rose-900/30';
                statusBox.innerText = 'خطا در پردازش پاسخ سرور.';
            }
        } else {
            statusBox.className = 'text-xs font-bold text-center p-2 rounded-lg text-rose-500 bg-rose-50 dark:bg-rose-900/30';
            statusBox.innerText = 'خطای شبکه.';
        }
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-phone"></i><span>برقراری تماس</span>';
        btn.classList.replace('bg-slate-400', 'bg-emerald-600');
        statusBox.className = 'text-xs font-bold text-center p-2 rounded-lg text-rose-500 bg-rose-50 dark:bg-rose-900/30';
        statusBox.innerText = 'خطا در ارتباط با سرور.';
    };
    xhr.send();
}


// ==============================================
// ✅ پشتیبانی از کیبورد فیزیکی در Web Phone
// ==============================================
document.addEventListener('keydown', function(e) {
    // ۱. بررسی باز بودن پنل
    var panel = document.getElementById('webPhonePanel');
    if (!panel || panel.classList.contains('hidden')) return;
    
    // ۲. اگر کاربر در حال تایپ در یک input/textarea/select است، دخالت نکن
    var active = document.activeElement;
    if (active && active.tagName) {
        var tag = active.tagName.toUpperCase();
        if (tag === 'TEXTAREA' || tag === 'SELECT') return;
        // اگر input عادی است (نه readonly) → بگذار کاربر تایپ کند
        if (tag === 'INPUT' && !active.readOnly) return;
    }
    
    var key = e.key;
    
    // ۳. اعداد ۰ تا ۹
    if (key >= '0' && key <= '9') {
        e.preventDefault();
        wpPress(key);
        return;
    }
    
    // ۴. ستاره و مربع
    if (key === '*' || key === '#') {
        e.preventDefault();
        wpPress(key);
        return;
    }
    
    // ۵. Backspace → حذف آخرین رقم
    if (key === 'Backspace') {
        e.preventDefault();
        wpBackspace();
        return;
    }
    
    // ۶. Delete → پاک کردن همه
    if (key === 'Delete') {
        e.preventDefault();
        wpClear();
        return;
    }
    
    // ۷. Enter → برقراری تماس
    if (key === 'Enter') {
        e.preventDefault();
        callFromWebPhone();
        return;
    }
    
    // ۸. Escape → بستن پنل
    if (key === 'Escape') {
        e.preventDefault();
        toggleWebPhone();
        return;
    }
});

function wpPress(num) {
    // فقط اعداد، ستاره، مربع و + مجاز است
    if (!/^[0-9*#+]$/.test(num)) return;
    wpDest += num;
    document.getElementById('wpDestInput').value = wpDest;
}

// ==============================================
// ✅ توابع پارک تماس
// ==============================================
function fetchParkedCalls() {
    fetch('?api=get_parked_calls')
        .then(r => r.json())
        .then(data => {
            var container = document.getElementById('parked-calls-container');
            if (data.error) {
                container.innerHTML = '<div class="col-span-full text-center py-6 text-rose-500 text-xs font-bold">' + data.error + '</div>';
                return;
            }

            var parked = data.parked_calls || [];
            if (parked.length === 0) {
                container.innerHTML = '<div class="col-span-full text-center py-6 text-slate-400 text-xs"><i class="fa-solid fa-check-circle text-emerald-500 text-lg mb-2 block"></i> هیچ تماسی در پارک نیست.</div>';
                return;
            }

            // ذخیره داخلی از قبل (اگر کاربر قبلا وارد کرده باشد)
            var savedExt = localStorage.getItem('webPhoneExt');
            var extInput = document.getElementById('parkPickupExt');
            if (savedExt && !extInput.value) extInput.value = savedExt;

            var html = '';
            parked.forEach(function(p) {
                var seconds = p.duration;
                var mins = Math.floor(seconds / 60);
                var secs = seconds % 60;
                var timeStr = mins > 0 ? (toPersianNum(mins) + ' د ' + toPersianNum(secs) + ' ث') : (toPersianNum(secs) + ' ثانیه');

                html += `
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-4 flex flex-col justify-between shadow-sm relative overflow-hidden">
                    <div class="absolute top-0 right-0 bg-blue-500 text-white text-[10px] font-bold px-3 py-1 rounded-bl-lg">جایگاه ${toPersianNum(p.space)}</div>
                    <div class="mt-2">
                        <div class="text-xs text-blue-500 mb-1"><i class="fa-solid fa-phone-volume mr-1"></i> تماس‌گیرنده:</div>
                        <div class="text-base font-black text-slate-800 dark:text-white mb-2" dir="ltr">${toPersianNum(p.caller)}</div>
                        <div class="text-[10px] text-slate-500 mb-3"><i class="fa-regular fa-clock mr-1"></i> مدت انتظار: ${timeStr}</div>
                    </div>
                    <button onclick="pickupParkedCall('${p.space}')" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg text-xs font-bold transition flex items-center justify-center gap-1 shadow-md shadow-blue-500/30">
                        <i class="fa-solid fa-phone"></i> برداشتن تماس
                    </button>
                </div>`;
            });
            container.innerHTML = html;
        })
        .catch(err => {
            document.getElementById('parked-calls-container').innerHTML = '<div class="col-span-full text-center py-6 text-rose-500 text-xs">خطا در ارتباط با سرور</div>';
        });
}

function pickupParkedCall(space) {
    var extInput = document.getElementById('parkPickupExt');
    var ext = extInput.value.trim();

    if (!ext) {
        alert('لطفاً ابتدا شماره داخلی خود را در کادر بالای این بخش وارد کنید.');
        extInput.focus();
        return;
    }

    // ذخیره داخلی برای استفاده‌های بعدی
    localStorage.setItem('webPhoneExt', ext);

    // نمایش حالت در حال ارسال
    var btn = event.target.closest('button');
    var originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> در حال اتصال...';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '?api=pickup_parked_call&space=' + encodeURIComponent(space) + '&ext=' + encodeURIComponent(ext), true);
    xhr.onload = function() {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.success) {
                alert('✅ ' + res.message);
                fetchParkedCalls(); // بروزرسانی لیست
            } else {
                alert('❌ خطا: ' + res.message);
            }
        } catch(e) {
            alert('خطا در پردازش پاسخ سرور.');
        }
    };
    xhr.send();
}
    </script>
	<!-- ========================================== -->
<!-- ✅ Web Phone (تلفن تحت وب شناور) -->
<!-- ========================================== -->

<!-- دکمه شناور -->
<button id="webPhoneFab" onclick="toggleWebPhone()" class="fixed bottom-6 left-6 w-14 h-14 bg-indigo-600 hover:bg-indigo-700 text-white rounded-full shadow-2xl z-[90] flex items-center justify-center transition-transform transform hover:scale-110 active:scale-95 border-2 border-white/20">
    <i class="fa-solid fa-phone-volume text-2xl"></i>
</button>

<!-- پنل شماره‌گیر -->
<div id="webPhonePanel" class="fixed bottom-24 left-6 w-80 bg-white dark:bg-slate-800 rounded-3xl shadow-2xl border border-slate-200 dark:border-slate-700 z-[90] hidden transition-all duration-300 transform scale-95 opacity-0 origin-bottom-left" dir="rtl">
    <!-- هدر پنل -->
    <div class="bg-indigo-600 text-white p-4 rounded-t-3xl flex justify-between items-center">
        <div class="flex items-center gap-2">
            <i class="fa-solid fa-phone-volume"></i>
            <h3 class="font-bold text-sm">تلفن تحت وب (Web Phone)</h3>
        </div>
        <button onclick="toggleWebPhone()" class="text-indigo-200 hover:text-white transition-colors">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>
    </div>

    <div class="p-5 space-y-4">
        <!-- فیلد داخلی منبع (داخلی خودم) -->
        <div>
            <label class="text-[10px] font-bold text-slate-500 mb-1 block">داخلی شما (Source):</label>
            <input type="text" id="wpExtInput" placeholder="مثلا 1001" class="w-full bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl px-3 py-2 text-sm text-center font-black vazir-font focus:outline-none focus:ring-2 focus:ring-indigo-500" dir="ltr">
        </div>

        <!-- نمایشگر شماره مقصد -->
        <div>
            <label class="text-[10px] font-bold text-slate-500 mb-1 block">شماره مقصد (Destination):</label>
            <div class="relative">
                <input type="text" id="wpDestInput" readonly class="w-full bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-3 text-xl text-center font-black vazir-font text-indigo-600 dark:text-indigo-400 focus:outline-none tracking-wider" dir="ltr" placeholder="...">
                <button onclick="wpBackspace()" class="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-rose-500 p-1.5 transition-colors" title="حذف آخرین رقم">
                    <i class="fa-solid fa-delete-left text-lg"></i>
                </button>
            </div>
        </div>

        <!-- دکمه‌های شماره‌گیر (Dialpad) -->
        <div class="grid grid-cols-3 gap-2">
            <button onclick="wpPress('1')" class="wp-btn">1</button>
            <button onclick="wpPress('2')" class="wp-btn">2<span class="wp-sub">ABC</span></button>
            <button onclick="wpPress('3')" class="wp-btn">3<span class="wp-sub">DEF</span></button>
            <button onclick="wpPress('4')" class="wp-btn">4<span class="wp-sub">GHI</span></button>
            <button onclick="wpPress('5')" class="wp-btn">5<span class="wp-sub">JKL</span></button>
            <button onclick="wpPress('6')" class="wp-btn">6<span class="wp-sub">MNO</span></button>
            <button onclick="wpPress('7')" class="wp-btn">7<span class="wp-sub">PQRS</span></button>
            <button onclick="wpPress('8')" class="wp-btn">8<span class="wp-sub">TUV</span></button>
            <button onclick="wpPress('9')" class="wp-btn">9<span class="wp-sub">WXYZ</span></button>
            <button onclick="wpPress('*')" class="wp-btn text-indigo-500">*</button>
            <button onclick="wpPress('0')" class="wp-btn">0<span class="wp-sub">+</span></button>
            <button onclick="wpPress('#')" class="wp-btn text-indigo-500">#</button>
        </div>

        <!-- دکمه تماس و پاک کردن -->
        <div class="flex gap-2 pt-2">
            <button onclick="wpClear()" class="w-12 h-12 rounded-full bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center hover:bg-slate-300 transition-colors" title="پاک کردن همه">
                <i class="fa-solid fa-eraser"></i>
            </button>
            <button onclick="callFromWebPhone()" id="wpCallBtn" class="flex-1 h-12 bg-emerald-600 hover:bg-emerald-700 text-white rounded-full font-bold text-sm shadow-lg shadow-emerald-500/30 flex items-center justify-center gap-2 transition-all">
                <i class="fa-solid fa-phone"></i>
                <span>برقراری تماس</span>
            </button>
        </div>
        
        <!-- نمایش وضعیت تماس -->
        <div id="wpStatus" class="text-xs font-bold text-center hidden p-2 rounded-lg"></div>
    </div>
</div>

<!-- ✅ استایل اختصاصی برای دکمه‌های شماره‌گیر -->
<style>
    .wp-btn {
        height: 3rem;
        border-radius: 0.75rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        font-size: 1.25rem;
        font-weight: 900;
        color: #1e293b;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        transition: all 0.1s;
    }
    .dark .wp-btn {
        background: #334155;
        border-color: #475569;
        color: #f1f5f9;
    }
    .wp-btn:active {
        background: #e2e8f0;
        transform: scale(0.95);
    }
    .dark .wp-btn:active {
        background: #1e293b;
    }
    .wp-sub {
        font-size: 0.55rem;
        font-weight: 600;
        color: #94a3b8;
        letter-spacing: 1px;
        margin-top: 2px;
    }
</style>
    <!-- ========================================== -->
    <!-- ✅ فوتر اختصاصی Netko.ir -->
    <!-- ========================================== -->
    <footer class="mt-8 mb-16 md:mb-8 bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 py-6 px-4 rounded-t-3xl shadow-[0_-4px_20px_-10px_rgba(0,0,0,0.05)] relative z-10">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex flex-col items-center md:items-start gap-2">
                <div class="text-sm font-black text-slate-800 dark:text-white flex items-center gap-2">
                    <i class="fa-solid fa-building text-indigo-500"></i>
                    مجموعه VoipTel Pro
                </div>
                <div class="flex flex-wrap justify-center md:justify-start items-center gap-4 text-xs text-slate-600 dark:text-slate-400 font-mono mt-1">
                    <span dir="ltr" class="flex items-center gap-1.5 bg-slate-100 dark:bg-slate-700 px-2.5 py-1 rounded-md">
                        <i class="fa-solid fa-phone text-emerald-500"></i> 09123456789
                    </span>
                    <span dir="ltr" class="flex items-center gap-1.5 bg-slate-100 dark:bg-slate-700 px-2.5 py-1 rounded-md">
                        <i class="fa-solid fa-envelope text-amber-500"></i> info@netko.ir
                    </span>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <a href="https://t.me/S0TOUDEH" target="_blank" class="bg-[#0088cc] hover:bg-[#0077b3] text-white px-5 py-2.5 rounded-xl text-xs font-bold flex items-center gap-2 transition-all shadow-md shadow-blue-500/20 hover:shadow-lg hover:-translate-y-0.5">
                    <i class="fa-brands fa-telegram text-lg"></i>
                    <span>ارتباط در تلگرام</span>
                </a>
            </div>
        </div>
        
        <!-- خط کپی‌رایت -->
        <div class="max-w-7xl mx-auto mt-6 pt-4 border-t border-slate-100 dark:border-slate-700 text-center text-[10px] text-slate-400 font-medium">
            تمامی حقوق محفوظ است &copy; <?php echo date('Y'); ?> - طراحی و توسعه توسط Netko.ir / 365voip.ir
        </div>
    </footer>

</body>
</html>
</body>
</html>