<?php
/**
 * SOMKQ 小平台 - 单文件入口 (v6.1 交互增强版)
 * 修复反馈：“至营业结束”选项不明显的问题。
 * 新增特性：勾选“至营业结束”自动禁用下班时间输入框，强化视觉反馈。
 */

// 1. 初始化环境
define('IN_APP', true);
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 引入核心文件
$app_path = __DIR__ . '/../../app/somkq';
if (!file_exists($app_path . '/config.php')) die("Config file missing");
$config = require $app_path . '/config.php';
require_once $app_path . '/db.php';
require_once $app_path . '/r2_client.php';

// Session 初始化
session_name($config['session_name']);
session_start();

// 2. 路由分发
$action = $_GET['action'] ?? 'home';

// 3. 权限拦截
$is_logged_in = isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true;

if (!$is_logged_in && $action !== 'login' && $action !== 'do_login') {
    header('Location: ?action=login');
    exit;
}

// 4. 控制器逻辑
try {
    $pdo = DB::connect();

    switch ($action) {
        case 'login':
            view_header('系统登录');
            view_login();
            view_footer();
            break;

        case 'do_login':
            $input_pass = $_POST['password'] ?? '';
            if ($input_pass === $config['admin_password']) {
                $_SESSION['is_logged_in'] = true;
                $_SESSION['user_role'] = 'admin'; // 管理员：完整权限
                header('Location: ?action=home');
            } elseif ($input_pass === $config['readonly_password']) {
                $_SESSION['is_logged_in'] = true;
                $_SESSION['user_role'] = 'readonly'; // 只读用户：仅查看和下载
                header('Location: ?action=home');
            } else {
                view_header('系统登录');
                view_login("密码错误");
                view_footer();
            }
            break;

        case 'logout':
            session_destroy();
            header('Location: ?action=login');
            break;

        case 'home':
            $page_offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $data = get_dashboard_data($pdo, $page_offset);
            view_header('工作台');
            view_dashboard($data, $page_offset);
            view_footer();
            break;

        case 'day_view':
            $date = $_GET['date'] ?? date('Y-m-d');
            $data = get_day_details($pdo, $config['staff_list'], $date);
            view_header($date . ' 详情');
            view_day_detail($date, $data);
            view_footer();
            break;

        case 'save_cal':
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                die('权限不足：只有管理员可以修改数据');
            }
            handle_save_calibration($pdo, $config);
            break;

        case 'save_shift':
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                die('权限不足：只有管理员可以修改数据');
            }
            handle_save_shift($pdo);
            break;

        case 'save_day_all':
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                die('权限不足：只有管理员可以修改数据');
            }
            handle_save_day_all($pdo, $config);
            break;

        case 'upload_video':
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                die('权限不足：只有管理员可以上传文件');
            }
            handle_upload_video($pdo, $config);
            break;

        case 'upload_video_ajax':
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => '权限不足：只有管理员可以上传文件']);
                exit;
            }
            handle_upload_video_ajax($pdo, $config);
            break;

        case 'monthly_report':
            $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
            $data = get_monthly_data($pdo, $config['staff_list'], $year, $month);
            view_header($year . '年' . $month . '月考勤总表');
            view_monthly_report($year, $month, $data);
            view_footer();
            break;

        case 'migrate_videos':
            if (($_SESSION['user_role'] ?? '') !== 'admin') {
                die('权限不足：只有管理员可以执行视频转移');
            }
            view_header('视频转移到 R2');
            handle_migrate_videos($pdo, $config);
            view_footer();
            break;

        default:
            header('Location: ?action=home');
            break;
    }
} catch (Exception $e) {
    die("系统错误: " . $e->getMessage());
}

// ==========================================
// 辅助函数
// ==========================================

function get_post_time($prefix) {
    $h = $_POST[$prefix . '_h'] ?? '';
    $m = $_POST[$prefix . '_m'] ?? '';
    $s = $_POST[$prefix . '_s'] ?? '';
    if (trim($h) === '') return null;
    $h = str_pad(trim($h), 2, '0', STR_PAD_LEFT);
    $m = str_pad(trim($m), 2, '0', STR_PAD_LEFT);
    $s = str_pad(trim($s), 2, '0', STR_PAD_LEFT);
    if (!ctype_digit($h) || !ctype_digit($m) || !ctype_digit($s)) return null;
    return "$h:$m:$s";
}

function get_post_time_from_array($arr) {
    $h = $arr['h'] ?? '';
    $m = $arr['m'] ?? '';
    $s = $arr['s'] ?? '';
    if (trim($h) === '') return null;
    $h = str_pad(trim($h), 2, '0', STR_PAD_LEFT);
    $m = str_pad(trim($m), 2, '0', STR_PAD_LEFT);
    $s = str_pad(trim($s), 2, '0', STR_PAD_LEFT);
    if (!ctype_digit($h) || !ctype_digit($m) || !ctype_digit($s)) return null;
    return "$h:$m:$s";
}

// ==========================================
// 动作处理 (Controller)
// ==========================================

