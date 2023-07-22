<?php

namespace Verot\Upload;

require_once __DIR__ . '/../app/function.php';
require_once APP_ROOT . '/app/class.upload.php';
require_once APP_ROOT . '/config/api_key.php';

// 允许跨域 https://stackoverflow.com/questions/8719276/cross-origin-request-headerscors-with-php-headers
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: PUT, POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: X-Requested-With, Content-Type, Origin, Cache-Control, Pragma, Authorization, Accept, Accept-Encoding");
// 定义返回头信息为Json
header("Content-type: application/json; charset=utf-8");

// 无文件
if (empty($_FILES['image'])) {
    exit(json_encode(
        array(
            "result"    =>  "failed",
            "code"      =>  204,
            "message"   =>  "没有选择上传的文件",
        )
    ));
}

// 黑/白IP名单上传
if ($config['check_ip']) {
    if (checkIP(null, $config['check_ip_list'], $config['check_ip_model'])) {
        // 上传错误 code:205 未授权IP
        exit(json_encode(array(
            "result"    =>  "failed",
            "code"      =>  205,
            "message"   =>  "黑名单内或白名单外用户不允许上传",
        )));
    }
}

$token = preg_replace('/[\W]/', '', $_POST['token']); // 获取Token并过滤非字母数字，删除空格;

// 检查api合法性
check_api($token);
$tokenID = $tokenList[$token]['id'];

$handle = new Upload($_FILES['image'], 'zh_CN');

if ($handle->uploaded) {
    // 允许上传的mime类型
    if ($config['allowed'] === 1) {
        $handle->allowed = array('image/*');
    }

    // 检查svg是否存在script和a标签代码
    if ($handle->file_src_name_ext === 'svg') {
        $svg = file_get_contents($handle->file_src_pathname);
        if (preg_match('/<script[\s\S]*?<\/script>/', $svg) || stripos($svg, 'href=')) {
            exit(json_encode(
                array(
                    "result"  => "failed",
                    "code"    => 205,
                    "message" => "请勿上传非法文件",
                )
            ));
        }
    }

    // 文件命名
    $handle->file_new_name_body = imgName($handle->file_src_name_body);
    // 添加Token ID
    $handle->file_name_body_add = '-' . $tokenID;
    // 最大上传限制
    $handle->file_max_size = $config['maxSize'];
    // 最大宽度
    $handle->image_max_width = $config['maxWidth'];
    // 最大高度
    $handle->image_max_height = $config['maxHeight'];
    // 最小宽度
    $handle->image_min_width = $config['minWidth'];
    // 最小高度
    $handle->image_min_height = $config['minHeight'];
    // 2023-01-06 转换图片为指定格式 只转换非webp格式和非动态图片
    if ($handle->file_src_name_ext !== 'webp' && !isGifAnimated($handle->file_src_pathname)) {
        $handle->image_convert = $config['imgConvert'];
    }
    // 2023-01-06 PNG 图像的压缩级别，介于 1（快速但大文件）和 9（慢但较小文件）之间
    $handle->png_compression = 9 - round($config['compress_ratio'] / 11.2);
    // WEBP 图像的压缩质量 1-100
    $handle->webp_quality = $config['compress_ratio'];
    // JPEG 图像的压缩质量 1-100
    $handle->jpeg_quality = $config['compress_ratio'];

    /* 等比例缩减图片 放到前端了*/
    /*
    if ($config['imgRatio']) {
        $handle->image_resize = true;
        $handle->image_x = $config['image_x'];
        $handle->image_y = $config['image_y'];
        // 如果调整后的图像大于原始图像，则取消调整大小，以防止放大
        $handle->image_no_enlarging = true;
    }
    */

    // 默认目录
    $Img_path = config_path();

    if ($config['token_path_status'] == 1) {
        $Img_path = config_path($tokenID . date('/Y/m/d/'));
    }

    // 存储图片路径:images/201807/
    $handle->process(APP_ROOT . $Img_path);

    // 图片完整相对路径:/i/2021/05/03/k88e7p.jpg
    if ($handle->processed) {
        // 图片相对路径
        $pathIMG = $Img_path . $handle->file_dst_name;
        // 图片访问网址
        $imageUrl = rand_imgurl() . $pathIMG;
        // 后续处理地址
        $processUrl = $config['domain'] . $pathIMG;

        // 隐藏config文件中的path目录,需要搭配网站设置
        if ($config['hide_path'] == 1) {
            $imageUrl = str_replace($config['path'], '/', $imageUrl);
        }

        // 源图保护 key值是由crc32加密的hide_key
        if ($config['hide'] == 1) {
            $imageUrl = $config['domain'] . '/app/hide.php?key=' . urlHash($pathIMG, 0, crc32($config['hide_key']));
        }

        // 删除文件链接
        if ($config['show_user_hash_del']) {
            $delUrl = $config['domain'] . '/app/del.php?hash=' . urlHash($pathIMG, 0);
        } else {
            $delUrl = "Admin closed user delete";
        }

        // 当设置访问生成缩略图时自动生成 2022-12-30 修正 2023-01-30
        $handleThumb = $config['domain'] . '/app/thumb.php?img=' . $pathIMG;
        if ($config['thumbnail'] == 2) {
            // 自定义缩略图长宽
            $handle->image_resize = true;
            $handle->image_x = $config['thumbnail_w'];
            $handle->image_y = $config['thumbnail_h'];
            // 如果调整后的图像大于原始图像，则取消调整大小，以防止放大
            $handle->image_no_enlarging = true;
            $handle->file_new_name_body = date('Y_m_d_') . $handle->file_dst_name_body;
            $handle->process(APP_ROOT . $config['path'] . 'cache/');
            $handleThumb = $config['domain'] . $config['path'] . 'cache/' . $handle->file_dst_name;
        }

        // 上传成功后返回json数据
        $reJson = array(
            "result"  => "success",
            "code"    => 200,
            "url"     => $imageUrl,
            "srcName" => $handle->file_src_name_body,
            "thumb"   => $handleThumb,
            "del"     => $delUrl,
            "ID"      => $tokenID, // 202-02-11 增加返回Token ID
            "messge"  => "success",
            // "memory" => getDistUsed(memory_get_peak_usage()), // 占用内存 2023-02-12
        );
        echo json_encode($reJson, JSON_UNESCAPED_UNICODE);
        $handle->clean();
    } else {
        // 上传错误 code:206 客户端文件有问题
        $reJson = array(
            "result"  => "failed",
            "code"    => 206,
            "message" => $handle->error,
            "memory"  => getDistUsed(memory_get_peak_usage()), // 占用内存 2023-02-12
            // 'log' => $handle->log, // 仅用作调试用
        );
        unset($handle);
        exit(json_encode($reJson, JSON_UNESCAPED_UNICODE));
    }

    /** 后续处理 */
    // 上传至其他位置
    // @any_upload($pathIMG, APP_ROOT . $pathIMG, 'upload');
    // 使用fastcgi_finish_request操作
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    // 同IP上传日志
    @write_ip_upload_count_logs();
    // 日志
    @write_upload_logs($pathIMG, $handle->file_src_name, $handle->file_dst_pathname, $handle->file_src_size);
    // 鉴黄
    @process_checkImg($processUrl);
    // 水印
    @water($handle->file_dst_pathname);
    // 压缩
    @process_compress($handle->file_dst_pathname);

    unset($handle);
}
