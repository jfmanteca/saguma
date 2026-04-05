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
    $plazo        = '30 días hábiles';
    $cliente  = htmlspecialchars($data['cliente'] ?? '', ENT_QUOTES);
    $contacto = htmlspecialchars($data['contacto'] ?? '', ENT_QUOTES);
    $telefono = htmlspecialchars($data['telefono'] ?? '', ENT_QUOTES);
    $mail     = htmlspecialchars($data['mail'] ?? '', ENT_QUOTES);
    $notas    = htmlspecialchars($data['notas'] ?? '', ENT_QUOTES);

    // Calcular totales
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
    unset($it);
    $iva_gen   = $subtotal_gen * 0.21;
    $total_gen = $subtotal_gen + $iva_gen;
    $hay_precios = $subtotal_gen > 0;
    $fmt = fn($n) => '$' . number_format($n, 0, ',', '.');

    // ── Filas de productos ───────────────────────────────────
    $filas_html = '';
    $n = 1;
    foreach ($items as $it) {
        if (!empty($it['desc'])) {
            $desc = ucfirst($it['desc']);
        } else {
            $cat    = $it['categoria'] ?? '';
            $prod   = $it['producto'] ?? '';
            $custom = $it['personalizacion'] ?? 'Sin personalizar';
            $desc   = trim($cat . ' ' . strtolower($prod));
            if ($custom === 'Bordado')   $desc .= ' c/ bordado';
            elseif ($custom === 'Estampado') $desc .= ' c/ estampado';
            elseif ($custom === 'Ambos')     $desc .= ' c/ bordado y estampado';
            $desc = ucfirst($desc);
        }
        $cant  = intval($it['cantidad'] ?? $it['cant'] ?? 0);
        $talle = htmlspecialchars($it['talle'] ?? '', ENT_QUOTES);
        $color = htmlspecialchars($it['color'] ?? '', ENT_QUOTES);
        $bg = ($n % 2 === 0) ? 'background:#f4f7fb' : 'background:#fff';
        $filas_html .= '<tr style="'.$bg.'">
            <td style="text-align:center;padding:5px 4px;border-bottom:1px solid #e8ecf0;font-size:10px">'.$n.'</td>
            <td style="padding:5px 4px;border-bottom:1px solid #e8ecf0;font-size:10px">'.htmlspecialchars($desc,ENT_QUOTES).'</td>
            <td style="text-align:center;padding:5px 4px;border-bottom:1px solid #e8ecf0;font-size:10px">'.$talle.'</td>
            <td style="text-align:center;padding:5px 4px;border-bottom:1px solid #e8ecf0;font-size:10px">'.$color.'</td>
            <td style="text-align:center;padding:5px 4px;border-bottom:1px solid #e8ecf0;font-size:10px">'.$cant.'</td>';
        if ($hay_precios) {
            $filas_html .=
                '<td style="text-align:right;padding:5px 4px;border-bottom:1px solid #e8ecf0;font-size:10px">'.$fmt($it['_pu']).'</td>'.
                '<td style="text-align:right;padding:5px 4px;border-bottom:1px solid #e8ecf0;font-size:10px">'.$fmt($it['_sub']).'</td>'.
                '<td style="text-align:right;padding:5px 4px;border-bottom:1px solid #e8ecf0;font-size:10px">'.$fmt($it['_iva']).'</td>'.
                '<td style="text-align:right;padding:5px 4px;border-bottom:1px solid #e8ecf0;font-size:10px">'.$fmt($it['_tot']).'</td>';
        } else {
            $filas_html .= '<td colspan="4" style="border-bottom:1px solid #e8ecf0"></td>';
        }
        $filas_html .= '</tr>';
        $n++;
    }
    // Filas vacías
    $max_rows = max(count($items) + 2, 8);
    for ($i = count($items); $i < $max_rows; $i++) {
        $bg = ($i % 2 === 0) ? 'background:#f4f7fb' : 'background:#fff';
        $filas_html .= '<tr style="'.$bg.'"><td colspan="9" style="height:19px;border-bottom:1px solid #e8ecf0"></td></tr>';
    }

    // Fila total general
    $filas_html .= '<tr style="background:#0f2447">
        <td colspan="4" style="text-align:right;padding:6px 4px;color:#fff;font-weight:bold;font-size:10px">TOTAL GENERAL</td>
        <td style="text-align:center;padding:6px 4px;color:#fff;font-weight:bold;font-size:10px">'.$total_prendas.'</td>
        <td style="padding:6px 4px;color:#fff"></td>';
    if ($hay_precios) {
        $filas_html .=
            '<td style="text-align:right;padding:6px 4px;color:#fff;font-weight:bold;font-size:10px">'.$fmt($subtotal_gen).'</td>'.
            '<td style="text-align:right;padding:6px 4px;color:#fff;font-weight:bold;font-size:10px">'.$fmt($iva_gen).'</td>'.
            '<td style="text-align:right;padding:6px 4px;color:#fff;font-weight:bold;font-size:10px">'.$fmt($total_gen).'</td>';
    } else {
        $filas_html .= '<td colspan="3" style="padding:6px 4px;color:#fff"></td>';
    }
    $filas_html .= '</tr>';

    // ── Bloque de totales ────────────────────────────────────
    $totales_html = '';
    if ($hay_precios) {
        $totales_html = '
        <table style="width:100%;margin-top:8px;margin-bottom:12px">
          <tr><td style="width:60%"></td><td style="width:40%">
            <table style="width:100%;border-collapse:collapse;border:1px solid #d0d8e8;border-radius:4px">
              <tr><td style="padding:5px 12px;font-size:11px;border-bottom:1px solid #eee">Subtotal s/IVA</td><td style="padding:5px 12px;font-size:11px;text-align:right;border-bottom:1px solid #eee">'.$fmt($subtotal_gen).'</td></tr>
              <tr><td style="padding:5px 12px;font-size:11px;border-bottom:1px solid #eee">IVA 21%</td><td style="padding:5px 12px;font-size:11px;text-align:right;border-bottom:1px solid #eee">'.$fmt($iva_gen).'</td></tr>
              <tr style="background:#1a3a6b"><td style="padding:6px 12px;font-size:12px;font-weight:bold;color:#fff">TOTAL c/IVA</td><td style="padding:6px 12px;font-size:12px;font-weight:bold;color:#fff;text-align:right">'.$fmt($total_gen).'</td></tr>
            </table>
          </td></tr>
        </table>';
    } else {
        $totales_html = '<p style="font-size:11px;color:#666;margin:8px 0 12px;font-style:italic">Los precios serán confirmados por nuestro equipo comercial a la brevedad.</p>';
    }

    // ── Notas ────────────────────────────────────────────────
    $notas_html = $notas ? '<div style="margin-bottom:10px;padding:8px 12px;background:#fffbeb;border:1px solid #fcd34d;border-radius:4px;font-size:10px"><strong>Observaciones:</strong> '.$notas.'</div>' : '';

    // ── HTML completo ────────────────────────────────────────
    $html = '
