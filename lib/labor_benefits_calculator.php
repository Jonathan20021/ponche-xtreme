<?php
/**
 * lib/labor_benefits_calculator.php
 *
 * Cálculo de PRESTACIONES LABORALES y DERECHOS ADQUIRIDOS conforme al Código de
 * Trabajo de la República Dominicana (Ley 16-92):
 *
 *   - Preaviso                  (arts. 76 y 79)
 *   - Auxilio de cesantía       (arts. 80 y 81, + 15 días/año anteriores al Código)
 *   - Vacaciones                (art. 177)
 *   - Salario de Navidad        (art. 219)  -> "regalía pascual"
 *
 * PARIDAD CON EL MINISTERIO DE TRABAJO
 * ------------------------------------
 * Esto NO es una interpretación propia de la ley: es el mismo algoritmo que corre
 * la calculadora oficial de https://calculo.mt.gob.do (su `js/site.min.js`,
 * clase `Calculator`). Se replicaron una por una sus reglas, incluidas las que
 * a simple vista parecen rarezas, porque el objetivo es que ambos den el MISMO
 * número al centavo:
 *
 *   1. La fecha de salida se corre UN DÍA hacia adelante antes de medir el
 *      tiempo. El MT lo hace en el datepicker (`new Date(y, m, d + 1)`). Es lo
 *      que hace que el último día laborado cuente.
 *   2. La diferencia entre fechas se mide con su propia aritmética (ver
 *      lbDifFechas), no con DateTime::diff. En los meses de 31 días el resultado
 *      puede diferir en un día respecto a DateTime; manda el del MT.
 *   3. Febrero se considera bisiesto con `año % 4 == 0`, sin la regla de los
 *      siglos. Se deja igual a propósito.
 *   4. El promedio DIARIO sale de dividir el promedio del período entre 23.83
 *      (ordinario) o 26 (intermitente/doméstico). El 23.83 es el divisor legal
 *      del Reglamento 258-93 (286 días laborables al año / 12).
 *   5. Las vacaciones NO se pagan al promedio: se pagan al salario del ÚLTIMO
 *      mes registrado, dividido entre el factor del período.
 *
 * Las escalas legales (días de preaviso, de cesantía, factores) son
 * configurables desde Nómina -> Configuración, para no tener que tocar código
 * si el Congreso mueve la ley.
 */

require_once __DIR__ . '/../db.php';

if (!function_exists('laborBenefitsDefaults')) {
    /**
     * Valores de fábrica: los mismos que usa la calculadora del MT.
     *
     * @return array<string,string>
     */
    function laborBenefitsDefaults(): array
    {
        return [
            // Divisores del salario del período -> salario diario, en el orden
            // Mensual, Quincenal, Semanal, Diario. Son los literales del MT: el
            // quincenal es 11.91, NO 23.83/2 (=11.915). Ese medio centésimo
            // basta para separarse del cálculo oficial, así que no se deriva.
            'benefits_divisores_ordinario'    => '23.83,11.91,5.5,1', // Reglamento 258-93
            'benefits_divisores_intermitente' => '26,13,6,1',         // intermitente y doméstico
            // Semanal -> mensual (semanas promedio por mes).
            'benefits_semanas_por_mes'        => '4.3333',

            // Preaviso, art. 76: meses de antigüedad => días de salario.
            'benefits_preaviso_3_6_meses'     => '7',
            'benefits_preaviso_6_12_meses'    => '14',
            'benefits_preaviso_12_meses_mas'  => '28',

            // Cesantía, arts. 80 y 81.
            'benefits_cesantia_1_5_anios'     => '21',  // por cada año, de 1 a 5
            'benefits_cesantia_5_anios_mas'   => '23',  // por cada año, desde 5
            'benefits_cesantia_fraccion_3_6'  => '6',   // fracción de 3 a 6 meses
            'benefits_cesantia_fraccion_6_12' => '13',  // fracción de más de 6 meses
            'benefits_cesantia_antes_codigo'  => '15',  // por año anterior al Código
            'benefits_fecha_codigo_trabajo'   => '1992-06-17',

            // Vacaciones, art. 177.
            'benefits_vacaciones_1_5_anios'   => '14',
            'benefits_vacaciones_5_anios_mas' => '18',
            'benefits_vacaciones_domestico'   => '14',
        ];
    }
}

if (!function_exists('laborBenefitsCompanyDefaults')) {
    /**
     * Identificación de la empresa que encabeza el documento de liquidación.
     *
     * Los valores de fábrica salen del contrato laboral que ya emite el sistema
     * (hr/view_contract.php), donde estaban escritos a mano. Aquí son ajustes
     * para que se puedan corregir desde la pantalla de Configuración sin tocar
     * código, que es como se maneja el resto del sistema.
     *
     * @return array<string,string>
     */
    function laborBenefitsCompanyDefaults(): array
    {
        return [
            'company_name'                 => 'EVALLISH SRL',
            'company_rnc'                  => '1-32637453',
            'company_address'              => 'Calle 6 No. 6, Reparto Conet, Santiago, República Dominicana',
            'company_phone'                => '',
            'company_email'                => '',
            'company_city'                 => 'Santiago',
            'company_representative'       => 'Hugo Antonio Hidalgo Núñez',
            'company_representative_title' => 'Gerente General',
        ];
    }
}

