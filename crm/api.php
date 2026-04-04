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

    // ── CREATE TABLE compras (IF NOT EXISTS) ──────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS compras (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tipo ENUM('taller','servicio') NOT NULL,
      nro_orden VARCHAR(20),
      proveedor_id INT,
      proveedor_nombre VARCHAR(150),
      venta_id INT DEFAULT NULL,
      fecha DATE NOT NULL,
      fecha_entrega_estimada DATE,
      concepto VARCHAR(200),
      categoria VARCHAR(100),
      subtotal_sin_iva DECIMAL(12,2) DEFAULT 0,
      iva DECIMAL(12,2) DEFAULT 0,
      total DECIMAL(12,2) NOT NULL DEFAULT 0,
      tiene_factura TINYINT(1) DEFAULT 0,
      nro_factura VARCHAR(50),
      estado VARCHAR(50) DEFAULT 'Pendiente',
      items_json LONGTEXT,
      anticipo_monto DECIMAL(12,2) DEFAULT 0,
      anticipo_fecha DATE,
      anticipo_forma_pago VARCHAR(50),
      saldo_monto DECIMAL(12,2) DEFAULT 0,
      saldo_fecha DATE,
      saldo_forma_pago VARCHAR(50),
      forma_pago VARCHAR(50),
      observaciones TEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // ── GET especial: compras (con JOINs) ─────────────────────
    if ($method === 'GET' && $tabla === 'compras') {
        $stmt = $pdo->query("SELECT c.*,
            COALESCE(p.razon_social, c.proveedor_nombre) as prov_display,
            v.cliente as venta_cliente,
            v.numero as venta_numero
            FROM compras c
            LEFT JOIN proveedores p ON c.proveedor_id = p.id
            LEFT JOIN ventas v ON c.venta_id = v.id
            ORDER BY c.fecha DESC, c.id DESC LIMIT 9999");
        respuesta($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── GET: listar todos ──────────────────────────────────
    if ($method === 'GET' && $tabla) {
        $tablas_ok = ['consultas','cotizaciones','ventas','pedidos','cashflow','productos','cobranzas','pagos','clientes','proveedores','compras'];
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
                $stmt = $pdo->prepare("INSERT INTO cobranzas (venta_id,fecha,monto,forma_pago,concepto,notas) VALUES (:venta_id,:fecha,:monto,:forma_pago,:concepto,:notas)");
                $stmt->execute([
                    'venta_id'   => $body['venta_id'] ?? null,
                    'fecha'      => $body['fecha'] ?? date('Y-m-d'),
                    'monto'      => floatval($body['monto'] ?? 0),
                    'forma_pago' => $body['forma_pago'] ?? '',
                    'concepto'   => $body['concepto'] ?? 'Saldo',
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
                $tipo_cobro = $body['concepto'] ?? 'Saldo';
                $concepto_cobr = $tipo_cobro . ' venta #' . str_pad($body['venta_id']??'', 3, '0', STR_PAD_LEFT) . $cliente_cobr;
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

            case 'clientes':
                $stmt = $pdo->prepare("INSERT INTO clientes (razon_social,referente,nombre_fantasia,cuit,telefono,mail,direccion,notas) VALUES (:razon_social,:referente,:nombre_fantasia,:cuit,:telefono,:mail,:direccion,:notas)");
                $stmt->execute([
                    'razon_social'   => $body['razon_social']   ?? '',
                    'referente'      => $body['referente']      ?? '',
                    'nombre_fantasia'=> $body['nombre_fantasia'] ?? '',
                    'cuit'           => $body['cuit']            ?? '',
                    'telefono'       => $body['telefono']        ?? '',
                    'mail'           => $body['mail']            ?? '',
                    'direccion'      => $body['direccion']       ?? '',
                    'notas'          => $body['notas']           ?? '',
                ]);
                respuesta(['ok'=>true,'insert_id'=>$pdo->lastInsertId()]);

            case 'proveedores':
                $stmt = $pdo->prepare("INSERT INTO proveedores (razon_social,referente,nombre_fantasia,categoria,cuit,telefono,mail,direccion,notas) VALUES (:razon_social,:referente,:nombre_fantasia,:categoria,:cuit,:telefono,:mail,:direccion,:notas)");
                $stmt->execute([
                    'razon_social'   => $body['razon_social']   ?? '',
                    'referente'      => $body['referente']      ?? '',
                    'nombre_fantasia'=> $body['nombre_fantasia'] ?? '',
                    'categoria'      => $body['categoria']      ?? '',
                    'cuit'           => $body['cuit']            ?? '',
                    'telefono'       => $body['telefono']        ?? '',
                    'mail'           => $body['mail']            ?? '',
                    'direccion'      => $body['direccion']       ?? '',
                    'notas'          => $body['notas']           ?? '',
                ]);
                respuesta(['ok'=>true,'insert_id'=>$pdo->lastInsertId()]);

            case 'compras':
                $meses_comp = ['January'=>'Enero','February'=>'Febrero','March'=>'Marzo','April'=>'Abril','May'=>'Mayo','June'=>'Junio','July'=>'Julio','August'=>'Agosto','September'=>'Septiembre','October'=>'Octubre','November'=>'Noviembre','December'=>'Diciembre'];
                $tipo_comp = $body['tipo'] ?? 'taller';
                $nro_orden_comp = null;

                // Check for saldo action
                if (!empty($body['action']) && $body['action'] === 'saldo') {
                    $comp_id = intval($body['id'] ?? 0);
                    if (!$comp_id) respuesta(['error'=>'ID requerido'], 400);
                    // Get current compra data
                    $sc = $pdo->prepare("SELECT * FROM compras WHERE id=:id");
                    $sc->execute(['id'=>$comp_id]);
                    $comp_row = $sc->fetch(PDO::FETCH_ASSOC);
                    if (!$comp_row) respuesta(['error'=>'Compra no encontrada'], 404);
                    $saldo_m = floatval($body['saldo_monto'] ?? 0);
                    $saldo_f = $body['saldo_fecha'] ?? date('Y-m-d');
                    $saldo_fp = $body['saldo_forma_pago'] ?? '';
                    $pdo->prepare("UPDATE compras SET saldo_monto=:sm, saldo_fecha=:sf, saldo_forma_pago=:sfp WHERE id=:id")
                        ->execute(['sm'=>$saldo_m,'sf'=>$saldo_f,'sfp'=>$saldo_fp,'id'=>$comp_id]);
                    // Insert cashflow EGRESO for saldo
                    $mes_s = $meses_comp[date('F', strtotime($saldo_f))] ?? date('F', strtotime($saldo_f));
                    $nro_o = $comp_row['nro_orden'] ?? '';
                    $prov_n = $comp_row['proveedor_nombre'] ?? '';
                    $concepto_s = 'Saldo ' . ($nro_o ? $nro_o . ' — ' : '') . $prov_n;
                    $pdo->prepare("INSERT INTO cashflow (fecha,mes,concepto,categoria,tipo,monto,notas,origen,ref_id) VALUES (:fecha,:mes,:concepto,:categoria,:tipo,:monto,:notas,:origen,:ref_id)")
                        ->execute(['fecha'=>$saldo_f,'mes'=>$mes_s,'concepto'=>$concepto_s,'categoria'=>'Producción / Taller','tipo'=>'EGRESO','monto'=>$saldo_m,'notas'=>$body['notas']??'','origen'=>'compra','ref_id'=>$comp_id]);
                    respuesta(['ok'=>true,'cf_id'=>$pdo->lastInsertId()]);
                }

                // Auto-generate nro_orden for taller
                if ($tipo_comp === 'taller') {
                    $stmtMaxOp = $pdo->query("SELECT MAX(CAST(SUBSTRING(nro_orden, 4) AS UNSIGNED)) FROM compras WHERE tipo='taller' AND nro_orden LIKE 'OP-%'");
                    $maxOp = $stmtMaxOp->fetchColumn();
                    $nro_orden_comp = 'OP-' . str_pad(($maxOp ?: 0) + 1, 3, '0', STR_PAD_LEFT);
                }

                // Resolve proveedor_nombre
                $prov_nombre_comp = $body['proveedor_nombre'] ?? '';
                if (!empty($body['proveedor_id'])) {
                    $sp = $pdo->prepare("SELECT razon_social FROM proveedores WHERE id=:id");
                    $sp->execute(['id'=>$body['proveedor_id']]);
                    $pr = $sp->fetch(PDO::FETCH_ASSOC);
                    if ($pr) $prov_nombre_comp = $pr['razon_social'];
                }

                $total_comp = floatval($body['total'] ?? 0);
                $stmt = $pdo->prepare("INSERT INTO compras (tipo,nro_orden,proveedor_id,proveedor_nombre,venta_id,fecha,fecha_entrega_estimada,concepto,categoria,subtotal_sin_iva,iva,total,tiene_factura,nro_factura,estado,items_json,anticipo_monto,anticipo_fecha,anticipo_forma_pago,forma_pago,observaciones) VALUES (:tipo,:nro_orden,:proveedor_id,:proveedor_nombre,:venta_id,:fecha,:fecha_entrega_estimada,:concepto,:categoria,:subtotal_sin_iva,:iva,:total,:tiene_factura,:nro_factura,:estado,:items_json,:anticipo_monto,:anticipo_fecha,:anticipo_forma_pago,:forma_pago,:observaciones)");
                $stmt->execute([
                    'tipo'                 => $tipo_comp,
                    'nro_orden'            => $nro_orden_comp,
                    'proveedor_id'         => $body['proveedor_id'] ?: null,
                    'proveedor_nombre'     => $prov_nombre_comp,
                    'venta_id'             => $body['venta_id'] ?: null,
                    'fecha'                => $body['fecha'] ?? date('Y-m-d'),
                    'fecha_entrega_estimada'=> $body['fecha_entrega_estimada'] ?: null,
                    'concepto'             => $body['concepto'] ?? null,
                    'categoria'            => $body['categoria'] ?? null,
                    'subtotal_sin_iva'     => floatval($body['subtotal_sin_iva'] ?? 0),
                    'iva'                  => floatval($body['iva'] ?? 0),
                    'total'                => $total_comp,
                    'tiene_factura'        => intval($body['tiene_factura'] ?? 0),
                    'nro_factura'          => $body['nro_factura'] ?? null,
                    'estado'               => $body['estado'] ?? ($tipo_comp === 'taller' ? 'Pendiente' : 'Pendiente'),
                    'items_json'           => $body['items_json'] ?? null,
                    'anticipo_monto'       => floatval($body['anticipo_monto'] ?? 0),
                    'anticipo_fecha'       => $body['anticipo_fecha'] ?: null,
                    'anticipo_forma_pago'  => $body['anticipo_forma_pago'] ?? null,
                    'forma_pago'           => $body['forma_pago'] ?? null,
                    'observaciones'        => $body['observaciones'] ?? null,
                ]);
                $comp_id_new = $pdo->lastInsertId();

                // Auto-insert cashflow EGRESO for anticipo (taller)
                $cf_anticipo_id = null;
                $anticipo_m = floatval($body['anticipo_monto'] ?? 0);
                if ($tipo_comp === 'taller' && $anticipo_m > 0) {
                    $ant_fecha = $body['anticipo_fecha'] ?? date('Y-m-d');
                    $mes_ant = $meses_comp[date('F', strtotime($ant_fecha))] ?? date('F', strtotime($ant_fecha));
                    $concepto_ant = 'Anticipo ' . $nro_orden_comp . ' — ' . $prov_nombre_comp;
                    $pdo->prepare("INSERT INTO cashflow (fecha,mes,concepto,categoria,tipo,monto,notas,origen,ref_id) VALUES (:fecha,:mes,:concepto,:categoria,:tipo,:monto,:notas,:origen,:ref_id)")
                        ->execute(['fecha'=>$ant_fecha,'mes'=>$mes_ant,'concepto'=>$concepto_ant,'categoria'=>'Producción / Taller','tipo'=>'EGRESO','monto'=>$anticipo_m,'notas'=>$body['observaciones']??'','origen'=>'compra','ref_id'=>$comp_id_new]);
                    $cf_anticipo_id = $pdo->lastInsertId();
                }

                // Auto-insert cashflow EGRESO for servicio pagado
                $cf_serv_id = null;
                if ($tipo_comp === 'servicio' && ($body['estado'] ?? '') === 'Pagado') {
                    $serv_fecha = $body['fecha'] ?? date('Y-m-d');
                    $mes_serv = $meses_comp[date('F', strtotime($serv_fecha))] ?? date('F', strtotime($serv_fecha));
                    $concepto_serv = ($body['concepto'] ?? 'Servicio') . ' — ' . $prov_nombre_comp;
                    $pdo->prepare("INSERT INTO cashflow (fecha,mes,concepto,categoria,tipo,monto,notas,origen,ref_id) VALUES (:fecha,:mes,:concepto,:categoria,:tipo,:monto,:notas,:origen,:ref_id)")
                        ->execute(['fecha'=>$serv_fecha,'mes'=>$mes_serv,'concepto'=>$concepto_serv,'categoria'=>$body['categoria']??'Otros egresos','tipo'=>'EGRESO','monto'=>$total_comp,'notas'=>$body['observaciones']??'','origen'=>'compra','ref_id'=>$comp_id_new]);
                    $cf_serv_id = $pdo->lastInsertId();
                }

                respuesta(['ok'=>true,'insert_id'=>$comp_id_new,'nro_orden'=>$nro_orden_comp,'cf_anticipo_id'=>$cf_anticipo_id,'cf_serv_id'=>$cf_serv_id]);

            default:
                respuesta(['error'=>'Tabla no soportada'], 400);
        }
    }

    // ── PUT: actualizar campo único o múltiples campos ────
    if ($method === 'PUT' && $tabla && $id) {
        $tablas_ok = ['consultas','cotizaciones','ventas','pedidos','cashflow','productos','cobranzas','pagos','clientes','proveedores','compras'];
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

        // Cobranzas: update + sync cashflow
        if ($tabla === 'cobranzas' && !empty($body['multi']) && !empty($body['fields'])) {
            $f = $body['fields'];
            $stmt = $pdo->prepare("UPDATE cobranzas SET venta_id=:venta_id, fecha=:fecha, monto=:monto, forma_pago=:forma_pago, concepto=:concepto, notas=:notas WHERE id=:id");
            $stmt->execute([
                'venta_id'   => $f['venta_id'] ?? null,
                'fecha'      => $f['fecha'] ?? date('Y-m-d'),
                'monto'      => floatval($f['monto'] ?? 0),
                'forma_pago' => $f['forma_pago'] ?? '',
                'concepto'   => $f['concepto'] ?? 'Saldo',
                'notas'      => $f['notas'] ?? '',
                'id'         => $id,
            ]);
            // Sync cashflow entry
            $fecha_c = $f['fecha'] ?? date('Y-m-d');
            $meses_es2 = ['January'=>'Enero','February'=>'Febrero','March'=>'Marzo','April'=>'Abril','May'=>'Mayo','June'=>'Junio','July'=>'Julio','August'=>'Agosto','September'=>'Septiembre','October'=>'Octubre','November'=>'Noviembre','December'=>'Diciembre'];
            $mes_c = $meses_es2[date('F', strtotime($fecha_c))] ?? date('F', strtotime($fecha_c));
            $cliente_c = '';
            if (!empty($f['venta_id'])) {
                $sv2 = $pdo->prepare("SELECT cliente FROM ventas WHERE id=:id");
                $sv2->execute(['id'=>$f['venta_id']]);
                $vrow2 = $sv2->fetch(PDO::FETCH_ASSOC);
                if($vrow2) $cliente_c = ' — ' . $vrow2['cliente'];
            }
            $concepto_c = ($f['concepto'] ?? 'Saldo') . ' venta #' . str_pad($f['venta_id']??'', 3, '0', STR_PAD_LEFT) . $cliente_c;
            $pdo->prepare("UPDATE cashflow SET fecha=:fecha, mes=:mes, concepto=:concepto, monto=:monto, notas=:notas WHERE origen='cobranza' AND ref_id=:ref_id")
                ->execute(['fecha'=>$fecha_c,'mes'=>$mes_c,'concepto'=>$concepto_c,'monto'=>floatval($f['monto']??0),'notas'=>$f['notas']??'','ref_id'=>$id]);
            respuesta(['ok'=>true]);
        }

        // Pagos: update + sync cashflow
        if ($tabla === 'pagos' && !empty($body['multi']) && !empty($body['fields'])) {
            $f = $body['fields'];
            $stmt = $pdo->prepare("UPDATE pagos SET concepto=:concepto, proveedor=:proveedor, categoria=:categoria, fecha=:fecha, monto=:monto, forma_pago=:forma_pago, orden_pedido_ref=:orden_pedido_ref, notas=:notas WHERE id=:id");
            $stmt->execute([
                'concepto'        => $f['concepto'] ?? '',
                'proveedor'       => $f['concepto'] ?? '',
                'categoria'       => $f['categoria'] ?? '',
                'fecha'           => $f['fecha'] ?? date('Y-m-d'),
                'monto'           => floatval($f['monto'] ?? 0),
                'forma_pago'      => $f['forma_pago'] ?? '',
                'orden_pedido_ref'=> $f['orden_pedido_ref'] ?? '',
                'notas'           => $f['notas'] ?? '',
                'id'              => $id,
            ]);
            // Sync cashflow entry
            $fecha_p = $f['fecha'] ?? date('Y-m-d');
            $meses_es3 = ['January'=>'Enero','February'=>'Febrero','March'=>'Marzo','April'=>'Abril','May'=>'Mayo','June'=>'Junio','July'=>'Julio','August'=>'Agosto','September'=>'Septiembre','October'=>'Octubre','November'=>'Noviembre','December'=>'Diciembre'];
            $mes_p = $meses_es3[date('F', strtotime($fecha_p))] ?? date('F', strtotime($fecha_p));
            $pdo->prepare("UPDATE cashflow SET fecha=:fecha, mes=:mes, concepto=:concepto, categoria=:categoria, monto=:monto, notas=:notas WHERE origen='pago' AND ref_id=:ref_id")
                ->execute(['fecha'=>$fecha_p,'mes'=>$mes_p,'concepto'=>$f['concepto']??'','categoria'=>$f['categoria']??'Otros egresos','monto'=>floatval($f['monto']??0),'notas'=>$f['notas']??'','ref_id'=>$id]);
            respuesta(['ok'=>true]);
        }

        // Compras: update general
        if ($tabla === 'compras' && !empty($body['multi']) && !empty($body['fields'])) {
            $f = $body['fields'];
            $prov_n_upd = $f['proveedor_nombre'] ?? '';
            if (!empty($f['proveedor_id'])) {
                $spUpd = $pdo->prepare("SELECT razon_social FROM proveedores WHERE id=:id");
                $spUpd->execute(['id'=>$f['proveedor_id']]);
                $prUpd = $spUpd->fetch(PDO::FETCH_ASSOC);
                if ($prUpd) $prov_n_upd = $prUpd['razon_social'];
            }
            $pdo->prepare("UPDATE compras SET proveedor_id=:proveedor_id, proveedor_nombre=:proveedor_nombre, venta_id=:venta_id, fecha=:fecha, fecha_entrega_estimada=:fecha_entrega_estimada, concepto=:concepto, categoria=:categoria, subtotal_sin_iva=:subtotal_sin_iva, iva=:iva, total=:total, tiene_factura=:tiene_factura, nro_factura=:nro_factura, estado=:estado, items_json=:items_json, anticipo_monto=:anticipo_monto, anticipo_fecha=:anticipo_fecha, anticipo_forma_pago=:anticipo_forma_pago, forma_pago=:forma_pago, observaciones=:observaciones WHERE id=:id")
                ->execute([
                    'proveedor_id'          => $f['proveedor_id'] ?: null,
                    'proveedor_nombre'      => $prov_n_upd,
                    'venta_id'              => $f['venta_id'] ?: null,
                    'fecha'                 => $f['fecha'] ?? date('Y-m-d'),
                    'fecha_entrega_estimada'=> $f['fecha_entrega_estimada'] ?: null,
                    'concepto'              => $f['concepto'] ?? null,
                    'categoria'             => $f['categoria'] ?? null,
                    'subtotal_sin_iva'      => floatval($f['subtotal_sin_iva'] ?? 0),
                    'iva'                   => floatval($f['iva'] ?? 0),
                    'total'                 => floatval($f['total'] ?? 0),
                    'tiene_factura'         => intval($f['tiene_factura'] ?? 0),
                    'nro_factura'           => $f['nro_factura'] ?? null,
                    'estado'                => $f['estado'] ?? 'Pendiente',
                    'items_json'            => $f['items_json'] ?? null,
                    'anticipo_monto'        => floatval($f['anticipo_monto'] ?? 0),
                    'anticipo_fecha'        => $f['anticipo_fecha'] ?: null,
                    'anticipo_forma_pago'   => $f['anticipo_forma_pago'] ?? null,
                    'forma_pago'            => $f['forma_pago'] ?? null,
                    'observaciones'         => $f['observaciones'] ?? null,
                    'id'                    => $id,
                ]);
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
                        'numero','contacto','fecha_sol','valida','plazo','monto','notas','items_json','visible_web',
                        'venta_id','concepto','orden_pedido_ref','categoria','proveedor',
                        'razon_social','referente','nombre_fantasia','telefono','mail','cuit','direccion'];
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
        $tablas_ok = ['consultas','cotizaciones','ventas','pedidos','cashflow','productos','cobranzas','pagos','clientes','proveedores','compras'];
        if (!in_array($tabla, $tablas_ok)) respuesta(['error'=>'Tabla inválida'], 400);
        // Compras: cascade delete from cashflow
        if ($tabla === 'compras') {
            $pdo->prepare("DELETE FROM cashflow WHERE origen='compra' AND ref_id=:id")->execute(['id'=>$id]);
            $pdo->prepare("DELETE FROM compras WHERE id=:id")->execute(['id'=>$id]);
            respuesta(['ok'=>true]);
        }
        $stmt = $pdo->prepare("DELETE FROM `$tabla` WHERE id = :id");
        $stmt->execute(['id'=>$id]);
        respuesta(['ok'=>true]);
    }

    respuesta(['error'=>'Ruta no encontrada'], 404);

} catch (PDOException $e) {
    respuesta(['error'=>'DB error: '.$e->getMessage()], 500);
}
