<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');

define('SMTP_HOST',       'smtp.hostinger.com');
define('SMTP_PORT',       465);
define('SMTP_USER',       'ventas@saguma.com.ar');
define('SMTP_PASS',       'Emanteca.10');
define('SMTP_FROM_NAME',  'SAGUMA — Indumentaria Laboral');
define('SMTP_FROM_EMAIL', 'ventas@saguma.com.ar');
define('VENTAS_INTERNO',  'ventas@saguma.com.ar');
define('CRM_BASE_URL',    'https://saguma.com.ar/crm');
define('WA_NUMBER',       '5491161125719');

require_once __DIR__ . '/vendor/autoload.php';

function enviarMailCotizacion($pdo, $data, $cotizacion_id, $numero) {
    try {
        $items = json_decode($data['items_json'] ?? '[]', true);
        if (!$items || !is_array($items)) return ['ok'=>false,'message'=>'Items inválidos'];

        $fecha    = date('Y-m-d');
        $contacto = $data['contacto'] ?? '';
        $cliente  = $data['cliente'] ?? '';

        $costos = _getCostosPersonalizacion($pdo);
        $items  = _calcularPrecios($pdo, $items, $costos);
        $total_prendas = array_sum(array_column($items, 'cantidad'));

        $pdf_path = _generarPDFCRM($data, $items, $numero, $fecha);
        if (!$pdf_path || !file_exists($pdf_path)) {
            return ['ok'=>false, 'message'=>'Error al generar el PDF'];
        }

        $wa_aceptar = 'https://wa.me/' . WA_NUMBER . '?text=' . urlencode("Hola, quiero confirmar la cotización {$numero} a nombre de {$cliente}. ¿Cómo seguimos?");
        $wa_dudas   = 'https://wa.me/' . WA_NUMBER . '?text=' . urlencode("Hola, tengo una consulta sobre la cotización {$numero} a nombre de {$cliente}.");

        $html_cliente = _mailCliente($contacto, $cliente, $numero, $fecha, $total_prendas, $wa_aceptar, $wa_dudas);
        $ok_cliente = _enviarMailConPDF(
            $data['mail'], $contacto,
            "Cotización {$numero} — SAGUMA Indumentaria Laboral",
            $html_cliente, $pdf_path, "Cotizacion_{$numero}.pdf",
            VENTAS_INTERNO
        );

        @unlink($pdf_path);

        $estado = $ok_cliente ? 'Enviada' : 'Error envío';
        $stmt = $pdo->prepare("UPDATE cotizaciones SET estado=? WHERE id=?");
        $stmt->execute([$estado, $cotizacion_id]);

        return ['ok'=>$ok_cliente, 'message'=>$ok_cliente ? 'Mail enviado' : 'Error envío'];
    } catch (Exception $e) {
        error_log("SAGUMA mail error: " . $e->getMessage());
        return ['ok'=>false, 'message'=>$e->getMessage()];
    }
}