if (!function_exists('laborBenefitsCompany')) {
    /**
     * Datos de la empresa vigentes.
     *
     * @return array<string,string>
     */
    function laborBenefitsCompany(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cfg = laborBenefitsCompanyDefaults();

        try {
            $keys = array_keys($cfg);
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($ph)");
            $stmt->execute($keys);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                // Aquí sí se respeta el vacío: el teléfono y el correo pueden
                // estar en blanco a propósito.
                $cfg[$r['setting_key']] = trim((string) ($r['setting_value'] ?? ''));
            }
        } catch (Throwable $e) {
            error_log('laborBenefitsCompany: ' . $e->getMessage());
        }

        $cache = $cfg;
        return $cache;
    }
}

if (!function_exists('laborBenefitsLogoBase64')) {
    /**
     * Logo listo para incrustar en el PDF, como data URI.
     *
     * Se reduce a 260 px antes de codificarlo: el original son 1600×1600 y
     * 310 KB, que en base64 se convierten en ~414 KB metidos dentro de CADA
     * documento. Reducido pesa unas veinte veces menos y en papel se ve igual.
     * El resultado se guarda en cache/ para no rehacerlo en cada liquidación.
     */
    function laborBenefitsLogoBase64(int $ancho = 260): string
    {
        $origen = dirname(__DIR__) . '/assets/logo.png';
        if (!is_file($origen)) {
            return '';
        }

        $cache = dirname(__DIR__) . '/cache/logo_pdf_' . $ancho . '.txt';
        if (is_file($cache) && filemtime($cache) >= filemtime($origen)) {
            return (string) file_get_contents($cache);
        }

        if (!function_exists('imagecreatefrompng')) {
            // Sin GD no se puede redimensionar: va el original.
            return 'data:image/png;base64,' . base64_encode((string) file_get_contents($origen));
        }

        try {
            $src = @imagecreatefrompng($origen);
            if (!$src) {
                return '';
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $alto = (int) round($h * ($ancho / max(1, $w)));

            $dst = imagecreatetruecolor($ancho, $alto);
            // El logo es transparente: sin esto el fondo sale negro en el PDF.
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $ancho, $alto, $w, $h);

            ob_start();
            imagepng($dst, null, 9);
            $bin = (string) ob_get_clean();

            imagedestroy($src);
            imagedestroy($dst);

            $uri = 'data:image/png;base64,' . base64_encode($bin);
            @file_put_contents($cache, $uri);
            return $uri;
        } catch (Throwable $e) {
            error_log('laborBenefitsLogoBase64: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('lbNumeroALetras')) {
    /**
     * Importe en letras para el documento de pago ("SETENTA Y OCHO MIL ...
     * PESOS DOMINICANOS CON 98/100"), como se acostumbra en un recibo.
     */
    function lbNumeroALetras(float $monto, string $moneda = 'PESOS DOMINICANOS'): string
    {
        $monto = round(abs($monto), 2);
        $entero = (int) floor($monto);
        $centavos = (int) round(($monto - $entero) * 100);

        $texto = lbEnteroALetras($entero);

        // "veintiuno pesos" no se dice: delante del sustantivo va apocopado, y
        // al apocoparse "veintiuno" gana tilde -> "veintiún pesos".
        $texto = preg_replace('/veintiuno$/u', 'veintiún', $texto);
        $texto = preg_replace('/uno$/u', 'un', $texto);

        // mb_strtoupper y no strtoupper: con strtoupper "millón" sale "MILLóN"
        // porque no entiende UTF-8.
        return mb_strtoupper($texto, 'UTF-8') . ' ' . $moneda . ' CON ' . sprintf('%02d', $centavos) . '/100';
    }
}

if (!function_exists('lbEnteroALetras')) {
    /** Convierte un entero a palabras en español. Uso interno de lbNumeroALetras. */
    function lbEnteroALetras(int $n): string
    {
        if ($n === 0) {
            return 'cero';
        }
        if ($n < 0) {
            return 'menos ' . lbEnteroALetras(-$n);
        }

        $unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
                     'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete',
                     'dieciocho', 'diecinueve', 'veinte'];
        $decenas  = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta',
                     7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];
        $centenas = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos',
                     5 => 'quinientos', 6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos',
                     9 => 'novecientos'];

        if ($n <= 20) {
            return $unidades[$n];
        }
        if ($n < 30) {
            // Se listan a mano porque tres llevan tilde: veintidós, veintitrés
            // y veintiséis. Concatenar "veinti" + unidad las dejaría sin ella.
            $veinti = [1 => 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco',
                       'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
            return $veinti[$n - 20];
        }
        if ($n < 100) {
            $d = intdiv($n, 10);
            $u = $n % 10;
            return $decenas[$d] . ($u ? ' y ' . $unidades[$u] : '');
        }
        if ($n === 100) {
            return 'cien';
        }
        if ($n < 1000) {
            $c = intdiv($n, 100);
            $r = $n % 100;
            return $centenas[$c] . ($r ? ' ' . lbEnteroALetras($r) : '');
        }
        if ($n < 1000000) {
            $miles = intdiv($n, 1000);
            $r = $n % 1000;
            $pref = $miles === 1 ? 'mil' : lbEnteroALetras($miles) . ' mil';
            return $pref . ($r ? ' ' . lbEnteroALetras($r) : '');
        }
        if ($n < 1000000000) {
            $mill = intdiv($n, 1000000);
            $r = $n % 1000000;
            $pref = $mill === 1 ? 'un millón' : lbEnteroALetras($mill) . ' millones';
            return $pref . ($r ? ' ' . lbEnteroALetras($r) : '');
        }

        $miles = intdiv($n, 1000000000);
        $r = $n % 1000000000;
        $pref = $miles === 1 ? 'mil millones' : lbEnteroALetras($miles) . ' mil millones';
        return $pref . ($r ? ' ' . lbEnteroALetras($r) : '');
    }
}

if (!function_exists('laborBenefitsConfig')) {
    /**
     * Escalas legales vigentes. Lo que no esté en system_settings usa el valor
     * de fábrica, así la calculadora funciona aunque no se haya corrido la
     * migración.
     *
     * @return array<string,string>
     */
    function laborBenefitsConfig(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cfg = laborBenefitsDefaults();

        try {
            $keys = array_keys($cfg);
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($ph)");
            $stmt->execute($keys);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $v = trim((string) ($r['setting_value'] ?? ''));
                if ($v !== '') {
                    $cfg[$r['setting_key']] = $v;
                }
            }
        } catch (Throwable $e) {
            error_log('laborBenefitsConfig: ' . $e->getMessage());
        }

        $cache = $cfg;
        return $cache;
    }
}

