<?php

namespace App\Http\Controllers;
use App\Models\Movement;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;


class MovementController extends Controller
{
    /**
     * index method to list movements.
     * @return \Illuminate\Http\Response
     */
    public function index() : JsonResponse
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $movements = $user->movements()
                ->orderBy('created_at', 'desc')
                ->paginate(10);
            
            if ($movements->isEmpty()) {
                return response()->json(['message' => 'No movements found'], 404);
            }
            return response()->json($movements, 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching movements.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * create new movement.
     * @param \Illuminate\Http\Request $request
     */
    public function create(Request $request) : JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'type' => 'required|string|in:income,expense',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validated->fails()) return response()->json([
            'error' => 'Validation failed',
            'messages' => $validated->errors()
        ], 422);

        try {

            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $movement = new Movement();
            $movement->type = $request->input('type');
            $movement->amount = $request->input('amount');
            $movement->description = $request->input('description', '');
            $movement->user_id = $user->id;
            $movement->save();

            return response()->json([
                'message' => 'Movement created successfully',
                'movement' => $movement
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while creating the movement.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function sugerirMovimientoConIA(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'transcripcion' => 'required|string',
        ]);

        if ($validated->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validated->errors()
            ], 422);
        }

        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $transcripcion = $request->input('transcripcion');

            // Preparar prompt: pasar etiquetas existentes para que pueda reutilizarlas
            $etiquetasExistentes = Tag::where('user_id', $user->id)->pluck('name_tag')->toArray();
            $listaEtiquetas = empty($etiquetasExistentes) ? 'Ninguna' : implode(', ', $etiquetasExistentes);

            $prompt = <<<PROMPT
    Eres un asistente de finanzas personales. Te doy una transcripción libre de voz de un movimiento del usuario. Tienes esta lista de etiquetas existentes: 
    $listaEtiquetas

    Tu tarea es extraer y clasificar el movimiento. Devuelve **únicamente un objeto JSON válido** con estas llaves:

    - "amount": el valor numérico del monto (sin separadores de miles, como número).
    - "currency": la moneda, asume "COP" si no se especifica otra.
    - "type": "expense" o "income" según corresponda.
    - "suggested_tag": una etiqueta existente si aplica bien, o una nueva etiqueta corta (1-2 palabras) si ninguna existente encaja.
    - "description": una versión corta / limpia de lo que pasó (puede ser la transcripción tal cual).

    Ejemplo de output esperado:
    {
    "amount": 50000,
    "currency": "COP",
    "type": "expense",
    "suggested_tag": "Herramientas",
    "description": "Hoy me compré una pala"
    }

    No agregues explicaciones ni texto adicional fuera del JSON. Si la transcripción no tiene suficiente información para alguna clave, haz la mejor suposición razonable (por ejemplo, si no se menciona moneda, usa "COP").

    Transcripción: "$transcripcion"
    PROMPT;

            $apiKey = config('services.groq.key');
            $modelos = [
                'llama3-70b-8192',
                'llama-3.1-8b-instant',
                'gemma2-9b-it',
            ];

            $rawResponseContent = null;
            foreach ($modelos as $modelo) {
                try {
                    $response = Http::withOptions([
                        'verify' => 'C:\Program Files\php8.3\extras\ssl\cacert.pem',
                    ])->withHeaders([
                        'Authorization' => "Bearer $apiKey",
                        'Content-Type' => 'application/json',
                    ])->post('https://api.groq.com/openai/v1/chat/completions', [
                        'model' => $modelo,
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        'temperature' => 0.2,
                        'max_tokens' => 300,
                    ]);

                    if ($response->successful()) {
                        $rawResponseContent = trim($response['choices'][0]['message']['content']);
                        break;
                    }
                } catch (\Exception $e) {
                    // seguir con el siguiente modelo
                    Log::warning("IA modelo $modelo falló: " . $e->getMessage());
                }
            }

            if (!$rawResponseContent) {
                return response()->json([
                    'error' => 'No se obtuvo respuesta válida de la IA.'
                ], 500);
            }

            // Intentar decodificar JSON. A veces la IA agrega texto antes/después, así que lo limpiamos.
            $jsonString = $this->extraerJson($rawResponseContent);
            if (!$jsonString) {
                return response()->json([
                    'error' => 'La IA respondió pero no pudo extraer JSON válido.',
                    'raw' => $rawResponseContent
                ], 500);
            }

            $data = json_decode($jsonString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'error' => 'Error al parsear el JSON de la IA.',
                    'parse_error' => json_last_error_msg(),
                    'raw' => $rawResponseContent
                ], 500);
            }

            // Normalizaciones mínimas
            if (!isset($data['currency']) || empty($data['currency'])) {
                $data['currency'] = 'COP';
            }
            if (!isset($data['type'])) {
                $data['type'] = 'expense';
            }

            return response()->json([
                'movement_suggestion' => $data,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error procesando con IA.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extrae el primer objeto JSON válido de un string que podría tener ruido.
     */
    private function extraerJson(string $text): ?string
    {
        // Buscar desde la primera llave { hasta la correspondiente } balanceada.
        $start = strpos($text, '{');
        if ($start === false) return null;

        $level = 0;
        $inString = false;
        $escape = false;
        $json = '';
        for ($i = $start; $i < strlen($text); $i++) {
            $char = $text[$i];
            $json .= $char;

            if ($char === '"' && !$escape) {
                $inString = !$inString;
            }
            if ($char === '\\' && !$escape) {
                $escape = true;
            } else {
                $escape = false;
            }
            if (!$inString) {
                if ($char === '{') $level++;
                if ($char === '}') $level--;
                if ($level === 0) {
                    // Probablemente cerró el JSON completo
                    break;
                }
            }
        }

        // Validar que sea JSON decodificable rápido
        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        return null;
    }

}
