<?php
// 防止直接访问（虽然目录限制了，但多加一层保险）
if (!defined('IN_APP')) {
    exit('Access Denied');
}

return [
    // -------------------------
    // 基础安全配置
    // -------------------------
    // 管理员密码 (建议生产环境使用 password_hash 生成的哈希值，此处为示例明文，建议修改)
    // 假设密码是 '123456'
    'admin_password' => '123456', 
    
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
    'path_video_upload' => '/dc_html/somkq/uploads/videos',
    
    // 图片上传物理目录 (无末尾斜杠)
    'path_image_upload' => '/dc_html/somkq/uploads/images',

    // -------------------------
    // URL 配置 (用于前端访问)
    // -------------------------
    // 基础 URL (无末尾斜杠)
    'base_url' => '/somkq',
    
    // 视频访问 URL 前缀
    'url_video' => '/somkq/uploads/videos',
    
    // 图片访问 URL 前缀
    'url_image' => '/somkq/uploads/images',
];