if (!function_exists('lbDiasMes')) {
    /**
     * Días del mes, replicando `Utils.diasMes` del MT.
     *
     * Ojo: el bisiesto es `año % 4 == 0` a secas, sin la excepción de los años
     * seculares. Se deja idéntico para no separarse del oficial (entre 1901 y
     * 2099 da lo mismo de todas formas).
     *
     * @param int $month 1-12
     */
    function lbDiasMes(int $month, int $year): int
    {
        switch ($month) {
            case 1: case 3: case 5: case 7: case 8: case 10: case 12:
                return 31;
            case 2:
                return $year % 4 === 0 ? 29 : 28;
        }
        return 30;
    }
}

if (!function_exists('lbRound2')) {
    /**
     * Equivalente exacto de `Math.round(x * 100) / 100` de JavaScript.
     *
     * NO se usa round($x, 2) de PHP: PHP corrige el error de representación en
     * coma flotante antes de redondear y JavaScript no, así que en los importes
     * que caen justo sobre el medio centavo los dos difieren en RD$0.01. El MT
     * publica el número de JavaScript, de modo que aquí manda JavaScript.
     *
     * Y TAMPOCO sirve el atajo floor($x * 100 + 0.5). Math.round no está
     * definido así: cuando $x * 100 vale 0.49999999999999994, sumarle 0.5 da
     * exactamente 1.0 (el resultado no cabe en un double y se redondea hacia
     * arriba), de modo que el atajo devuelve 1 donde JavaScript devuelve 0. Por
     * eso la parte entera y la fraccionaria se separan antes de decidir: la
     * resta $y - floor($y) sí es exacta.
     */
    function lbRound2(float $x): float
    {
        $y = $x * 100;
        if (!is_finite($y)) {
            return $x;
        }
        $entero = floor($y);
        // Empate exacto -> hacia arriba, como manda Math.round.
        if (($y - $entero) >= 0.5) {
            $entero += 1;
        }
        return $entero / 100;
    }
}

if (!function_exists('lbFixed2')) {
    /**
     * Equivalente de `Number(x.toFixed(2))` de JavaScript: así muestra el MT los
     * promedios y los totales.
     *
     * Tampoco sirve aquí sprintf('%.2F'): ante un empate exacto (por ejemplo
     * 197834.625, que en binario se representa clavado) toFixed sube y sprintf
     * baja al par. Por eso se expande el double a su decimal exacto y se
     * redondea a mano hacia arriba.
     */
    function lbFixed2(float $x): float
    {
        if (!is_finite($x)) {
            return $x;
        }
        $neg = $x < 0;
        $abs = $neg ? -$x : $x;

        // 53 decimales (el máximo que acepta sprintf de PHP) agotan la expansión
        // exacta de un double con magnitud de dinero: aquí no hay redondeo
        // intermedio que pueda cambiar el tercer decimal.
        $s = sprintf('%.53F', $abs);
        $dot = strpos($s, '.');
        $entero = substr($s, 0, $dot);
        $frac   = substr($s, $dot + 1) . '000';

        $centavos = (int) $entero * 100 + (int) substr($frac, 0, 2);
        if ($frac[2] >= '5') {
            $centavos++;
        }

        $r = $centavos / 100;
        return $neg ? -$r : $r;
    }
}

if (!function_exists('lbDivisores')) {
    /**
     * "23.83,11.91,5.5,1" -> [23.83, 11.91, 5.5, 1.0], siempre con 4 posiciones
     * (Mensual, Quincenal, Semanal, Diario) y sin ceros que revienten la división.
     *
     * @return array<int,float>
     */
    function lbDivisores(string $raw): array
    {
        $partes = array_map('trim', explode(',', $raw));
        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $v = (float) ($partes[$i] ?? 0);
            $out[$i] = $v > 0 ? $v : 1.0;
        }
        return $out;
    }
}

