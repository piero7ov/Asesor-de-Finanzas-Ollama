<?php
/* ==========================================================
   Asesor Finanzas (Ollama local) - SIMPLE
   ----------------------------------------------------------
   - Texto libre + formulario opcional (datos estructurados)
   - Salida en <pre> (SIN Markdown aún)
   - Modelo/URL fijos (no visibles)
   - Timeout: 240s
   - max_execution_time: 300s
   - num_predict: 450
   - Prompt más preciso
   ========================================================== */

ini_set('max_execution_time', '300');
set_time_limit(300);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$model   = "mistral:instruct";
$baseUrl = "http://127.0.0.1:11434";

/* Inputs */
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

function numOrBlank($v): string {
  $v = trim((string)$v);
  if ($v === "") return "";
  $v = str_replace(",", ".", $v);
  if (!is_numeric($v)) return "";
  return (string)$v;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $userText = trim((string)$prompt);

  // 1) Construir “DATOS ESTRUCTURADOS” solo con lo que el usuario rellenó
  $ctx = [];

  $ing = numOrBlank($ingresos);
  if ($ing !== "") $ctx[] = "Ingresos_mensuales_EUR: $ing";

  $gastos = [];
  $map = [
    "Alquiler/Hipoteca" => $g_alquiler,
    "Luz"               => $g_luz,
    "Agua"              => $g_agua,
    "Internet"          => $g_internet,
    "Comida"            => $g_comida,
    "Transporte"        => $g_transporte,
    "Otros"             => $g_otros,
  ];

  foreach ($map as $name => $val) {
    $n = numOrBlank($val);
    if ($n !== "") $gastos[] = "$name: $n";
  }

  if (!empty($gastos)) {
    $ctx[] = "Gastos_fijos_mensuales_EUR: " . implode(", ", $gastos);
  }

  $m = trim((string)$meta);
  if ($m !== "") $ctx[] = "Meta/Objetivo (texto): $m";

  $pm = numOrBlank($plazoMeses);
  if ($pm !== "") $ctx[] = "Plazo_meses: $pm";

  $contextBlock = empty($ctx) ? "No se proporcionaron datos estructurados." : implode("\n", $ctx);

  // 2) Prompt más preciso
  $system =
    "Eres un consejero de finanzas personales BASICO.\n".
    "Responde en español, claro y práctico. NO recomiendes inversiones complejas.\n".
    "Usa los DATOS ESTRUCTURADOS si existen. Si faltan datos, haz hasta 3 preguntas concretas.\n".
    "\n".
    "REGLAS:\n".
    "- No inventes números.\n".
    "- Si ves cantidades tipo 14.000€ o 14,000€, interprétalas como 14000.\n".
    "- Para cálculos usa números sin separador de miles: 14000 (no 14.000).\n".
    "- Si haces una operación, escribe SIEMPRE el resultado final (no dejes operaciones a medias).\n".
    "- Moneda: EUR (€). Da un solo valor (sin rangos).\n".
    "\n".
    "SALIDA:\n".
    "- Máximo 10 líneas.\n".
    "- Si hay cálculo: \"Calculo: <operacion> = <resultado>€\".\n".
    "- Última línea obligatoria: \"RESULTADO: <clave>=<numero>€\".\n".
    "  Claves posibles: Dinero_disponible_EUR | Ahorro_mensual_EUR | Cuota_mensual_EUR | Conclusion.\n\n";

  $finalPrompt = $system
    . "DATOS ESTRUCTURADOS (si el usuario los dio):\n"
    . $contextBlock
    . "\n\nPREGUNTA LIBRE DEL USUARIO:\n"
    . ($userText !== "" ? $userText : $defaultPrompt);

  // 3) Llamada a Ollama
  $payload = [
    "model"  => $model,
    "prompt" => $finalPrompt,
    "stream" => false,
    "options" => [
      "num_predict"  => 450,
      "temperature"  => 0.3
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

  if ($raw === false) {
    $result = ["ok" => false, "err" => "cURL error: " . $curlErr, "out" => ""];
  } elseif ($httpCode < 200 || $httpCode >= 300) {
    $result = ["ok" => false, "err" => "HTTP $httpCode\n$raw", "out" => ""];
  } else {
    $json = json_decode($raw, true);
    if (!is_array($json)) {
      $result = ["ok" => false, "err" => "Respuesta no-JSON:\n$raw", "out" => ""];
    } else {
      $result = ["ok" => true, "out" => (string)($json["response"] ?? ""), "err" => ""];
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
      --bg:#f6f7fb; --card:#fff; --border:#e6e8f0; --text:#111827;
      --muted:#6b7280; --accent:#2563eb; --radius:12px;
    }
    *{box-sizing:border-box}
    body{
      margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      background:var(--bg); color:var(--text); padding:20px;
    }
    .wrap{max-width:980px; margin:0 auto; display:flex; flex-direction:column; gap:14px;}
    h1{margin:0 0 6px; font-size:20px;}
    .sub{margin:0 0 14px; color:var(--muted); font-size:14px; line-height:1.4;}
    .card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:14px;}
    label{display:block; font-size:12px; font-weight:700; margin-bottom:6px; color:var(--muted);}
    input, textarea{
      width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px;
      outline:none; font:inherit; background:#fff;
    }
    textarea{min-height:140px; resize:vertical;}
    input:focus, textarea:focus{border-color:rgba(37,99,235,.55); box-shadow:0 0 0 4px rgba(37,99,235,.10);}
    .hint{margin:12px 0; color:var(--muted); font-size:14px; line-height:1.4;}
    button{
      border:1px solid rgba(37,99,235,.35); background:var(--accent); color:#fff;
      padding:10px 14px; border-radius:10px; cursor:pointer; font-weight:700;
    }
    button:hover{filter:brightness(.95)}
    .grid{display:grid; grid-template-columns: 1fr 1fr; gap:12px;}
    .grid3{display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;}
    .sep{margin:12px 0; border-top:1px dashed var(--border);}
    .err{
      background:#fff1f2; border:1px solid #fecdd3; color:#7f1d1d;
      padding:10px 12px; border-radius:10px; white-space:pre-wrap;
    }
    pre{
      white-space:pre-wrap; word-wrap:break-word; background:#0b1220; color:#e5e7eb;
      padding:12px; border-radius:10px; overflow:auto; margin:0;
    }
    small{color:var(--muted)}
    @media (max-width: 820px){
      .grid,.grid3{grid-template-columns:1fr}
    }
  </style>
</head>
<body>

<div class="wrap">
  <div>
    <h1>Asesor de Finanzas</h1>
  </div>

  <div class="card">
    <form method="post">
      <p class="hint">
        Ejemplos:<br>
        - “Cobro 1500€ y tengo estos gastos, ¿cuánto me queda para ocio?”<br>
        - “Quiero ahorrar 14000€ en 24 meses, ¿cuánto debo apartar al mes?”<br>
        - “Quiero pagar un coche de 10000€ en 36 meses, ¿cuánto es al mes?”
      </p>

      <label for="prompt">Tu pregunta (texto libre)</label>
      <textarea id="prompt" name="prompt"><?=h($prompt)?></textarea>

      <div class="sep"></div>

      <p class="hint" style="margin-top:0;">
        Formulario (opcional): rellena lo que tengas. Si no sabes algo, déjalo en blanco.
      </p>

      <div class="grid">
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
          <input id="g_luz" name="g_luz" value="<?=h($g_luz)?>" placeholder="Ej: 45">
        </div>
        <div>
          <label for="g_agua">Agua (€)</label>
          <input id="g_agua" name="g_agua" value="<?=h($g_agua)?>" placeholder="Ej: 17">
        </div>
        <div>
          <label for="g_internet">Internet (€)</label>
          <input id="g_internet" name="g_internet" value="<?=h($g_internet)?>" placeholder="Ej: 30">
        </div>
      </div>

      <div class="grid3" style="margin-top:12px;">
        <div>
          <label for="g_comida">Comida (€)</label>
          <input id="g_comida" name="g_comida" value="<?=h($g_comida)?>" placeholder="Ej: 200">
        </div>
        <div>
          <label for="g_transporte">Transporte (€)</label>
          <input id="g_transporte" name="g_transporte" value="<?=h($g_transporte)?>" placeholder="Ej: 70">
        </div>
        <div>
          <label for="g_otros">Otros (€)</label>
          <input id="g_otros" name="g_otros" value="<?=h($g_otros)?>" placeholder="Ej: 50">
        </div>
      </div>

      <div class="grid" style="margin-top:12px;">
        <div>
          <label for="meta">Meta / objetivo (opcional)</label>
          <input id="meta" name="meta" value="<?=h($meta)?>" placeholder="Ej: ahorrar 14000€ para un coche">
        </div>
        <div>
          <label for="plazoMeses">Plazo en meses (opcional)</label>
          <input id="plazoMeses" name="plazoMeses" value="<?=h($plazoMeses)?>" placeholder="Ej: 24">
        </div>
      </div>

      <div style="margin-top:12px;">
        <button type="submit">Preguntar</button>
      </div>
    </form>
  </div>

  <?php if ($result !== null): ?>
    <div class="card">
      <h2 style="margin:0 0 10px; font-size:16px;">Respuesta</h2>

      <?php if (!$result["ok"]): ?>
        <div class="err"><?=nl2br(h($result["err"]))?></div>
      <?php else: ?>
        <pre><?=h($result["out"])?></pre>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <small>
      Si no responde, asegúrate de que Ollama está ejecutándose en tu PC.
    </small>
  </div>
</div>

</body>
</html>
