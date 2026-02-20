<?php
/* ==========================================================
   Asesor Finanzas
   ----------------------------------------------------------
   3) Botón "Limpiar"
   4) Mostrar: "Última consulta tardó X segundos"

   Mantiene lo que ya tienes:
   - PHP calcula resultados fiables (margen, ahorro/mes, cuota, tiempo)
   - Ollama devuelve exactamente 5 tips accionables (sin cálculos)
   - Salida en Markdown + render Markdown seguro en HTML
   - Historial en JSON + botón de autorrellenar
   ----------------------------------------------------------
   ========================================================== */

ini_set('max_execution_time', '300');
set_time_limit(300);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$model   = "mistral:instruct";
$baseUrl = "http://127.0.0.1:11434";

/* ----------------------------------------------------------
   Historial JSON (guardar y autorrellenar)
---------------------------------------------------------- */
$DATA_DIR = __DIR__ . "/data";
$HIST_PATH = $DATA_DIR . "/historial.json";
$HIST_MAX = 50;

function ensure_data_dir(string $dir): void {
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

function read_history(string $path): array {
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false || trim($raw) === "") return [];
  $json = json_decode($raw, true);
  return is_array($json) ? $json : [];
}

function write_history(string $path, array $items): bool {
  $tmp = $path . ".tmp";
  $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) return false;
  if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
  return @rename($tmp, $path);
}

function append_history(string $path, array $entry, int $max = 50): void {
  $items = read_history($path);
  $items[] = $entry;
  if (count($items) > $max) {
    $items = array_slice($items, -$max);
  }
  write_history($path, $items);
}

function last_history(string $path): ?array {
  $items = read_history($path);
  if (empty($items)) return null;
  return $items[count($items) - 1];
}

/* ----------------------------------------------------------
   Inputs (POST)
---------------------------------------------------------- */
$action = $_POST["action"] ?? "ask"; // ask | prefill | clear

$defaultPrompt = "";
$prompt = $_POST["prompt"] ?? $defaultPrompt;

$ingresos = $_POST["ingresos"] ?? "";

$g_alquiler    = $_POST["g_alquiler"] ?? "";
$g_luz         = $_POST["g_luz"] ?? "";
$g_agua        = $_POST["g_agua"] ?? "";
$g_internet    = $_POST["g_internet"] ?? "";
$g_comida      = $_POST["g_comida"] ?? "";
$g_transporte  = $_POST["g_transporte"] ?? "";
$g_otros       = $_POST["g_otros"] ?? "";

$meta       = $_POST["meta"] ?? "";
$plazoMeses = $_POST["plazoMeses"] ?? "";

$result = null;

/* ==========================================================
   Markdown seguro (simple)
   - Escapa HTML siempre
   - Soporta: **negrita**, *cursiva*, `code`, headings (#) y listas (- )
========================================================== */
function md_inline_safe(string $s): string {
  $s = preg_replace_callback('/`([^`]+)`/', function($m){
    return '<code>' . $m[1] . '</code>';
  }, $s);

  $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
  $s = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $s);

  return $s;
}

function render_markdown_safe(string $md): string {
  $md = str_replace(["\r\n", "\r"], "\n", $md);
  $lines = explode("\n", $md);

  $html = '';
  $inUl = false;

  foreach ($lines as $rawLine) {
    $line = rtrim($rawLine);

    // Escapa SIEMPRE HTML
    $line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

    if (trim($line) === "") {
      if ($inUl) { $html .= "</ul>"; $inUl = false; }
      continue;
    }

    if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
      if ($inUl) { $html .= "</ul>"; $inUl = false; }
      $level = strlen($m[1]);
      $tag = ($level === 1) ? "h3" : (($level === 2) ? "h4" : "h5");
      $html .= "<{$tag}>" . md_inline_safe($m[2]) . "</{$tag}>";
      continue;
    }

    if (preg_match('/^\-\s+(.+)$/', $line, $m)) {
      if (!$inUl) { $html .= "<ul>"; $inUl = true; }
      $html .= "<li>" . md_inline_safe($m[1]) . "</li>";
      continue;
    }

    if ($inUl) { $html .= "</ul>"; $inUl = false; }
    $html .= "<p>" . md_inline_safe($line) . "</p>";
  }

  if ($inUl) $html .= "</ul>";

  return $html;
}