if (!function_exists('lbParseFecha')) {
    /**
     * 'Y-m-d' -> ['y'=>int, 'm'=>int (0-11, como en JS), 'd'=>int]
     *
     * @return array{y:int,m:int,d:int}|null
     */
    function lbParseFecha(?string $date): ?array
    {
        $date = trim((string) $date);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return null;
        }
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
        if (!checkdate($mo, $d, $y)) {
            return null;
        }
        return ['y' => $y, 'm' => $mo - 1, 'd' => $d];
    }
}

if (!function_exists('lbFechaToString')) {
    /** @param array{y:int,m:int,d:int} $f */
    function lbFechaToString(array $f): string
    {
        return sprintf('%04d-%02d-%02d', $f['y'], $f['m'] + 1, $f['d']);
    }
}

if (!function_exists('lbSumarDias')) {
    /**
     * @param array{y:int,m:int,d:int} $f
     * @return array{y:int,m:int,d:int}
     */
    function lbSumarDias(array $f, int $dias): array
    {
        $ts = mktime(12, 0, 0, $f['m'] + 1, $f['d'] + $dias, $f['y']);
        return ['y' => (int) date('Y', $ts), 'm' => (int) date('n', $ts) - 1, 'd' => (int) date('j', $ts)];
    }
}

if (!function_exists('lbDifFechas')) {
    /**
     * Diferencia años/meses/días tal como la calcula el MT (`calcDifFechas`).
     *
     * No se usa DateTime::diff a propósito: el MT ajusta el día prestado con los
     * días del MES DE INICIO y el AÑO DE FIN, lo que en algunos bordes da un día
     * distinto. Cambiar esto rompería la paridad con el cálculo oficial.
     *
     * @param array{y:int,m:int,d:int} $ini
     * @param array{y:int,m:int,d:int} $fin
     * @return array{years:int,months:int,days:int}
     */
    function lbDifFechas(array $ini, array $fin): array
    {
        $anioIni = $ini['y']; $mesIni = $ini['m']; $diaIni = $ini['d'];
        $anioFin = $fin['y']; $mesFin = $fin['m']; $diaFin = $fin['d'];

        if ($diaFin < $diaIni) {
            $diaFin += lbDiasMes($mesIni + 1, $anioFin);
            $mesFin -= 1;
        }
        if ($mesFin < $mesIni) {
            $mesFin += 12;
            $anioFin -= 1;
        }

        return [
            'years'  => $anioFin - $anioIni,
            'months' => $mesFin - $mesIni,
            'days'   => $diaFin - $diaIni,
        ];
    }
}

if (!function_exists('lbTiempoToString')) {
    /** @param array{years:int,months:int,days:int} $t */
    function lbTiempoToString(array $t): string
    {
        $partes = [];
        if ($t['years'] > 0) {
            $partes[] = $t['years'] . ($t['years'] > 1 ? ' años' : ' año');
        }
        if ($t['months'] > 0) {
            $partes[] = $t['months'] . ($t['months'] > 1 ? ' meses' : ' mes');
        }
        if ($t['days'] > 0) {
            $partes[] = $t['days'] . ($t['days'] > 1 ? ' días' : ' día');
        }
        if (!$partes) {
            return '0 días';
        }
        if (count($partes) === 1) {
            return $partes[0];
        }
        $ultimo = array_pop($partes);
        return implode(', ', $partes) . ' y ' . $ultimo;
    }
}

if (!function_exists('laborBenefitsPeriodos')) {
    /** Etiquetas de las frecuencias de pago, en el orden que usa el MT. */
    function laborBenefitsPeriodos(): array
    {
        return [0 => 'Mensual', 1 => 'Quincenal', 2 => 'Semanal', 3 => 'Diario'];
    }
}

if (!function_exists('laborBenefitsTiposCalculo')) {
    function laborBenefitsTiposCalculo(): array
    {
        return [0 => 'Ordinario', 1 => 'Intermitente', 2 => 'Trabajo doméstico'];
    }
}

