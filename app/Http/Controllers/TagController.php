<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log; // 👈 ESTA LÍNEA
use App\Models\Tag;
class TagController extends Controller
{
    // ✅ Crear una etiqueta manualmente
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name_tag' => 'required|string|max:100',
            ]);

            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'No autenticado'], 401);
            }

            // Validar si ya existe una etiqueta con ese nombre para ese usuario
            $existe = Tag::where('user_id', $user->id)
                        ->where('name_tag', $request->name_tag)
                        ->exists();

            if ($existe) {
                return response()->json(['error' => 'La etiqueta ya existe para este usuario.'], 409);
            }

            $tag = Tag::create([
                'name_tag' => $request->name_tag,
                'user_id' => Auth::id(),
            ]);

            return response()->json([
                'message' => 'Etiqueta creada exitosamente.',
                'tag' => $tag
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error al crear etiqueta: ' . $e->getMessage());
            return response()->json([
                'error' => 'Ocurrió un error al crear la etiqueta.',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    // 🤖 Sugerir una etiqueta con IA usando Groq
    public function sugerirDesdeIA(Request $request)
    {
        $request->validate([
            'descripcion' => 'required|string',
            'monto' => 'required|numeric',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'No autenticado'], 401);
        }

        try {
            // Obtener todas las etiquetas existentes del usuario
            $etiquetasExistentes = Tag::where('user_id', $user->id)->pluck('name_tag')->toArray();

            // Generar sugerencia con IA (usando etiquetas existentes como contexto)
            $sugerida = $this->etiquetarConIA($request->descripcion, $request->monto, $etiquetasExistentes);

            return response()->json([
                'sugerencia' => $sugerida,
            ]);

        } catch (\Exception $e) {
            Log::error('Error con IA: ' . $e->getMessage());
            return response()->json(['error' => 'Error al sugerir etiqueta.', 'message' => $e->getMessage()], 500);
        }
    }
private function etiquetarConIA(string $descripcion, float $monto, array $opciones): string
    {
        $apiKey = config('services.groq.key');
        $model = config('services.groq.model');
        $lista = empty($opciones)
            ? 'Transporte, Alimentación, Servicios, Entretenimiento, Salud, Ingresos, Educación, Vivienda, Otros'
            : implode(', ', $opciones);

        // Prompt para la IA
        $prompt = <<<PROMPT
    Actúa como un asistente de finanzas personales. Tienes una lista de etiquetas existentes del usuario:

    $lista

    Tu tarea es:
    1. Si alguna de estas etiquetas describe bien el movimiento, responde solo con una de ellas.
    2. Si **ninguna** etiqueta aplica bien, entonces **inventa una nueva etiqueta corta y precisa** (1 o 2 palabras máximo) que clasifique este movimiento.

    No expliques tu respuesta. Solo responde con una sola etiqueta (ya sea existente o nueva).

    Movimiento:
    Descripción: "$descripcion"
    Monto: $monto

    Etiqueta sugerida:
    PROMPT;

        try {
            $response = Http::withOptions([
                'verify' => 'C:\Program Files\php8.3\extras\ssl\cacert.pem', // Ruta a tu cacert.pem
            ])->withHeaders([
                'Authorization' => "Bearer $apiKey",
                'Content-Type' => 'application/json',
            ])->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
            ]);

            if ($response->successful()) {
                return trim($response['choices'][0]['message']['content']);
            }

            throw new \Exception('Respuesta inválida de Groq: ' . $response->body());
        } catch (\Exception $e) {
            // Puedes guardar el error en logs o enviarlo como parte de la respuesta
            Log::error('Error con IA: ' . $e->getMessage());
            throw new \Exception('Error al comunicarse con el servicio de etiquetas IA.');
        }
    }

}