<style>
  * { box-sizing: border-box; }
  body { font-family: helvetica, Arial, sans-serif; font-size: 11px; color: #111; margin: 0; padding: 0; }
  table { border-collapse: collapse; }
</style>

<!-- HEADER -->
<table style="width:100%;background:#1a3a6b;margin-bottom:0" cellpadding="0" cellspacing="0">
  <tr>
    <td style="padding:14px 16px 10px;color:#fff">
      <div style="font-size:22px;font-weight:bold;letter-spacing:3px;color:#fff">SAGUMA</div>
      <div style="font-size:8px;color:rgba(255,255,255,.6);letter-spacing:2px;text-transform:uppercase;margin-top:2px">INDUMENTARIA LABORAL · MAYORISTA</div>
    </td>
    <td style="padding:14px 16px 10px;color:#fff;text-align:right;font-size:9px;line-height:1.8;vertical-align:top">
      Organizacion Crima SA &nbsp;·&nbsp; CUIT 30-70949492-5<br>
      Av. Cordoba 391 9B &ndash; CABA<br>
      ventas@saguma.com.ar &nbsp;·&nbsp; +54 11 6112-5719
    </td>
  </tr>
</table>

<!-- SUB-HEADER -->
<table style="width:100%;background:#e8eef7;border-bottom:3px solid #1a3a6b;margin-bottom:12px" cellpadding="0" cellspacing="0">
  <tr>
    <td style="padding:5px 16px;font-size:9px;font-weight:bold;color:#1a3a6b;text-transform:uppercase;letter-spacing:.5px">COTIZACION MAYORISTA — N&deg; '.$numero.'</td>
    <td style="padding:5px 16px;font-size:9px;color:#1a3a6b;text-align:right">www.saguma.com.ar</td>
  </tr>
</table>

<!-- DATOS CLIENTE / COTIZACION -->
<table style="width:100%;margin-bottom:14px" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:50%;padding-right:8px;vertical-align:top">
      <table style="width:100%;border-collapse:collapse;border:1px solid #d0d8e8">
        <tr><td style="padding:4px 8px;background:#f7f9fc;border:1px solid #d0d8e8"><span style="font-size:8px;font-weight:bold;color:#1a3a6b;text-transform:uppercase">Empresa / Cliente</span><br><strong style="font-size:11px">'.$cliente.'</strong></td></tr>
        <tr><td style="padding:4px 8px;border:1px solid #d0d8e8"><span style="font-size:8px;font-weight:bold;color:#1a3a6b;text-transform:uppercase">Contacto</span><br><span style="font-size:10px">'.($contacto ?: '—').'</span></td></tr>
        <tr><td style="padding:4px 8px;background:#f7f9fc;border:1px solid #d0d8e8"><span style="font-size:8px;font-weight:bold;color:#1a3a6b;text-transform:uppercase">Teléfono</span><br><span style="font-size:10px">'.($telefono ?: '—').'</span></td></tr>
        <tr><td style="padding:4px 8px;border:1px solid #d0d8e8"><span style="font-size:8px;font-weight:bold;color:#1a3a6b;text-transform:uppercase">Mail</span><br><span style="font-size:10px">'.$mail.'</span></td></tr>
      </table>
    </td>
    <td style="width:50%;padding-left:8px;vertical-align:top">
      <table style="width:100%;border-collapse:collapse;border:1px solid #d0d8e8">
        <tr><td style="padding:4px 8px;background:#f7f9fc;border:1px solid #d0d8e8"><span style="font-size:8px;font-weight:bold;color:#1a3a6b;text-transform:uppercase">N° Cotizacion</span><br><strong style="font-size:11px">'.$numero.'</strong></td></tr>
        <tr><td style="padding:4px 8px;border:1px solid #d0d8e8"><span style="font-size:8px;font-weight:bold;color:#1a3a6b;text-transform:uppercase">Fecha Envio</span><br><span style="font-size:10px">'.$fecha_envio.'</span></td></tr>
        <tr><td style="padding:4px 8px;background:#f7f9fc;border:1px solid #d0d8e8"><span style="font-size:8px;font-weight:bold;color:#1a3a6b;text-transform:uppercase">Valida Hasta</span><br><span style="font-size:10px">'.$fecha_valida.'</span></td></tr>
        <tr><td style="padding:4px 8px;border:1px solid #d0d8e8"><span style="font-size:8px;font-weight:bold;color:#1a3a6b;text-transform:uppercase">Plazo de Entrega</span><br><span style="font-size:10px">'.$plazo.'</span></td></tr>
      </table>
    </td>
  </tr>
</table>

<!-- TABLA PRODUCTOS -->
<table style="width:100%;border-collapse:collapse;margin-bottom:4px">
  <thead>
    <tr style="background:#1a3a6b">
      <th style="padding:7px 4px;color:#fff;font-size:9px;text-align:center;text-transform:uppercase;width:24px">#</th>
      <th style="padding:7px 4px;color:#fff;font-size:9px;text-align:left;text-transform:uppercase">Descripcion</th>
      <th style="padding:7px 4px;color:#fff;font-size:9px;text-align:center;text-transform:uppercase;width:55px">Talle</th>
      <th style="padding:7px 4px;color:#fff;font-size:9px;text-align:center;text-transform:uppercase;width:55px">Color</th>
      <th style="padding:7px 4px;color:#fff;font-size:9px;text-align:center;text-transform:uppercase;width:40px">Cant.</th>
      <th style="padding:7px 4px;color:#fff;font-size:9px;text-align:right;text-transform:uppercase;width:80px">P.Unit s/IVA</th>
      <th style="padding:7px 4px;color:#fff;font-size:9px;text-align:right;text-transform:uppercase;width:80px">Subtotal</th>
      <th style="padding:7px 4px;color:#fff;font-size:9px;text-align:right;text-transform:uppercase;width:70px">IVA 21%</th>
      <th style="padding:7px 4px;color:#fff;font-size:9px;text-align:right;text-transform:uppercase;width:80px">Total c/IVA</th>
    </tr>
  </thead>
  <tbody>'.$filas_html.'</tbody>
</table>

'.$totales_html.'

<!-- CONDICIONES -->
'.$notas_html.'
<table style="width:100%;border:1px solid #d0d8e8;border-left:3px solid #1a3a6b;margin-bottom:12px">
  <tr><td style="padding:8px 12px">
    <div style="font-size:9px;font-weight:bold;color:#1a3a6b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px">CONDICIONES COMERCIALES</div>
    <div style="font-size:9px;line-height:1.8;color:#333">
      &middot; Pedido minimo: 30 unidades<br>
      &middot; Plazo de entrega: 30 dias habiles desde confirmacion<br>
      &middot; Anticipo: 50% al confirmar &nbsp;|&nbsp; Saldo: 50% contra entrega<br>
      &middot; Validez: 7 dias corridos desde fecha de envio<br>
      &middot; Talles 3XL, 4XL y 5XL: +12% sobre precio unitario<br>
      &middot; Los costos de prendas con bordado y estampado son orientativos. Disenos de gran tamano o complejidad podran requerir un ajuste en el precio, sujeto a evaluacion previa.
    </div>
  </td></tr>
</table>

<!-- PIE -->
<div style="text-align:center;font-size:8px;color:#aaa;margin-top:10px;padding-top:8px;border-top:1px solid #eee">
  Cotizacion sin valor fiscal. Precios validos por el periodo indicado.
</div>';

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('SAGUMA CRM');
    $pdf->SetTitle('Cotizacion ' . $numero);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    $pdf->writeHTML($html, true, false, true, false, '');

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
    unset($it);
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