if (!function_exists('laborBenefitsCalculate')) {
    /**
     * Motor de cálculo. Puerto directo de `Calculator.Calcular()` del MT.
     *
     * @param array $in {
     *   fecha_ingreso:       string  Y-m-d
     *   fecha_salida:        string  Y-m-d  (último día laborado, inclusive)
     *   periodo_idx:         int     0=Mensual 1=Quincenal 2=Semanal 3=Diario
     *   tipo_calculo_idx:    int     0=Ordinario 1=Intermitente 2=Doméstico
     *   salarios:            array   hasta 12 filas ['salario'=>float,'comision'=>float]
     *                                índice 0 = mes más antiguo, 11 = último mes
     *   preavisado:          bool    true = ya recibió el preaviso -> no se indemniza
     *   incluir_cesantia:    bool
     *   incluir_navidad:     bool
     *   vacaciones_tomadas:  bool    true = ya disfrutó las del último año
     * }
     * @param array<string,string>|null $cfg escalas legales (laborBenefitsConfig)
     *
     * @return array{ok:bool,error?:string,...}
     */
    function laborBenefitsCalculate(array $in, ?array $cfg = null): array
    {
        $cfg = $cfg ?: laborBenefitsDefaults();

        $ingreso = lbParseFecha($in['fecha_ingreso'] ?? null);
        $salidaReal = lbParseFecha($in['fecha_salida'] ?? null);

        if (!$ingreso || !$salidaReal) {
            return ['ok' => false, 'error' => 'Debes indicar una fecha de ingreso y una fecha de salida válidas.'];
        }
        if (lbFechaToString($salidaReal) < lbFechaToString($ingreso)) {
            return ['ok' => false, 'error' => 'La fecha de salida no puede ser anterior a la de ingreso.'];
        }

        // (1) El MT corre la salida un día: el último día laborado cuenta.
        $salida = lbSumarDias($salidaReal, 1);

        $periodoIdx = (int) ($in['periodo_idx'] ?? 0);
        $tipoIdx    = (int) ($in['tipo_calculo_idx'] ?? 0);
        if ($periodoIdx < 0 || $periodoIdx > 3) { $periodoIdx = 0; }
        if ($tipoIdx < 0 || $tipoIdx > 2)       { $tipoIdx = 0; }
        $esDomestico = ($tipoIdx === 2);

        // Ordinario usa una tabla de divisores; intermitente y doméstico la otra.
        $divisores = lbDivisores($tipoIdx === 0
            ? $cfg['benefits_divisores_ordinario']
            : $cfg['benefits_divisores_intermitente']);

        $factorActual        = $divisores[$periodoIdx];
        $salarioPorDiaFactor = $divisores[0]; // el divisor diario no depende del período

        // Multiplicador del salario del período -> salario mensual.
        $periodoMensual = [1.0, 2.0, (float) $cfg['benefits_semanas_por_mes'], $salarioPorDiaFactor];

        // ---- Tiempo laborado -------------------------------------------------
        $tiempo = lbDifFechas($ingreso, $salida);
        $mesesTotales = 12 * $tiempo['years'] + $tiempo['months'];

        // Cuántas casillas de salario están activas (igual que el MT).
        $mesesActivos = $tiempo['years'] > 0 ? 12 : ($tiempo['months'] > 0 ? $tiempo['months'] : 1);

        // ---- Salarios --------------------------------------------------------
        $salarios = [];
        $acumulado = 0.0;
        for ($i = 0; $i < 12; $i++) {
            $fila = $in['salarios'][$i] ?? [];
            $sal = (float) ($fila['salario'] ?? 0);
            $com = (float) ($fila['comision'] ?? 0);
            if ($sal < 0) { $sal = 0.0; }
            if ($com < 0) { $com = 0.0; }
            if ($i >= $mesesActivos) {
                // Casillas fuera del tiempo laborado no suman.
                $sal = 0.0; $com = 0.0;
            }
            // Regla del MT: sin salario base, la comisión sola no cuenta.
            $total = ($sal == 0.0) ? 0.0 : $sal + $com;
            $salarios[$i] = ['salario' => $sal, 'comision' => $com, 'total' => $total];
            $acumulado += $total;
        }

        if ($acumulado <= 0) {
            return ['ok' => false, 'error' => 'Debes registrar al menos un salario mayor que cero.'];
        }

        // Promedio del período (mensual si el pago es mensual, etc.)
        if ($tiempo['years'] > 0) {
            $promedioPeriodo = $acumulado / 12;
        } elseif ($tiempo['months'] > 0) {
            $promedioPeriodo = $acumulado / $tiempo['months'];
        } else {
            $promedioPeriodo = $acumulado;
        }

        $promedioMensual = $promedioPeriodo * $periodoMensual[$periodoIdx];
        $promedioDiario  = $promedioPeriodo / $factorActual;

        // ---- Preaviso (arts. 76 y 79) ---------------------------------------
        $preavisado = !empty($in['preavisado']);
        $diasPreaviso = 0;
        $montoPreaviso = 0.0;
        if (!$preavisado) {
            if ($mesesTotales >= 12) {
                $diasPreaviso = (int) $cfg['benefits_preaviso_12_meses_mas'];
            } elseif ($mesesTotales >= 6) {
                $diasPreaviso = (int) $cfg['benefits_preaviso_6_12_meses'];
            } elseif ($mesesTotales >= 3) {
                $diasPreaviso = (int) $cfg['benefits_preaviso_3_6_meses'];
            }
            $montoPreaviso = lbRound2($promedioDiario * $diasPreaviso);
        }

        // ---- Cesantía (arts. 80 y 81) ---------------------------------------
        $incluirCesantia = !empty($in['incluir_cesantia']);
        $diasCesantiaAntes = 0;
        $diasCesantiaDespues = 0;
        $montoCesantiaAntes = 0.0;
        $montoCesantiaDespues = 0.0;

        if ($incluirCesantia) {
            $fechaCodigo = lbParseFecha($cfg['benefits_fecha_codigo_trabajo']) ?: ['y' => 1992, 'm' => 5, 'd' => 17];

            if (lbFechaToString($fechaCodigo) > lbFechaToString($ingreso)) {
                // Entró antes del Código de 1992: el tramo viejo se paga a 15
                // días por año y el resto con la escala nueva.
                $antes   = lbDifFechas($ingreso, $fechaCodigo);
                $despues = lbDifFechas($fechaCodigo, $salida);

                if ($antes['years'] > 0) {
                    $diasCesantiaAntes = ((int) $cfg['benefits_cesantia_antes_codigo']) * $antes['years'];
                }
                // Los residuos de ambos tramos se juntan para no perder tiempo.
                if ($antes['days'] + $despues['days'] >= 30) {
                    $despues['months'] += 1;
                }
                if ($despues['months'] + $antes['months'] >= 12) {
                    $despues['months'] = $despues['months'] + $antes['months'] - 12;
                    $despues['years'] += 1;
                }
                $tramo = $despues;
            } else {
                $tramo = $tiempo;
            }

            if ($tramo['years'] >= 5) {
                $diasCesantiaDespues += ((int) $cfg['benefits_cesantia_5_anios_mas']) * $tramo['years'];
            } elseif ($tramo['years'] >= 1) {
                $diasCesantiaDespues += ((int) $cfg['benefits_cesantia_1_5_anios']) * $tramo['years'];
            }
            if ($tramo['months'] >= 6) {
                $diasCesantiaDespues += (int) $cfg['benefits_cesantia_fraccion_6_12'];
            } elseif ($tramo['months'] >= 3) {
                $diasCesantiaDespues += (int) $cfg['benefits_cesantia_fraccion_3_6'];
            }

            $montoCesantiaAntes   = lbRound2($diasCesantiaAntes * $promedioDiario);
            $montoCesantiaDespues = lbRound2($diasCesantiaDespues * $promedioDiario);
        }

        // ---- Vacaciones (art. 177) ------------------------------------------
        $vacacionesTomadas = !empty($in['vacaciones_tomadas']);
        $diasVacaciones = 0;

        if ($vacacionesTomadas) {
            // Ya disfrutó las del último año: solo se paga la fracción corrida
            // desde el último aniversario (escala del párrafo del art. 177).
            if (!$esDomestico) {
                $fraccion = $tiempo['months'];
                if     ($fraccion >= 11) { $diasVacaciones = 12; }
                elseif ($fraccion >= 10) { $diasVacaciones = 11; }
                elseif ($fraccion >= 9)  { $diasVacaciones = 10; }
                elseif ($fraccion >= 8)  { $diasVacaciones = 9; }
                elseif ($fraccion >= 7)  { $diasVacaciones = 8; }
                elseif ($fraccion >= 6)  { $diasVacaciones = 7; }
                elseif ($fraccion >= 5)  { $diasVacaciones = 6; }
            }
        } else {
            if ($mesesTotales >= 60) {
                $diasVacaciones = $esDomestico
                    ? (int) $cfg['benefits_vacaciones_domestico']
                    : (int) $cfg['benefits_vacaciones_5_anios_mas'];
            } elseif ($mesesTotales >= 12) {
                $diasVacaciones = (int) $cfg['benefits_vacaciones_1_5_anios'];
            } elseif ($mesesTotales >= 5 && !$esDomestico) {
                $diasVacaciones = $mesesTotales + 1;
            }
        }

        // (5) Las vacaciones se pagan al salario del ÚLTIMO mes, no al promedio.
        if ($tiempo['years'] > 0) {
            $salarioVacaciones = $salarios[11]['total'];
        } elseif ($tiempo['months'] > 0) {
            $salarioVacaciones = $salarios[$tiempo['months'] - 1]['total'];
        } else {
            $salarioVacaciones = $salarios[0]['total'];
        }
        $montoVacaciones = lbRound2($diasVacaciones * ($salarioVacaciones / $factorActual));

        // ---- Salario de Navidad / regalía pascual (art. 219) -----------------
        $incluirNavidad = !empty($in['incluir_navidad']);
        $montoNavidad = 0.0;
        $tiempoNavidad = ['years' => 0, 'months' => 0, 'days' => 0];

        if ($incluirNavidad) {
            if ($ingreso['y'] !== $salida['y']) {
                // Salió un 31 de diciembre (la salida corrida cae en el 1 de
                // enero): el año calendario se cuenta desde el ingreso.
                $inicioNavidad = ($salida['d'] === 1 && $salida['m'] === 0)
                    ? $ingreso
                    : ['y' => $salida['y'], 'm' => 0, 'd' => 1];
            } else {
                $inicioNavidad = $ingreso;
            }

            $diasDelMesSalida = lbDiasMes($salida['m'] + 1, $salida['y']);
            $tiempoNavidad = lbDifFechas($inicioNavidad, $salida);

            if ($tiempoNavidad['years'] > 0) {
                // Año calendario completo: una duodécima parte de 12 meses = 1 mes.
                $montoNavidad = $promedioMensual;
            } else {
                $montoNavidad = ($tiempoNavidad['months'] * $promedioMensual
                    + $tiempoNavidad['days'] * $salarioPorDiaFactor / $diasDelMesSalida * $promedioDiario) / 12;
            }
        }

        // ---- Totales ---------------------------------------------------------
        $subtotalCrudo = $montoPreaviso + $montoCesantiaAntes + $montoCesantiaDespues + $montoVacaciones;
        $subtotal = lbFixed2($subtotalCrudo);
        // El total sale del salario de Navidad SIN redondear, igual que el MT.
        $total    = lbFixed2($subtotalCrudo + $montoNavidad);

        $ultimoSalario = $tiempo['years'] > 0
            ? $salarios[11]['total']
            : ($tiempo['months'] > 0 ? $salarios[$tiempo['months'] - 1]['total'] : $salarios[0]['total']);

        return [
            'ok' => true,
            'fecha_ingreso'      => lbFechaToString($ingreso),
            'fecha_salida'       => lbFechaToString($salidaReal),
            'periodo'            => laborBenefitsPeriodos()[$periodoIdx],
            'periodo_idx'        => $periodoIdx,
            'tipo_calculo'       => laborBenefitsTiposCalculo()[$tipoIdx],
            'tipo_calculo_idx'   => $tipoIdx,

            'tiempo'             => $tiempo,
            'tiempo_texto'       => lbTiempoToString($tiempo),
            'meses_totales'      => $mesesTotales,
            'meses_activos'      => $mesesActivos,

            'salarios'           => $salarios,
            // Con pago mensual las casillas se pueden nombrar por su mes real;
            // con pago quincenal, semanal o diario no hay tal correspondencia y
            // se quedan numeradas.
            'periodos_etiquetas' => $periodoIdx === 0
                ? array_column(lbEtiquetasMeses(lbFechaToString($salidaReal)), 'etiqueta')
                : [],
            'salario_acumulado'  => lbFixed2($acumulado),
            'ultimo_salario'     => lbFixed2($ultimoSalario),
            'promedio_mensual'   => lbFixed2($promedioMensual),
            'promedio_diario'    => lbFixed2($promedioDiario),
            'factor_actual'      => $factorActual,
            'factor_diario'      => $salarioPorDiaFactor,

            'preaviso'           => ['dias' => $diasPreaviso, 'monto' => $montoPreaviso, 'omitido' => $preavisado],
            'cesantia_antes'     => ['dias' => $diasCesantiaAntes, 'monto' => $montoCesantiaAntes],
            'cesantia_despues'   => ['dias' => $diasCesantiaDespues, 'monto' => $montoCesantiaDespues],
            'cesantia_total'     => lbFixed2($montoCesantiaAntes + $montoCesantiaDespues),
            'vacaciones'         => ['dias' => $diasVacaciones, 'monto' => $montoVacaciones, 'tomadas' => $vacacionesTomadas],
            'navidad'            => [
                'monto'  => lbRound2($montoNavidad),
                'tiempo' => $tiempoNavidad,
                'texto'  => $tiempoNavidad['years'] > 0 ? '1 año' : lbTiempoToString($tiempoNavidad),
            ],

            'subtotal'           => $subtotal,
            'total'              => $total,
        ];
    }
}

