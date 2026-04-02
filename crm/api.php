<?php
// ============================================================
//  SAGUMA CRM/ERP — API PHP
//  Archivo: api.php
//  IMPORTANTE: Editá las 4 líneas de configuración abajo
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ============================================================
//  CONFIGURACIÓN — EDITÁ ESTOS 4 VALORES EN TU HOSTING
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'u868157557_saguma_crm');
define('DB_USER', 'u868157557_saguma_crm_use');
define('DB_PASS', 'Hernancattaneo2026');
// ============================================================

function conectar() {
    $pdo = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

function respuesta($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$tabla  = $_GET['tabla'] ?? '';
$id     = $_GET['id'] ?? null;
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $pdo = conectar();

    // ── GET: listar todos ──────────────────────────────────
    if ($method === 'GET' && $tabla) {
        $tablas_ok = ['consultas','cotizaciones','ventas','pedidos','cashflow','productos','cobranzas','pagos'];
        if (!in_array($tabla, $tablas_ok)) respuesta(['error'=>'Tabla inválida'], 400);
        $stmt = $pdo->query("SELECT * FROM `$tabla` ORDER BY id DESC LIMIT 9999");
        respuesta($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── POST: crear registro ───────────────────────────────
    if ($method === 'POST' && $tabla) {
        switch ($tabla) {

            case 'consultas':
                // Auto-generar ID consulta
                $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(id_consulta, 5) AS UNSIGNED)) FROM consultas WHERE id_consulta LIKE 'SAG-%'");
                $max = $stmt->fetchColumn();
                $nuevo_id = 'SAG-' . str_pad(($max ?: 0) + 1, 6, '0', STR_PAD_LEFT);
                $sql = "INSERT INTO consultas
                    (id_consulta,canal,nombre,empresa,telefono,mail,calificada,estado,prenda,consulta_texto,observaciones)
                    VALUES (:id_consulta,:canal,:nombre,:empresa,:telefono,:mail,:calificada,:estado,:prenda,:consulta_texto,:observaciones)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'id_consulta'    => $nuevo_id,
                    'canal'          => $body['canal'] ?? '',
                    'nombre'         => $body['nombre'] ?? '',
                    'empresa'        => $body['empresa'] ?? '',
                    'telefono'       => $body['telefono'] ?? '',
                    'mail'           => $body['mail'] ?? '',
                    'calificada'     => $body['calificada'] ?? 'Pendiente de evaluar',
                    'estado'         => $body['estado'] ?? 'Pendiente',
                    'prenda'         => $body['prenda'] ?? '',
                    'consulta_texto' => $body['consulta_texto'] ?? '',
                    'observaciones'  => $body['observaciones'] ?? '',
                ]);
                respuesta(['ok'=>true,'id'=>$nuevo_id,'insert_id'=>$pdo->lastInsertId()]);

            case 'cotizaciones':
                // Timezone Argentina
                date_default_timezone_set('America/Argentina/Buenos_Aires');

                $es_web = (!empty($body['origen']) && $body['origen'] === 'cotizador-web');

                // SIEMPRE generar número secuencial C01-XXXXX (único punto de generación)
                $stmtNum = $pdo->query("SELECT MAX(CAST(SUBSTRING(numero,5) AS UNSIGNED)) as max_num FROM cotizaciones WHERE numero LIKE 'C01-%'");
                $row = $stmtNum->fetch(PDO::FETCH_ASSOC);
                $sigNum = ($row && $row['max_num']) ? intval($row['max_num']) + 1 : 216;
                $numero_cot = 'C01-' . str_pad($sigNum, 5, '0', STR_PAD_LEFT);

                $fecha_hoy = date('Y-m-d');
                $fecha_valida = date('Y-m-d', strtotime('+7 days'));

                // Calcular monto desde items si no viene
                $monto = $body['monto'] ?: null;
                if (!$monto && !empty($body['items_json'])) {
                    $items_calc = json_decode($body['items_json'], true);
                    if (is_array($items_calc)) {
                        $subtotal = 0;
                        foreach ($items_calc as $it) {
                            $cant = floatval($it['cant'] ?? $it['cantidad'] ?? 0);
                            $precio = floatval($it['precio'] ?? 0);
                            $subtotal += $cant * $precio;
                        }
                        $monto = $subtotal > 0 ? round($subtotal * 1.21, 2) : null;
                    }
                }

                $sql = "INSERT INTO cotizaciones (numero,fecha,cliente,contacto,telefono,mail,fecha_sol,valida,plazo,estado,monto,notas,items_json)
                        VALUES (:numero,:fecha,:cliente,:contacto,:telefono,:mail,:fecha_sol,:valida,:plazo,:estado,:monto,:notas,:items_json)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'numero'     => $numero_cot,
                    'fecha'      => $body['fecha'] ?? $fecha_hoy,
                    'cliente'    => $body['cliente'] ?? '',
                    'contacto'   => $body['contacto'] ?? '',
                    'telefono'   => $body['telefono'] ?? '',
                    'mail'       => $body['mail'] ?? '',
                    'fecha_sol'  => $body['fecha_sol'] ?? $fecha_hoy,
                    'valida'     => $body['valida'] ?? $fecha_valida,
                    'plazo'      => $body['plazo'] ?? '30 días hábiles',
                    'estado'     => $es_web ? 'Pendiente' : ($body['estado'] ?? 'Enviada'),
                    'monto'      => $monto,
                    'notas'      => $body['notas'] ?? '',
                    'items_json' => $body['items_json'] ?? null,
                ]);
                $cot_id = $pdo->lastInsertId();

                // >>> Enviar cotización por mail SOLO si viene del cotizador web
                $mail_result = ['ok'=>false,'message'=>'No enviado'];
                try {
                    $es_web = (!empty($body['origen']) && $body['origen'] === 'cotizador-web');
                    if ($es_web && !empty($body['mail']) && !empty($body['items_json'])) {
                        require_once __DIR__ . '/enviar_cotizacion.php';
                        $mail_result = enviarMailCotizacion($pdo, $body, $cot_id, $numero_cot);
                    }
                } catch (Throwable $mailErr) {
                    error_log("SAGUMA mail error: " . $mailErr->getMessage());
                    $mail_result = ['ok'=>false, 'message'=>$mailErr->getMessage()];
                }

                respuesta([
                    'ok'           => true,
                    'insert_id'    => $cot_id,
                    'numero'       => $numero_cot,
                    'mail_enviado' => $mail_result['ok'],
                    'mail_mensaje' => $mail_result['message']
                ]);

            case 'ventas':
                $cant  = floatval($body['cantidad'] ?? 0);
                $precio = floatval($body['precio_neto'] ?? 0);
                $neto  = $cant * $precio;
                $iva   = $neto * 0.21;
                $total = $neto + $iva;
                $sql = "INSERT INTO ventas
                    (numero,fecha,cliente,producto,color,talle,cantidad,precio_neto,ingreso_neto,iva,total_con_iva,forma_pago,estado,orden_fabricante,factura,observaciones)
                    VALUES (:numero,:fecha,:cliente,:producto,:color,:talle,:cantidad,:precio_neto,:ingreso_neto,:iva,:total_con_iva,:forma_pago,:estado,:orden_fabricante,:factura,:observaciones)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'numero'          => $body['numero'] ?? null,
                    'fecha'           => $body['fecha'] ?? date('Y-m-d'),
                    'cliente'         => $body['cliente'] ?? '',
                    'producto'        => $body['producto'] ?? '',
                    'color'           => $body['color'] ?? '',
                    'talle'           => $body['talle'] ?? '',
                    'cantidad'        => $cant,
                    'precio_neto'     => $precio,
                    'ingreso_neto'    => $neto,
                    'iva'             => $iva,
                    'total_con_iva'   => $total,
                    'forma_pago'      => $body['forma_pago'] ?? '',
                    'estado'          => $body['estado'] ?? 'Pendiente',
                    'orden_fabricante'=> $body['orden_fabricante'] ?? '',
                    'factura'         => $body['factura'] ?? '',
                    'observaciones'   => $body['observaciones'] ?? '',
                ]);
                respuesta(['ok'=>true,'insert_id'=>$pdo->lastInsertId(),'ingreso_neto'=>$neto,'total_con_iva'=>$total]);

            case 'pedidos':
                $sql = "INSERT INTO pedidos (numero,proveedor,fecha,subtotal,iva21,total,anticipo,estado,notas,items_json,fecha_entrega,venta_ref)
                        VALUES (:numero,:proveedor,:fecha,:subtotal,:iva21,:total,:anticipo,:estado,:notas,:items_json,:fecha_entrega,:venta_ref)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'numero'        => $body['numero'] ?? '',
                    'proveedor'     => $body['proveedor'] ?? '',
                    'fecha'         => $body['fecha'] ?? date('Y-m-d'),
                    'subtotal'      => $body['subtotal'] ?: null,
                    'iva21'         => $body['iva21'] ?: null,
                    'total'         => $body['total'] ?: null,
                    'anticipo'      => $body['anticipo'] ?: null,
                    'estado'        => $body['estado'] ?? 'En producción',
                    'notas'         => $body['notas'] ?? '',
                    'items_json'    => $body['items_json'] ?? null,
                    'fecha_entrega' => $body['fecha_entrega'] ?? null,
                    'venta_ref'     => $body['venta_ref'] ?? null,
                ]);
                respuesta(['ok'=>true,'insert_id'=>$pdo->lastInsertId()]);

            case 'productos':
                $stmt = $pdo->prepare("INSERT INTO productos (categoria,tela,marca,costo,margen,precio,visible_web,descripcion,img_url) VALUES (:categoria,:tela,:marca,:costo,:margen,:precio,:visible_web,:descripcion,:img_url)");
                $stmt->execute([
                    'categoria'   => $body['categoria']   ?? '',
                    'tela'        => $body['tela']         ?? '',
                    'marca'       => $body['marca']        ?? '',
                    'costo'       => floatval($body['costo']  ?? 0),
                    'margen'      => floatval($body['margen'] ?? 0),
                    'precio'      => floatval($body['precio'] ?? 0),
                    'visible_web' => intval($body['visible_web'] ?? 1),
                    'descripcion' => $body['descripcion']  ?? null,
                    'img_url'     => $body['img_url']      ?? null,
                ]);
                respuesta(['ok'=>true,'insert_id'=>$pdo->lastInsertId()]);

            case 'cobranzas':
                // Crear la cobranza
                $stmt = $pdo->prepare("INSERT INTO cobranzas (venta_id,fecha,monto,forma_pago,notas) VALUES (:venta_id,:fecha,:monto,:forma_pago,:notas)");
                $stmt->execute([
                    'venta_id'   => $body['venta_id'] ?? null,
                    'fecha'      => $body['fecha'] ?? date('Y-m-d'),
                    'monto'      => floatval($body['monto'] ?? 0),
                    'forma_pago' => $body['forma_pago'] ?? '',
                    'notas'      => $body['notas'] ?? '',
                ]);
                $cobr_id = $pdo->lastInsertId();

                // Auto-insertar en cashflow como INGRESO
                $fecha_cobr = $body['fecha'] ?? date('Y-m-d');
                $mes_cobr = date('F', strtotime($fecha_cobr));
                $meses_es = ['January'=>'Enero','February'=>'Febrero','March'=>'Marzo','April'=>'Abril','May'=>'Mayo','June'=>'Junio','July'=>'Julio','August'=>'Agosto','September'=>'Septiembre','October'=>'Octubre','November'=>'Noviembre','December'=>'Diciembre'];
                $mes_esp = $meses_es[$mes_cobr] ?? $mes_cobr;
                // Obtener cliente de la venta
                $cliente_cobr = '';
                if (!empty($body['venta_id'])) {
                    $sv = $pdo->prepare("SELECT cliente, numero FROM ventas WHERE id=:id");
                    $sv->execute(['id'=>$body['venta_id']]);
                    $vrow = $sv->fetch(PDO::FETCH_ASSOC);
                    if($vrow) $cliente_cobr = ' — ' . $vrow['cliente'];
                }
                $concepto_cobr = 'Cobro venta #' . str_pad($body['venta_id']??'', 3, '0', STR_PAD_LEFT) . $cliente_cobr;
                $stmt2 = $pdo->prepare("INSERT INTO cashflow (fecha,mes,concepto,categoria,tipo,monto,notas,origen,ref_id) VALUES (:fecha,:mes,:concepto,:categoria,:tipo,:monto,:notas,:origen,:ref_id)");
                $stmt2->execute([
                    'fecha'     => $fecha_cobr,
                    'mes'       => $mes_esp,
                    'concepto'  => $concepto_cobr,
                    'categoria' => 'Cobros directos',
                    'tipo'      => 'INGRESO',
                    'monto'     => floatval($body['monto'] ?? 0),
                    'notas'     => $body['notas'] ?? '',
                    'origen'    => 'cobranza',
                    'ref_id'    => $cobr_id,
                ]);
                respuesta(['ok'=>true,'insert_id'=>$cobr_id,'cf_id'=>$pdo->lastInsertId()]);

            case 'pagos':
                // Crear el pago
                $stmt = $pdo->prepare("INSERT INTO pagos (concepto,proveedor,categoria,fecha,monto,forma_pago,orden_pedido_ref,notas) VALUES (:concepto,:proveedor,:categoria,:fecha,:monto,:forma_pago,:orden_pedido_ref,:notas)");
                $stmt->execute([
                    'concepto'        => $body['concepto'] ?? '',
                    'proveedor'       => $body['proveedor'] ?? ($body['concepto'] ?? ''),
                    'categoria'       => $body['categoria'] ?? '',
                    'fecha'           => $body['fecha'] ?? date('Y-m-d'),
                    'monto'           => floatval($body['monto'] ?? 0),
                    'forma_pago'      => $body['forma_pago'] ?? '',
                    'orden_pedido_ref'=> $body['orden_pedido_ref'] ?? '',
                    'notas'           => $body['notas'] ?? '',
                ]);
                $pago_id = $pdo->lastInsertId();

                // Auto-insertar en cashflow como EGRESO
                $fecha_pago = $body['fecha'] ?? date('Y-m-d');
                $mes_pago_en = date('F', strtotime($fecha_pago));
                $mes_pago_esp = $meses_es[$mes_pago_en] ?? $mes_pago_en;
                $stmt3 = $pdo->prepare("INSERT INTO cashflow (fecha,mes,concepto,categoria,tipo,monto,notas,origen,ref_id) VALUES (:fecha,:mes,:concepto,:categoria,:tipo,:monto,:notas,:origen,:ref_id)");
                $stmt3->execute([
                    'fecha'     => $fecha_pago,
                    'mes'       => $mes_pago_esp,
                    'concepto'  => $body['concepto'] ?? '',
                    'categoria' => $body['categoria'] ?? 'Otros egresos',
                    'tipo'      => 'EGRESO',
                    'monto'     => floatval($body['monto'] ?? 0),
                    'notas'     => $body['notas'] ?? '',
                    'origen'    => 'pago',
                    'ref_id'    => $pago_id,
                ]);
                respuesta(['ok'=>true,'insert_id'=>$pago_id,'cf_id'=>$pdo->lastInsertId()]);

            case 'cashflow':
                $sql = "INSERT INTO cashflow (fecha,mes,concepto,categoria,tipo,monto,notas)
                        VALUES (:fecha,:mes,:concepto,:categoria,:tipo,:monto,:notas)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'fecha'     => $body['fecha'] ?? date('Y-m-d'),
                    'mes'       => $body['mes'] ?? '',
                    'concepto'  => $body['concepto'] ?? '',
                    'categoria' => $body['categoria'] ?? '',
                    'tipo'      => $body['tipo'] ?? 'EGRESO',
                    'monto'     => floatval($body['monto'] ?? 0),
                    'notas'     => $body['notas'] ?? '',
                ]);
                respuesta(['ok'=>true,'insert_id'=>$pdo->lastInsertId()]);

            default:
                respuesta(['error'=>'Tabla no soportada'], 400);
        }
    }

    // ── PUT: actualizar campo único o múltiples campos ────
    if ($method === 'PUT' && $tabla && $id) {
        $tablas_ok = ['consultas','cotizaciones','ventas','pedidos','cashflow','productos','cobranzas','pagos'];
        if (!in_array($tabla, $tablas_ok)) respuesta(['error'=>'Tabla inválida'], 400);

        // Productos: actualizar costo, margen y precio
        if ($tabla === 'productos') {
            // Toggle visible_web
            if (isset($body['campo']) && $body['campo'] === 'visible_web') {
                $stmt = $pdo->prepare("UPDATE productos SET visible_web=:val WHERE id=:id");
                $stmt->execute(['val'=>intval($body['valor']), 'id'=>$id]);
                respuesta(['ok'=>true]);
            }
            if (!empty($body['categoria'])) {
                // Full product edit
                $stmt = $pdo->prepare("UPDATE productos SET categoria=:categoria, tela=:tela, marca=:marca, costo=:costo, margen=:margen, precio=:precio, descripcion=:descripcion, img_url=:img_url WHERE id=:id");
                $stmt->execute([
                    'categoria'   => $body['categoria']   ?? '',
                    'tela'        => $body['tela']         ?? '',
                    'marca'       => $body['marca']        ?? '',
                    'costo'       => floatval($body['costo']  ?? 0),
                    'margen'      => floatval($body['margen'] ?? 0),
                    'precio'      => floatval($body['precio'] ?? 0),
                    'descripcion' => $body['descripcion']  ?? null,
                    'img_url'     => $body['img_url']      ?? null,
                    'id'          => $id,
                ]);
            } else {
                // Price-only edit
                $stmt = $pdo->prepare("UPDATE productos SET costo=:costo, margen=:margen, precio=:precio WHERE id=:id");
                $stmt->execute([
                    'costo'  => floatval($body['costo']  ?? 0),
                    'margen' => floatval($body['margen'] ?? 0),
                    'precio' => floatval($body['precio'] ?? 0),
                    'id'     => $id,
                ]);
            }
            respuesta(['ok'=>true]);
        }

        if (!empty($body['multi']) && !empty($body['fields'])) {
            // Multi-field update (used by editar consulta)
            $allowed = ['nombre','empresa','telefono','mail','canal','estado','calificada',
                        'prenda','consulta_texto','observaciones','fecha_entrega',
                        'fecha','cliente','producto','color','talle','cantidad','precio_neto',
                        'ingreso_neto','iva','total_con_iva','forma_pago','orden_fabricante',
                        'factura','fab_tipo','costo_fab_neto','flete','comisiones','otros_costos',
                        'costo_total_neto','ganancia_bruta','margen_bruto',
                        'numero','contacto','fecha_sol','valida','plazo','monto','notas','items_json','visible_web'];
            $sets = []; $params = ['id' => $id];
            foreach ($body['fields'] as $k => $v) {
                if (in_array($k, $allowed)) {
                    $sets[] = "`$k` = :$k";
                    $params[$k] = $v;
                }
            }
            if (empty($sets)) respuesta(['error'=>'Sin campos válidos'], 400);
            $sql = "UPDATE `$tabla` SET " . implode(', ', $sets) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            // Single field update
            $campo = $body['campo'] ?? 'estado';
            $valor = $body['valor'] ?? '';
            $stmt = $pdo->prepare("UPDATE `$tabla` SET `$campo` = :valor WHERE id = :id");
            $stmt->execute(['valor'=>$valor,'id'=>$id]);
        }
        respuesta(['ok'=>true]);
    }

    // ── DELETE ─────────────────────────────────────────────
    if ($method === 'DELETE' && $tabla && $id) {
        $tablas_ok = ['consultas','cotizaciones','ventas','pedidos','cashflow','productos','cobranzas','pagos'];
        if (!in_array($tabla, $tablas_ok)) respuesta(['error'=>'Tabla inválida'], 400);
        $stmt = $pdo->prepare("DELETE FROM `$tabla` WHERE id = :id");
        $stmt->execute(['id'=>$id]);
        respuesta(['ok'=>true]);
    }

    respuesta(['error'=>'Ruta no encontrada'], 404);

} catch (PDOException $e) {
    respuesta(['error'=>'DB error: '.$e->getMessage()], 500);
}
