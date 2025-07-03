<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Movement;
use Illuminate\Support\Facades\Auth;
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
}
