<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Policies\TicketPolicy;
use App\Services\TicketWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Status cuti tim IT (diisi sendiri). Selama cuti: tiket aktifnya bisa diambil alih rekan site,
     * dan tidak menerima notifikasi antrian / eskalasi.
     */
    public function updateAvailability(Request $request, TicketWorkflow $workflow): RedirectResponse
    {
        $user = $request->user();
        abort_unless(TicketPolicy::isHandler($user), 403);

        if ($request->boolean('clear')) {
            $user->update(['away_from' => null, 'away_until' => null, 'away_note' => null]);

            return Redirect::route('profile.edit')->with('status', 'availability-cleared');
        }

        $validated = $request->validate([
            'away_from' => ['nullable', 'date'],
            'away_until' => array_filter(['required', 'date', 'after_or_equal:today', $request->filled('away_from') ? 'after_or_equal:away_from' : null]),
            'away_note' => ['nullable', 'string', 'max:255'],
        ], [
            'away_until.required' => 'Tanggal kembali wajib diisi.',
            'away_until.after_or_equal' => 'Tanggal kembali tidak boleh sebelum hari ini atau sebelum tanggal mulai.',
        ]);

        $wasAway = $user->isAway();
        $user->update($validated + ['away_from' => null, 'away_note' => null]);

        if (! $wasAway && $user->isAway()) {
            $workflow->announceHandlerAway($user);
        }

        return Redirect::route('profile.edit')->with('status', 'availability-updated');
    }

    /**
     * Update the user's profile picture.
     */
    public function updateProfilePicture(Request $request): RedirectResponse
    {
        $request->validate([
            'profile_picture' => ['required', 'image', 'max:2048'] // Max 2MB
        ]);

        $user = $request->user();

        if ($request->hasFile('profile_picture')) {
            // Delete old profile picture if exists
            if ($user->profile_picture) {
                Storage::disk('public')->delete($user->profile_picture);
            }

            // Store new profile picture
            $path = $request->file('profile_picture')->store('profile-pictures', 'public');
            $user->profile_picture = $path;
            $user->save();
        }

        return Redirect::route('profile.edit')->with('status', 'profile-picture-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        if ($user->profile_picture) {
            Storage::disk('public')->delete($user->profile_picture);
        }

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
