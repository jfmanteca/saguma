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
    // REESCRITURA COMPLETA:
    // Header/subheader: metodos nativos TCPDF (gradient, Cell, MultiCell) — sin CSS.
    // Cuerpo: writeHTML con tabla spacer 10mm|190mm|10mm, valores identicos al CRM (html2pdf).
    // UTF-8 en Cell() nativo funciona bien con Helvetica; solo se usan entidades en writeHTML.
    $fecha_envio  = date('d/m/Y');
    $fecha_valida = date('d/m/Y', strtotime('+7 days'));
    $plazo    = $data['plazo']    ?? '30 días hábiles';
    $cliente  = $data['cliente']  ?? '';
    $contacto = $data['contacto'] ?? '';
    $telefono = $data['telefono'] ?? '';
    $mail     = $data['mail']     ?? '';
    $notas    = $data['notas']    ?? '';
    $esc      = fn($s) => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

    // ── Calcular totales ─────────────────────────────────────
    $subtotal_gen  = 0;
    $total_prendas = 0;
    foreach ($items as &$it) {
        $cant = intval($it['cantidad'] ?? $it['cant'] ?? 0);
        $pu   = floatval($it['precio_base'] ?? 0) + floatval($it['costo_custom'] ?? 0);
        $sub  = $pu * $cant;
        $it['_pu']  = $pu;
        $it['_sub'] = $sub;
        $it['_iva'] = $sub * 0.21;
        $it['_tot'] = $sub * 1.21;
        $subtotal_gen  += $sub;
        $total_prendas += $cant;
    }
    unset($it);
    $iva_gen     = $subtotal_gen * 0.21;
    $total_gen   = $subtotal_gen + $iva_gen;
    $hay_precios = $subtotal_gen > 0;
    $fmt = fn($n) => '$' . number_format($n, 0, ',', '.');

    // ── Colores (idénticos al CRM) ────────────────────────────
    $AZ  = '#1a3a6b';
    $AZD = '#0f2447';
    $AZL = '#e8eef7';
    $GR1 = '#f7f9fc';
    $GR2 = '#f4f7fb';
    $BRD = '#d0d8e8';
    $ROW = '#e8ecf0';

    // ── TCPDF setup ──────────────────────────────────────────
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('SAGUMA CRM');
    $pdf->SetTitle('Cotizacion ' . $numero);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(true, 8);
    $pdf->AddPage();

    // ════════════════════════════════════════════════════════
    // HEADER NATIVO — gradiente igual al CRM
    // CRM: linear-gradient(135deg, #0f2447 0%, #1a3a6b 60%, #2563b0 100%)
    // ════════════════════════════════════════════════════════
    $HDR_H = 28; // mm
    $pdf->LinearGradient(0, 0, 210, $HDR_H,
        [15, 36, 71],   // #0f2447
        [37, 99, 176],  // #2563b0
        [0, 0, 1, 1]    // top-left → bottom-right (135deg)
    );

    // SAGUMA — izquierda
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(10, 7);
    $pdf->Cell(100, 10, 'SAGUMA', 0, 0, 'L');

    // Tagline — justo debajo de SAGUMA (igual que CRM: margin-top:2px)
    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetTextColor(170, 196, 232);
    $pdf->SetXY(10, 17);
    $pdf->Cell(100, 4, "INDUMENTARIA LABORAL  \xc2\xb7  MAYORISTA", 0, 0, 'L');

    // Info organización — derecha, 3 líneas alineadas a la derecha
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(220, 235, 255);
    $pdf->SetXY(105, 7);
    $pdf->MultiCell(95, 5,
        "Organizaci\xc3\xb3n Crima SA  \xc2\xb7  CUIT 30-70949492-5\n" .
        "Av. C\xc3\xb3rdoba 391 9\xc2\xb0B \xe2\x80\x93 CABA\n" .
        "ventas@saguma.com.ar  \xc2\xb7  +54 11 6112-5719",
        0, 'R');

    // ════════════════════════════════════════════════════════
    // SUB-HEADER NATIVO
    // CRM: background:#e8eef7; border-bottom:3px solid #1a3a6b; padding:6px 28px
    // ════════════════════════════════════════════════════════
    $SUB_H = 9; // mm
    $y_sub = $HDR_H;

    $pdf->SetFillColor(232, 238, 247);
    $pdf->Rect(0, $y_sub, 210, $SUB_H, 'F');

    $pdf->SetDrawColor(26, 58, 107);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(0, $y_sub + $SUB_H, 210, $y_sub + $SUB_H);
    $pdf->SetLineWidth(0.2);

    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetTextColor(26, 58, 107);
    $pdf->SetXY(10, $y_sub + 2);
    $pdf->Cell(95, 5,
        "COTIZACI\xc3\x93N MAYORISTA \xe2\x80\x94 N\xc2\xb0 " . $numero,
        0, 0, 'L');

    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetXY(105, $y_sub + 2);
    $pdf->Cell(95, 5, 'www.saguma.com.ar', 0, 0, 'R');

    // ════════════════════════════════════════════════════════
    // BODY — writeHTML posicionado después del header nativo
    // Valores idénticos al CRM (mismos colores, padding, font-size)
    // ════════════════════════════════════════════════════════
    $pdf->SetY($HDR_H + $SUB_H);

    // ── Estilos (iguales al CRM) ─────────────────────────────
    // CRM: .hcell { padding:5px 8px; font-size:10.5px }
    // CRM: .hcell .lbl { font-size:9px; font-weight:700; color:#1a3a6b }
    // CRM: thead th { padding:8px 6px; font-size:9.5px }
    // CRM: tbody td { padding:5px 6px; font-size:10.5px }
    $cel_o = "background-color:$GR1;padding:3px 7px;border:1px solid $BRD;vertical-align:top"; // odd
    $cel_e = "background-color:#ffffff;padding:3px 7px;border:1px solid $BRD;vertical-align:top"; // even
    $lbl_s = "font-size:7.5px;font-weight:bold;color:$AZ";
    $val_s = "font-size:9px;color:#111111";
    $th    = "background-color:$AZ;color:#ffffff;padding:6px 5px;font-size:8.5px;font-weight:bold";
    $td    = "padding:6px 5px;border-bottom:1px solid $ROW;font-size:9px;color:#111111;vertical-align:middle";
    $tfoot = "background-color:$AZD;color:#ffffff;font-weight:bold;padding:6px 5px;font-size:8.5px;vertical-align:middle";

    // ── Grid datos cliente (idéntico a hgrid del CRM) ────────
    // CRM: grid-template-columns:1fr 1fr; gap:0; margin-bottom:14px
    // Odd cells (1,3,5,7) = left column = bg #f7f9fc
    // Even cells (2,4,6,8) = right column = bg #ffffff
    $d = '&#8212;';
    // <span> label azul + <br> + <span> valor negro en un solo flujo (sin espacio entre div bloques)
    $lbl = fn($t) => "<span style=\"{$lbl_s}\">{$t}</span>";
    $blk = fn($t) => "<span style=\"{$val_s}\">{$t}</span>";
    $grid = "