if (!function_exists('lbEtiquetasMeses')) {
    /**
     * Los 12 meses que terminan en el mes de la fecha de salida, como
     * ['2025-08' => 'Ago 2025', ...] en orden: la posición 11 es el mes de
     * salida. Es la referencia de las casillas de salario cuando el pago es
     * mensual, y la base del panel de devengado de nómina.
     *
     * @return array<int,array{mes:string,etiqueta:string,dias_mes:int}>
     */
    function lbEtiquetasMeses(string $exitDate): array
    {
        $nombres = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        $fin = lbParseFecha($exitDate);
        if (!$fin) {
            return [];
        }

        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $ts = mktime(12, 0, 0, $fin['m'] + 1 - (11 - $i), 1, $fin['y']);
            $out[$i] = [
                'mes'      => date('Y-m', $ts),
                'etiqueta' => $nombres[(int) date('n', $ts)] . ' ' . date('Y', $ts),
                'dias_mes' => (int) date('t', $ts),
            ];
        }
        return $out;
    }
}

if (!function_exists('laborBenefitsPayrollMonths')) {
    /**
     * Salario ordinario REALMENTE devengado, mes a mes, según la nómina.
     *
     * POR QUÉ EXISTE ESTO: dos tercios de la plantilla cobra por hora y trabaja
     * jornadas variables. Estimar su salario mensual como "tarifa × 190.67 h"
     * (44 h semanales) da un número que, contrastado contra la nómina real, se
     * desvía en promedio un 118% y en algún caso más de 900%. Liquidar con esa
     * cifra sería inflar las prestaciones. Así que el monto sale de lo que se
     * le pagó, no de una jornada teórica.
     *
     * Las quincenas se reparten entre los meses que tocan a prorrata de días, y
     * cada mes viene marcado con su cobertura: solo se puede confiar en los
     * meses `completa`.
     *
     * Devuelve 12 casillas alineadas con la rejilla del formulario: la última
     * (índice 11) es el mes de la fecha de salida.
     *
     * @return array<int,array{mes:string,etiqueta:string,monto:float,cobertura:string,dias_cubiertos:int,dias_mes:int}>
     */
    function laborBenefitsPayrollMonths(PDO $pdo, int $employeeId, string $exitDate): array
    {
        $slots = lbEtiquetasMeses($exitDate);
        if (!$slots) {
            return [];
        }
        foreach ($slots as $i => $s) {
            $slots[$i] += ['monto' => 0.0, 'cobertura' => 'sin_datos', 'dias_cubiertos' => 0];
        }
        $desde = $slots[0]['mes'] . '-01';
        $hasta = date('Y-m-t', strtotime($slots[11]['mes'] . '-01'));

        try {
            $stmt = $pdo->prepare("
                SELECT p.start_date, p.end_date, pr.gross_salary
                FROM payroll_records pr
                INNER JOIN payroll_periods p ON p.id = pr.payroll_period_id
                WHERE pr.employee_id = ?
                  AND p.end_date >= ? AND p.start_date <= ?
            ");
            $stmt->execute([$employeeId, $desde, $hasta]);
            $registros = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('laborBenefitsPayrollMonths: ' . $e->getMessage());
            return array_values($slots);
        }

        // Índice mes -> casilla, para repartir cada quincena por días.
        $porMes = [];
        foreach ($slots as $i => $s) {
            $porMes[$s['mes']] = $i;
        }

        foreach ($registros as $r) {
            $ini = strtotime($r['start_date'] . ' 12:00:00');
            $fn  = strtotime($r['end_date'] . ' 12:00:00');
            if (!$ini || !$fn || $fn < $ini) {
                continue;
            }
            $diasPeriodo = (int) round(($fn - $ini) / 86400) + 1;
            $porDia = ((float) $r['gross_salary']) / max(1, $diasPeriodo);

            for ($t = $ini; $t <= $fn; $t += 86400) {
                $mes = date('Y-m', $t);
                if (!isset($porMes[$mes])) {
                    continue;
                }
                $k = $porMes[$mes];
                $slots[$k]['monto'] += $porDia;
                $slots[$k]['dias_cubiertos']++;
            }
        }

        foreach ($slots as $i => $s) {
            $slots[$i]['monto'] = lbFixed2($s['monto']);
            if ($s['dias_cubiertos'] <= 0) {
                $slots[$i]['cobertura'] = 'sin_datos';
            } elseif ($s['dias_cubiertos'] >= $s['dias_mes']) {
                $slots[$i]['cobertura'] = 'completa';
            } else {
                $slots[$i]['cobertura'] = 'parcial';
            }
        }

        return array_values($slots);
    }
}

if (!function_exists('laborBenefitsEmployeeDefaults')) {
    /**
     * Datos del colaborador para precargar el formulario: fecha de ingreso,
     * fecha de salida (si ya está dado de baja) y salario mensual.
     *
     * El salario vive en `users`, no en `employees`. Solo se rellena solo cuando
     * hay un salario mensual FIJO registrado; a quien cobra por hora no se le
     * inventa una jornada teórica (ver laborBenefitsPayrollMonths), se le
     * ofrece el devengado real de la nómina para que RRHH lo revise.
     *
     * @return array<string,mixed>|null
     */
    function laborBenefitsEmployeeDefaults(PDO $pdo, int $employeeId): ?array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT e.id, e.employee_code, e.first_name, e.last_name, e.position,
                       e.hire_date, e.termination_date, e.termination_reason,
                       e.identification_number, e.id_card_number,
                       d.name AS department_name,
                       u.monthly_salary_dop, u.monthly_salary, u.hourly_rate_dop, u.hourly_rate,
                       u.daily_salary_dop, u.preferred_currency, u.compensation_type
                FROM employees e
                LEFT JOIN users u ON u.id = e.user_id
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE e.id = ?
            ");
            $stmt->execute([$employeeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('laborBenefitsEmployeeDefaults: ' . $e->getMessage());
            return null;
        }

        if (!$row) {
            return null;
        }

        $divisorDiario = lbDivisores(laborBenefitsConfig($pdo)['benefits_divisores_ordinario'])[0];

        // Solo se autocompleta con un salario FIJO. Todo lo demás se deja en
        // blanco a propósito: es preferible que RRHH escriba el número a que la
        // pantalla proponga uno inventado que nadie vuelve a mirar.
        $salario = (float) ($row['monthly_salary_dop'] ?? 0);
        $origen  = 'Salario mensual fijo registrado en el sistema';
        $fijo    = $salario > 0;

        if (!$fijo && (float) ($row['daily_salary_dop'] ?? 0) > 0) {
            $salario = lbFixed2((float) $row['daily_salary_dop'] * $divisorDiario);
            $origen  = 'Salario diario fijo × ' . rtrim(rtrim(number_format($divisorDiario, 2), '0'), '.');
            $fijo    = true;
        }

        $porHora = (float) ($row['hourly_rate_dop'] ?? 0);
        if (!$fijo) {
            $salario = 0.0;
            $origen  = $porHora > 0
                ? 'Cobra por hora (RD$' . number_format($porHora, 2) . '/h): su salario mensual varía, '
                  . 'así que no se rellena solo. Usa el devengado de nómina o escríbelo.'
                : 'Sin salario registrado — escríbelo a mano.';
        }

        // Devengado real, para que RRHH lo aplique con un clic.
        $salida = $row['termination_date'] ?: date('Y-m-d');
        $mesesNomina = laborBenefitsPayrollMonths($pdo, (int) $row['id'], $salida);
        $conDatos = 0;
        foreach ($mesesNomina as $m) {
            if ($m['cobertura'] === 'completa') {
                $conDatos++;
            }
        }

        return [
            'id'               => (int) $row['id'],
            'nombre'           => trim($row['first_name'] . ' ' . $row['last_name']),
            'codigo'           => $row['employee_code'],
            'cedula'           => $row['identification_number'] ?: ($row['id_card_number'] ?: ''),
            'posicion'         => $row['position'] ?: '',
            'departamento'     => $row['department_name'] ?: '',
            'hire_date'        => $row['hire_date'],
            'termination_date' => $row['termination_date'],
            'salario_mensual'  => $salario,
            'salario_fijo'     => $fijo,
            'salario_origen'   => $origen,
            'tarifa_hora'      => $porHora,
            'meses_nomina'     => $mesesNomina,
            'meses_completos'  => $conDatos,
        ];
    }
}
