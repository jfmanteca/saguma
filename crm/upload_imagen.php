<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$dir = __DIR__ . '/product_images/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

if (!isset($_FILES['imagen'])) {
    echo json_encode(['ok'=>false,'error'=>'No se recibió imagen']);
    exit;
}

$file    = $_FILES['imagen'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg','jpeg','png','webp'];

if (!in_array($ext, $allowed)) {
    echo json_encode(['ok'=>false,'error'=>'Formato no permitido (jpg, png, webp)']);
    exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['ok'=>false,'error'=>'La imagen no puede superar 5MB']);
    exit;
}

$filename = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
    echo json_encode(['ok'=>false,'error'=>'Error al guardar el archivo']);
    exit;
}

$proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$base  = $proto . '://' . $_SERVER['HTTP_HOST'];
$url   = $base . '/crm/product_images/' . $filename;

echo json_encode(['ok'=>true,'url'=>$url]);
