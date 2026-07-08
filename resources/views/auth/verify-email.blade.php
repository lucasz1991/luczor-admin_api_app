<x-guest-layout>
    <h1 class="text-xl font-semibold text-white">E-Mail bestaetigen</h1>
    <p class="mt-1 text-sm text-slate-400">Bitte bestaetige deine E-Mail-Adresse, bevor du das Luczor Admin API Dashboard nutzt.</p>

    @if (session('status') === 'verification-link-sent')
        <div class="mt-4 rounded-md border border-emerald-400/30 bg-emerald-400/10 p-3 text-sm text-emerald-100">
            Ein neuer Verifizierungslink wurde gesendet.
        </div>
    @endif

    <div class="mt-6 flex gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button class="luczor-btn" type="submit">Link erneut senden</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="luczor-btn-secondary" type="submit">Logout</button>
        </form>
    </div>
</x-guest-layout>
