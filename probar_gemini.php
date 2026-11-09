<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = env('GEMINI_API_KEY');

$response = \Illuminate\Support\Facades\Http::timeout(30)
    ->withHeaders([
        'x-goog-api-key' => $key,
    ])
    ->post(
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent',
        [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Responde únicamente: CONEXION EXITOSA']
                    ]
                ]
            ]
        ]
    );

if ($response->successful()) {
    echo "GEMINI CONECTADO\n";
    echo $response->json('candidates.0.content.parts.0.text') . "\n";
} else {
    echo "ERROR DE CONEXION\n";
    echo "HTTP: " . $response->status() . "\n";
    echo "Tipo: " . ($response->json('error.status') ?? 'DESCONOCIDO') . "\n"; echo "Mensaje: " . ($response->json('error.message') ?? 'SIN DETALLES') . "\n";
}
