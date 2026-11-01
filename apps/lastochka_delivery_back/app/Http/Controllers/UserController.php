<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
  protected function checkCredentials($token): bool
  {
    return $token == config('services.system.token');
  }

  public function delete(Request $request)
  {
    if (!$this->checkCredentials($request->token)) {
      return response()->json(null, 500);
    }

    if (!$request->id) {
      return response()->json(null, 500);
    }

    $user = User::find($request->id);

    $user->detachOrders();

    return response()->json(['message' => 'OK']);
  }

}