<table style=\"width:190mm;border-collapse:collapse;margin-top:12px;margin-bottom:12px\" cellpadding=\"0\" cellspacing=\"0\">
  <tr>
    <td style=\"{$cel_o};width:95mm\">" . $lbl('EMPRESA / CLIENTE') . "<br><span style=\"{$val_s};font-weight:bold\">" . ($esc($cliente) ?: $d) . "</span></td>
    <td style=\"{$cel_e};width:95mm\">" . $lbl('N&#176; COTIZACI&#211;N') . "<br><span style=\"{$val_s};font-weight:bold\">" . $esc($numero) . "</span></td>
  </tr>
  <tr>
    <td style=\"{$cel_o}\">" . $lbl('CONTACTO') . "<br>" . $blk($esc($contacto) ?: $d) . "</td>
    <td style=\"{$cel_e}\">" . $lbl('FECHA ENV&#205;O') . "<br>" . $blk($fecha_envio) . "</td>
  </tr>
  <tr>
    <td style=\"{$cel_o}\">" . $lbl('TEL&#201;FONO') . "<br>" . $blk($esc($telefono) ?: $d) . "</td>
    <td style=\"{$cel_e}\">" . $lbl('V&#193;LIDA HASTA') . "<br>" . $blk($fecha_valida) . "</td>
  </tr>
  <tr>
    <td style=\"{$cel_o}\">" . $lbl('MAIL') . "<br>" . $blk($esc($mail) ?: $d) . "</td>
    <td style=\"{$cel_e}\">" . $lbl('PLAZO DE ENTREGA') . "<br>" . $blk($esc($plazo)) . "</td>
  </tr>
