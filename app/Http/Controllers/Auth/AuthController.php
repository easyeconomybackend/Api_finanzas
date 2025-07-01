<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Handle user login
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request) : JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'phone' => 'required|string|max:15',
            'password' => 'required|string|min:8',
        ]);

        if ($validated->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de inicio de sesión inválidos',
                'errors' => $validated->errors(),
            ], 422);
        }

        try {
            $user = User::where('phone', $request->input('phone'))->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Credenciales no válidas',
                ], 404);
            }

            if (!password_verify($request->input('password'), $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contraseña incorrecta',
                ], 401);
            }

            $token = $user->createToken('auth_token')->plainTextToken;
            return response()->json([
                'status' => 'success',
                'message' => 'Inicio de sesión exitoso',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al iniciar sesión',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