function _generarPDFCRM($data, $items, $numero, $fecha) {
    $fecha_envio  = date('d/m/Y');
    $fecha_valida = date('d/m/Y', strtotime('+7 days'));
    $plazo        = '30 dias habiles';
    $cliente  = $data['cliente'] ?? '';
    $contacto = $data['contacto'] ?? '';
    $telefono = $data['telefono'] ?? '';
    $mail     = $data['mail'] ?? '';
    $notas    = $data['notas'] ?? '';

    // Calcular totales con IVA
    $subtotal_gen = 0;
    $total_prendas = 0;
    foreach ($items as &$it) {
        $cant = intval($it['cantidad'] ?? $it['cant'] ?? 0);
        $pu   = floatval($it['precio_base'] ?? 0) + floatval($it['costo_custom'] ?? 0);
        $sub  = $pu * $cant;
        $it['_pu'] = $pu; $it['_sub'] = $sub; $it['_iva'] = $sub * 0.21; $it['_tot'] = $sub * 1.21;
        $subtotal_gen += $sub;
        $total_prendas += $cant;
    }
    $iva_gen   = $subtotal_gen * 0.21;
    $total_gen = $subtotal_gen + $iva_gen;
    $hay_precios = $subtotal_gen > 0;

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('SAGUMA CRM');
    $pdf->SetTitle('Cotizacion ' . $numero);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 10, 12);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    $w = 186; // ancho útil

    // ── HEADER AZUL ─────────────────────────────────────────
    $pdf->SetFillColor(55, 96, 146);
    $pdf->Rect(0, 0, 210, 28, 'F');

    $pdf->SetFont('helvetica', 'B', 22);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(14, 5);
    $pdf->Cell(80, 9, 'SAGUMA', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetXY(14, 14);
    $pdf->Cell(80, 4, 'INDUMENTARIA LABORAL  ·  MAYORISTA', 0, 0, 'L');

    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetXY(110, 5);
    $pdf->Cell(88, 4, 'Organizacion Crima SA  ·  CUIT 30-70949492-5', 0, 1, 'R');
    $pdf->SetX(110); $pdf->Cell(88, 4, 'Av. Cordoba 391 9°B – CABA', 0, 1, 'R');
    $pdf->SetX(110); $pdf->Cell(88, 4, 'ventas@saguma.com.ar  ·  +54 11 6112-5719', 0, 1, 'R');

    // Sub-header gris
    $pdf->SetFillColor(238, 238, 238);
    $pdf->Rect(0, 28, 210, 7, 'F');
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColor(55, 96, 146);
    $pdf->SetXY(14, 29);
    $pdf->Cell(100, 5, 'COTIZACION MAYORISTA — N° ' . $numero, 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->Cell(74, 5, 'www.saguma.com.ar', 0, 0, 'R');

    // ── RECUADROS DATOS ─────────────────────────────────────
    $y = 40;
    $pdf->SetDrawColor(190, 190, 190);
    $bw = 90; // ancho de cada recuadro
    $bh = 38;

    // Recuadro izquierdo
    $pdf->Rect(12, $y, $bw, $bh, 'D');
    $lx = 15; $ly = $y + 2;
    $pdf->SetFont('helvetica', 'B', 6.5); $pdf->SetTextColor(55, 96, 146);
    $pdf->SetXY($lx, $ly); $pdf->Cell(80, 3.5, 'EMPRESA / CLIENTE', 0, 1);
    $pdf->SetFont('helvetica', 'B', 9); $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX($lx); $pdf->Cell(80, 5, $cliente, 0, 1);

    $pdf->SetFont('helvetica', 'B', 6.5); $pdf->SetTextColor(55, 96, 146);
    $pdf->SetX($lx); $pdf->Cell(80, 3.5, 'CONTACTO', 0, 1);
    $pdf->SetFont('helvetica', '', 8.5); $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX($lx); $pdf->Cell(80, 4, $contacto ?: '—', 0, 1);

    $pdf->SetFont('helvetica', 'B', 6.5); $pdf->SetTextColor(55, 96, 146);
    $pdf->SetX($lx); $pdf->Cell(80, 3.5, 'TELEFONO', 0, 1);
    $pdf->SetFont('helvetica', '', 8.5); $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX($lx); $pdf->Cell(80, 4, $telefono ?: '—', 0, 1);

    $pdf->SetFont('helvetica', 'B', 6.5); $pdf->SetTextColor(55, 96, 146);
    $pdf->SetX($lx); $pdf->Cell(80, 3.5, 'MAIL', 0, 1);
    $pdf->SetFont('helvetica', '', 8.5); $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX($lx); $pdf->Cell(80, 4, $mail, 0, 1);

    // Recuadro derecho
    $rx = 108;
    $pdf->Rect($rx, $y, $bw, $bh, 'D');
    $rlx = $rx + 3; $rly = $y + 2;
    $pdf->SetFont('helvetica', 'B', 6.5); $pdf->SetTextColor(55, 96, 146);
    $pdf->SetXY($rlx, $rly); $pdf->Cell(80, 3.5, 'N° COTIZACION', 0, 1);
    $pdf->SetFont('helvetica', 'B', 9); $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX($rlx); $pdf->Cell(80, 5, $numero, 0, 1);

    $pdf->SetFont('helvetica', 'B', 6.5); $pdf->SetTextColor(55, 96, 146);
    $pdf->SetX($rlx); $pdf->Cell(80, 3.5, 'FECHA ENVIO', 0, 1);
    $pdf->SetFont('helvetica', '', 8.5); $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX($rlx); $pdf->Cell(80, 4, $fecha_envio, 0, 1);

    $pdf->SetFont('helvetica', 'B', 6.5); $pdf->SetTextColor(55, 96, 146);
    $pdf->SetX($rlx); $pdf->Cell(80, 3.5, 'VALIDA HASTA', 0, 1);
    $pdf->SetFont('helvetica', '', 8.5); $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX($rlx); $pdf->Cell(80, 4, $fecha_valida, 0, 1);

    $pdf->SetFont('helvetica', 'B', 6.5); $pdf->SetTextColor(55, 96, 146);
    $pdf->SetX($rlx); $pdf->Cell(80, 3.5, 'PLAZO DE ENTREGA', 0, 1);
    $pdf->SetFont('helvetica', '', 8.5); $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX($rlx); $pdf->Cell(80, 4, $plazo, 0, 1);

    // ── TABLA PRODUCTOS ─────────────────────────────────────
    $ty = $y + $bh + 6;
    $pdf->SetY($ty);

    // Anchos columnas (como CRM): #, DESC, TALLE, COLOR, CANT, P.UNIT, SUBTOTAL, IVA, TOTAL
    $c = [8, 50, 16, 16, 13, 22, 22, 17, 22];

    $pdf->SetFillColor(55, 96, 146);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 6.5);
    $h = ['#','DESCRIPCION','TALLE','COLOR','CANT.','P.UNIT S/IVA','SUBTOTAL','IVA 21%','TOTAL C/IVA'];
    $al = ['C','L','C','C','C','R','R','R','R'];
    for ($i=0; $i<9; $i++) $pdf->Cell($c[$i], 6, $h[$i], 0, 0, $al[$i], true);
    $pdf->Ln();

    // Filas
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 7.5);
    $n = 1;
    foreach ($items as $it) {
        // Usar 'desc' si viene del nuevo formato, sino construir
        if (!empty($it['desc'])) {
            $desc = $it['desc'];
        } else {
            $cat  = $it['categoria'] ?? '';
            $prod = $it['producto'] ?? '';
            $custom = $it['personalizacion'] ?? 'Sin personalizar';
            $desc = trim($cat . ' ' . strtolower($prod));
            if ($custom === 'Bordado') $desc .= ' c/ bordado';
            elseif ($custom === 'Estampado') $desc .= ' c/ estampado';
            elseif ($custom === 'Ambos') $desc .= ' c/ bordado y estampado';
        }

        $cant = intval($it['cantidad'] ?? $it['cant'] ?? 0);
        $talle = $it['talle'] ?? '';
        $color = $it['color'] ?? '';

        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Cell($c[0], 7, $n, 'B', 0, 'C');
        $pdf->Cell($c[1], 7, ucfirst($desc), 'B', 0, 'L', false, '', 1);
        $pdf->Cell($c[2], 7, $talle, 'B', 0, 'C');
        $pdf->Cell($c[3], 7, $color, 'B', 0, 'C');
        $pdf->Cell($c[4], 7, $cant, 'B', 0, 'C');
        if ($hay_precios) {
            $pdf->Cell($c[5], 7, '$'.number_format($it['_pu'],0,',','.'), 'B', 0, 'R');
            $pdf->Cell($c[6], 7, '$'.number_format($it['_sub'],0,',','.'), 'B', 0, 'R');
            $pdf->Cell($c[7], 7, '$'.number_format($it['_iva'],0,',','.'), 'B', 0, 'R');
            $pdf->Cell($c[8], 7, '$'.number_format($it['_tot'],0,',','.'), 'B', 0, 'R');
        } else {
            $pdf->Cell($c[5], 7, '', 'B', 0, 'R');
            $pdf->Cell($c[6], 7, '', 'B', 0, 'R');
            $pdf->Cell($c[7], 7, '', 'B', 0, 'R');
            $pdf->Cell($c[8], 7, '', 'B', 0, 'R');
        }
        $pdf->Ln();
        $n++;
    }

    // Filas vacías (solo si hay menos de 8 items para completar la tabla)
    $max_rows = max(count($items) + 2, 8);
    for ($i = count($items); $i < $max_rows; $i++) {
        for ($j=0; $j<9; $j++) $pdf->Cell($c[$j], 7, '', 'B', 0);
        $pdf->Ln();
    }

    $pdf->Ln(4);

    // ── TOTAL GENERAL ───────────────────────────────────────
    if ($hay_precios) {
        $pdf->SetFillColor(55, 96, 146);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pre_w = $c[0]+$c[1]+$c[2]+$c[3]; // antes de CANT
        $pdf->Cell($pre_w, 7, 'TOTAL GENERAL', 0, 0, 'R', true);
        $pdf->Cell($c[4], 7, $total_prendas, 0, 0, 'C', true);
        $pdf->Cell($c[5], 7, '', 0, 0, 'R', true);
        $pdf->Cell($c[6], 7, '$'.number_format($subtotal_gen,0,',','.'), 0, 0, 'R', true);
        $pdf->Cell($c[7], 7, '$'.number_format($iva_gen,0,',','.'), 0, 0, 'R', true);
        $pdf->Cell($c[8], 7, '$'.number_format($total_gen,0,',','.'), 0, 0, 'R', true);
        $pdf->Ln(10);

        // Resumen
        $pdf->SetTextColor(0,0,0);
        $pdf->SetFont('helvetica', '', 8.5);
        $xr = 128;
        $pdf->SetX($xr); $pdf->Cell(35, 6, 'Subtotal s/IVA', 1, 0, 'L'); $pdf->Cell(30, 6, '$'.number_format($subtotal_gen,0,',','.'), 1, 1, 'R');
        $pdf->SetX($xr); $pdf->Cell(35, 6, 'IVA 21%', 1, 0, 'L'); $pdf->Cell(30, 6, '$'.number_format($iva_gen,0,',','.'), 1, 1, 'R');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX($xr); $pdf->Cell(35, 7, 'TOTAL c/IVA', 1, 0, 'L'); $pdf->Cell(30, 7, '$'.number_format($total_gen,0,',','.'), 1, 1, 'R');
    } else {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($w, 7, 'TOTAL: ' . $total_prendas . ' prendas', 0, 1, 'L');
        $pdf->Ln(1);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(100,100,100);
        $pdf->Cell($w, 5, 'Los precios seran confirmados por nuestro equipo comercial a la brevedad.', 0, 1);
    }

    // ── CONDICIONES COMERCIALES ─────────────────────────────
    $pdf->Ln(6);
    $y = $pdf->GetY();
    $pdf->SetDrawColor(190, 190, 190);
    $pdf->Rect(12, $y, $w, 42, 'D');
    $pdf->SetFont('helvetica', 'B', 7.5); $pdf->SetTextColor(55, 96, 146);
    $pdf->SetXY(15, $y+2); $pdf->Cell(0, 4, 'CONDICIONES COMERCIALES', 0, 1);
    $pdf->SetFont('helvetica', '', 7.5); $pdf->SetTextColor(0,0,0);
    $pdf->SetX(17); $pdf->Cell(0, 4, '· Pedido minimo: 30 unidades', 0, 1);
    $pdf->SetX(17); $pdf->Cell(0, 4, '· Plazo de entrega: 30 dias habiles desde confirmacion', 0, 1);
    $pdf->SetX(17); $pdf->Cell(0, 4, '· Anticipo: 50% al confirmar | Saldo: 50% contra entrega', 0, 1);
    $pdf->SetX(17); $pdf->Cell(0, 4, '· Validez: 7 dias corridos desde fecha de envio', 0, 1);
    $pdf->SetX(17); $pdf->Cell(0, 4, '· Talles 3XL, 4XL y 5XL: +12% sobre precio unitario', 0, 1);
    $pdf->SetX(17); $pdf->MultiCell(168, 4, '· Los costos de las prendas con bordado y estampado son orientativos. Disenos de gran tamano o complejidad podran requerir un ajuste en el precio, sujeto a evaluacion previa.', 0, 'L');

    // ── FIRMAS ──────────────────────────────────────────────
    $pdf->Ln(10);
    $y = $pdf->GetY();
    $pdf->SetDrawColor(0,0,0);
    $pdf->Line(14, $y, 92, $y);
    $pdf->Line(118, $y, 196, $y);
    $pdf->SetFont('helvetica', '', 7.5); $pdf->SetTextColor(80,80,80);
    $pdf->SetXY(14, $y+1); $pdf->Cell(78, 4, 'Aclaracion y Firma del Cliente', 0, 0, 'C');
    $pdf->SetX(118); $pdf->Cell(78, 4, 'Firma y Sello · Organizacion Crima SA', 0, 1, 'C');

    // ── PIE ─────────────────────────────────────────────────
    $pdf->Ln(6);
    $pdf->SetFont('helvetica', 'I', 7); $pdf->SetTextColor(150,150,150);
    $pdf->Cell(0, 4, 'Cotizacion sin valor fiscal. Precios validos por el periodo indicado.', 0, 1, 'C');

    $tmp = sys_get_temp_dir() . '/cot_' . str_replace('-','', $numero) . '_' . time() . '.pdf';
    $pdf->Output($tmp, 'F');
    return $tmp;
}


