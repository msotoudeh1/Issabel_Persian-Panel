<?php
// play.php - استریم و دانلود فایل‌های صوتی ایزابل
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_GET['file']) || empty($_GET['file'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "پارامتر فایل ارسال نشده است.";
    exit;
}

// دریافت و ایمن‌سازی نام فایل
$file = urldecode($_GET['file']);
$file = str_replace(array('../', '..\\', "\0"), '', $file);
$file = ltrim($file, '/\\');

// حالت دانلود در صورت ارسال dl=1
$is_download = (isset($_GET['dl']) && $_GET['dl'] == '1');

$base_dir = '/var/spool/asterisk/monitor/';
$full_path = '';

// ۱. اگر مسیر مطلق داده شده بود
if (preg_match('#^/#', $file) && file_exists($file)) {
    $full_path = $file;
}
// ۲. مستقیم در پوشه base
elseif (file_exists($base_dir . $file)) {
    $full_path = $base_dir . $file;
}
// ۳. جستجو در زیرپوشه‌ها (ساختار YYYY/MM/DD)
else {
    $patterns = array(
        $base_dir . $file,                     // YYYY/MM/DD/name
        $base_dir . '*/' . $file,              // YYYY/name
        $base_dir . '*/*/' . $file,            // YYYY/MM/name
        $base_dir . '*/*/*/' . $file,          // YYYY/MM/DD/name
        $base_dir . '*/*/*/*/' . $file,        // یک لایه عمیق‌تر
        $base_dir . '*/*/*/' . basename($file),
    );
    foreach ($patterns as $p) {
        $res = glob($p);
        if (!empty($res)) {
            $full_path = $res[0];
            break;
        }
    }
    
    // ۴. اگر با glob پیدا نشد، با find روی کل پوشه
    if (empty($full_path) && function_exists('shell_exec')) {
        $safe_name = escapeshellarg(basename($file));
        $safe_base = escapeshellarg($base_dir);
        $out = @shell_exec("find {$safe_base} -type f -name {$safe_name} 2>/dev/null | head -n 1");
        if ($out && trim($out) !== '') {
            $full_path = trim($out);
        }
    }
}

// بررسی نهایی وجود فایل و دسترسی
if (empty($full_path) || !file_exists($full_path) || !is_readable($full_path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "فایل صوتی یافت نشد یا دسترسی خواندن آن وجود ندارد.";
    exit;
}

// تعیین Content-Type بر اساس پسوند
$extension = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
$mime_types = array(
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'gsm'  => 'audio/gsm',
    'ogg'  => 'audio/ogg',
    'ulaw' => 'audio/basic',
    'alaw' => 'audio/basic',
    'g729' => 'audio/g729',
    'sln'  => 'audio/wav',
);
$mime_type = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';

// پاکسازی هر نوع خروجی قبلی
while (ob_get_level()) {
    ob_end_clean();
}

$file_size = filesize($full_path);
$filename = basename($full_path);

// هدرهای اصلی
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . $file_size);

// اگر dl=1 بود → دانلود، در غیر این صورت → پخش آنلاین
if ($is_download) {
    // نام فایل را با کوتیشن امن ارسال می‌کنیم
    $safe_filename = str_replace('"', '', $filename);
    header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
} else {
    header('Content-Disposition: inline; filename="' . $filename . '"');
}

header('Accept-Ranges: bytes');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// پشتیبانی از Range (برای seek در پلیر و دانلود رزومه‌ای)
if (!$is_download && isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];
    if (preg_match('/bytes=(\d+)-(\d*)/', $range, $m)) {
        $start = intval($m[1]);
        $end   = ($m[2] !== '') ? intval($m[2]) : $file_size - 1;

        if ($start > $end || $start >= $file_size) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header('Content-Range: bytes */' . $file_size);
            exit;
        }

        $length = $end - $start + 1;
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
        header('Content-Length: ' . $length);

        $fp = fopen($full_path, 'rb');
        fseek($fp, $start);
        $buf_size = 8192;
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $read = ($remaining > $buf_size) ? $buf_size : $remaining;
            echo fread($fp, $read);
            $remaining -= $read;
            flush();
        }
        fclose($fp);
        exit;
    }
}

// ارسال کامل فایل (بدون Range یا حالت دانلود)
readfile($full_path);
exit;