</table>";

    // ── Tabla de productos ────────────────────────────────────
    // Anchos: 7+50+16+13+11+23+23+19+28 = 190mm
    $W = ['7mm','50mm','16mm','13mm','11mm','23mm','23mm','19mm','28mm'];

    $rows = '';
    $n = 1;
    foreach ($items as $it) {
        if (!empty($it['desc'])) {
            $desc = ucfirst($it['desc']);
        } else {
            $cat    = $it['categoria']       ?? '';
            $prod   = $it['producto']        ?? '';
            $custom = $it['personalizacion'] ?? 'Sin personalizar';
            $desc   = trim($cat . ' ' . strtolower($prod));
            if ($custom === 'Bordado')       $desc .= ' c/ bordado';
            elseif ($custom === 'Estampado') $desc .= ' c/ estampado';
            elseif ($custom === 'Ambos')     $desc .= ' c/ bordado y estampado';
            $desc = ucfirst($desc);
        }
        $cant  = intval($it['cantidad'] ?? $it['cant'] ?? 0);
        $talle = $esc($it['talle'] ?? '');
        $color = $esc($it['color'] ?? '');
        $bg    = ($n % 2 === 0) ? "background-color:$GR2;" : '';

        $rows .= "<tr>
          <td style=\"{$bg}text-align:center;width:{$W[0]};{$td}\">{$n}</td>
          <td style=\"{$bg}width:{$W[1]};{$td}\">" . $esc($desc) . "</td>
          <td style=\"{$bg}width:{$W[2]};{$td}\">{$talle}</td>
          <td style=\"{$bg}width:{$W[3]};{$td}\">{$color}</td>
          <td style=\"{$bg}text-align:center;width:{$W[4]};{$td}\">{$cant}</td>";
        if ($hay_precios) {
            $rows .=
              "<td style=\"{$bg}text-align:right;width:{$W[5]};{$td}\">" . $fmt($it['_pu']) . "</td>" .
              "<td style=\"{$bg}text-align:right;width:{$W[6]};{$td}\">" . $fmt($it['_sub']) . "</td>" .
              "<td style=\"{$bg}text-align:right;width:{$W[7]};{$td}\">" . $fmt($it['_iva']) . "</td>" .
              "<td style=\"{$bg}text-align:right;width:{$W[8]};{$td}\">" . $fmt($it['_tot']) . "</td>";
        } else {
            $rows .= "<td colspan=\"4\" style=\"{$bg}{$td}\"></td>";
        }
        $rows .= "</tr>";
        $n++;
    }

    // Filas vacías (mínimo 14)
    $max_rows = max(count($items), 14);
    for ($i = count($items); $i < $max_rows; $i++) {
        $bg = (($i + 1) % 2 === 0) ? "background-color:$GR2;" : '';
        $rows .= "<tr><td colspan=\"9\" style=\"{$bg}height:21px;border-bottom:1px solid $ROW\"></td></tr>";
    }

    // Fila TOTAL GENERAL
    $rows .= "<tr>
      <td colspan=\"4\" style=\"{$tfoot};text-align:right\">TOTAL GENERAL</td>
      <td style=\"{$tfoot};text-align:center;width:{$W[4]}\">{$total_prendas}</td>
      <td style=\"{$tfoot}\"></td>";
    if ($hay_precios) {
        $rows .=
          "<td style=\"{$tfoot};text-align:right;width:{$W[6]}\">" . $fmt($subtotal_gen) . "</td>" .
          "<td style=\"{$tfoot};text-align:right;width:{$W[7]}\">" . $fmt($iva_gen) . "</td>" .
          "<td style=\"{$tfoot};text-align:right;width:{$W[8]}\">" . $fmt($total_gen) . "</td>";
    } else {
        $rows .= "<td colspan=\"3\" style=\"{$tfoot}\"></td>";
    }
    $rows .= "</tr>";

    $tabla = "