// ── MAIL CLIENTE ────────────────────────────────────────────
function _mailCliente($contacto, $cliente, $numero, $fecha, $total_prendas, $wa_aceptar, $wa_dudas) {
    $nombre = explode(' ', trim($contacto))[0] ?: 'Estimado/a';
    $fecha_fmt = date('d/m/Y');

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f1ec;font-family:Helvetica,Arial,sans-serif;">
<div style="max-width:600px;margin:0 auto;padding:32px 16px;">
<div style="background:#0a1e3d;border-radius:12px 12px 0 0;padding:32px 40px;text-align:center;">
<h1 style="font-size:28px;color:#fff;margin:0 0 4px;letter-spacing:3px;">SAGUMA</h1>
<p style="font-size:11px;color:rgba(255,255,255,.4);letter-spacing:2px;text-transform:uppercase;margin:0;">Indumentaria laboral</p></div>
<div style="background:#fff;padding:40px;border:1px solid #e9ecef;border-top:none;">
<p style="font-size:16px;color:#1a2333;margin:0 0 20px;line-height:1.6;">Hola <strong>'.htmlspecialchars($nombre).'</strong>,</p>
<p style="font-size:15px;color:#343a40;margin:0 0 16px;line-height:1.7;">Gracias por tu consulta. Adjunto encontrás la cotización <strong>'.htmlspecialchars($numero).'</strong> correspondiente al pedido de <strong>'.$total_prendas.' prendas</strong> para <strong>'.htmlspecialchars($cliente).'</strong>.</p>
<p style="font-size:15px;color:#343a40;margin:0 0 24px;line-height:1.7;">Revisá el PDF adjunto con el detalle completo. La cotización tiene una validez de 7 días desde la fecha de emisión ('.$fecha_fmt.').</p>
<div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:10px;padding:20px;margin-bottom:32px;">
<p style="font-size:13px;color:#6b7a99;margin:0;line-height:1.6;">📎 <strong style="color:#1a2333;">El detalle de productos, cantidades y personalización está en el PDF adjunto a este mail.</strong></p></div>
<p style="font-size:14px;color:#6b7a99;margin:0 0 16px;text-align:center;">¿Cómo querés continuar?</p>
<div style="text-align:center;margin-bottom:12px;"><a href="'.$wa_aceptar.'" target="_blank" style="display:inline-block;background:#25d366;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:600;font-size:15px;">✓ Aceptar cotización</a></div>
<div style="text-align:center;margin-bottom:8px;"><a href="'.$wa_dudas.'" target="_blank" style="display:inline-block;background:transparent;color:#2563b0;padding:12px 32px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;border:1.5px solid #2563b0;">Tengo dudas / Consultar</a></div>
<p style="font-size:12px;color:#adb5bd;text-align:center;margin:16px 0 0;">También podés responder directamente a este mail.</p></div>
<div style="padding:24px 40px;text-align:center;background:#f8f9fa;border-radius:0 0 12px 12px;border:1px solid #e9ecef;border-top:none;">
<p style="font-size:12px;color:#adb5bd;margin:0;">SAGUMA · Indumentaria Laboral · Buenos Aires, Argentina</p>
<p style="font-size:11px;color:#adb5bd;margin:4px 0 0;">ventas@saguma.com.ar · +54 11 6112 5719</p></div>
</div></body></html>';
}