function handle_save_calibration($pdo, $config) {
    $date = $_POST['date'];
    $m_time = get_post_time('monitor_time');
    $r_time = get_post_time('real_time');

    $offset = 0;
    if ($m_time && $r_time) {
        $m_ts = strtotime("$date $m_time");
        $r_ts = strtotime("$date $r_time");
        $offset = $r_ts - $m_ts;
    }

    $image_path = null;
    if (!empty($_FILES['cal_image']['name'])) {
        $ext = strtolower(pathinfo($_FILES['cal_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $filename = $date . '_' . uniqid() . '.' . $ext;
            $target = $config['path_image_upload'] . '/' . $filename;
            if (move_uploaded_file($_FILES['cal_image']['tmp_name'], $target)) {
                $image_path = $filename;
            }
        }
    }

    $sql = "INSERT INTO somkq_daily_calibration
            (cal_date, monitor_time_ref, real_time_ref, time_offset_seconds, calibration_image)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            monitor_time_ref = VALUES(monitor_time_ref),
            real_time_ref = VALUES(real_time_ref),
            time_offset_seconds = VALUES(time_offset_seconds)";

    $params = [$date, $m_time, $r_time, $offset, $image_path];
    if ($image_path) {
        $sql .= ", calibration_image = VALUES(calibration_image)";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    header("Location: ?action=day_view&date=$date");
    exit;
}

function handle_save_shift($pdo) {
    $id = $_POST['record_id'] ?? '';
    $date = $_POST['date'];
    $staff = $_POST['staff_name'];
    $shift = $_POST['shift_type'];

    $start = get_post_time('start_time_monitor');
    $end = get_post_time('end_time_monitor');
    $is_closing = isset($_POST['is_end_at_closing']) ? 1 : 0;

    if (empty($id)) {
        $stmt = $pdo->prepare("SELECT id FROM somkq_shift_records WHERE record_date=? AND staff_name=? AND shift_type=?");
        $stmt->execute([$date, $staff, $shift]);
        $existing = $stmt->fetchColumn();
        if ($existing) $id = $existing;
    }

    if ($id) {
        $stmt = $pdo->prepare("UPDATE somkq_shift_records SET start_time_monitor=?, end_time_monitor=?, is_end_at_closing=? WHERE id=?");
        $stmt->execute([$start, $end, $is_closing, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO somkq_shift_records (record_date, staff_name, shift_type, start_time_monitor, end_time_monitor, is_end_at_closing) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$date, $staff, $shift, $start, $end, $is_closing]);
    }

    header("Location: ?action=day_view&date=$date");
    exit;
}

function handle_save_day_all($pdo, $config) {
    $date = $_POST['date'];

    // 1. 处理校准 (Calibration)
    // 检查是否有校准数据提交
    if (isset($_POST['calibration'])) {
        $cal_data = $_POST['calibration'];

        // 解析时间：calibration[monitor_time][h/m/s]
        $m_time = get_post_time_from_array($cal_data['monitor_time'] ?? []);
        $r_time = get_post_time_from_array($cal_data['real_time'] ?? []);

        $offset = 0;
        if ($m_time && $r_time) {
            $m_ts = strtotime("$date $m_time");
            $r_ts = strtotime("$date $r_time");
            $offset = $r_ts - $m_ts;
        }

        // 图片处理
        $image_path = null;
        if (!empty($_FILES['cal_image']['name'])) {
            $file = $_FILES['cal_image'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    // 查询是否已有旧照片
                    $stmt = $pdo->prepare("SELECT calibration_image FROM somkq_daily_calibration WHERE cal_date = ?");
                    $stmt->execute([$date]);
                    $old_image = $stmt->fetchColumn();

                    // 如果有旧照片，移动到归档目录
                    if ($old_image) {
                        $old_image_path = $config['path_image_upload'] . '/' . $old_image;
                        if (file_exists($old_image_path)) {
                            $archive_dir = $config['path_image_archive'];

                            // 确保归档目录存在
                            if (!is_dir($archive_dir)) {
                                mkdir($archive_dir, 0777, true);
                            }

                            // 生成归档文件名，添加时间戳避免冲突
                            $archive_filename = pathinfo($old_image, PATHINFO_FILENAME) . '_archived_' . time() . '.' . pathinfo($old_image, PATHINFO_EXTENSION);
                            $archive_path = $archive_dir . '/' . $archive_filename;

                            // 移动旧照片到归档目录
                            rename($old_image_path, $archive_path);
                        }
                    }

                    // 保存新照片
                    $filename = $date . '_' . uniqid() . '.' . $ext;
                    $target_dir = $config['path_image_upload'];

                    if (!is_dir($target_dir)) {
                        mkdir($target_dir, 0777, true);
                    }

                    $target = $target_dir . '/' . $filename;
                    if (move_uploaded_file($file['tmp_name'], $target)) {
                        $image_path = $filename;
                    }
                }
            }
        }

        // 保存校准
        $sql = "INSERT INTO somkq_daily_calibration
                (cal_date, monitor_time_ref, real_time_ref, time_offset_seconds, calibration_image)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                monitor_time_ref = VALUES(monitor_time_ref),
                real_time_ref = VALUES(real_time_ref),
                time_offset_seconds = VALUES(time_offset_seconds)";

        $params = [$date, $m_time, $r_time, $offset, $image_path];
        if ($image_path) {
            $sql .= ", calibration_image = VALUES(calibration_image)";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // 2. 处理班次 (Shifts)
    if (isset($_POST['shifts']) && is_array($_POST['shifts'])) {
        foreach ($_POST['shifts'] as $staff => $staff_shifts) {
            foreach ($staff_shifts as $shift_type => $data) {
                // record_id 可能为空，如果需要更新现有记录，逻辑需要查找或传递 ID
                // 统一保存逻辑会根据 date+staff+shift_type 唯一性来处理

                $start = get_post_time_from_array($data['start_time'] ?? []);
                $end = get_post_time_from_array($data['end_time'] ?? []);
                $is_closing = isset($data['is_end_at_closing']) ? 1 : 0;
                $special_tag = isset($data['special_tag']) && !empty($data['special_tag']) ? $data['special_tag'] : null;

                // 查找现有记录 ID
                $stmt = $pdo->prepare("SELECT id FROM somkq_shift_records WHERE record_date=? AND staff_name=? AND shift_type=?");
                $stmt->execute([$date, $staff, $shift_type]);
                $existing_id = $stmt->fetchColumn();

                if ($existing_id) {
                    $stmt = $pdo->prepare("UPDATE somkq_shift_records SET start_time_monitor=?, end_time_monitor=?, is_end_at_closing=?, special_tag=? WHERE id=?");
                    $stmt->execute([$start, $end, $is_closing, $special_tag, $existing_id]);
                } else {
                    // 只有当有数据输入时才插入新记录
                    if ($start || $end || $is_closing || $special_tag) {
                        $stmt = $pdo->prepare("INSERT INTO somkq_shift_records (record_date, staff_name, shift_type, start_time_monitor, end_time_monitor, is_end_at_closing, special_tag) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$date, $staff, $shift_type, $start, $end, $is_closing, $special_tag]);
                    }
                }
            }
        }
    }

    header("Location: ?action=day_view&date=$date");
    exit;
}

function handle_upload_video($pdo, $config) {
    $record_id = $_POST['record_id'] ?? '';
    $date = $_POST['date'];
    $staff = $_POST['staff_name'];
    $shift = $_POST['shift_type'];
    $timing = $_POST['timing_type'];

    if (empty($record_id)) {
        $stmt = $pdo->prepare("SELECT id FROM somkq_shift_records WHERE record_date=? AND staff_name=? AND shift_type=?");
        $stmt->execute([$date, $staff, $shift]);
        $record_id = $stmt->fetchColumn();

        if (!$record_id) {
            $stmt = $pdo->prepare("INSERT INTO somkq_shift_records (record_date, staff_name, shift_type) VALUES (?, ?, ?)");
            $stmt->execute([$date, $staff, $shift]);
            $record_id = $pdo->lastInsertId();
        }
    }

    if (!empty($_FILES['video_file']['name'])) {
        $file = $_FILES['video_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (in_array($ext, ['mp4', 'mov', 'avi'])) {
            $safe_staff = md5($staff);
            $save_name = sprintf("%s_%s_%s_%s_%s.%s",
                $date, $staff, $shift, $timing, substr(uniqid(), -5), $ext
            );
            $target = $config['path_video_upload'] . '/' . $save_name;

            if (move_uploaded_file($file['tmp_name'], $target)) {
                $stmt = $pdo->prepare("INSERT INTO somkq_shift_videos (record_id, timing_type, file_name, original_name, file_size) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$record_id, $timing, $save_name, $file['name'], $file['size']]);
            }
        }
    }

    header("Location: ?action=day_view&date=$date");
    exit;
}

function handle_upload_video_ajax($pdo, $config) {
    header('Content-Type: application/json');

    try {
        $record_id = $_POST['record_id'] ?? '';
        $date = $_POST['date'];
        $staff = $_POST['staff_name'];
        $shift = $_POST['shift_type'];
        $timing = $_POST['timing_type'];

        if (empty($record_id)) {
            $stmt = $pdo->prepare("SELECT id FROM somkq_shift_records WHERE record_date=? AND staff_name=? AND shift_type=?");
            $stmt->execute([$date, $staff, $shift]);
            $record_id = $stmt->fetchColumn();

            if (!$record_id) {
                $stmt = $pdo->prepare("INSERT INTO somkq_shift_records (record_date, staff_name, shift_type) VALUES (?, ?, ?)");
                $stmt->execute([$date, $staff, $shift]);
                $record_id = $pdo->lastInsertId();
            }
        }

        if (!empty($_FILES['video_file']['name'])) {
            $file = $_FILES['video_file'];

            // 1. Error Code Check
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $msg = 'Upload Error Code: ' . $file['error'];
                if ($file['error'] == UPLOAD_ERR_INI_SIZE || $file['error'] == UPLOAD_ERR_FORM_SIZE) {
                    $msg = 'File too large (exceeds server limit)';
                }
                echo json_encode(['status' => 'error', 'message' => $msg]);
                exit;
            }

            // 2. Extension Check
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp4', 'mov', 'avi'])) {
                echo json_encode(['status' => 'error', 'message' => "Invalid file extension: .$ext"]);
                exit;
            }

            $safe_staff = md5($staff);
            $save_name = sprintf("%s_%s_%s_%s_%s.%s",
                $date, $staff, $shift, $timing, substr(uniqid(), -5), $ext
            );
            $target_dir = $config['path_video_upload'];
            $target = $target_dir . '/' . $save_name;

            // 3. Directory Check (Defensive)
            if (!is_dir($target_dir)) {
                if (!mkdir($target_dir, 0777, true)) {
                    echo json_encode(['status' => 'error', 'message' => 'Failed to create upload directory']);
                    exit;
                }
            }
            if (!is_writable($target_dir)) {
                echo json_encode(['status' => 'error', 'message' => 'Upload directory not writable']);
                exit;
            }

            // 4. Move File
            if (move_uploaded_file($file['tmp_name'], $target)) {
                $stmt = $pdo->prepare("INSERT INTO somkq_shift_videos (record_id, timing_type, file_name, original_name, file_size) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$record_id, $timing, $save_name, $file['name'], $file['size']]);

                echo json_encode([
                    'status' => 'success',
                    'file_url' => $config['url_video'] . '/' . $save_name,
                    'record_id' => $record_id
                ]);
                exit;
            } else {
                echo json_encode(['status' => 'error', 'message' => 'move_uploaded_file failed']);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'No file received']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Exception: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// R2 云存储相关函数
// ==========================================

/**
 * 创建 R2 客户端
 */
function create_r2_client($config) {
    return new R2Client(
        $config['r2_account_id'],
        $config['r2_access_key_id'],
        $config['r2_secret_access_key'],
        $config['r2_bucket_name'],
        $config['r2_region']
    );
}

/**
 * 上传文件到 R2（带重试机制）
 * @param R2Client $r2Client R2客户端
 * @param string $localPath 本地文件路径
 * @param string $r2Key R2中的对象键（路径）
 * @param int $maxRetries 最大重试次数
 * @return bool 是否成功
 */
function upload_to_r2_with_retry($r2Client, $localPath, $r2Key, $maxRetries = 3) {
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $result = $r2Client->putObject($r2Key, $localPath, mime_content_type($localPath));

        if ($result['success']) {
            return true;
        }

        if ($attempt >= $maxRetries) {
            return false;
        }

        usleep(500000 * $attempt); // 0.5秒, 1秒, 1.5秒
    }
    return false;
}

/**
 * 处理视频转移到 R2
 */
function handle_migrate_videos($pdo, $config) {
    // 计算上个月的时间范围
    $last_month_start = date('Y-m-01', strtotime('first day of last month'));
    $last_month_end = date('Y-m-t', strtotime('last day of last month'));

    $last_month_year = date('Y', strtotime($last_month_start));
    $last_month_month = date('m', strtotime($last_month_start));

    echo "<div class='container' style='max-width: 800px;'>";
    echo "<h2>转移上个月视频到 R2</h2>";
    echo "<p>目标月份: <strong>{$last_month_year}年{$last_month_month}月</strong> ({$last_month_start} 至 {$last_month_end})</p>";
    echo "<hr>";

    // 查询上个月的所有视频（file_name 不以 http 开头的）
    $stmt = $pdo->prepare("
        SELECT v.id, v.file_name, v.original_name, r.record_date
        FROM somkq_shift_videos v
        INNER JOIN somkq_shift_records r ON v.record_id = r.id
        WHERE r.record_date >= ? AND r.record_date <= ?
        AND v.file_name NOT LIKE 'http%'
        ORDER BY v.id ASC
    ");
    $stmt->execute([$last_month_start, $last_month_end]);
    $videos = $stmt->fetchAll();

    $total = count($videos);

    if ($total === 0) {
        echo "<div style='padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;'>";
        echo "✅ 没有需要转移的视频（可能已全部转移或该月无视频记录）";
        echo "</div>";
        echo "<p style='margin-top: 20px;'><a href='?action=home' style='color: #007bff;'>← 返回工作台</a></p>";
        echo "</div>";
        return;
    }

    echo "<p>找到 <strong>{$total}</strong> 个视频文件需要转移</p>";
    echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 20px;'>";
    echo "<strong>转移进度：</strong>";
    echo "<div id='progress-bar' style='width: 100%; height: 30px; background: #e9ecef; border-radius: 4px; margin-top: 10px; overflow: hidden;'>";
    echo "<div id='progress-fill' style='width: 0%; height: 100%; background: #28a745; transition: width 0.3s;'></div>";
    echo "</div>";
    echo "<p id='progress-text' style='margin-top: 10px; font-size: 14px;'>准备开始...</p>";
    echo "</div>";

    echo "<div id='log-container' style='background: #fff; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;'>";

    // 刷新输出缓冲，使进度实时显示
    if (ob_get_level() > 0) ob_flush();
    flush();

    // 创建 R2 客户端
    try {
        $r2Client = create_r2_client($config);
    } catch (Exception $e) {
        echo "<div style='color: red;'>❌ 无法连接到 R2: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "</div></div>";
        return;
    }

    $success_count = 0;
    $fail_count = 0;
    $skipped_count = 0;

    foreach ($videos as $index => $video) {
        $vid_id = $video['id'];
        $file_name = $video['file_name'];
        $record_date = $video['record_date'];

        $progress = round(($index / $total) * 100);
        $current_num = $index + 1;

        echo "<script>";
        echo "document.getElementById('progress-fill').style.width = '{$progress}%';";
        echo "document.getElementById('progress-text').textContent = '正在处理: {$current_num}/{$total} ({$progress}%)';";
        echo "</script>";

        if (ob_get_level() > 0) ob_flush();
        flush();

        echo "<div style='margin-bottom: 8px;'>";
        echo "<strong>[{$current_num}/{$total}]</strong> {$file_name} ... ";

        if (ob_get_level() > 0) ob_flush();
        flush();

        // 检查本地文件是否存在
        $local_path = $config['path_video_upload'] . '/' . $file_name;
        if (!file_exists($local_path)) {
            echo "<span style='color: orange;'>⚠️ 跳过（本地文件不存在）</span>";
            $skipped_count++;
            echo "</div>";
            continue;
        }

        // 构建 R2 路径: somkq/videos/YYYY/MM/filename.mp4
        $date_year = date('Y', strtotime($record_date));
        $date_month = date('m', strtotime($record_date));
        $r2_key = "somkq/videos/{$date_year}/{$date_month}/{$file_name}";

        // 上传到 R2（带重试）
        $upload_success = upload_to_r2_with_retry($r2Client, $local_path, $r2_key, 3);

        if (!$upload_success) {
            echo "<span style='color: red;'>❌ 上传失败（重试3次后仍失败）</span>";
            $fail_count++;
            echo "</div>";
            continue;
        }

        // 构建归档目录路径: uploads/videos/archived/YYYY/MM/
        $archive_dir = $config['path_video_archive'] . "/{$date_year}/{$date_month}";
        if (!is_dir($archive_dir)) {
            mkdir($archive_dir, 0777, true);
        }

        // 移动本地文件到归档目录
        $archive_path = $archive_dir . '/' . $file_name;
        if (!rename($local_path, $archive_path)) {
            echo "<span style='color: orange;'>⚠️ 已上传R2但本地归档失败</span>";
            // 即使归档失败，仍然更新数据库，因为R2已经有了
        }

        // 更新数据库，将 file_name 改为 R2 的完整 URL
        $r2_url = $config['r2_public_url'] . '/' . $r2_key;
        $update_stmt = $pdo->prepare("UPDATE somkq_shift_videos SET file_name = ? WHERE id = ?");
        $update_stmt->execute([$r2_url, $vid_id]);

        echo "<span style='color: green;'>✅ 成功</span>";
        $success_count++;
        echo "</div>";

        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    // 更新进度条为100%
    echo "<script>";
    echo "document.getElementById('progress-fill').style.width = '100%';";
    echo "document.getElementById('progress-text').textContent = '转移完成！';";
    echo "</script>";

    echo "</div>"; // log-container

    echo "<hr>";
    echo "<h3>转移结果汇总</h3>";
    echo "<ul>";
    echo "<li>总计: {$total} 个视频</li>";
    echo "<li style='color: green;'>✅ 成功: {$success_count}</li>";
    echo "<li style='color: red;'>❌ 失败: {$fail_count}</li>";
    echo "<li style='color: orange;'>⚠️ 跳过: {$skipped_count}</li>";
    echo "</ul>";

    echo "<p style='margin-top: 20px;'><a href='?action=home' style='color: #007bff; font-size: 16px;'>← 返回工作台</a></p>";
    echo "</div>";
}

// ==========================================
// 数据获取 (Model)
// ==========================================

function get_dashboard_data($pdo, $offset = 0) {
    $dates = [];
    $start_index = $offset * 7;
    for ($i = $start_index; $i < $start_index + 7; $i++) {
        $dates[] = date('Y-m-d', strtotime("-$i days"));
    }
    $placeholders = str_repeat('?,', count($dates) - 1) . '?';

    $stmt = $pdo->prepare("SELECT * FROM somkq_daily_calibration WHERE cal_date IN ($placeholders)");
    $stmt->execute($dates);
    $cal_map = [];
    while ($row = $stmt->fetch()) $cal_map[$row['cal_date']] = $row;

    $stmt = $pdo->prepare("SELECT record_date, COUNT(*) as cnt FROM somkq_shift_records WHERE record_date IN ($placeholders) GROUP BY record_date");
    $stmt->execute($dates);
    $rec_map = [];
    while ($row = $stmt->fetch()) $rec_map[$row['record_date']] = $row['cnt'];

    return ['dates' => $dates, 'cal_map' => $cal_map, 'rec_map' => $rec_map];
}

function get_day_details($pdo, $staff_list, $date) {
    $stmt = $pdo->prepare("SELECT * FROM somkq_daily_calibration WHERE cal_date = ?");
    $stmt->execute([$date]);
    $calibration = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM somkq_shift_records WHERE record_date = ?");
    $stmt->execute([$date]);
    $records_raw = $stmt->fetchAll();

    $record_ids = array_column($records_raw, 'id');
    $videos_map = [];
    if (!empty($record_ids)) {
        $placeholders = str_repeat('?,', count($record_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT * FROM somkq_shift_videos WHERE record_id IN ($placeholders)");
        $stmt->execute($record_ids);
        while ($v = $stmt->fetch()) {
            $videos_map[$v['record_id']][$v['timing_type']][] = $v;
        }
    }

    $staff_data = [];
    foreach ($staff_list as $staff) {
        foreach (['am', 'pm'] as $type) {
            $found = null;
            foreach ($records_raw as $r) {
                if ($r['staff_name'] === $staff && $r['shift_type'] === $type) {
                    $found = $r;
                    break;
                }
            }
            $rid = $found['id'] ?? null;
            $staff_data[$staff][$type] = [
                'record' => $found,
                'videos_start' => $rid ? ($videos_map[$rid]['start'] ?? []) : [],
                'videos_end'   => $rid ? ($videos_map[$rid]['end'] ?? []) : []
            ];
        }
    }

    return [
        'calibration' => $calibration,
        'staff_data' => $staff_data
    ];
}

function calc_display_time($time_str, $offset_seconds) {
    if (!$time_str) return '';
    if ($offset_seconds === 0 || $offset_seconds === null) return $time_str;
    $base_date = date('Y-m-d');
    $ts = strtotime("$base_date $time_str");
    $new_ts = $ts + $offset_seconds;
    return date('H:i:s', $new_ts);
}

function get_monthly_data($pdo, $staff_list, $year, $month) {
    // 生成该月所有日期
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $dates = [];
    for ($day = 1; $day <= $days_in_month; $day++) {
        $dates[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    // 获取该月所有校准数据
    $placeholders = str_repeat('?,', count($dates) - 1) . '?';
    $stmt = $pdo->prepare("SELECT * FROM somkq_daily_calibration WHERE cal_date IN ($placeholders)");
    $stmt->execute($dates);
    $cal_map = [];
    while ($row = $stmt->fetch()) {
        $cal_map[$row['cal_date']] = $row;
    }

    // 获取该月所有班次记录
    $stmt = $pdo->prepare("SELECT * FROM somkq_shift_records WHERE record_date IN ($placeholders) ORDER BY record_date, staff_name, shift_type");
    $stmt->execute($dates);
    $records_raw = $stmt->fetchAll();

    // 组织数据结构: [日期][员工][班次类型]
    $records_map = [];
    $record_ids = [];
    foreach ($records_raw as $rec) {
        $date = $rec['record_date'];
        $staff = $rec['staff_name'];
        $shift = $rec['shift_type'];
        $records_map[$date][$staff][$shift] = $rec;
        $record_ids[] = $rec['id'];
    }

    // 获取该月所有班次的视频记录
    $videos_map = [];
    if (!empty($record_ids)) {
        $placeholders_videos = str_repeat('?,', count($record_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT * FROM somkq_shift_videos WHERE record_id IN ($placeholders_videos)");
        $stmt->execute($record_ids);
        while ($v = $stmt->fetch()) {
            $videos_map[$v['record_id']][$v['timing_type']][] = $v;
        }
    }

    return [
        'dates' => $dates,
        'cal_map' => $cal_map,
        'records_map' => $records_map,
        'videos_map' => $videos_map
    ];
}

// ==========================================
// 视图层 (View) - Mobile Optimized
// ==========================================

function view_header($title) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
        <title><?php echo $title; ?> - SOMKQ</title>
        <style>
            :root { --primary: #007bff; --bg: #f2f4f8; --card: #fff; --text: #333; --border: #e6e8eb; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Helvetica Neue", Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding-bottom: 60px; -webkit-tap-highlight-color: transparent; }
            * { box-sizing: border-box; }

            .navbar { background: #333; color: #fff; padding: 15px; display: flex; align-items: center; position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
            .navbar a { color: #fff; text-decoration: none; font-size: 15px; padding: 5px 10px; margin-right: 5px; }
            .navbar .title { font-weight: 600; font-size: 17px; flex-grow: 1; text-align: center; margin-right: 40px; }

            .container { max-width: 600px; margin: 0 auto; padding: 12px; }

            .card { background: var(--card); border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.04); margin-bottom: 16px; overflow: hidden; }
            .card-header { padding: 12px 15px; background: #fff; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 16px; color: #333; display: flex; justify-content: space-between; align-items: center; }
            .card-body { padding: 15px; }

            /* --- 沉浸式时间输入框 --- */
            .time-box-wrapper { display: flex; align-items: center; border: 1px solid #ddd; border-radius: 8px; background: #f9f9f9; padding: 8px 4px; width: 100%; transition: all 0.2s; }
            .time-box-wrapper:focus-within { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px rgba(0,123,255,0.1); }
            /* 禁用状态 */
            .time-box-wrapper.disabled { background: #eee; border-color: #eee; color: #999; }
            .time-box-wrapper.disabled input { color: #999; }

            .time-input-s { width: 33%; border: none; text-align: center; font-size: 18px; padding: 0; outline: none; background: transparent; font-family: "SF Mono", Consolas, monospace; color: #333; font-weight: 500; }
            .time-sep { color: #ccc; font-weight: bold; }

            /* --- 动作区块 (Start/End) --- */
            .action-section { margin-bottom: 20px; padding-left: 12px; border-left: 4px solid #ddd; }
            .action-section.start { border-left-color: #28a745; }
            .action-section.end { border-left-color: #dc3545; }
            .action-title { font-size: 14px; color: #666; margin-bottom: 8px; font-weight: 600; display: flex; justify-content: space-between; }

            /* --- 营业结束复选框强化 --- */
            .closing-checkbox-label {
                display: flex; align-items: center; margin-top: 10px; padding: 8px;
                background: #fff5f5; border: 1px solid #ffcccc; border-radius: 6px;
                color: #c00; font-size: 14px; font-weight: 500; cursor: pointer;
            }
            .closing-checkbox-label input { margin-right: 8px; transform: scale(1.2); }

            /* --- 方块上传按钮 --- */
            .media-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 10px; }
            .media-item { position: relative; padding-top: 100%; background: #f0f0f0; border-radius: 8px; overflow: hidden; border: 1px solid #eee; }
            .media-content { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; }

            .upload-btn { background: #fff; border: 1px dashed #aaa; cursor: pointer; color: #aaa; font-size: 24px; transition: 0.2s; }
            .upload-btn:active { background: #eee; }
            .video-thumb { width: 100%; height: 100%; object-fit: cover; background: #000; }
            .video-missing { font-size: 10px; color: red; text-align: center; padding: 2px; line-height: 1.2; display: flex; align-items: center; justify-content: center; background: #fff0f0; height: 100%; width: 100%; }

            .btn { border: none; padding: 12px; border-radius: 8px; font-size: 15px; cursor: pointer; color: #fff; background: var(--primary); text-decoration: none; display: block; text-align: center; width: 100%; font-weight: 500;}
            .btn:active { opacity: 0.9; transform: scale(0.99); }

            /* 登录页 */
            .login-box { padding: 30px 20px; background: #fff; border-radius: 12px; margin-top: 40px; }
            input.login-input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 16px; margin-bottom: 20px; -webkit-appearance: none; }

            /* 监控时间tooltip样式 */
            .time-display {
                position: relative;
                display: inline-block;
                cursor: pointer;
            }
            .time-tooltip {
                position: absolute;
                bottom: 100%;
                left: 50%;
                transform: translateX(-50%);
                background: rgba(0, 0, 0, 0.85);
                color: #fff;
                padding: 6px 10px;
                border-radius: 4px;
                font-size: 11px;
                white-space: nowrap;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.15s, visibility 0.15s;
                pointer-events: none;
                z-index: 1000;
                margin-bottom: 5px;
            }
            .time-tooltip::after {
                content: '';
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                border: 5px solid transparent;
                border-top-color: rgba(0, 0, 0, 0.85);
            }
            .time-display:hover .time-tooltip,
            .time-display.active .time-tooltip {
                opacity: 1;
                visibility: visible;
            }
            /* 无视频文件的时间显示为红色 */
            .time-no-video {
                color: #dc3545 !important;
            }
        </style>
    </head>
    <body>
    <?php
}

function view_footer() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 1. 自动跳转脚本 (沉浸式输入)
        const inputs = document.querySelectorAll('.time-input-s');
        inputs.forEach((input, index) => {
            input.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length >= 2) {
                    const parent = this.closest('.time-box-wrapper');
                    const siblings = Array.from(parent.querySelectorAll('.time-input-s'));
                    const myIdx = siblings.indexOf(this);
                    if (myIdx < siblings.length - 1) {
                        siblings[myIdx + 1].focus();
                    }
                }
                calcRealTime(); // 触发实时计算
            });
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && this.value.length === 0) {
                    const parent = this.closest('.time-box-wrapper');
                    const siblings = Array.from(parent.querySelectorAll('.time-input-s'));
                    const myIdx = siblings.indexOf(this);
                    if (myIdx > 0) {
                        siblings[myIdx - 1].focus();
                    }
                }
            });
        });

        // 2. AJAX 文件上传 (增强版)
        document.body.addEventListener('change', function(e) {
            if (e.target.classList.contains('auto-upload-input')) {
                const input = e.target;
                if (input.files.length > 0) {
                    const label = input.parentElement;
                    const originalContent = label.innerHTML;
                    label.innerHTML = '<span style="font-size:12px;">⏳</span>'; // Loading state

                    const formData = new FormData();
                    formData.append('video_file', input.files[0]);

                    // 从 dataset 获取上下文参数
                    const ds = input.dataset;
                    formData.append('date', ds.date);
                    formData.append('staff_name', ds.staffName);
                    formData.append('shift_type', ds.shiftType);
                    formData.append('timing_type', ds.timingType);
                    formData.append('record_id', ds.recordId);

                    // 改用 AJAX 提交
                    fetch('?action=upload_video_ajax', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            // 刷新该格子的显示
                            // 找到 media-grid
                            const grid = label.closest('.media-grid');
                            // 创建新的 media-item 插入到 grid 前面
                            const newItem = document.createElement('div');
                            newItem.className = 'media-item';
                            newItem.innerHTML = `
                                <div class="media-content">
                                    <a href="${data.file_url}" target="_blank" style="display:block;width:100%;height:100%;">
                                        <video src="${data.file_url}#t=0.1" class="video-thumb" preload="metadata" muted></video>
                                    </a>
                                </div>
                            `;
                            // 插入在 + 号按钮之前
                            // 注意：grid 的最后一个子元素通常是 + 号按钮所在的 div
                            const uploadItem = label.closest('.media-item');
                            grid.insertBefore(newItem, uploadItem);

                            // 检查数量，如果达到3个，隐藏上传按钮 (简单逻辑)
                            if (grid.querySelectorAll('.media-item').length > 3) {
                                uploadItem.style.display = 'none';
                            }

                            // 恢复 + 号 (虽然可能被挤走，但为了下次使用)
                            label.innerHTML = '+';
                            input.value = ''; // 清空 input 以便下次选择同一文件

                            // 如果是第一次上传，更新 record_id 到页面上的隐藏域 (如果存在)
                            // 这里比较复杂，因为页面上有多个 form，但我们现在是一个大 form。
                            // 但是 upload form 是独立的。
                            // 关键是：如果新创建了 record，我们需要确保主表单也能提交正确的 update。
                            // 不过 handle_save_day_all 不依赖 record_id，而是根据 date/staff/shift_type 查找，所以没问题。

                        } else {
                            alert('上传失败: ' + data.message);
                            label.innerHTML = '+';
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('上传出错');
                        label.innerHTML = '+';
                    });
                }
            }
        });

        // 3. "至营业结束" 勾选联动逻辑
        function toggleClosingState(checkbox) {
            const container = checkbox.closest('.action-section');
            const timeWrapper = container.querySelector('.time-box-wrapper');
            const inputs = timeWrapper.querySelectorAll('input');
            const realTimeDisplay = container.querySelector('.real-time-display');

            if (checkbox.checked) {
                timeWrapper.classList.add('disabled');
                inputs.forEach(inp => inp.disabled = true); // 禁用输入
                // 更新显示文本
                if(realTimeDisplay) realTimeDisplay.textContent = '实: 营业结束';
            } else {
                timeWrapper.classList.remove('disabled');
                inputs.forEach(inp => inp.disabled = false);
                calcRealTime(); // 恢复时重新计算
            }
        }

        // 初始化所有 checkbox 状态
        document.querySelectorAll('.is-closing-check').forEach(cb => {
            toggleClosingState(cb); // 初始化
            cb.addEventListener('change', function() {
                toggleClosingState(this);
            });
        });

        // 4. 实时计算逻辑
        function getTimeFromInputs(wrapper) {
            if (!wrapper) return null;
            const h = wrapper.querySelector('.h-input').value;
            const m = wrapper.querySelector('.m-input').value;
            const s = wrapper.querySelector('.s-input').value;
            if (h === '' || m === '' || s === '') return null;
            return parseInt(h) * 3600 + parseInt(m) * 60 + parseInt(s);
        }

        function formatTime(seconds) {
            let h = Math.floor(seconds / 3600);
            let m = Math.floor((seconds % 3600) / 60);
            let s = seconds % 60;
            // 简单处理跨天 ( > 24h ) 或负数
            // 这里假设都在同一天或简单的偏移
            // 为了显示效果，允许超过24小时或负数（显示 logic 可能需要调整，但这里保持简单）
             if (h < 0) h += 24;
             if (h >= 24) h %= 24;

            return [h, m, s].map(v => v.toString().padStart(2, '0')).join(':');
        }

        function calcRealTime() {
            // 获取校准时间
            const monitorWrapper = document.querySelector('.monitor-input-group-cal-monitor');
            const realWrapper = document.querySelector('.monitor-input-group-cal-real');

            let offset = 0;
            const mTime = getTimeFromInputs(monitorWrapper);
            const rTime = getTimeFromInputs(realWrapper);

            if (mTime !== null && rTime !== null) {
                offset = rTime - mTime;
            } else {
                // 如果当前没有输入完整的校准时间，尝试读取后端传来的初始 offset
                // 但为了即时反馈，如果用户正在修改校准，应该以输入为准。
                // 如果用户没动校准输入框，getTimeFromInputs 会返回初始值 (value attribute)
                // 只要 input 有 value。
                // 如果 input 空，则 offset = 0 (或维持旧值? 暂且 0)
                const savedOffset = document.getElementById('current-offset').value;
                if (mTime === null || rTime === null) {
                   offset = parseInt(savedOffset);
                }
            }

            // 更新误差显示
            const offsetDisplay = document.getElementById('offset-display');
            if (mTime !== null && rTime !== null) {
                offsetDisplay.textContent = `误差: ${offset}s (预览)`;
                offsetDisplay.style.color = 'blue';
            }

            // 遍历所有 monitor inputs
            document.querySelectorAll('.real-time-display').forEach(display => {
                const sourceId = display.getAttribute('data-source');
                const sourceWrapper = document.querySelector(`.monitor-input-group-${sourceId}`);

                // 检查是否被 "至营业结束" 覆盖
                const container = display.closest('.action-section');
                const checkbox = container.querySelector('.is-closing-check');
                if (checkbox && checkbox.checked) {
                    display.textContent = '实: 营业结束';
                    return;
                }

                const sTime = getTimeFromInputs(sourceWrapper);
                if (sTime !== null) {
                    const rSeconds = sTime + offset;
                    display.textContent = '实: ' + formatTime(rSeconds);
                } else {
                    display.textContent = '实: --:--';
                }
            });
        }

        // 绑定校准输入框事件
        const calInputs = document.querySelectorAll('.monitor-input-group-cal-monitor input, .monitor-input-group-cal-real input');
        calInputs.forEach(input => {
            input.addEventListener('input', calcRealTime);
        });

        // 初始计算一次
        calcRealTime();

        // 处理时间tooltip的移动端点击事件
        const timeDisplays = document.querySelectorAll('.time-display');
        timeDisplays.forEach(timeDisplay => {
            timeDisplay.addEventListener('click', function(e) {
                e.stopPropagation();
                // 移除其他所有active状态
                document.querySelectorAll('.time-display.active').forEach(el => {
                    if (el !== this) el.classList.remove('active');
                });
                // 切换当前元素的active状态
                this.classList.toggle('active');
            });
        });

        // 点击空白处关闭所有tooltip
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.time-display')) {
                document.querySelectorAll('.time-display.active').forEach(el => {
                    el.classList.remove('active');
                });
            }
        });
    });
    </script>
    </body>
    </html>
    <?php
}

function view_time_input_visual($name, $value_str, $id_suffix = '') {
    $curr_h = ''; $curr_m = ''; $curr_s = '';
    if ($value_str && strpos($value_str, ':') !== false) {
        list($curr_h, $curr_m, $curr_s) = explode(':', $value_str);
    }
    $extra_class = $id_suffix ? "monitor-input-group-$id_suffix" : "";
    ?>
    <div class="time-box-wrapper <?php echo $extra_class; ?>" data-suffix="<?php echo $id_suffix; ?>">
        <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="2" placeholder="HH" name="<?php echo $name; ?>[h]" value="<?php echo $curr_h; ?>" class="time-input-s h-input">
        <span class="time-sep">:</span>
        <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="2" placeholder="MM" name="<?php echo $name; ?>[m]" value="<?php echo $curr_m; ?>" class="time-input-s m-input">
        <span class="time-sep">:</span>
        <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="2" placeholder="SS" name="<?php echo $name; ?>[s]" value="<?php echo $curr_s; ?>" class="time-input-s s-input">
    </div>
    <?php
}

// 核心组件：视频格子渲染
function view_video_grid($date, $staff, $shift_type, $timing_type, $videos, $record_id, $config, $is_admin = true) {
    ?>
    <div class="media-grid">
        <?php foreach($videos as $v):
            // 检查是否为 R2 URL（已转移到云端）
            if (strpos($v['file_name'], 'http') === 0) {
                // R2 视频：直接使用完整 URL
                $file_url = $v['file_name'];
                $exists = true; // R2 视频视为始终存在
            } else {
                // 本地视频：拼接本地路径
                $file_url = $config['url_video'] . '/' . $v['file_name'];
                $file_abs = $config['path_video_upload'] . '/' . $v['file_name'];
                $exists = file_exists($file_abs);
            }
        ?>
        <div class="media-item">
            <div class="media-content">
                <?php if($exists): ?>
                    <div style="display:flex; flex-direction:column; height:100%;">
                        <a href="<?php echo $file_url; ?>" target="_blank" style="flex:1; display:block;">
                            <video src="<?php echo $file_url; ?>#t=0.1" class="video-thumb" preload="metadata" muted></video>
                        </a>
                        <a href="<?php echo $file_url; ?>" download="<?php echo $v['original_name']; ?>"
                           style="display:block; background:#f0f0f0; text-align:center; padding:2px; font-size:9px; color:#666; text-decoration:none; border-top:1px solid #ddd;">
                            下载
                        </a>
                    </div>
                <?php else: ?>
                    <div class="video-missing">⚠️丢失</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if($is_admin && count($videos) < 3): ?>
        <div class="media-item">
            <label class="media-content upload-btn">
                +
                <input type="file" name="video_file" accept="video/*" class="auto-upload-input" style="display:none;"
                    data-date="<?php echo $date; ?>"
                    data-staff-name="<?php echo $staff; ?>"
                    data-shift-type="<?php echo $shift_type; ?>"
                    data-timing-type="<?php echo $timing_type; ?>"
                    data-record-id="<?php echo $record_id; ?>">
            </label>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function view_login($error = null) {
    ?>
    <div class="container">
        <div class="login-box">
            <h2 style="text-align:center;margin-top:0;margin-bottom:20px;">管理员验证</h2>
            <?php if($error): ?><div style="color:red;margin-bottom:15px;text-align:center;"><?php echo $error; ?></div><?php endif; ?>
            <form action="?action=do_login" method="post">
                <input type="password" name="password" class="login-input" placeholder="请输入访问密码">
                <button class="btn">进入系统</button>
            </form>
        </div>
    </div>
    <?php
}

function view_dashboard($data, $offset) {
    $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
    ?>
    <div class="navbar">
        <a href="?action=monthly_report">月度总表</a>
        <?php if($is_admin): ?>
        <a href="?action=migrate_videos" style="background: #ffc107; color: #000; border-radius: 3px; padding: 5px 10px; margin-right: 5px;">📦 转移上月视频</a>
        <?php endif; ?>
        <span class="title">工作台</span>
        <a href="?action=logout">退出</a>
    </div>
    <div class="container">
        <div style="display:flex; justify-content:space-between; margin-bottom:15px; padding:0 5px;">
             <?php if($offset > 0): ?>
                <a href="?action=home&offset=<?php echo $offset - 1; ?>" style="color:#666;text-decoration:none;">← 返回较新</a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            <a href="?action=home&offset=<?php echo $offset + 1; ?>" style="color:#666;text-decoration:none;">查看更早 →</a>
        </div>

        <?php
        $weeks = ['日','一','二','三','四','五','六'];
        foreach($data['dates'] as $date):
            $w = $weeks[date('w', strtotime($date))];
            $cal = $data['cal_map'][$date] ?? null;
            $cnt = $data['rec_map'][$date] ?? 0;
            $border_color = 'transparent';
            if ($cal) $border_color = '#28a745';
            elseif ($cnt > 0) $border_color = '#ffc107';
        ?>
            <a href="?action=day_view&date=<?php echo $date; ?>" class="card" style="display:block; text-decoration:none; color:inherit; border-left: 5px solid <?php echo $border_color; ?>;">
                <div class="card-body" style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-size:18px; font-weight:600;">
                            <?php echo date('m-d', strtotime($date)); ?>
                            <span style="font-size:14px; color:#888; font-weight:normal; margin-left:5px;">周<?php echo $w; ?></span>
                        </div>
                        <div style="font-size:12px; color:#666; margin-top:5px;">
                            <?php
                            if ($cal) echo "误差: " . ($cal['time_offset_seconds']>0?'+':'') . $cal['time_offset_seconds'] . "s";
                            else echo "未校准";
                            ?>
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <span style="font-size:20px; font-weight:bold; color:#333;"><?php echo $cnt; ?></span>
                        <div style="font-size:10px; color:#999;">记录</div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
}

function view_day_detail($date, $data) {
    global $config;
    $cal = $data['calibration'];
    $offset = $cal['time_offset_seconds'] ?? null;
    $offset_display = is_numeric($offset) ? $offset : 0;

    // 检查用户权限
    $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
    $is_readonly = ($_SESSION['user_role'] ?? '') === 'readonly';

    // 导航日期计算
    $prev_day = date('Y-m-d', strtotime($date . ' -1 day'));
    $next_day = date('Y-m-d', strtotime($date . ' +1 day'));
    ?>
    <div class="navbar">
        <a href="?action=home">← 列表</a>
        <div class="title" style="margin:0 10px; display:flex; align-items:center; justify-content:center;">
             <a href="?action=day_view&date=<?php echo $prev_day; ?>" style="font-size:18px; color:#bbb; padding:0 10px;">◀</a>
             <span><?php echo $date; ?></span>
             <a href="?action=day_view&date=<?php echo $next_day; ?>" style="font-size:18px; color:#bbb; padding:0 10px;">▶</a>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            <?php if ($is_readonly): ?>
                <span style="font-size:12px; color:#ff9800; background:#fff3e0; padding:4px 8px; border-radius:4px;">👁️ 只读模式</span>
            <?php endif; ?>
            <a href="?action=logout">退出</a>
        </div>
    </div>

    <?php if ($is_admin): ?>
    <form action="?action=save_day_all" method="post" enctype="multipart/form-data" id="main-form">
    <?php else: ?>
    <div>
    <?php endif; ?>
        <input type="hidden" name="date" value="<?php echo $date; ?>">

        <div class="container" style="padding-bottom: 80px;">
            <!-- 校准卡片 -->
            <div class="card">
                <div class="card-header">
                    <span>⏱ 时间校准</span>
                    <span style="font-size:12px; color:<?php echo is_numeric($offset)?'green':'#999'; ?>" id="offset-display">
                        <?php echo is_numeric($offset) ? "误差: {$offset}s" : "未录入"; ?>
                    </span>
                    <input type="hidden" id="current-offset" value="<?php echo $offset_display; ?>">
                </div>
                <div class="card-body">
                    <?php if ($is_admin): ?>
                        <div style="display:flex; gap:10px; margin-bottom:10px;">
                            <div style="flex:1;">
                                <label style="font-size:12px;color:#666;">监控时间</label>
                                <?php view_time_input_visual('calibration[monitor_time]', $cal['monitor_time_ref'] ?? '', 'cal-monitor'); ?>
                            </div>
                            <div style="flex:1;">
                                <label style="font-size:12px;color:#666;">实际时间</label>
                                <?php view_time_input_visual('calibration[real_time]', $cal['real_time_ref'] ?? '', 'cal-real'); ?>
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; align-items:center;">
                             <input type="file" name="cal_image" accept="image/*" style="width:100%; font-size:12px;">
                             <span style="font-size:10px; color:#999; white-space:nowrap;">(Max 2MB)</span>
                        </div>
                    <?php else: ?>
                        <div style="display:flex; gap:10px; margin-bottom:10px;">
                            <div style="flex:1;">
                                <label style="font-size:12px;color:#666;">监控时间</label>
                                <div style="padding:8px; background:#f5f5f5; border-radius:4px; font-family:monospace; color:#333;">
                                    <?php echo $cal['monitor_time_ref'] ?? '未设置'; ?>
                                </div>
                            </div>
                            <div style="flex:1;">
                                <label style="font-size:12px;color:#666;">实际时间</label>
                                <div style="padding:8px; background:#f5f5f5; border-radius:4px; font-family:monospace; color:#333;">
                                    <?php echo $cal['real_time_ref'] ?? '未设置'; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if(!empty($cal['calibration_image'])): ?>
                        <div style="margin-top:10px; font-size:12px; color:#007bff;">
                            <a href="<?php echo $config['url_image'] . '/' . $cal['calibration_image']; ?>" target="_blank">查看已上传凭证图</a>
                            |
                            <a href="<?php echo $config['url_image'] . '/' . $cal['calibration_image']; ?>" download="<?php echo $cal['calibration_image']; ?>">下载</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 班次卡片 (按班次组织) -->
            <?php
            // 为每个员工定义颜色标识
            $staff_colors = [
                'YI' => ['bg' => '#e8f5e9', 'text' => '#2e7d32'],      // 绿色系
                'JIAN' => ['bg' => '#fff8e1', 'text' => '#f57f17'],    // 黄色系
                'IRE' => ['bg' => '#fce4ec', 'text' => '#c2185b']      // 粉色系
            ];

            // 班次颜色
            $shift_colors = [
                'am' => ['bg' => '#e3f2fd', 'header' => '#90caf9'],    // 淡蓝色 (上午)
                'pm' => ['bg' => '#fff3e0', 'header' => '#ffcc80']     // 淡橙色 (下午)
            ];

            foreach(['am' => '上午班', 'pm' => '下午班'] as $type_key => $type_name):
                $colors = $shift_colors[$type_key];
            ?>
            <div class="card" style="background:<?php echo $colors['bg']; ?>;">
                <div class="card-header" style="background:<?php echo $colors['header']; ?>; border-bottom-color:<?php echo $colors['header']; ?>;">
                    <?php echo $type_key === 'am' ? '☀️' : '🌙'; ?> <?php echo $type_name; ?>
                </div>
                <div class="card-body" style="padding:0;">
                    <!-- 上班区域 -->
                    <div style="padding:15px; border-bottom:2px solid #ddd; background:rgba(40, 167, 69, 0.05);">
                        <div style="font-weight:bold; margin-bottom:15px; font-size:16px; color:#28a745; border-bottom:2px solid #28a745; padding-bottom:8px;">
                            📥 上班打卡
                        </div>
                        <?php foreach ($data['staff_data'] as $staff_name => $shifts):
                            $info = $shifts[$type_key];
                            $rec = $info['record'];
                            $record_id = $rec['id'] ?? '';
                            $m_start = $rec['start_time_monitor'] ?? '';
                            $r_start = calc_display_time($m_start, $offset);
                            $prefix = "shifts[$staff_name][$type_key]";
                            $staff_color = $staff_colors[$staff_name] ?? ['bg' => '#f5f5f5', 'text' => '#666'];
                        ?>
                        <div style="padding:12px; margin-bottom:12px; border-left:4px solid <?php echo $staff_color['text']; ?>; background:<?php echo $staff_color['bg']; ?>; border-radius:4px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <span style="font-weight:bold; color:<?php echo $staff_color['text']; ?>; font-size:15px;">
                                    👤 <?php echo $staff_name; ?>
                                </span>
                                <span class="real-time-display" data-source="<?php echo "{$staff_name}_{$type_key}_start"; ?>" style="font-family:monospace; color:#28a745; font-size:13px;">
                                    实: <?php echo $r_start ?: '--:--'; ?>
                                </span>
                            </div>
                            <?php if ($is_admin): ?>
                                <div style="margin-bottom:8px;">
                                    <label style="font-size:12px; color:#666; display:block; margin-bottom:4px;">监控时间</label>
                                    <?php view_time_input_visual($prefix . '[start_time]', $m_start, "{$staff_name}_{$type_key}_start"); ?>
                                </div>
                            <?php else: ?>
                                <div style="padding:8px; background:#fff; border-radius:4px; font-family:monospace; color:#333; margin-bottom:8px;">
                                    监控: <?php echo $m_start ?: '未设置'; ?>
                                </div>
                            <?php endif; ?>
                            <?php view_video_grid($date, $staff_name, $type_key, 'start', $info['videos_start'], $record_id, $config, $is_admin); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- 下班区域 -->
                    <div style="padding:15px; background:rgba(220, 53, 69, 0.05);">
                        <div style="font-weight:bold; margin-bottom:15px; font-size:16px; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:8px;">
                            📤 下班打卡
                        </div>
                        <?php foreach ($data['staff_data'] as $staff_name => $shifts):
                            $info = $shifts[$type_key];
                            $rec = $info['record'];
                            $record_id = $rec['id'] ?? '';
                            $m_end = $rec['end_time_monitor'] ?? '';
                            $is_closing = $rec['is_end_at_closing'] ?? 0;
                            $r_end = $is_closing ? '营业结束' : calc_display_time($m_end, $offset);
                            $prefix = "shifts[$staff_name][$type_key]";
                            $staff_color = $staff_colors[$staff_name] ?? ['bg' => '#f5f5f5', 'text' => '#666'];
                        ?>
                        <div style="padding:12px; margin-bottom:12px; border-left:4px solid <?php echo $staff_color['text']; ?>; background:<?php echo $staff_color['bg']; ?>; border-radius:4px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <span style="font-weight:bold; color:<?php echo $staff_color['text']; ?>; font-size:15px;">
                                    👤 <?php echo $staff_name; ?>
                                </span>
                                <span class="real-time-display" data-source="<?php echo "{$staff_name}_{$type_key}_end"; ?>" style="font-family:monospace; color:#dc3545; font-size:13px;">
                                    实: <?php echo $r_end ?: '--:--'; ?>
                                </span>
                            </div>
                            <?php if ($is_admin): ?>
                                <div style="margin-bottom:8px;">
                                    <label style="font-size:12px; color:#666; display:block; margin-bottom:4px;">监控时间</label>
                                    <?php view_time_input_visual($prefix . '[end_time]', $m_end, "{$staff_name}_{$type_key}_end"); ?>
                                </div>
                                <label class="closing-checkbox-label">
                                    <input type="checkbox" name="<?php echo $prefix; ?>[is_end_at_closing]" class="is-closing-check" <?php echo $is_closing?'checked':''; ?>>
                                    标记为"至营业结束"
                                </label>
                            <?php else: ?>
                                <div style="padding:8px; background:#fff; border-radius:4px; font-family:monospace; color:#333; margin-bottom:8px;">
                                    监控: <?php echo $is_closing ? '营业结束' : ($m_end ?: '未设置'); ?>
                                </div>
                            <?php endif; ?>
                            <?php view_video_grid($date, $staff_name, $type_key, 'end', $info['videos_end'], $record_id, $config, $is_admin); ?>
                        </div>
                        <?php endforeach; ?>

                        <!-- 特殊标记 (移到下班区域底部) -->
                        <?php if ($is_admin): ?>
                        <div style="margin-top:15px; padding:12px; background:#fff; border-radius:6px; border:1px solid #ddd;">
                            <label style="font-size:13px; color:#666; font-weight:bold; display:block; margin-bottom:12px;">班次特殊标记</label>
                            <?php foreach ($data['staff_data'] as $staff_name => $shifts):
                                $rec = $shifts[$type_key]['record'];
                                $prefix = "shifts[$staff_name][$type_key]";
                                $staff_color = $staff_colors[$staff_name] ?? ['text' => '#666'];
                            ?>
                            <div style="margin-bottom:10px;">
                                <label style="font-size:13px; color:<?php echo $staff_color['text']; ?>; font-weight:bold; display:block; margin-bottom:6px;">
                                    👤 <?php echo $staff_name; ?>
                                </label>
                                <select name="<?php echo $prefix; ?>[special_tag]" style="width:100%; padding:10px 12px; border:2px solid <?php echo $staff_color['text']; ?>; border-radius:6px; background:#fff; font-size:14px; color:#333; -webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: url('data:image/svg+xml;charset=UTF-8,%3Csvg%20width%3D%2212%22%20height%3D%228%22%20viewBox%3D%220%200%2012%208%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Cpath%20d%3D%22M1%201l5%205%205-5%22%20stroke%3D%22%23666%22%20stroke-width%3D%222%22%20fill%3D%22none%22%2F%3E%3C%2Fsvg%3E'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px;">
                                    <option value="">无标记</option>
                                    <option value="补货" <?php echo (isset($rec['special_tag']) && $rec['special_tag'] === '补货') ? 'selected' : ''; ?>>📦 补货</option>
                                    <option value="加班" <?php echo (isset($rec['special_tag']) && $rec['special_tag'] === '加班') ? 'selected' : ''; ?>>⏰ 加班</option>
                                    <option value="培训" <?php echo (isset($rec['special_tag']) && $rec['special_tag'] === '培训') ? 'selected' : ''; ?>>📚 培训</option>
                                    <option value="盘点" <?php echo (isset($rec['special_tag']) && $rec['special_tag'] === '盘点') ? 'selected' : ''; ?>>📋 盘点</option>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                            <?php
                            // 只读模式：显示已设置的标记
                            $has_tags = false;
                            foreach ($data['staff_data'] as $staff_name => $shifts) {
                                if (!empty($shifts[$type_key]['record']['special_tag'])) {
                                    $has_tags = true;
                                    break;
                                }
                            }
                            if ($has_tags):
                            ?>
                            <div style="margin-top:15px; padding:12px; background:#fff; border-radius:6px; border:1px solid #ddd;">
                                <label style="font-size:13px; color:#666; font-weight:bold; display:block; margin-bottom:8px;">班次特殊标记</label>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <?php foreach ($data['staff_data'] as $staff_name => $shifts):
                                        $special_tag = $shifts[$type_key]['record']['special_tag'] ?? '';
                                        if ($special_tag):
                                            $tag_emoji = ['补货' => '📦', '加班' => '⏰', '培训' => '📚', '盘点' => '📋'];
                                            $staff_color = $staff_colors[$staff_name] ?? ['text' => '#666'];
                                    ?>
                                        <span style="background:<?php echo $staff_color['text']; ?>; color:#fff; padding:6px 12px; border-radius:4px; font-size:12px; font-weight:bold;">
                                            <?php echo $staff_name; ?>: <?php echo ($tag_emoji[$special_tag] ?? '') . ' ' . $special_tag; ?>
                                        </span>
                                    <?php
                                        endif;
                                    endforeach;
                                    ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 悬浮保存条 -->
        <?php if ($is_admin): ?>
        <div style="position:fixed; bottom:0; left:0; width:100%; padding:10px; background:#fff; border-top:1px solid #ddd; box-shadow:0 -2px 10px rgba(0,0,0,0.1); display:flex; gap:10px; z-index:900;">
             <button type="submit" class="btn" style="flex:1; font-size:16px; font-weight:bold;">保存所有更改</button>
        </div>
        <?php endif; ?>
    <?php if ($is_admin): ?>
    </form>
    <?php else: ?>
    </div>
    <?php endif; ?>
    <?php
}

function view_monthly_report($year, $month, $data) {
    global $config;
    $staff_list = $config['staff_list'];

    // 导航月份计算
    $prev_month = $month - 1;
    $prev_year = $year;
    if ($prev_month < 1) {
        $prev_month = 12;
        $prev_year--;
    }

    $next_month = $month + 1;
    $next_year = $year;
    if ($next_month > 12) {
        $next_month = 1;
        $next_year++;
    }
    ?>
    <div class="navbar">
        <a href="?action=home">← 工作台</a>
        <div class="title" style="margin:0 10px; display:flex; align-items:center; justify-content:center; gap:10px;">
            <a href="?action=monthly_report&year=<?php echo $prev_year; ?>&month=<?php echo $prev_month; ?>" style="font-size:18px; color:#bbb; padding:0 5px;">◀</a>
            <form method="get" action="" style="display:flex; align-items:center; gap:5px; margin:0;">
                <input type="hidden" name="action" value="monthly_report">
                <select name="year" style="padding:4px; border:1px solid #ddd; border-radius:4px; background:#fff;" onchange="this.form.submit()">
                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y == $year) ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                    <?php endfor; ?>
                </select>
                <select name="month" style="padding:4px; border:1px solid #ddd; border-radius:4px; background:#fff;" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo ($m == $month) ? 'selected' : ''; ?>><?php echo $m; ?>月</option>
                    <?php endfor; ?>
                </select>
            </form>
            <a href="?action=monthly_report&year=<?php echo $next_year; ?>&month=<?php echo $next_month; ?>" style="font-size:18px; color:#bbb; padding:0 5px;">▶</a>
        </div>
        <a href="?action=logout">退出</a>
    </div>

    <div class="container" style="max-width:100%; padding: 12px;">
        <div class="card">
            <div class="card-header">📊 月度考勤总表</div>
            <div class="card-body" style="padding:0; overflow-x: auto;">
                <table style="width:100%; border-collapse: collapse; font-size:12px; min-width: 800px;">
                    <thead>
                        <tr style="background:#f5f5f5; position: sticky; top: 0; z-index: 10;">
                            <th style="border:1px solid #ddd; padding:8px; min-width:80px;">日期</th>
                            <?php foreach ($staff_list as $staff): ?>
                                <th colspan="2" style="border:1px solid #ddd; padding:8px; background:#e3f2fd;">
                                    <?php echo $staff; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr style="background:#fafafa; position: sticky; top: 41px; z-index: 10;">
                            <th style="border:1px solid #ddd; padding:6px;">班次</th>
                            <?php foreach ($staff_list as $staff): ?>
                                <th style="border:1px solid #ddd; padding:6px; font-size:11px; background:#fff3e0;">上午班</th>
                                <th style="border:1px solid #ddd; padding:6px; font-size:11px; background:#ffe0e0;">下午班</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['dates'] as $date):
                            $cal = $data['cal_map'][$date] ?? null;
                            $offset = $cal['time_offset_seconds'] ?? 0;
                            $day_of_week = date('w', strtotime($date));
                            $week_names = ['日','一','二','三','四','五','六'];
                            $is_weekend = ($day_of_week == 0 || $day_of_week == 6);
                            $row_bg = $is_weekend ? '#fff9f0' : '#ffffff';
                        ?>
                        <tr style="background:<?php echo $row_bg; ?>;">
                            <td style="border:1px solid #ddd; padding:6px; text-align:center; font-weight:500;">
                                <a href="?action=day_view&date=<?php echo $date; ?>" style="text-decoration:none; color:#007bff; display:block;">
                                    <?php echo date('m-d', strtotime($date)); ?><br>
                                    <span style="font-size:10px; color:#888;">周<?php echo $week_names[$day_of_week]; ?></span>
                                </a>
                            </td>
                            <?php foreach ($staff_list as $staff): ?>
                                <?php foreach (['am', 'pm'] as $shift):
                                    $rec = $data['records_map'][$date][$staff][$shift] ?? null;
                                    $start_monitor = $rec['start_time_monitor'] ?? '';
                                    $end_monitor = $rec['end_time_monitor'] ?? '';
                                    $is_closing = $rec['is_end_at_closing'] ?? 0;
                                    $record_id = $rec['id'] ?? null;
                                    $special_tag = $rec['special_tag'] ?? '';

                                    // 计算实际时间
                                    $start_real = $start_monitor ? calc_display_time($start_monitor, $offset) : '';
                                    $end_real = $is_closing ? '营业结束' : ($end_monitor ? calc_display_time($end_monitor, $offset) : '');

                                    // 判断是否有校准（是否显示实际时间）
                                    $has_calibration = ($cal !== null && $offset != 0);

                                    // 显示逻辑：如果有校准，显示实际时间；否则显示监控时间并注明
                                    if ($has_calibration) {
                                        $display_start = $start_real;
                                        $display_end = $end_real;
                                        $time_label = '';
                                    } else {
                                        $display_start = $start_monitor;
                                        $display_end = $is_closing ? '营业结束' : $end_monitor;
                                        $time_label = '<span style="color:#999; font-size:9px;">(监控)</span>';
                                    }

                                    // 检查是否有视频记录
                                    $has_start_video = false;
                                    $has_end_video = false;
                                    if ($record_id && isset($data['videos_map'][$record_id])) {
                                        $has_start_video = !empty($data['videos_map'][$record_id]['start']);
                                        $has_end_video = !empty($data['videos_map'][$record_id]['end']);
                                    }

                                    // 检查是否有校准图片（仅当班次为上午班时显示）
                                    $has_cal_image = ($shift === 'am' && !empty($cal['calibration_image']));

                                    // 异常检测：检查是否只有上班或只有下班时间
                                    $has_incomplete_record = false;
                                    $incomplete_type = ''; // 'missing_end' 或 'missing_start'

                                    // 只有上班时间，没有下班时间（且不是"营业结束"）
                                    if ($display_start && !$display_end && !$is_closing) {
                                        $has_incomplete_record = true;
                                        $incomplete_type = 'missing_end';
                                    }
                                    // 只有下班时间，没有上班时间
                                    elseif (!$display_start && $display_end) {
                                        $has_incomplete_record = true;
                                        $incomplete_type = 'missing_start';
                                    }

                                    // 为不同员工定义不同的警告边框颜色
                                    $staff_warning_colors = [
                                        'YI' => '#dc3545',      // 红色
                                        'JIAN' => '#fd7e14',    // 橙色
                                        'IRE' => '#e83e8c'      // 粉红色
                                    ];
                                    $warning_color = $staff_warning_colors[$staff] ?? '#dc3545';

                                    // 单元格样式
                                    $cell_style = 'border:1px solid #ddd; padding:6px; font-size:11px; font-family:monospace;';
                                    if ($has_incomplete_record) {
                                        $cell_style = "border:3px solid $warning_color; padding:4px; font-size:11px; font-family:monospace; background:#fff3cd; position:relative;";
                                    }
                                ?>
                                <td style="<?php echo $cell_style; ?>">
                                    <?php if ($display_start || $display_end || $special_tag): ?>
                                        <?php if ($has_incomplete_record): ?>
                                            <div style="position:absolute; top:2px; right:2px;">
                                                <span style="color:<?php echo $warning_color; ?>; font-size:14px; font-weight:bold;" title="<?php echo $incomplete_type === 'missing_end' ? '缺少下班时间' : '缺少上班时间'; ?>">⚠️</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($special_tag): ?>
                                            <div style="margin-bottom:4px; text-align:center;">
                                                <span style="background:#ff9800; color:#fff; padding:2px 6px; border-radius:3px; font-size:9px; font-weight:bold; display:inline-block;">
                                                    <?php
                                                    $tag_emoji = [
                                                        '补货' => '📦',
                                                        '加班' => '⏰',
                                                        '培训' => '📚',
                                                        '盘点' => '📋'
                                                    ];
                                                    echo ($tag_emoji[$special_tag] ?? '') . ' ' . $special_tag;
                                                    ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <div style="margin-bottom:2px;">
                                            <span style="color:#28a745;">上:</span>
                                            <?php if ($display_start): ?>
                                                <span class="time-display <?php echo !$has_start_video ? 'time-no-video' : ''; ?>">
                                                    <?php echo $display_start; ?>
                                                    <span class="time-tooltip">监控时间: <?php echo $start_monitor ?: '--'; ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:#ccc;">--</span>
                                            <?php endif; ?>
                                            <?php if ($has_start_video): ?>
                                                <span style="color:#007bff; font-size:10px; margin-left:2px;" title="有视频记录">🎥</span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <span style="color:#dc3545;">下:</span>
                                            <?php if ($display_end): ?>
                                                <span class="time-display <?php echo !$has_end_video ? 'time-no-video' : ''; ?>">
                                                    <?php echo $display_end; ?>
                                                    <span class="time-tooltip">监控时间: <?php echo $is_closing ? '营业结束' : ($end_monitor ?: '--'); ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:#ccc;">--</span>
                                            <?php endif; ?>
                                            <?php if ($has_end_video): ?>
                                                <span style="color:#007bff; font-size:10px; margin-left:2px;" title="有视频记录">🎥</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($has_cal_image): ?>
                                            <div style="margin-top:2px;">
                                                <span style="color:#ff9800; font-size:10px;" title="有校准图片">📷</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!$has_calibration && ($display_start || $display_end)): ?>
                                            <div style="text-align:center; margin-top:2px;"><?php echo $time_label; ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#ccc; font-style:italic;">无记录</span>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 按人员统计 -->
        <div style="margin-top: 20px; padding: 15px; background: #fff; border-radius: 8px; font-size: 13px; font-family: monospace;">
            <div style="font-weight: bold; margin-bottom: 15px; font-size: 16px; font-family: sans-serif; color: #333;">📋 按人员统计</div>
            <?php foreach ($staff_list as $staff):
                // 为不同员工定义颜色
                $staff_colors_map = [
                    'YI' => '#28a745',      // 绿色
                    'JIAN' => '#fd7e14',    // 橙色
                    'IRE' => '#e83e8c'      // 粉色
                ];
                $staff_color = $staff_colors_map[$staff] ?? '#007bff';
            ?>
                <div style="margin-bottom: 20px;">
                    <div style="font-weight: bold; font-size: 14px; color: <?php echo $staff_color; ?>; border-bottom: 2px solid <?php echo $staff_color; ?>; padding-bottom: 5px; margin-bottom: 10px;">
                        ===== <?php echo $staff; ?> =====
                    </div>
                    <div style="line-height: 1.8;">
                        <?php foreach ($data['dates'] as $date):
                            $cal = $data['cal_map'][$date] ?? null;
                            $offset = $cal['time_offset_seconds'] ?? 0;
                            $day_of_week = date('w', strtotime($date));
                            $week_names = ['日','一','二','三','四','五','六'];

                            // 获取上午班和下午班的记录
                            $am_rec = $data['records_map'][$date][$staff]['am'] ?? null;
                            $pm_rec = $data['records_map'][$date][$staff]['pm'] ?? null;

                            // 判断是否有校准
                            $has_calibration = ($cal !== null && $offset != 0);

                            // 格式化上午班时间
                            $am_start_monitor = $am_rec['start_time_monitor'] ?? '';
                            $am_end_monitor = $am_rec['end_time_monitor'] ?? '';
                            $am_is_closing = $am_rec['is_end_at_closing'] ?? 0;
                            $am_special_tag = $am_rec['special_tag'] ?? '';

                            if ($has_calibration) {
                                $am_start = $am_start_monitor ? calc_display_time($am_start_monitor, $offset) : '';
                                $am_end = $am_is_closing ? '营业结束' : ($am_end_monitor ? calc_display_time($am_end_monitor, $offset) : '');
                            } else {
                                $am_start = $am_start_monitor;
                                $am_end = $am_is_closing ? '营业结束' : $am_end_monitor;
                            }

                            // 格式化上午班显示
                            if ($am_start && $am_end) {
                                $am_display = substr($am_start, 0, 5) . '-' . ($am_end === '营业结束' ? '营业结束' : substr($am_end, 0, 5));
                            } elseif ($am_start) {
                                $am_display = substr($am_start, 0, 5) . '-（缺失）';
                            } elseif ($am_end) {
                                $am_display = '（缺失）-' . ($am_end === '营业结束' ? '营业结束' : substr($am_end, 0, 5));
                            } else {
                                $am_display = '--';
                            }

                            // 添加上午班特殊标记
                            if ($am_special_tag) {
                                $tag_emoji_map = [
                                    '补货' => '📦',
                                    '加班' => '⏰',
                                    '培训' => '📚',
                                    '盘点' => '📋'
                                ];
                                $am_tag_emoji = $tag_emoji_map[$am_special_tag] ?? '';
                                $am_display .= ' [' . $am_tag_emoji . $am_special_tag . ']';
                            }

                            // 格式化下午班时间
                            $pm_start_monitor = $pm_rec['start_time_monitor'] ?? '';
                            $pm_end_monitor = $pm_rec['end_time_monitor'] ?? '';
                            $pm_is_closing = $pm_rec['is_end_at_closing'] ?? 0;
                            $pm_special_tag = $pm_rec['special_tag'] ?? '';

                            if ($has_calibration) {
                                $pm_start = $pm_start_monitor ? calc_display_time($pm_start_monitor, $offset) : '';
                                $pm_end = $pm_is_closing ? '营业结束' : ($pm_end_monitor ? calc_display_time($pm_end_monitor, $offset) : '');
                            } else {
                                $pm_start = $pm_start_monitor;
                                $pm_end = $pm_is_closing ? '营业结束' : $pm_end_monitor;
                            }

                            // 格式化下午班显示
                            if ($pm_start && $pm_end) {
                                $pm_display = substr($pm_start, 0, 5) . '-' . ($pm_end === '营业结束' ? '营业结束' : substr($pm_end, 0, 5));
                            } elseif ($pm_start) {
                                $pm_display = substr($pm_start, 0, 5) . '-（缺失）';
                            } elseif ($pm_end) {
                                $pm_display = '（缺失）-' . ($pm_end === '营业结束' ? '营业结束' : substr($pm_end, 0, 5));
                            } else {
                                $pm_display = '--';
                            }

                            // 添加下午班特殊标记
                            if ($pm_special_tag) {
                                $tag_emoji_map = [
                                    '补货' => '📦',
                                    '加班' => '⏰',
                                    '培训' => '📚',
                                    '盘点' => '📋'
                                ];
                                $pm_tag_emoji = $tag_emoji_map[$pm_special_tag] ?? '';
                                $pm_display .= ' [' . $pm_tag_emoji . $pm_special_tag . ']';
                            }

                            // 生成监控时间显示
                            $am_monitor_display = '';
                            if ($am_start_monitor && $am_end_monitor) {
                                $am_monitor_display = substr($am_start_monitor, 0, 5) . '-' . ($am_is_closing ? '营业结束' : substr($am_end_monitor, 0, 5));
                            } elseif ($am_start_monitor) {
                                $am_monitor_display = substr($am_start_monitor, 0, 5) . '-（缺失）';
                            } elseif ($am_end_monitor) {
                                $am_monitor_display = '（缺失）-' . ($am_is_closing ? '营业结束' : substr($am_end_monitor, 0, 5));
                            }

                            $pm_monitor_display = '';
                            if ($pm_start_monitor && $pm_end_monitor) {
                                $pm_monitor_display = substr($pm_start_monitor, 0, 5) . '-' . ($pm_is_closing ? '营业结束' : substr($pm_end_monitor, 0, 5));
                            } elseif ($pm_start_monitor) {
                                $pm_monitor_display = substr($pm_start_monitor, 0, 5) . '-（缺失）';
                            } elseif ($pm_end_monitor) {
                                $pm_monitor_display = '（缺失）-' . ($pm_is_closing ? '营业结束' : substr($pm_end_monitor, 0, 5));
                            }

                            // 只显示有记录的日期
                            if ($am_display !== '--' || $pm_display !== '--'):
                        ?>
                            <div style="padding: 2px 0;">
                                <a href="?action=day_view&date=<?php echo $date; ?>" style="text-decoration: none; color: #007bff;">
                                    <?php echo date('m-d', strtotime($date)); ?> 周<?php echo $week_names[$day_of_week]; ?>
                                </a>
                                <span style="color: #666;">
                                    &nbsp;&nbsp;上午: <?php echo $am_display; ?>
                                    <?php if ($am_monitor_display && $has_calibration): ?>
                                        <span style="color: #999; font-size: 11px;"> (监控: <?php echo $am_monitor_display; ?>)</span>
                                    <?php endif; ?>
                                    &nbsp;&nbsp;下午: <?php echo $pm_display; ?>
                                    <?php if ($pm_monitor_display && $has_calibration): ?>
                                        <span style="color: #999; font-size: 11px;"> (监控: <?php echo $pm_monitor_display; ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php
                            endif;
                        endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top: 15px; padding: 10px; background: #fff; border-radius: 8px; font-size: 12px; color: #666;">
            <div style="font-weight: bold; margin-bottom: 8px;">说明：</div>
            <ul style="margin: 0; padding-left: 20px;">
                <li><strong>点击日期</strong>可以跳转到当天的详细记录页面</li>
                <li>使用顶部的<strong>年份和月份选择器</strong>可以快速跳转到任意月份，或使用 ◀ ▶ 按钮逐月浏览</li>
                <li>显示时间优先级：如果当天有校准数据，显示<strong>实际时间</strong>；否则显示<strong>监控时间</strong>并标注"(监控)"</li>
                <li><strong>监控时间查看：</strong>
                    <ul style="margin-top: 5px;">
                        <li>在<strong>月度考勤总表</strong>中，将鼠标悬停在时间上可查看监控时间</li>
                        <li>在<strong>按人员统计</strong>中，有校准的日期会在时间后显示监控时间</li>
                    </ul>
                </li>
                <li>周末行以浅黄色背景显示</li>
                <li><span style="color:#28a745;">上:</span> 表示上班时间，<span style="color:#dc3545;">下:</span> 表示下班时间</li>
                <li>"营业结束"表示该班次工作至营业结束时间</li>
                <li>🎥 表示该时间点有视频记录，📷 表示有校准图片</li>
                <li>橙色标签显示特殊标记（如 📦 补货、⏰ 加班、📚 培训、📋 盘点），可在日常记录页面设置</li>
                <li><strong>⚠️ 异常标识：</strong>当班次记录不完整时（只有上班时间或只有下班时间），会显示：
                    <ul style="margin-top: 5px;">
                        <li><span style="color:#dc3545;">红色粗边框</span> = YI 的异常记录</li>
                        <li><span style="color:#fd7e14;">橙色粗边框</span> = JIAN 的异常记录</li>
                        <li><span style="color:#e83e8c;">粉色粗边框</span> = IRE 的异常记录</li>
                        <li>浅黄色背景 + 右上角警告图标 ⚠️ 表示记录不完整，需要补充</li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
    <?php
}
?>