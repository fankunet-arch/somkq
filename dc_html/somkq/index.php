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
            handle_save_calibration($pdo, $config);
            break;

        case 'save_shift':
            handle_save_shift($pdo);
            break;

        case 'save_day_all':
            handle_save_day_all($pdo, $config);
            break;

        case 'upload_video':
            handle_upload_video($pdo, $config);
            break;

        case 'upload_video_ajax':
            handle_upload_video_ajax($pdo, $config);
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

                // 查找现有记录 ID
                $stmt = $pdo->prepare("SELECT id FROM somkq_shift_records WHERE record_date=? AND staff_name=? AND shift_type=?");
                $stmt->execute([$date, $staff, $shift_type]);
                $existing_id = $stmt->fetchColumn();

                if ($existing_id) {
                    $stmt = $pdo->prepare("UPDATE somkq_shift_records SET start_time_monitor=?, end_time_monitor=?, is_end_at_closing=? WHERE id=?");
                    $stmt->execute([$start, $end, $is_closing, $existing_id]);
                } else {
                    // 只有当有数据输入时才插入新记录
                    if ($start || $end || $is_closing) {
                        $stmt = $pdo->prepare("INSERT INTO somkq_shift_records (record_date, staff_name, shift_type, start_time_monitor, end_time_monitor, is_end_at_closing) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$date, $staff, $shift_type, $start, $end, $is_closing]);
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
function view_video_grid($date, $staff, $shift_type, $timing_type, $videos, $record_id, $config) {
    ?>
    <div class="media-grid">
        <?php foreach($videos as $v):
            $file_url = $config['url_video'] . '/' . $v['file_name'];
            $file_abs = $config['path_video_upload'] . '/' . $v['file_name'];
            $exists = file_exists($file_abs);
        ?>
        <div class="media-item">
            <div class="media-content">
                <?php if($exists): ?>
                    <a href="<?php echo $file_url; ?>" target="_blank" style="display:block;width:100%;height:100%;">
                        <video src="<?php echo $file_url; ?>#t=0.1" class="video-thumb" preload="metadata" muted></video>
                    </a>
                <?php else: ?>
                    <div class="video-missing">⚠️丢失</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if(count($videos) < 3): ?>
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
    ?>
    <div class="navbar">
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
        <div style="width:40px;"></div> <!-- 占位保持居中 -->
    </div>

    <form action="?action=save_day_all" method="post" enctype="multipart/form-data" id="main-form">
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
                    <?php if(!empty($cal['calibration_image'])): ?>
                        <div style="margin-top:10px; font-size:12px; color:#007bff;">
                            <a href="<?php echo $config['url_image'] . '/' . $cal['calibration_image']; ?>" target="_blank">查看已上传凭证图</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 员工班次卡片 -->
            <?php foreach ($data['staff_data'] as $staff_name => $shifts): ?>
            <div class="card">
                <div class="card-header" style="background:#f8f9fa;">
                    👤 <?php echo $staff_name; ?>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php foreach(['am' => '上午班', 'pm' => '下午班'] as $type_key => $type_name):
                        $info = $shifts[$type_key];
                        $rec = $info['record'];
                        $record_id = $rec['id'] ?? '';
                        $m_start = $rec['start_time_monitor'] ?? '';
                        $m_end = $rec['end_time_monitor'] ?? '';
                        $is_closing = $rec['is_end_at_closing'] ?? 0;
                        $r_start = calc_display_time($m_start, $offset);
                        $r_end = $is_closing ? '营业结束' : calc_display_time($m_end, $offset);

                        $prefix = "shifts[$staff_name][$type_key]";
                    ?>
                    <div style="padding:15px; border-bottom:1px solid #eee;">
                        <div style="font-weight:bold; margin-bottom:12px; font-size:16px; color:#444;">
                            <?php echo $type_name; ?>
                        </div>

                        <!-- 隐藏域: 记录ID, 方便后端判断是更新还是插入 -->
                        <!-- 注意：统一保存模式下，后端主要根据 date+staff+shift_type 定位，record_id 辅助 -->

                        <div class="action-section start">
                            <div class="action-title">
                                <span>上班 (监控)</span>
                                <span class="real-time-display" data-source="<?php echo "{$staff_name}_{$type_key}_start"; ?>" style="font-weight:normal; font-family:monospace; color:#28a745;">
                                    实: <?php echo $r_start ?: '--:--'; ?>
                                </span>
                            </div>
                            <?php view_time_input_visual($prefix . '[start_time]', $m_start, "{$staff_name}_{$type_key}_start"); ?>
                            <?php view_video_grid($date, $staff_name, $type_key, 'start', $info['videos_start'], $record_id, $config); ?>
                        </div>

                        <div class="action-section end">
                            <div class="action-title">
                                <span>下班 (监控)</span>
                                <span class="real-time-display" data-source="<?php echo "{$staff_name}_{$type_key}_end"; ?>" style="font-weight:normal; font-family:monospace; color:#dc3545;">
                                    实: <?php echo $r_end ?: '--:--'; ?>
                                </span>
                            </div>
                            <?php view_time_input_visual($prefix . '[end_time]', $m_end, "{$staff_name}_{$type_key}_end"); ?>

                            <label class="closing-checkbox-label">
                                <input type="checkbox" name="<?php echo $prefix; ?>[is_end_at_closing]" class="is-closing-check" <?php echo $is_closing?'checked':''; ?>>
                                标记为“至营业结束”
                            </label>

                            <?php view_video_grid($date, $staff_name, $type_key, 'end', $info['videos_end'], $record_id, $config); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 悬浮保存条 -->
        <div style="position:fixed; bottom:0; left:0; width:100%; padding:10px; background:#fff; border-top:1px solid #ddd; box-shadow:0 -2px 10px rgba(0,0,0,0.1); display:flex; gap:10px; z-index:900;">
             <button type="submit" class="btn" style="flex:1; font-size:16px; font-weight:bold;">保存所有更改</button>
        </div>
    </form>
    <?php
}
?>