// ── MAIL INTERNO ────────────────────────────────────────────
function _mailInterno($data, $items, $numero, $fecha, $id) {
    $tp = array_sum(array_column($items,'cantidad'));
    $filas='';
    foreach ($items as $it) {
        $cat = $it['categoria']??''; $prod = $it['producto']??''; $custom = $it['personalizacion']??'-';
        $desc = trim($cat . ' ' . strtolower($prod));
        if ($custom==='Bordado') $desc .= ' c/ bordado';
        elseif ($custom==='Estampado') $desc .= ' c/ estampado';
        elseif ($custom==='Ambos') $desc .= ' c/ bordado y estampado';
        $filas.='<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-size:13px;">'.htmlspecialchars(ucfirst($desc)).'</td><td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:center;font-size:13px;">'.intval($it['cantidad']).'</td></tr>';
    }
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:20px;background:#f4f1ec;font-family:Arial,sans-serif;">
<div style="max-width:700px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;border:1px solid #dee2e6;">
<div style="background:#0a1e3d;color:#fff;padding:20px 24px;">
<h2 style="margin:0;font-size:18px;">Nueva Cotización — '.htmlspecialchars($numero).'</h2>
<p style="margin:4px 0 0;font-size:12px;color:rgba(255,255,255,.5);">Cotizador web · '.date('d/m/Y H:i').'</p></div>
<div style="padding:24px;">
<table style="font-size:14px;margin-bottom:24px;">
<tr><td style="padding:4px 16px 4px 0;color:#6b7a99;">Empresa:</td><td style="font-weight:600;">'.htmlspecialchars($data['cliente']??'').'</td></tr>
<tr><td style="padding:4px 16px 4px 0;color:#6b7a99;">Contacto:</td><td>'.htmlspecialchars($data['contacto']??'').'</td></tr>
<tr><td style="padding:4px 16px 4px 0;color:#6b7a99;">Email:</td><td>'.htmlspecialchars($data['mail']??'').'</td></tr>
<tr><td style="padding:4px 16px 4px 0;color:#6b7a99;">Teléfono:</td><td>'.htmlspecialchars($data['telefono']??'-').'</td></tr></table>
<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
<thead><tr style="background:#f8f9fa;"><th style="padding:8px 12px;text-align:left;font-size:11px;color:#6b7a99;border-bottom:2px solid #dee2e6;">Producto</th><th style="padding:8px 12px;text-align:center;font-size:11px;color:#6b7a99;border-bottom:2px solid #dee2e6;">Cant.</th></tr></thead>
<tbody>'.$filas.'</tbody></table>
'.(!empty($data['notas'])?'<div style="padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;margin-bottom:16px;"><strong style="font-size:12px;color:#856404;">Observaciones:</strong><p style="font-size:13px;color:#856404;margin:4px 0 0;">'.nl2br(htmlspecialchars($data['notas'])).'</p></div>':'').'
<a href="'.CRM_BASE_URL.'/#cotizaciones" style="display:inline-block;background:#2563b0;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;">Ver en CRM</a>
</div></div></body></html>';
}

