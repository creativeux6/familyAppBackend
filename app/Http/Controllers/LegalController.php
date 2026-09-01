<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Account\Services\AccountDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function privacy(): View
    {
        return view('legal.privacy', [
            'appName' => config('app.name', 'Tijori'),
            'appUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function accountDeletionForm(): View
    {
        return view('legal.account-deletion', [
            'appName' => config('app.name', 'Tijori'),
            'appUrl' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function accountDeletionSubmit(Request $request, AccountDeletionService $deletion): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:255'],
            'confirmation' => ['required', 'string', 'max:64'],
        ]);

        $phone = preg_replace('/\s+/', '', $data['phone']) ?: $data['phone'];
        $user = User::query()->where('phone', $phone)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return back()->withErrors([
                'phone' => ['These credentials do not match our records.'],
            ])->withInput($request->except('password'));
        }

        try {
            $deletion->delete($user, $data['confirmation']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($request->except('password'));
        }

        return redirect()
            ->route('legal.account-deletion')
            ->with('status', 'Your Tijori account and personal data have been deleted.');
    }
}
