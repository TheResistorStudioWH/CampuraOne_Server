<?php
header('Content-Type: application/json; charset=utf-8');

function response_json($code, $message, $data = null) {
    echo json_encode([
        "code" => $code,
        "message" => $message,
        "data" => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response_json(405, "method not allowed");
}

if (!isset($_FILES['image'])) {
    response_json(400, "missing image file");
}

$file = $_FILES['image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    response_json(400, "upload failed", [
        "error" => $file['error']
    ]);
}

$adID = isset($_POST['adID']) ? intval($_POST['adID']) : 0;
$type = isset($_POST['type']) ? strtoupper(trim($_POST['type'])) : "";

$allowedAdTypes = ["S", "L"];

if (!in_array($type, $allowedAdTypes, true)) {
    response_json(400, "invalid ad type", [
        "allowedTypes" => $allowedAdTypes
    ]);
}

// 限制 5MB
$maxSize = 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    response_json(400, "image too large", [
        "maxSizeMB" => 5
    ]);
}

// 校验真实 MIME 类型，不只看后缀
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

$allowedMimeTypes = [
    "image/jpeg" => "jpg",
    "image/png" => "png",
    "image/webp" => "webp"
];

if (!array_key_exists($mimeType, $allowedMimeTypes)) {
    response_json(400, "unsupported image type", [
        "mimeType" => $mimeType,
        "allowedMimeTypes" => array_keys($allowedMimeTypes)
    ]);
}

$extension = $allowedMimeTypes[$mimeType];

// 读取图片尺寸
$imageInfo = getimagesize($file['tmp_name']);

if ($imageInfo === false) {
    response_json(400, "invalid image file");
}

$width = intval($imageInfo[0]);
$height = intval($imageInfo[1]);

if ($width <= 0 || $height <= 0) {
    response_json(400, "invalid image size");
}

// 尺寸规则
$rules = [
    "L" => [
        "maxWidth" => 1200,
        "maxHeight" => 400,
        "ratioWidth" => 3,
        "ratioHeight" => 1,
        "description" => "L size should be 1200x400 or same ratio smaller"
    ],
    "S" => [
        "maxWidth" => 600,
        "maxHeight" => 600,
        "ratioWidth" => 1,
        "ratioHeight" => 1,
        "description" => "S size should be 600x600 or same ratio smaller"
    ]
];

$rule = $rules[$type];

if ($width > $rule["maxWidth"] || $height > $rule["maxHeight"]) {
    response_json(400, "image size too large for ad type", [
        "type" => $type,
        "width" => $width,
        "height" => $height,
        "maxWidth" => $rule["maxWidth"],
        "maxHeight" => $rule["maxHeight"],
        "requirement" => $rule["description"]
    ]);
}

// 等比例校验：避免浮点误差，用交叉相乘
// L: width / height == 3 / 1
// S: width / height == 1 / 1
if ($width * $rule["ratioHeight"] !== $height * $rule["ratioWidth"]) {
    response_json(400, "invalid image ratio for ad type", [
        "type" => $type,
        "width" => $width,
        "height" => $height,
        "requiredRatio" => $rule["ratioWidth"] . ":" . $rule["ratioHeight"],
        "requirement" => $rule["description"]
    ]);
}

// 图片保存到 uploads，不保存到 api
$uploadDir = "/www/wwwroot/129.211.189.35/uploads/ads/imgs/";

if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        response_json(500, "failed to create upload directory");
    }
}

$fileName =
    "ad_" .
    $type . "_" .
    ($adID > 0 ? $adID . "_" : "") .
    date("Ymd_His") .
    "_" .
    bin2hex(random_bytes(4)) .
    "." .
    $extension;

$targetPath = $uploadDir . $fileName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    response_json(500, "failed to save image");
}

$imageURL = "http://129.211.189.35/uploads/ads/imgs/" . $fileName;

response_json(200, "success", [
    "url" => $imageURL,
    "fileName" => $fileName,
    "adID" => $adID,
    "type" => $type,
    "width" => $width,
    "height" => $height,
    "mimeType" => $mimeType
]);