// ── COSTOS Y PRECIOS ────────────────────────────────────────
function _getCostosPersonalizacion($pdo) {
    $c = ['Bordado'=>0,'Estampado'=>0,'Ambos'=>0];
    try {
        $rows = $pdo->query("SELECT LOWER(tela) as n, precio FROM productos WHERE LOWER(categoria) = 'adicionales'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (strpos($r['n'],'bordado')!==false) $c['Bordado']=floatval($r['precio']??0);
            if (strpos($r['n'],'estampado')!==false) $c['Estampado']=floatval($r['precio']??0);
        }
        $c['Ambos']=$c['Bordado']+$c['Estampado'];
    } catch (Exception $e) { error_log("SAGUMA costos: ".$e->getMessage()); }
    return $c;
}

function _calcularPrecios($pdo, $items, $costos) {
    foreach ($items as &$it) {
        // Si el precio ya viene calculado del frontend (con recargo por talle), usarlo
        if (!empty($it['precio']) && floatval($it['precio']) > 0) {
            $pu = floatval($it['precio']);
        } else {
            $base = 0;
            if (!empty($it['producto_id'])) {
                $st = $pdo->prepare("SELECT precio FROM productos WHERE id=?");
                $st->execute([$it['producto_id']]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $base = floatval($r['precio'] ?? 0);
            }
            $custom = $it['personalizacion'] ?? 'Sin personalizar';
            $cc = $costos[$custom] ?? 0;
            $pu = $base + $cc;
        }
        $cant = intval($it['cantidad'] ?? $it['cant'] ?? 0);
        $it['precio_base'] = $pu;
        $it['costo_custom'] = 0;
        $it['subtotal'] = $pu * $cant;
    }
    return $items;
}

// ── ENVÍO ───────────────────────────────────────────────────
function _enviarMailConPDF($to, $to_name, $subject, $html, $pdf_path, $pdf_filename, $cc = null) {
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $m->isSMTP(); $m->Host=SMTP_HOST; $m->SMTPAuth=true;
        $m->Username=SMTP_USER; $m->Password=SMTP_PASS;
        $m->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $m->Port=SMTP_PORT; $m->CharSet='UTF-8';
        $m->Timeout=10;
        $m->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $m->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $m->addAddress($to, $to_name);
        if ($cc) $m->addCC($cc);
        $m->isHTML(true); $m->Subject=$subject; $m->Body=$html;
        $m->AltBody=strip_tags(str_replace(['<br>','<br/>','<br />'],"\n",$html));
        if ($pdf_path && file_exists($pdf_path)) $m->addAttachment($pdf_path, $pdf_filename, 'base64', 'application/pdf');
        $m->send(); return true;
    } catch (Exception $e) { error_log("SAGUMA PHPMailer: ".$m->ErrorInfo); return false; }
}
