<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use Symfony\Component\HttpFoundation\Response;

class VerifyJwtToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'Authorization token missing or invalid format'], 401);
        }

        $jwt = trim(str_replace('Bearer ', '', $authHeader));

        try {
            // Decode JWT using RS256 and public key
            $publicKey = env('JWT_PUBLIC_KEY');
            $decoded = JWT::decode($jwt, new Key($publicKey, 'RS256'));

            // Optionally: Check for expiration manually if needed
            if (isset($decoded->exp) && $decoded->exp < time()) {
                return response()->json(['error' => 'Token has expired'], 401);
            }

            // Pass decoded JWT payload to request
            $request->attributes->set('jwt_payload', $decoded);

            return $next($request);
        } catch (ExpiredException $e) {
            return response()->json(['error' => 'Token expired'], 401);
        } catch (SignatureInvalidException $e) {
            return response()->json(['error' => 'Invalid signature'], 401);
        } catch (BeforeValidException $e) {
            return response()->json(['error' => 'Token not yet valid'], 401);
        } catch (\Exception $e) {
            return response()->json(['error' => 'JWT Error: ' . $e->getMessage()], 401);
        }
    }
}
