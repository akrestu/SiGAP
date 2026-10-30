{{-- Profil — mockup: ProfileMobile, ProfileDesktop --}}
@php
    $user = auth()->user();
    $statusText = [
        'profile-updated' => 'Data diri disimpan',
        'password-updated' => 'Kata sandi diperbarui',
        'profile-picture-updated' => 'Foto profil diperbarui',
        'availability-updated' => 'Status cuti disimpan',
        'availability-cleared' => 'Status cuti dihapus, Anda kembali tersedia',
    ][session('status')] ?? null;
    $isTicketHandler = \App\Policies\TicketPolicy::isHandler($user);
    $notifTypes = [
        'job_approved' => ['Laporan disetujui', 'bi-check-circle'],
        'job_rejected' => ['Laporan ditolak', 'bi-x-circle'],
        'pending_approval' => ['Menunggu persetujuan saya', 'bi-hourglass-split'],
        'new_comment' => ['Komentar baru', 'bi-chat-left-text'],
        'email_notifications' => ['Kirim juga lewat email', 'bi-envelope'],
    ];
@endphp

<x-app-layout title="Profil">
    <div class="mx-auto" style="max-width:880px">
        {{-- Kartu identitas + foto --}}
        <section class="card p-3 p-lg-4 mb-3" aria-labelledby="me-name">
            <div class="d-flex align-items-center gap-3">
                <div class="position-relative flex-none">
                    <x-sg.avatar :user="$user" :size="80" />
                </div>
                <div class="min-w-0">
                    <h1 id="me-name" class="h4 fw-bold mb-1">{{ $user->name }}</h1>
                    <div class="d-flex flex-wrap align-items-center gap-2 small text-muted">
                        <x-sg.badge-level :user="$user" />{{ $user->department?->name }}{{ $user->jobSite ? ' · '.$user->jobSite->name : '' }}
                    </div>
                    <div class="small text-muted mt-1">User ID: <span class="font-monospace">{{ $user->user_id }}</span></div>
                </div>
            </div>
            <form action="{{ route('profile.update-picture') }}" method="POST" enctype="multipart/form-data" class="mt-3" x-data="{ busy: false }"
                  x-ref="pic" @change="busy = true; $refs.pic.submit()">
                @csrf
                <input type="file" name="profile_picture" accept="image/*" capture="user" class="d-none" x-ref="camera">
                <input type="file" name="profile_picture" accept="image/*" class="d-none" x-ref="gallery">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-fill" @click="$refs.gallery.disabled = true; $refs.camera.disabled = false; $refs.camera.click()" :disabled="busy">
                        <i class="bi bi-camera" aria-hidden="true"></i> Ambil Foto
                    </button>
                    <button type="button" class="btn btn-outline-secondary flex-fill" @click="$refs.camera.disabled = true; $refs.gallery.disabled = false; $refs.gallery.click()" :disabled="busy">
                        <i class="bi bi-images" aria-hidden="true"></i> Pilih dari Galeri
                    </button>
                </div>
                <div class="small mt-1" :class="busy ? '' : 'text-muted'" x-text="busy ? 'Mengunggah…' : 'JPG atau PNG, maks. 2 MB.'"></div>
                @error('profile_picture')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </form>
        </section>

        <div class="row g-3">
            {{-- Data diri --}}
            <div class="col-lg-6">
                <section class="card p-3 p-lg-4 h-100" aria-labelledby="info-title">
                    <h2 id="info-title" class="h6 fw-bold mb-3">Data diri</h2>
                    <form action="{{ route('profile.update') }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <label for="name" class="form-label fw-semibold">Nama lengkap</label>
                        <input id="name" name="name" class="form-control mb-1 @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required autocomplete="name">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <label for="email" class="form-label fw-semibold mt-2">Email</label>
                        <input id="email" type="email" name="email" class="form-control mb-1 @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required autocomplete="email">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-primary w-100 mt-3">Simpan data diri</button>
                    </form>
                </section>
            </div>

            {{-- Kata sandi --}}
            <div class="col-lg-6">
                <section class="card p-3 p-lg-4 h-100" aria-labelledby="pw-title" x-data="{ show: false }">
                    <h2 id="pw-title" class="h6 fw-bold mb-3">Kata sandi</h2>
                    <form action="{{ route('password.update') }}" method="POST">
                        @csrf
                        @method('PUT')
                        @foreach (['current_password' => ['Kata sandi saat ini', 'current-password'], 'password' => ['Kata sandi baru', 'new-password'], 'password_confirmation' => ['Ulangi kata sandi baru', 'new-password']] as $field => [$label, $ac])
                            <label for="{{ $field }}" class="form-label fw-semibold {{ $loop->first ? '' : 'mt-2' }}">{{ $label }}</label>
                            <input id="{{ $field }}" name="{{ $field }}" :type="show ? 'text' : 'password'" type="password" autocomplete="{{ $ac }}"
                                   class="form-control mb-1 {{ $errors->updatePassword->has($field) ? 'is-invalid' : '' }}">
                            @if ($errors->updatePassword->has($field))<div class="invalid-feedback">{{ $errors->updatePassword->first($field) }}</div>@endif
                        @endforeach
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="pw-show" x-model="show">
                            <label class="form-check-label" for="pw-show">Tampilkan kata sandi</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mt-3">Perbarui kata sandi</button>
                    </form>
                </section>
            </div>

            {{-- Tampilan --}}
            <div class="col-lg-6">
                <section class="card p-3 p-lg-4 h-100" aria-labelledby="theme-title" x-data>
                    <h2 id="theme-title" class="h6 fw-bold mb-1">Tampilan</h2>
                    <p class="small text-muted">Disimpan di perangkat ini.</p>
                    <div class="sg-segment" role="radiogroup" aria-label="Tema">
                        @foreach (['light' => ['Terang', 'bi-sun'], 'dark' => ['Gelap', 'bi-moon'], 'system' => ['Sistem', 'bi-circle-half']] as $mode => [$label, $icon])
                            <input type="radio" class="btn-check" name="theme" id="theme-{{ $mode }}" value="{{ $mode }}"
                                   :checked="$store.theme.mode === '{{ $mode }}'" @change="$store.theme.set('{{ $mode }}')">
                            <label class="sg-segment__item" for="theme-{{ $mode }}"><i class="bi {{ $icon }}" aria-hidden="true"></i>{{ $label }}</label>
                        @endforeach
                    </div>
                </section>
            </div>

            {{-- Preferensi notifikasi (disimpan otomatis) --}}
            <div class="col-lg-6">
                <section class="card p-3 p-lg-4 h-100" aria-labelledby="notif-title"
                         x-data="{
                            prefs: {}, loaded: false, saving: false,
                            async load() {
                                try { const r = await fetch('/notifications/preferences', { headers: { Accept: 'application/json' } }); this.prefs = (await r.json()).preferences || {}; }
                                finally { this.loaded = true; }
                            },
                            async save() {
                                this.saving = true;
                                try {
                                    const r = await fetch('/notifications/preferences', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(this.prefs) });
                                    const d = await r.json();
                                    sgToast(d.success ? 'Preferensi notifikasi disimpan' : (d.message || 'Gagal menyimpan'), { tone: d.success ? 'success' : 'danger' });
                                } catch (e) { sgToast('Gagal menyimpan preferensi', { tone: 'danger' }); }
                                finally { this.saving = false; }
                            }
                         }" x-init="load()">
                    <h2 id="notif-title" class="h6 fw-bold mb-1">Notifikasi</h2>
                    <p class="small text-muted">Perubahan disimpan otomatis.</p>
                    <template x-if="!loaded"><x-sg.skeleton :count="1" /></template>
                    <div x-show="loaded" x-cloak>
                        @foreach ($notifTypes as $key => [$label, $icon])
                            <div class="form-check form-switch sg-switch-row">
                                <label class="form-check-label" for="pref-{{ $key }}"><i class="bi {{ $icon }} me-2" aria-hidden="true"></i>{{ $label }}</label>
                                <input class="form-check-input" type="checkbox" role="switch" id="pref-{{ $key }}" x-model="prefs.{{ $key }}" @change="save()" :disabled="saving">
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
        </div>

        {{-- Status cuti tim IT: tiket aktif bisa diambil alih rekan site, notifikasi antrian dilewati --}}
        @if ($isTicketHandler)
            <section class="card p-3 p-lg-4 mt-3" aria-labelledby="away-title">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <h2 id="away-title" class="h6 fw-bold mb-0">Status cuti</h2>
                    @if ($user->isAway())
                        <span class="sg-badge sg-badge--pending">Cuti s.d. {{ \App\Support\Format::date($user->away_until) }}</span>
                    @elseif ($user->away_until && $user->away_until->gte(today()))
                        <span class="sg-badge sg-badge--open">Terjadwal {{ \App\Support\Format::date($user->away_from) }}</span>
                    @else
                        <span class="sg-badge sg-badge--approved">Tersedia</span>
                    @endif
                </div>
                <p class="small text-muted">Selama cuti, tiket yang Anda tangani bisa diambil alih rekan tim IT di site Anda, dan Anda tidak menerima notifikasi antrian tiket.</p>
                <form action="{{ route('profile.availability') }}" method="POST">
                    @csrf
                    @method('PATCH')
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label for="away_from" class="form-label fw-semibold">Mulai</label>
                            <input id="away_from" type="date" name="away_from" class="form-control @error('away_from') is-invalid @enderror"
                                   value="{{ old('away_from', optional($user->away_from)->format('Y-m-d')) }}">
                            <div class="form-text">Kosongkan = mulai hari ini.</div>
                            @error('away_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label for="away_until" class="form-label fw-semibold">Sampai <span class="text-danger">*</span></label>
                            <input id="away_until" type="date" name="away_until" min="{{ today()->format('Y-m-d') }}" required
                                   class="form-control @error('away_until') is-invalid @enderror"
                                   value="{{ old('away_until', optional($user->away_until)->format('Y-m-d')) }}">
                            @error('away_until')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="away_note" class="form-label fw-semibold">Keterangan</label>
                            <input id="away_note" name="away_note" maxlength="255" class="form-control @error('away_note') is-invalid @enderror"
                                   value="{{ old('away_note', $user->away_note) }}" placeholder="Mis. cuti tahunan, dinas luar">
                            @error('away_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="d-flex flex-column flex-sm-row gap-2 mt-3">
                        <button type="submit" class="btn btn-primary flex-fill">Simpan status cuti</button>
                        @if ($user->away_until)
                            <button type="submit" name="clear" value="1" formnovalidate class="btn btn-outline-secondary flex-fill">Saya sudah kembali</button>
                        @endif
                    </div>
                </form>
            </section>
        @endif

        {{-- Akun --}}
        <section class="card p-3 p-lg-4 mt-3" aria-labelledby="acc-title">
            <h2 id="acc-title" class="h6 fw-bold mb-3">Akun</h2>
            <div class="d-flex flex-column flex-sm-row gap-2">
                <form method="POST" action="{{ route('logout') }}" class="flex-fill d-grid">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Keluar</button>
                </form>
                <button type="button" class="btn btn-reject-outline flex-fill" data-bs-toggle="modal" data-bs-target="#delete-account">
                    <i class="bi bi-trash3" aria-hidden="true"></i> Hapus akun
                </button>
            </div>
        </section>
    </div>

    <x-sg.sheet id="delete-account" title="Hapus akun?">
        <form method="POST" action="{{ route('profile.destroy') }}">
            @csrf
            @method('DELETE')
            <p>Akun dan data Anda akan dihapus permanen. Masukkan kata sandi untuk konfirmasi.</p>
            <label for="del-password" class="form-label fw-semibold">Kata sandi</label>
            <input id="del-password" type="password" name="password" autocomplete="current-password"
                   class="form-control {{ $errors->userDeletion->has('password') ? 'is-invalid' : '' }}" required>
            @if ($errors->userDeletion->has('password'))<div class="invalid-feedback">{{ $errors->userDeletion->first('password') }}</div>@endif
            <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-reject flex-fill">Hapus akun</button>
            </div>
        </form>
    </x-sg.sheet>

    @push('scripts')
        @if ($statusText)<script>window.__sgFlash = (window.__sgFlash || []).concat([['success', @js($statusText)]]);</script>@endif
        @if ($errors->userDeletion->isNotEmpty())
            <script>bootstrap.Modal.getOrCreateInstance(document.getElementById('delete-account')).show();</script>
        @endif
    @endpush
</x-app-layout>
