<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Mail\ForgetPassword;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class APIController extends Controller
{
    public function sendError($error, $errorMessages = [], $code = 404): \Illuminate\Http\JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['data'] = $errorMessages;
        }
        return response()->json($response, $code);
    }

    public function sendResponse($result, $message): \Illuminate\Http\JsonResponse
    {
        $response = [
            'success' => true,
            'data'    => $result,
            'message' => $message,
        ];
//        dd($response);
        return response()->json($response, 200);
    }

    // custom login
    public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:250',
            'password' => 'required|max:250',
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(400, $validator->errors()->first());
        }



        $credentials = $request->only('email', 'password');
        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            $isAllowed = Auth::user()->hasPermissionTo(ModulePermissions::VIEW_DASHBOARD);
            $token = $user->createToken('authToken')->plainTextToken;
            $roles = $user->getRoleNames();
            $permissions = $user->getAllPermissions()->pluck("name");


            return $this->sendResponse([$user,$token,$isAllowed,$roles,$permissions], "success");
        }
        return $this->sendResponse(400, "Credentials not match with our records");
    }

    public function logout(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        // Revoke all of the user's tokens (logging them out)
        $user->tokens()->delete();
        $request->session()->invalidate();

        return $this->sendResponse(null,'Successfully logged out');
    }

    // reset password
    public function reset_pass(Request $request) {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return $this->sendResponse(400, $validator->errors()->first());
        }

        try {
            $updatePassword = DB::table('password_resets')->where('token', $request->token)->first();

            if(!$updatePassword){
                return $this->sendResponse(400, 'Invalid token.');
            }

            // update users password
            User::where('email', $updatePassword->email)->update(['password' => Hash::make($request->password)]);

            // delete old data from database
            DB::table('password_resets')->where(['email'=> $updatePassword->email])->delete();

            return $this->sendResponse(null, "success");
        } catch (\Throwable $th) {
            return $this->sendResponse(400, "Something went wrong, please contact support.");
        }
    }

    public function passwordRecovery(Request $request)
    {
        $request->validate([
            "email" => "required"
        ]);

        $user = User::where("email", $request->email)->first();
        if($user) {
            $token_str = Str::random(64);
            $url = env('APP_URL');
            //$token = $url."reset-password/".$token_str;

            Log::info($token_str);

            DB::table('password_resets')->insert([
                'email' => $request->email,
                'token' => $token_str,
                'created_at' => Carbon::now()
            ]);

            Mail::to($user->email)->send(new ForgetPassword($user->name,$token_str));
        }


    }
}