/* ==========================================================
   Helpers numéricos / parsing
========================================================== */
function numOrBlank($v): string {
  $v = trim((string)$v);
  if ($v === "") return "";
  $v = str_replace(",", ".", $v);
  if (!is_numeric($v)) return "";
  return (string)$v;
}

function fmt_eur_es($n): string {
  $s = number_format((float)$n, 2, ',', '');
  $s = preg_replace('/,00$/', '', $s);
  return $s;
}

function plural_es(int $n, string $singular, string $plural): string {
  return ($n === 1) ? $singular : $plural;
}

function parseMoneyFromText(string $txt): ?float {
  $t = trim($txt);
  if ($t === "") return null;

  $t = str_replace(["€", "EUR", "eur", " "], "", $t);

  if (!preg_match('/[-+]?\d[\d\.,]*/', $t, $m)) return null;
  $n = $m[0];

  // 14.000,50 => 14000.50
  if (strpos($n, '.') !== false && strpos($n, ',') !== false) {
    $n = str_replace('.', '', $n);
    $n = str_replace(',', '.', $n);
  } else {
    // 10,5 => 10.5
    if (strpos($n, ',') !== false && strpos($n, '.') === false) {
      $n = str_replace(',', '.', $n);
    }
    // 14.000 => 14000 (asume miles si el último bloque es de 3)
    if (strpos($n, '.') !== false && strpos($n, ',') === false) {
      $parts = explode('.', $n);
      $last = end($parts);
      if (strlen($last) === 3) $n = str_replace('.', '', $n);
    }
  }

  if (!is_numeric($n)) return null;
  return (float)$n;
}

/* ==========================================================
   Intent detection
========================================================== */
function detect_intent(string $q): array {
  $t = mb_strtolower(trim($q), 'UTF-8');

  $isTiempo =
    (strpos($t, "cuánto tiempo") !== false) ||
    (strpos($t, "cuanto tiempo") !== false) ||
    (strpos($t, "tardar") !== false) ||
    (strpos($t, "en cuanto") !== false);

  $isCuota =
    (strpos($t, "financ") !== false) ||
    (strpos($t, "cuota") !== false)  ||
    (strpos($t, "pagar") !== false)  ||
    (strpos($t, "mensualidad") !== false);

  $isMargen =
    (strpos($t, "me queda") !== false) ||
    (strpos($t, "ocio") !== false)     ||
    (strpos($t, "margen") !== false)   ||
    (strpos($t, "sobr") !== false)     ||
    (strpos($t, "disponible") !== false);

  $isAhorro =
    (strpos($t, "ahorr") !== false)   ||
    (strpos($t, "meta") !== false)    ||
    (strpos($t, "objetiv") !== false) ||
    (strpos($t, "juntar") !== false)  ||
    (strpos($t, "reunir") !== false);

  if ($isTiempo) return ["type" => "tiempo"];
  if ($isCuota)  return ["type" => "cuota"];
  if ($isMargen) return ["type" => "margen"];
  if ($isAhorro) return ["type" => "ahorro"];
  return ["type" => "general"];
}

/* ==========================================================
   Limpieza tips IA (exactamente 5)
========================================================== */
function clean_ai_tips(string $txt): array {
  $txt = str_replace(["\r\n", "\r"], "\n", $txt);
  $lines = explode("\n", $txt);

  $tips = [];
  foreach ($lines as $ln) {
    $ln = trim($ln);
    if ($ln === "") continue;

    if (preg_match('/^tip\s*:/i', $ln)) {
      $ln = preg_replace('/^tip\s*:\s*/i', 'Tip: ', $ln);
      $tips[] = $ln;
    }
    if (count($tips) >= 5) break;
  }

  // Fallback si el modelo no obedeció: trocea en frases
  if (count($tips) < 5) {
    $plain = trim(preg_replace('/\s+/', ' ', $txt));
    $parts = preg_split('/\.\s+|\;\s+|\?\s+|\!\s+/', $plain);
    foreach ($parts as $p) {
      $p = trim($p);
      if ($p === "") continue;
      $tips[] = "Tip: " . $p;
      if (count($tips) >= 5) break;
    }
  }

  while (count($tips) < 5) {
    $tips[] = "Tip: Apunta tus gastos una semana para detectar fugas pequeñas y ajustar sin agobiarte.";
  }

  return array_slice($tips, 0, 5);
}

