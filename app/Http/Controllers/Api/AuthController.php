<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const SUPPORTED_WALLETS = ['NGN', 'BTC', 'USDT'];

    public function register(Request $request, AuditLogger $auditLogger)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Every user gets a zero-balance wallet per supported currency up front,
        // so the rest of the app never has to lazily create-or-404 a wallet mid-request.
        foreach (self::SUPPORTED_WALLETS as $currency) {
            Wallet::create([
                'user_id' => $user->id,
                'currency' => $currency,
                'kind' => 'user',
                'balance' => 0,
            ]);
        }

        $auditLogger->log($user->id, 'auth.register', 'User', $user->id, [], $request);

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('pulsepay-mobile')->plainTextToken,
        ], 201);
    }

    public function login(Request $request, AuditLogger $auditLogger)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $auditLogger->log($user->id, 'auth.login', 'User', $user->id, [], $request);

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('pulsepay-mobile')->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return $request->user();
    }
}
