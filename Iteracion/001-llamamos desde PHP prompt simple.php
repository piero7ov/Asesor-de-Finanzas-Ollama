<?php
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$defaultPrompt = "";
$model   = "mistral:instruct";
$baseUrl = "http://127.0.0.1:11434";

$prompt  = $_POST["prompt"] ?? $defaultPrompt;
$result  = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $userPrompt = trim((string)$prompt);

  $system = "Eres un consejero de finanzas personales (básico) para personas que se independizan.\n"
          . "Responde claro y práctico. Si faltan datos, pide los mínimos necesarios.\n\n"
          . "Pregunta del usuario:\n";

  $finalPrompt = $system . $userPrompt;
  if ($userPrompt === "") $finalPrompt = $system . $defaultPrompt;

  $payload = [
    "model"  => $model,
    "prompt" => $finalPrompt,
    "stream" => false
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
    .wrap{max-width:900px; margin:0 auto; display:flex; flex-direction:column; gap:14px;}
    h1{margin:0 0 6px; font-size:20px;}
    .sub{margin:0 0 14px; color:var(--muted); font-size:14px; line-height:1.4;}
    .card{background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:14px;}
    label{display:block; font-size:12px; font-weight:700; margin-bottom:6px; color:var(--muted);}
    textarea{
      width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px;
      outline:none; font:inherit; background:#fff; min-height:150px; resize:vertical;
    }
    textarea:focus{border-color:rgba(37,99,235,.55); box-shadow:0 0 0 4px rgba(37,99,235,.10);}
    .hint{margin:12px 0; color:var(--muted); font-size:14px; line-height:1.4;}
    button{
      border:1px solid rgba(37,99,235,.35); background:var(--accent); color:#fff;
      padding:10px 14px; border-radius:10px; cursor:pointer; font-weight:700;
    }
    button:hover{filter:brightness(.95)}
    .err{
      background:#fff1f2; border:1px solid #fecdd3; color:#7f1d1d;
      padding:10px 12px; border-radius:10px; white-space:pre-wrap;
    }
    pre{
      white-space:pre-wrap; word-wrap:break-word; background:#0b1220; color:#e5e7eb;
      padding:12px; border-radius:10px; overflow:auto; margin:0;
    }
    small{color:var(--muted)}
  </style>
</head>
<body>

<div class="wrap">
  <div>
    <h1>Asesor de Finanzas</h1>
    <p class="sub">Dime tu situación y te ayudo a organizar tus gastos y objetivos de forma simple.</p>
  </div>

  <div class="card">
    <form method="post">
      <p class="hint">
        Ejemplos:<br>
        - “Cobro 1400€ y pago 650€ de alquiler, ¿cuánto debería ahorrar?”<br>
        - “Quiero ahorrar 14.000€ en 24 meses, ¿cuánto es al mes?”
      </p>

      <label for="prompt">Tu pregunta</label>
      <textarea id="prompt" name="prompt"><?=h($prompt)?></textarea>

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