/* ==========================================================
   3) BOTÓN LIMPIAR (PRG)
========================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "clear") {
  // No borra historial; solo limpia formulario en pantalla
  header("Location: " . $_SERVER["PHP_SELF"]);
  exit;
}

/* ==========================================================
   Autorellenar SOLO cuando se pulsa el botón
========================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "prefill") {
  ensure_data_dir($DATA_DIR);
  $last = last_history($HIST_PATH);

  if (is_array($last)) {
    $prompt      = (string)($last["prompt"] ?? "");
    $ingresos    = (string)($last["ingresos"] ?? "");
    $meta        = (string)($last["meta"] ?? "");
    $plazoMeses  = (string)($last["plazoMeses"] ?? "");

    $g = (array)($last["gastos"] ?? []);
    $g_alquiler   = (string)($g["alquiler"] ?? "");
    $g_luz        = (string)($g["luz"] ?? "");
    $g_agua       = (string)($g["agua"] ?? "");
    $g_internet   = (string)($g["internet"] ?? "");
    $g_comida     = (string)($g["comida"] ?? "");
    $g_transporte = (string)($g["transporte"] ?? "");
    $g_otros      = (string)($g["otros"] ?? "");
  }

  // No llamamos a Ollama ni calculamos; solo mostramos el form con datos
  $result = null;
}

/* ==========================================================
   Acción principal: preguntar
========================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && $action === "ask") {
  $tStart = microtime(true); // 4) Medición total (PHP)

  $userText = trim((string)$prompt);
  $metaTxt  = trim((string)$meta);

  /* 1) Intent */
  $intentType = detect_intent($userText . " " . $metaTxt)["type"];

  /* 2) PHP calcula */
  $ing = numOrBlank($ingresos);
  $ingF = ($ing !== "") ? (float)$ing : null;

  $gMapRaw = [
    "Alquiler/Hipoteca" => $g_alquiler,
    "Luz"               => $g_luz,
    "Agua"              => $g_agua,
    "Internet"          => $g_internet,
    "Comida"            => $g_comida,
    "Transporte"        => $g_transporte,
    "Otros"             => $g_otros,
  ];

  $gMapNum = [];
  $totalGastos = 0.0;

  foreach ($gMapRaw as $name => $val) {
    $n = numOrBlank($val);
    if ($n !== "") {
      $f = (float)$n;
      $gMapNum[$name] = $f;
      $totalGastos += $f;
    }
  }

  $margen = null;
  if ($ingF !== null && !empty($gMapNum)) {
    $margen = $ingF - $totalGastos;
  }

  $pm = numOrBlank($plazoMeses);
  $plazoF = ($pm !== "") ? (float)$pm : null;

  $metaAmount = parseMoneyFromText($metaTxt);
  if ($metaAmount === null) $metaAmount = parseMoneyFromText($userText);

  $precio = parseMoneyFromText($userText);
  if ($precio === null) $precio = parseMoneyFromText($metaTxt);

  $ahorroMensual = null;
  if ($metaAmount !== null && $plazoF !== null && $plazoF > 0) {
    $ahorroMensual = $metaAmount / $plazoF;
  }

  $cuotaMensual = null;
  if ($precio !== null && $plazoF !== null && $plazoF > 0) {
    $cuotaMensual = $precio / $plazoF;
  }

  $diff = null;
  if ($margen !== null && $ahorroMensual !== null) {
    $diff = $margen - $ahorroMensual;
  }

  $mesesExactos = null;
  $mesesRedondeo = null;
  if ($intentType === "tiempo" && $metaAmount !== null && $margen !== null && $margen > 0) {
    $mesesExactos = $metaAmount / $margen;
    $mesesRedondeo = (int)ceil($mesesExactos);
  }

  /* 3) Líneas calculadas por PHP */
  $calcLines = [];

  if (!empty($gMapNum)) {
    $calcLines[] = "Calculo: Total_gastos_fijos_EUR = " . fmt_eur_es($totalGastos) . "€";
  }

  if ($margen !== null) {
    $calcLines[] = "Calculo: Dinero_disponible_EUR = " . fmt_eur_es($ingF) . "€ - " . fmt_eur_es($totalGastos) . "€ = " . fmt_eur_es($margen) . "€";
  }

  $summaryLine = "Resumen: ";
  $resultLine  = "RESULTADO: ";

  if ($intentType === "margen") {
    if ($margen !== null) {
      $summaryLine .= "Te quedan " . fmt_eur_es($margen) . "€ al mes después de tus gastos fijos.";
      $resultLine  .= "Dinero_disponible_EUR=" . fmt_eur_es($margen) . "€";
    } else {
      $summaryLine .= "No puedo calcular lo que te queda con los datos actuales.";
      $resultLine  .= "Conclusion=Faltan ingresos y/o gastos para calcular lo que te queda.";
    }
  } elseif ($intentType === "ahorro") {
    if ($ahorroMensual !== null) {
      $calcLines[] = "Calculo: Ahorro_mensual_EUR = " . fmt_eur_es($metaAmount) . "€ / " . fmt_eur_es($plazoF) . " = " . fmt_eur_es($ahorroMensual) . "€";

      if ($diff !== null) {
        $calcLines[] = "Calculo: Diferencia_EUR = " . fmt_eur_es($margen) . "€ - " . fmt_eur_es($ahorroMensual) . "€ = " . fmt_eur_es($diff) . "€";
        $summaryLine .= "Para tu objetivo necesitas ahorrar " . fmt_eur_es($ahorroMensual) . "€ al mes. ";
        $summaryLine .= ($diff >= 0)
          ? "Con tus datos, te sobran " . fmt_eur_es($diff) . "€ al mes."
          : "Con tus datos, te faltan " . fmt_eur_es(abs($diff)) . "€ al mes.";
      } else {
        $summaryLine .= "Para tu objetivo necesitas ahorrar " . fmt_eur_es($ahorroMensual) . "€ al mes.";
      }

      $resultLine .= "Ahorro_mensual_EUR=" . fmt_eur_es($ahorroMensual) . "€";
    } else {
      $summaryLine .= "Para calcular cuánto ahorrar al mes necesito una meta en € y un plazo en meses.";
      $resultLine  .= "Conclusion=Para calcular el ahorro mensual necesito una meta en € y un plazo en meses.";
    }
  } elseif ($intentType === "cuota") {
    if ($cuotaMensual !== null) {
      $calcLines[] = "Calculo: Cuota_mensual_EUR = " . fmt_eur_es($precio) . "€ / " . fmt_eur_es($plazoF) . " = " . fmt_eur_es($cuotaMensual) . "€";
      $summaryLine .= "La cuota básica sería " . fmt_eur_es($cuotaMensual) . "€ al mes (sin intereses).";
      $resultLine  .= "Cuota_mensual_EUR=" . fmt_eur_es($cuotaMensual) . "€";
    } else {
      $summaryLine .= "Para calcular la cuota mensual necesito el precio en € y el plazo en meses.";
      $resultLine  .= "Conclusion=Para calcular cuota mensual necesito el precio en € y el plazo en meses.";
    }
  } elseif ($intentType === "tiempo") {
    if ($mesesRedondeo !== null) {
      $calcLines[] = "Calculo: Meses_necesarios = " . fmt_eur_es($metaAmount) . "€ / " . fmt_eur_es($margen) . "€ = " . fmt_eur_es($mesesExactos) . " meses";
      $calcLines[] = "Calculo: Meses_redondeados = " . fmt_eur_es($mesesRedondeo) . " " . plural_es($mesesRedondeo, "mes", "meses");
      $summaryLine .= "Con tu dinero disponible actual, tardarías aproximadamente " . fmt_eur_es($mesesRedondeo) . " " . plural_es($mesesRedondeo, "mes", "meses") . ".";
      $resultLine  .= "Tiempo_meses=" . fmt_eur_es($mesesRedondeo);
    } else {
      $summaryLine .= "Para estimar el tiempo necesito una meta en € y tu dinero disponible mensual (ingresos y gastos).";
      $resultLine  .= "Conclusion=Para calcular el tiempo necesito una meta en € y tu dinero disponible mensual.";
    }
  } else {
    if ($margen !== null) {
      $summaryLine .= "Con tus datos, tu dinero disponible aproximado es " . fmt_eur_es($margen) . "€ al mes.";
      $resultLine  .= "Dinero_disponible_EUR=" . fmt_eur_es($margen) . "€";
    } else {
      $summaryLine .= "Dime ingresos y gastos (o rellena el formulario) y te ayudo con un cálculo simple.";
      $resultLine  .= "Conclusion=Dime ingresos y gastos (o rellena el formulario) y te ayudo.";
    }
  }

  /* 4) Prompt a IA: exactamente 5 tips situacionales (sin cálculos, sin €) */
  $system =
    "Eres un consejero de finanzas personales BASICO.\n".
    "Tu tarea es dar consejos prácticos a corto plazo para organizarse.\n".
    "No recomiendes inversiones complejas ni productos financieros avanzados.\n".
    "Devuelve EXACTAMENTE 5 líneas.\n".
    "Cada línea debe empezar por 'Tip: ' y ser un consejo accionable.\n".
    "No incluyas cálculos, no incluyas cantidades, no incluyas euros, no incluyas números.\n".
    "Adapta los tips a la situación del usuario (por ejemplo: pareja, compra concreta, ocio, ahorro, cuota).\n".
    "Evita tips obvios tipo 'resta ingresos menos gastos'.\n".
    "Prioriza tips ejecutables: hábitos, límites, reglas simples, orden de pagos, sobres/cuentas, prevención de imprevistos.\n".
    "No uses listas numeradas ni viñetas; solo las 5 líneas Tip.\n\n";

  $ctx = "Intent=" . $intentType . "\n";
  if ($ingF !== null) $ctx .= "Ingresos_mensuales_EUR=SI\n";
  if (!empty($gMapNum)) $ctx .= "Gastos_fijos=SI\n";
  if ($margen !== null) $ctx .= "Tiene_dinero_disponible=SI\n";
  if ($ahorroMensual !== null) $ctx .= "Tiene_objetivo_ahorro=SI\n";
  if ($cuotaMensual !== null) $ctx .= "Consulta_cuota=SI\n";
  if ($mesesRedondeo !== null) $ctx .= "Consulta_tiempo=SI\n";

  $finalPrompt = $system
    . "CONTEXTO (banderas):\n{$ctx}\n"
    . "PREGUNTA_DEL_USUARIO:\n" . ($userText !== "" ? $userText : $defaultPrompt) . "\n"
    . "NOTA: Si hay formulario, los cálculos ya están hechos por PHP. Da tips de hábitos y organización, no de matemáticas.\n";

  /* 5) Llamada a Ollama */
  $payload = [
    "model"  => $model,
    "prompt" => $finalPrompt,
    "stream" => false,
    "options" => [
      "num_predict" => 340,
      "temperature" => 0.35
    ]
  ];

  $url = rtrim($baseUrl, "/") . "/api/generate";

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 240,
  ]);

  $raw = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlErr  = curl_error($ch);
  curl_close($ch);

  $elapsed = microtime(true) - $tStart; // 4) tiempo total

  if ($raw === false) {
    $result = ["ok" => false, "err" => "cURL error: " . $curlErr, "out_html" => "", "elapsed" => $elapsed];
  } elseif ($httpCode < 200 || $httpCode >= 300) {
    $result = ["ok" => false, "err" => "HTTP $httpCode\n$raw", "out_html" => "", "elapsed" => $elapsed];
  } else {
    $json = json_decode($raw, true);
    if (!is_array($json)) {
      $result = ["ok" => false, "err" => "Respuesta no-JSON:\n$raw", "out_html" => "", "elapsed" => $elapsed];
    } else {
      $ai = (string)($json["response"] ?? "");
      $tips = clean_ai_tips($ai);

      // Composición Markdown
      $summaryText = preg_replace('/^Resumen:\s*/', '', $summaryLine);

      $md = "";
      $md .= "**Resumen:** " . $summaryText . "\n\n";

      $md .= "**Tips**\n";
      foreach ($tips as $t) {
        $tClean = preg_replace('/^Tip:\s*/', '', $t);
        $md .= "- " . $tClean . "\n";
      }

      if (!empty($calcLines)) {
        $md .= "\n**Cálculos**\n";
        foreach ($calcLines as $c) {
          $md .= "- " . $c . "\n";
        }
      }

      // Última línea exacta
      $md .= "\n" . $resultLine;

      $htmlOut = '<div class="md">' . render_markdown_safe($md) . '</div>';

      // Guardar historial (no se muestra automáticamente; solo sirve para autorrellenar)
      ensure_data_dir($DATA_DIR);
      append_history($HIST_PATH, [
        "id" => uniqid("h_", true),
        "ts" => date("c"),
        "prompt" => $userText,
        "ingresos" => (string)$ingresos,
        "gastos" => [
          "alquiler" => (string)$g_alquiler,
          "luz" => (string)$g_luz,
          "agua" => (string)$g_agua,
          "internet" => (string)$g_internet,
          "comida" => (string)$g_comida,
          "transporte" => (string)$g_transporte,
          "otros" => (string)$g_otros,
        ],
        "meta" => (string)$meta,
        "plazoMeses" => (string)$plazoMeses,
        "intent" => $intentType,
        "elapsed_sec" => $elapsed,
      ], $HIST_MAX);

      $result = ["ok" => true, "err" => "", "out_html" => $htmlOut, "elapsed" => $elapsed];
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asesor de Finanzas</title>

  <style>
    :root{
      --grad-main: linear-gradient(135deg, #0ea5e9, #f97316);
      --grad-head: linear-gradient(135deg, #1e3a8a, #0ea5e9);

      --bg: #f6f7fb;
      --card: #ffffff;
      --border: #e6e8f0;
      --text: #0f172a;
      --muted: #475569;

      --radius: 16px;
      --shadow: 0 14px 40px rgba(2, 6, 23, .10);
      --shadow2: 0 10px 26px rgba(2, 6, 23, .08);
      --mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background:
        radial-gradient(800px 500px at 10% 5%, rgba(14,165,233,.18), transparent 60%),
        radial-gradient(800px 500px at 90% 10%, rgba(249,115,22,.14), transparent 60%),
        radial-gradient(800px 500px at 60% 110%, rgba(30,58,138,.10), transparent 60%),
        var(--bg);
      color: var(--text);
      padding: 22px 16px;
    }

    .wrap{
      max-width: 1100px;
      margin: 0 auto;
      display:flex;
      flex-direction:column;
      gap: 14px;
    }

    .topbar{
      border-radius: var(--radius);
      background: var(--grad-head);
      box-shadow: var(--shadow);
      overflow:hidden;
      border: 1px solid rgba(255,255,255,.25);
    }
    .topbar-inner{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 14px;
      padding: 14px 16px;
      color: #fff;
    }
    .brand{
      display:flex;
      align-items:center;
      gap: 12px;
      min-width:0;
    }
    .brand img{
      height: 42px;
      width: auto;
      display:block;
      filter: drop-shadow(0 10px 18px rgba(0,0,0,.22));
    }
    .brand .title{
      display:flex;
      flex-direction:column;
      gap: 2px;
      min-width:0;
    }
    .brand h1{
      margin:0;
      font-size: 18px;
      letter-spacing:.2px;
      line-height: 1.15;
      white-space: nowrap;
      overflow:hidden;
      text-overflow: ellipsis;
    }
    .brand p{
      margin:0;
      font-size: 13px;
      opacity: .92;
      line-height: 1.25;
      white-space: nowrap;
      overflow:hidden;
      text-overflow: ellipsis;
    }

    .card{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow2);
      overflow:hidden;
    }

    .cardHead{
      padding: 14px 16px;
      border-bottom: 1px solid var(--border);
      background: linear-gradient(180deg, rgba(14,165,233,.08), rgba(249,115,22,.05));
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
    }
    .cardHead h2{
      margin:0;
      font-size: 14px;
      letter-spacing:.2px;
      color: #0f172a;
    }

    .content{ padding: 16px; }

    .hint{
      margin: 0 0 12px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.55;
    }

    label{
      display:block;
      font-size: 12px;
      font-weight: 800;
      color: #334155;
      margin: 12px 0 6px;
      letter-spacing:.2px;
    }

    input, textarea{
      width:100%;
      padding: 12px 12px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: #fff;
      color: var(--text);
      outline:none;
      font: inherit;
      transition: border-color .15s ease, box-shadow .15s ease, transform .06s ease;
    }

    textarea{
      min-height: 130px;
      resize: vertical;
    }

    input::placeholder, textarea::placeholder{ color: rgba(15,23,42,.35); }

    input:focus, textarea:focus{
      border-color: rgba(14,165,233,.55);
      box-shadow: 0 0 0 4px rgba(14,165,233,.14);
    }

    .sep{
      margin: 14px 0;
      border-top: 1px dashed rgba(71,85,105,.25);
    }

    .grid2{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .grid3{
      display:grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 12px;
    }

    .actions{
      display:flex;
      flex-wrap:wrap;
      gap: 10px;
      margin-top: 14px;
      align-items:center;
    }

    button{
      border: 0;
      background: var(--grad-main);
      color: #0b1220;
      padding: 11px 14px;
      border-radius: 12px;
      cursor:pointer;
      font-weight: 900;
      letter-spacing:.2px;
      box-shadow: 0 12px 26px rgba(14,165,233,.22);
      transition: transform .06s ease, filter .15s ease;
    }
    button:hover{ filter: brightness(1.02); }
    button:active{ transform: translateY(1px); }

    /* Botones secundarios (Autorellenar / Limpiar) */
    .btn-secondary{
      background: #ffffff;
      color: #0f172a;
      border: 1px solid rgba(2,6,23,.14);
      box-shadow: 0 10px 22px rgba(2,6,23,.06);
    }
    .btn-secondary:hover{ filter: none; }
    .btn-secondary:active{ transform: translateY(1px); }

    .note{
      padding: 12px 16px;
      color: var(--muted);
      font-size: 12px;
      border-top: 1px solid var(--border);
      background: rgba(2, 6, 23, .02);
    }

    .err{
      background: #fff1f2;
      border: 1px solid #fecdd3;
      color: #7f1d1d;
      padding: 12px 14px;
      border-radius: 14px;
      white-space: pre-wrap;
    }

    /* Render Markdown */
    .md{
      background: #ffffff;
      border: 1px solid rgba(2,6,23,.10);
      border-radius: 14px;
      padding: 14px;
      line-height: 1.65;
    }
    .md p{ margin: 0 0 10px; color: var(--text); }
    .md ul{ margin: 0 0 12px 18px; padding:0; }
    .md li{ margin: 6px 0; color: var(--text); }
    .md code{
      font-family: var(--mono);
      background: rgba(2,6,23,.06);
      border: 1px solid rgba(2,6,23,.08);
      padding: 1px 6px;
      border-radius: 8px;
    }
    .md h3,.md h4,.md h5{
      margin: 0 0 10px;
      font-size: 14px;
      color: var(--text);
    }

    /* 4) Tiempo de respuesta */
    .timebox{
      margin: 0 0 12px;
      padding: 10px 12px;
      border-radius: 12px;
      background: rgba(14,165,233,.10);
      border: 1px solid rgba(14,165,233,.18);
      color: #0f172a;
      font-size: 12px;
    }

    @media (max-width: 900px){
      .grid2,.grid3{ grid-template-columns: 1fr; }
      body{ padding: 18px 12px; }
      .brand img{ height: 36px; }
    }
  </style>
</head>
<body>

<div class="wrap">

  <div class="topbar">
    <div class="topbar-inner">
      <div class="brand">
        <img
          src="https://piero7ov.github.io/pierodev-assets/brand/pierodev/logos/logocompleto.png"
          alt="PIERODEV"
          loading="eager"
        >
        <div class="title">
          <h1>Asesor de Finanzas</h1>
          <p>Consulta tus finanzas personales y te dare consejos</p>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="cardHead"><h2>Consulta</h2></div>
    <div class="content">
      <form method="post">
        <p class="hint">
          Ejemplos:<br>
          - "Cuánto me queda para ocio con estos gastos?"<br>
          - "Quiero ahorrar 14000€ en 24 meses, cuánto debo apartar al mes?"<br>
          - "Cuánto tiempo tardaré en ahorrar 1300€?"<br>
          - "Quiero pagar algo de 10000€ en 36 meses, cuánto es al mes?"
        </p>

        <label for="prompt">Tu pregunta (texto libre)</label>
        <textarea id="prompt" name="prompt" placeholder="Escribe tu pregunta..."><?=h($prompt)?></textarea>

        <div class="sep"></div>

        <p class="hint" style="margin-top:0;">
          Formulario (opcional): rellena lo que tengas. Si no sabes algo, déjalo en blanco.
        </p>

        <div class="grid2">
          <div>
            <label for="ingresos">Ingresos mensuales (€)</label>
            <input id="ingresos" name="ingresos" value="<?=h($ingresos)?>" placeholder="Ej: 1600">
          </div>
          <div>
            <label for="g_alquiler">Alquiler / Hipoteca (€)</label>
            <input id="g_alquiler" name="g_alquiler" value="<?=h($g_alquiler)?>" placeholder="Ej: 800">
          </div>
        </div>

        <div class="grid3" style="margin-top:12px;">
          <div>
            <label for="g_luz">Luz (€)</label>
            <input id="g_luz" name="g_luz" value="<?=h($g_luz)?>" placeholder="Ej: 50">
          </div>
          <div>
            <label for="g_agua">Agua (€)</label>
            <input id="g_agua" name="g_agua" value="<?=h($g_agua)?>" placeholder="Ej: 15">
          </div>
          <div>
            <label for="g_internet">Internet (€)</label>
            <input id="g_internet" name="g_internet" value="<?=h($g_internet)?>" placeholder="Ej: 20">
          </div>
        </div>

        <div class="grid3" style="margin-top:12px;">
          <div>
            <label for="g_comida">Comida (€)</label>
            <input id="g_comida" name="g_comida" value="<?=h($g_comida)?>" placeholder="Ej: 150">
          </div>
          <div>
            <label for="g_transporte">Transporte (€)</label>
            <input id="g_transporte" name="g_transporte" value="<?=h($g_transporte)?>" placeholder="Ej: 20">
          </div>
          <div>
            <label for="g_otros">Otros (€)</label>
            <input id="g_otros" name="g_otros" value="<?=h($g_otros)?>" placeholder="Ej: 50">
          </div>
        </div>

        <div class="grid2" style="margin-top:12px;">
          <div>
            <label for="meta">Meta / objetivo (opcional)</label>
            <input id="meta" name="meta" value="<?=h($meta)?>" placeholder="Ej: ahorrar 400€ para una compra">
          </div>
          <div>
            <label for="plazoMeses">Plazo en meses (opcional)</label>
            <input id="plazoMeses" name="plazoMeses" value="<?=h($plazoMeses)?>" placeholder="Ej: 24">
          </div>
        </div>

        <div class="actions">
          <!-- Ask -->
          <button type="submit" name="action" value="ask">Preguntar</button>

          <!-- Autorellenar (solo al pulsar) -->
          <button type="submit" name="action" value="prefill" class="btn-secondary">Autorellenar última</button>

          <!-- 3) Limpiar -->
          <button type="submit" name="action" value="clear" class="btn-secondary">Limpiar</button>
        </div>
      </form>
    </div>
    <div class="note">Si no responde, verifica que Ollama esté ejecutándose en tu PC. Los resultados pueden fallar, no se asegura que sean correctos.</div>
  </div>

  <?php if ($result !== null): ?>
    <div class="card">
      <div class="cardHead"><h2>Respuesta</h2></div>
      <div class="content">
        <?php if (!$result["ok"]): ?>
          <?php if (isset($result["elapsed"])): ?>
            <div class="timebox">Última consulta tardó <?=h(number_format((float)$result["elapsed"], 2, ',', ''))?> segundos</div>
          <?php endif; ?>
          <div class="err"><?=nl2br(h($result["err"]))?></div>
        <?php else: ?>
          <!-- 4) Tiempo de la última consulta -->
          <div class="timebox">Última consulta tardó <?=h(number_format((float)$result["elapsed"], 2, ',', ''))?> segundos</div>
          <?= $result["out_html"] ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

</body>
</html>
