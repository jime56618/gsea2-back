<?php

// Medición local de controladores. La conexión MySQL queda protegida por transacción READ ONLY.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql') throw new RuntimeException('Este diagnóstico requiere MySQL de lectura.');
$pdo = $db->getPdo();
$pdo->exec('START TRANSACTION READ ONLY');
try {
    $after = ($argv[1] ?? '') === 'after';
    $user = App\Models\User::where('current_workspace_id', 1)->whereHas('workspaces', fn ($q) => $q->where('workspaces.id', 1))->firstOrFail();
    $app['auth']->guard()->setUser($user);
    $cases = [
        'summary' => [App\Http\Controllers\Api\DashboardController::class, 'summary', '/api/dashboard/summary'.($after ? '?compact=1' : '')],
        'cobranza' => [App\Http\Controllers\Api\DashboardController::class, 'cobranzaSummary', '/api/dashboard/cobranza'],
        'clientes' => [App\Http\Controllers\Api\ContratanteController::class, 'index', '/api/contratantes?per_page=1'],
        'vencidas' => [App\Http\Controllers\Api\PolizaController::class, 'index', '/api/polizas?vencidas=1&per_page=1'],
        'agenda' => [App\Http\Controllers\Api\CalendarController::class, 'events', '/api/calendar/events?agenda=hoy_y_proximos_7_dias&per_page=4'],
        'metas' => [App\Http\Controllers\Api\MetaComercialController::class, 'index', '/api/dashboard/metas-comerciales'],
    ];
    if ($after) $cases['totales'] = [App\Http\Controllers\Api\DashboardController::class, 'totales', '/api/dashboard/totales'];
    $db->enableQueryLog(); $results = []; $aggregates = [];
    foreach ($cases as $label => [$class, $method, $url]) {
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $request = Illuminate\Http\Request::create($url, 'GET');
            $app->instance('request', $request); $request->setUserResolver(fn () => $user);
            $db->flushQueryLog(); $start = hrtime(true);
            $response = $app->make($class)->$method($request);
            $content = $response instanceof Illuminate\Http\JsonResponse ? $response->getContent() : json_encode($response);
            $times[] = (hrtime(true) - $start) / 1e6;
            $log = $db->getQueryLog();
            foreach ($log as $query) if (!preg_match('/^(select|show)\b/i', ltrim($query['query']))) throw new RuntimeException('Consulta inesperada.');
        }
        sort($times);
        $results[$label] = ['median_ms' => round($times[2], 2), 'min_ms' => round($times[0], 2), 'max_ms' => round($times[4], 2),
            'queries' => count($log), 'sql_ms' => round(array_sum(array_column($log, 'time')), 2), 'bytes' => strlen($content)];
        if ($label === 'summary') {
            $aggregate = json_decode($content, true); unset($aggregate['polizas_por_vencer']['listado'], $aggregate['tramites']['listado']);
            $aggregates[$label] = $aggregate;
        }
    }
    $suffix = $after ? 'after' : 'before';
    file_put_contents(sys_get_temp_dir().'/gsea-dashboard-perf-'.$suffix.'.json', json_encode($results));
    file_put_contents(sys_get_temp_dir().'/gsea-dashboard-aggregates-'.$suffix.'.json', json_encode($aggregates));
    echo json_encode($results, JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    $pdo->exec('ROLLBACK');
}
