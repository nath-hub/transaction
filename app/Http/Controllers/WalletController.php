<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletController extends Controller
{
    public function index($entrepriseId)
    {
        return Wallet::where('entreprise_id', $entrepriseId)->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'entreprise_id' => 'required|uuid|exists:entreprises,id', 
            'solde' => 'nullable|numeric|min:0',
            // 'status' => 'in:actif,inactif,suspendu',
            'devise' => 'required|string|max:10',
        ]);

        // $user = $request->user();

        // if($user->role === "admin_entreprise" || )

        $wallet = Wallet::create([
            'id' => Str::uuid(),
            ...$data
        ]);

        return response()->json($wallet, 201);
    }

    public function show($id)
    {
        $wallet = Wallet::findOrFail($id);
        return response()->json($wallet);
    }

    public function update(Request $request, $id)
    {
        $wallet = Wallet::findOrFail($id);

        $data = $request->validate([
            'solde' => 'nullable|numeric|min:0',
            'status' => 'in:actif,inactif,suspendu',
            'devise' => 'string|max:10',
        ]);

        $wallet->update($data);
        return response()->json($wallet);
    }

    public function destroy($id)
    {
        $wallet = Wallet::findOrFail($id);
        $wallet->delete();

        return response()->json(['message' => 'Wallet supprimé']);
    }

}
