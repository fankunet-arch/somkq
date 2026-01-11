<?php
// 防止直接访问（虽然目录限制了，但多加一层保险）
if (!defined('IN_APP')) {
    exit('Access Denied');
}

return [
    // -------------------------
    // 基础安全配置
    // -------------------------
    // 管理员密码 (完整权限：可查看、编辑、添加、删除)
    // 建议生产环境使用 password_hash 生成的哈希值，此处为示例明文，建议修改
    // 假设密码是 '123456'
    'admin_password' => '123456',

    // 只读密码 (只读权限：只能查看和下载，不能编辑)
    // 假设只读密码是 'view123'
    'readonly_password' => 'view123',

    // Session 名称
    'session_name' => 'somkq_sess',

    // -------------------------
    // 业务配置
    // -------------------------
    // 员工列表 (顺序对应显示顺序)
    'staff_list' => [
        'YI',
        'JIAN',
        'IRE'
    ],

    // -------------------------
    // 路径配置 (绝对路径)
    // -------------------------
    // 视频上传物理目录 (无末尾斜杠)
    'path_video_upload' => __DIR__ . '/../../dc_html/somkq/uploads/videos',

    // 图片上传物理目录 (无末尾斜杠)
    'path_image_upload' => __DIR__ . '/../../dc_html/somkq/uploads/images',

    // 图片归档目录 (无末尾斜杠)
    'path_image_archive' => __DIR__ . '/../../dc_html/somkq/uploads/images/archived',

    // 视频归档目录 (无末尾斜杠)
    'path_video_archive' => __DIR__ . '/../../dc_html/somkq/uploads/videos/archived',

    // -------------------------
    // URL 配置 (用于前端访问)
    // -------------------------
    // 基础 URL (无末尾斜杠)
    'base_url' => '/somkq',

    // 视频访问 URL 前缀
    'url_video' => '/somkq/uploads/videos',

    // 图片访问 URL 前缀
    'url_image' => '/somkq/uploads/images',

    // -------------------------
    // Cloudflare R2 配置
    // -------------------------
    'r2_account_id' => '5abbc858234958e8b524efcca03de6bf',
    'r2_access_key_id' => '335ddb5dc9d85c57a1a47f5e549b0c51',
    'r2_secret_access_key' => '68100f3a4a1c75924c3e57f3cd4d5625fe653dd9484c5115f404d1039307afac',
    'r2_bucket_name' => 'vis-videos',
    'r2_endpoint' => 'https://5abbc858234958e8b524efcca03de6bf.r2.cloudflarestorage.com',
    'r2_region' => 'auto',
    'r2_public_url' => 'https://vis.dc.abcabc.net', // R2公共访问域名
];