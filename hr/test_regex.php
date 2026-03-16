<?php
// Test simple de regex patterns

$tests = [
    [
        'query' => '¿Cuántos candidatos tienen expectativas salariales entre RD$20,000 y RD$30,000?',
        'pattern' => '/(?:entre|between)[^\d]*([\d,]+)[^\d]+(?:y|and)[^\d]*([\d,]+)/i'
    ],
    [
        'query' => 'Candidatos con salario entre 25000 y 35000 pesos',
        'pattern' => '/(?:entre|between)[^\d]*([\d,]+)[^\d]+(?:y|and)[^\d]*([\d,]+)/i'
    ],
    [
        'query' => 'Mostrar personas con salario mayor a 20000',
        'pattern' => '/(?:salario|salary|sueldo|aspiracion|expectativa)[^\d]+(?:mayor|mas|superior|arriba)[^\d]+([\d,]+)/i'
    ],
    [
        'query' => 'Candidatos con más de 3 años de experiencia',
        'pattern' => '/(?:experiencia|experience)[^\d]+([\d]+)[^\d]+(?:año|year|anos)/i'
    ],
    [
        'query' => 'Aplicaciones de los últimos 7 días',
        'pattern' => '/(?:ultimos|reciente|ultima|nuevo|nueva|last)[^\d]+([\d]+)[^\d]+(?:dia|day)/i'
    ],
    [
        'query' => 'Candidatos con salario menor a 15000',
        'pattern' => '/(?:salario|salary|sueldo|aspiracion|expectativa)[^\d]+(?:menor|menos|inferior|debajo)[^\d]+([\d,]+)/i'
    ]
];

echo "<h1>Test de Patrones Regex</h1>";
echo "<style>
    body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #0f172a; color: #e2e8f0; }
    h1 { color: #60a5fa; }
    h3 { color: #818cf8; margin-top: 30px; }
    .query { color: #fbbf24; font-style: italic; }
    .pattern { color: #a78bfa; font-size: 14px; margin: 10px 0; }
    .result { padding: 15px; margin: 10px 0; border-radius: 8px; }
    .success { background: #1e293b; color: #10b981; }
    .error { background: #7f1d1d; color: #fca5a5; }
    .matches { color: #22d3ee; }
</style>";

foreach ($tests as $i => $test) {
    echo "<hr><h3>Test " . ($i + 1) . "</h3>";
    echo "<div class='query'>Consulta: {$test['query']}</div>";
    echo "<div class='pattern'>Patrón: {$test['pattern']}</div>";
    
    if (preg_match($test['pattern'], $test['query'], $matches)) {
        echo "<div class='result success'>";
        echo "✅ Match encontrado<br>";
        echo "<div class='matches'>";
        echo "Grupos capturados:<br>";
        foreach ($matches as $idx => $match) {
            $cleaned = preg_replace('/[^0-9]/', '', $match);
            echo "  [$idx] = '$match' → limpio: '$cleaned'<br>";
        }
        echo "</div>";
        echo "</div>";
    } else {
        echo "<div class='result error'>❌ No match</div>";
    }
}
?>
