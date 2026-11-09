<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$response = \Illuminate\Support\Facades\Http::timeout(30)
    ->withHeaders([
        'x-goog-api-key' => env('GEMINI_API_KEY'),
    ])
    ->get('https://generativelanguage.googleapis.com/v1beta/models');

echo "HTTP: " . $response->status() . "\n";

if ($response->successful()) {
    foreach ($response->json('models', []) as $model) {
        if (in_array('generateContent', $model['supportedGenerationMethods'] ?? [])) {
            echo $model['name'] . "\n";
        }
    }
} else {
    echo "Tipo: " . ($response->json('error.status') ?? 'DESCONOCIDO') . "\n";
    echo "Mensaje: " . ($response->json('error.message') ?? 'Sin detalles') . "\n";
}