<table style=\"width:190mm;border-collapse:collapse;margin-bottom:10px\" cellpadding=\"0\" cellspacing=\"0\">
  <thead><tr>
    <th style=\"{$th};text-align:center;width:{$W[0]}\">#</th>
    <th style=\"{$th};text-align:left;width:{$W[1]}\">DESCRIPCI&#211;N</th>
    <th style=\"{$th};text-align:left;width:{$W[2]}\">TALLE</th>
    <th style=\"{$th};text-align:left;width:{$W[3]}\">COLOR</th>
    <th style=\"{$th};text-align:center;width:{$W[4]}\">CANT.</th>
    <th style=\"{$th};text-align:right;width:{$W[5]}\">P.UNIT S/IVA</th>
    <th style=\"{$th};text-align:right;width:{$W[6]}\">SUBTOTAL</th>
    <th style=\"{$th};text-align:right;width:{$W[7]}\">IVA 21%</th>
    <th style=\"{$th};text-align:right;width:{$W[8]}\">TOTAL C/IVA</th>
  </tr></thead>
  <tbody>{$rows}</tbody>
</table>";

    // ── Totales (idéntico a .tbox del CRM) ───────────────────
    // CRM: .trow { padding:6px 14px; font-size:11px }
    // CRM: .trow.g { background:#1a3a6b; font-size:13px }
    $totales = '';
    if ($hay_precios) {
        $totales = "
<table style=\"width:190mm;border-collapse:collapse;margin-bottom:14px\" cellpadding=\"0\" cellspacing=\"0\">
  <tr>
    <td style=\"width:55%\"></td>
    <td style=\"width:45%\">
      <table style=\"width:100%;border-collapse:collapse;border:1px solid $BRD\" cellpadding=\"0\" cellspacing=\"0\">
        <tr>
          <td style=\"background-color:#ffffff;padding:4px 12px;font-size:9px;border-bottom:1px solid #eeeeee\">Subtotal s/IVA</td>
          <td style=\"background-color:#ffffff;padding:4px 12px;font-size:9px;text-align:right;border-bottom:1px solid #eeeeee\">" . $fmt($subtotal_gen) . "</td>
        </tr>
        <tr>
          <td style=\"background-color:#ffffff;padding:4px 12px;font-size:9px;border-bottom:1px solid #eeeeee\">IVA 21%</td>
          <td style=\"background-color:#ffffff;padding:4px 12px;font-size:9px;text-align:right;border-bottom:1px solid #eeeeee\">" . $fmt($iva_gen) . "</td>
        </tr>
        <tr>
          <td style=\"background-color:$AZ;color:#ffffff;padding:5px 12px;font-size:10px;font-weight:bold\">TOTAL c/IVA</td>
          <td style=\"background-color:$AZ;color:#ffffff;padding:5px 12px;font-size:10px;font-weight:bold;text-align:right\">" . $fmt($total_gen) . "</td>
        </tr>
      </table>
    </td>
  </tr>
</table>";
    } else {
        $totales = "<p style=\"font-size:9px;color:#666;margin:0 0 14px;font-style:italic\">Los precios ser&#225;n confirmados por nuestro equipo comercial a la brevedad.</p>";
    }

    // ── Observaciones ─────────────────────────────────────────
    $obs_html = '';
    if ($notas) {
        $obs_html = "
<table style=\"width:190mm;border-collapse:collapse;margin-bottom:10px\" cellpadding=\"0\" cellspacing=\"0\">
  <tr>
    <td style=\"background-color:#fffbeb;padding:5px 10px;border:1px solid #fcd34d;font-size:9px\">
      <strong>Obs:</strong> " . $esc($notas) . "
    </td>
  </tr>
</table>";
    }

    // ── Condiciones comerciales (idéntico al CRM: .cond ul li) ──
    // CRM: padding:10px 14px; font-size:10px; line-height:1.75; ul padding-left:16px
    $li = "font-size:8.5px;color:#333333;line-height:1.4";
    $cond = "
<table style=\"width:190mm;border-collapse:collapse;border:1px solid $BRD;border-left:3px solid $AZ;margin-bottom:10px\" cellpadding=\"0\" cellspacing=\"0\">
  <tr>
    <td style=\"background-color:#f5f7fb;padding:8px 12px\">
      <span style=\"font-size:8.5px;font-weight:bold;color:$AZ\">CONDICIONES COMERCIALES</span><br>
      <span style=\"$li\">&#8226; Pedido m&#237;nimo: 30 unidades</span><br>
      <span style=\"$li\">&#8226; Plazo de entrega: 30 d&#237;as h&#225;biles desde confirmaci&#243;n</span><br>
      <span style=\"$li\">&#8226; Anticipo: 50% al confirmar | Saldo: 50% contra entrega</span><br>
      <span style=\"$li\">&#8226; Validez: 7 d&#237;as corridos desde fecha de env&#237;o</span><br>
      <span style=\"$li\">&#8226; Talle XXXL: +12% sobre precio unitario</span><br>
      <span style=\"$li\">&#8226; Los costos de prendas con bordado y estampado son orientativos. Dise&#241;os de gran tama&#241;o o complejidad podr&#225;n requerir un ajuste en el precio, sujeto a evaluaci&#243;n previa.</span>
    </td>
  </tr>
</table>";

    // ── Pie ───────────────────────────────────────────────────
    // CRM: .nota { font-size:9px; color:#aaa; margin-top:18px; border-top:1px solid #eee }
    $pie = "
<table style=\"width:190mm;border-collapse:collapse;border-top:1px solid #eeeeee\" cellpadding=\"0\" cellspacing=\"0\">
  <tr>
    <td style=\"background-color:#ffffff;padding:6px;text-align:center;font-size:9px;color:#aaaaaa\">Cotizaci&#243;n sin valor fiscal. Precios v&#225;lidos por el per&#237;odo indicado.</td>
  </tr>
</table>";

    // Espaciador: celda con height explicito — lo unico que TCPDF respeta para agregar espacio vertical
    $esp = '<table style="width:190mm;border-collapse:collapse" cellpadding="0" cellspacing="0"><tr><td style="height:10px;font-size:1px;line-height:1px"> </td></tr></table>';

    // ── HTML body completo (spacer 10mm | 190mm contenido | 10mm) ──
    $html = '
<style>
  body { font-family: helvetica, Arial, sans-serif; margin:0; padding:0; }
  table { border-collapse: collapse; }
</style>
<table style="width:100%;border-collapse:collapse" cellpadding="0" cellspacing="0">
  <tr>
    <td style="width:10mm"></td>
    <td style="width:190mm;vertical-align:top">
      ' . $esp . $grid . $esp . $tabla . $totales . $obs_html . $esp . $cond . $pie . '
    </td>
    <td style="width:10mm"></td>
  </tr>
</table>';

    $pdf->SetFont('helvetica', '', 9);
    $pdf->writeHTML($html, true, false, true, false, '');

    $tmp = sys_get_temp_dir() . '/cot_' . str_replace('-','', $numero) . '_' . time() . '.pdf';
    $pdf->Output($tmp, 'F');
    return $tmp;
}


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
