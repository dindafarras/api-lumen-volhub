<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Skill;
use App\Models\Kegiatan;
use App\Models\Pendaftar;
use App\Models\Experience;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Date;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Redis;

use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;


class UserApiController extends Controller
{
    //Login User
    public function loginUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $credentials = $request->only('username', 'password');

        try {
            if (!$token = auth('user')->attempt($credentials)) {
                return response()->json(['message' => 'Login gagal, username atau password salah'], 401);
            }
        } catch (JWTException $e) {
            return response()->json([
                'message' => 'Gagal membuat token',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
        ], 200);
    }

    // Regitrasi User
    public function registrasiUser(Request $request) 
    {
        try {   
            // Validasi input
            $validator = Validator::make($request->all(), [
                'nama_user' => 'required|max:50',
                'username' => 'required|max:50',
                'email_user' => 'required|email',
                'password' => [
                    'required',
                    'min:8',
                    'max:255',
                    'regex:/[A-Z]/',    
                    'regex:/[0-9]/',    
                    'regex:/[\W_]/',    
                ],
                'nomor_telephone' => [
                    'required',
                    'regex:/^[0-9]{10,15}$/',
                ]
            ], [
                'nama_user.required' => 'Nama User wajib diisi.',
                'nama_user.max' => 'Nama User tidak boleh lebih dari 50 karakter.',
                'username.required' => 'Username wajib diisi.',
                'username.max' => 'Username tidak boleh lebih dari 50 karakter.',
                'email_user.required' => 'Email user wajib diisi.',
                'email_user.email' => 'Format email tidak valid.',
                'password.required' => 'Password wajib diisi',
                'password.min' => 'Password harus memiliki minimal 8 karakter.',
                'password.max' => 'Password tidak boleh lebih dari 255 karakter.',
                'password.regex' => 'Password harus mengandung setidaknya satu huruf kapital, satu angka, dan satu simbol.',
                'nomor_telephone.required' => 'Nomor telephone wajib diisi.',
                'nomor_telephone.regex' => 'Nomor telephone harus berupa angka dan memiliki panjang 10-15 digit.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $registrasi = new User;
            $registrasi->nama_user = $request->nama_user;
            $registrasi->username = $request->username;
            $registrasi->email_user = $request->email_user;
            $registrasi->password = Hash::make($request->password);
            $registrasi->nomor_telephone = $request->nomor_telephone;

            $existing_username = User::where('username', $request->username)->first();
            if ($existing_username) {
                // Jika username sudah digunakan, return dengan pesan error
                return response()->json([
                    'message' => 'Username sudah digunakan',
                    'status' => 'error'
                ], 400); // Menggunakan status kode 400 untuk menandakan adanya kesalahan validasi
            }

            $registrasi-> save();

            Redis::del('user:all');

            return response()->json([
                'message' => 'Registrasi Berhasil',
                'data'=>$registrasi
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal melakukan registrasi',
                'error'=>$e->getMessage() 
            ], 500);
        }
    }

    // Kelola profile user
    public function profile() 
    {
        $userId = auth('user')->id();

        $key = "user:profile:{$userId}";
        $userData = Redis::get($key);

        if (!$userData){
            $user = User::with('experiences', 'skills', 'pendaftars')->find($userId);
            if (!$user) {
                return response()->json([
                    'message' => '"Nope, we couldn’t find that ID. It’s either gone or never existed 🙄"'
                ], 404);
            }

            Redis::setex($key, 3600, json_encode($user));

            return response()->json([
                'success' => true,
                'message' => 'Data user berhasil diambil dari database',
                'data' => $user
            ]);
        } else {
            $user = json_decode($userData, true);
            return response()->json([
                'message' => 'Data User masih ada di Redis',
                'data' => $user
            ], 200);
        }
    }

    public function editProfile(Request $request, $userId)
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nope, we couldn’t find that ID. It’s either gone or never existed 🙄',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'username' => 'nullable|max:50',
                'email_user' => 'nullable|email',
                'nama_user' => 'nullable|max:50',
                'nomor_telephone' => 'nullable|regex:/^[0-9]{10,15}$/',
                'pendidikan_terakhir' => 'nullable|in:SD,SMP,SMA/SMK,Diploma (D1 - D4),Sarjana (S1),Magister (S2),Doktor (S3)',
                'gender' => 'nullable|in:Laki-laki,Perempuan',
                'domisili' => 'nullable|string|max:255',
                'deskripsi' => 'nullable|string',
                'bio' => 'nullable|string',
                'usia' => 'nullable|integer|min:1|max:120',
                'instagram' => 'nullable|url',
                'linkedIn' => 'nullable|url',
                'password' => 'nullable|min:8|max:255|regex:/[A-Z]/|regex:/[0-9]/|regex:/[\W_]/',
                'cv' => 'nullable|file|mimes:pdf|max:2048',
                'foto_profile' => 'nullable|image|mimes:jpg,jpeg,png|max:2048'
            ], [
                'username.max' => 'Username tidak boleh lebih dari 50 karakter.',
                'email_user.email' => 'Format email tidak valid.',
                'nama_user.max' => 'Nama user tidak boleh lebih dari 50 karakter',
                'nomor_telephone.required' => 'Nomor telephone wajib diisi.',
                'nomor_telephone.regex' => 'Nomor telephone harus berupa angka dan memiliki panjang 10-15 digit.',
                'pendidikan_terakhir.in' => 'Pendidikan terakhir tidak valid.',
                'gender.in' => 'Gender tidak valid.',
                'usia.integer' => 'Usia harus berupa angka.',
                'usia.min' => 'Usia tidak boleh kurang dari 1 tahun.',
                'usia.max' => 'Usia tidak boleh lebih dari 120 tahun.',
                'instagram.url' => 'URL Instagram tidak valid.',
                'linkedIn.url' => 'URL LinkedIn tidak valid.',
                'password.min' => 'Password harus memiliki minimal 8 karakter.',
                'password.max' => 'Password tidak boleh lebih dari 255 karakter.',
                'password.regex' => 'Password harus mengandung setidaknya satu huruf kapital, satu angka, dan satu simbol.',
                'cv.file' => 'CV harus berupa file.',
                'cv.mimes' => 'CV harus berupa file PDF.',
                'cv.max' => 'Ukuran CV tidak boleh lebih dari 2MB.',
                'foto_profile.image' => 'Foto profil harus berupa gambar.',
                'foto_profile.mimes' => 'Foto profil harus berupa file JPG, JPEG, atau PNG.',
                'foto_profile.max' => 'Ukuran foto profil tidak boleh lebih dari 2MB.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 400);
            }

            $user->username = $request->username ?? $user->username;

            $existing_username = User::where('username', $request->username)->first();
            if ($existing_username) {
                // Jika username sudah digunakan, return dengan pesan error
                return response()->json([
                    'message' => 'Username sudah digunakan',
                    'status' => 'error'
                ], 400); // Menggunakan status kode 400 untuk menandakan adanya kesalahan validasi
            }
            
            $user->email_user = $request->email_user ?? $user->email_user;
            $user->nama_user = $request->nama_user ?? $user->nama_user;
            $user->nomor_telephone = $request->nomor_telephone ?? $user->nomor_telephone;
            $user->pendidikan_terakhir = $request->pendidikan_terakhir ?? $user->pendidikan_terakhir;
            $user->gender = $request->gender ?? $user->gender;
            $user->domisili = $request->domisili ?? $user->domisili;
            $user->deskripsi = $request->deskripsi ?? $user->deskripsi;
            $user->bio = $request->bio ?? $user->bio;
            $user->usia = $request->usia ?? $user->usia;
            $user->instagram = $request->instagram ?? $user->instagram;
            $user->linkedIn = $request->linkedIn ?? $user->linkedIn;
            
            
            // Hash password jika diubah
            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            // Upload CV
            if ($request->hasFile('cv')) {
                $this->handleFileUpload($request->file('cv'), 'cv', $user, 'cv');
            }

            // Upload foto profile
            if ($request->hasFile('foto_profile')) {
                $this->handleFileUpload($request->file('foto_profile'), 'foto-profile', $user, 'foto_profile');
            }

            // Simpan perubahan pada user
            $user->save();

            Redis::del("user:all", "user:profile:{$userId}");

            return response()->json([
                'message' => 'Berhasil edit User',
                'data'=>$user
            ], 200);
        } catch (\Exception $e){
            return response()->json([
                'message' => 'Gagal edit User',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Kelola pendaftaran
    public function applyActivity(Request $request, $userId, $idActivity) 
    {
        try {
            $user = User::find($userId);
            $activity = Kegiatan::find($idActivity);

            // Validasi apakah user dan kegiatan ditemukan
            if (!$user) {
                return response()->json([
                    'message' => 'User tidak ditemukan.',
                ], 404);
            }

            if (!$activity) {
                return response()->json([
                    'message' => 'Kegiatan tidak ditemukan.',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'motivasi' => 'required|max:255',
            ], [
                'motivasi.required' => 'Motivasi wajib diisi.',
                'motivasi.max' => 'Motivasi tidak boleh lebih dari 255 karakter'              
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 400);
            }

            $apply = new Pendaftar;
            $apply->motivasi = $request->motivasi;
            $apply->status_applicant = 'In-review';
            $apply->id_user = $user->id;
            $apply->id_kegiatan = $activity->id_kegiatan;

            // Jika user sudah melakukan pendaftaran pada kegiatan yang sama
            $existingApplication = Pendaftar::where('id_user', $user->id)
                                    ->where('id_kegiatan', $activity->id_kegiatan)
                                    ->first();

            if ($existingApplication) {
                return response()->json([
                    'message' => 'Anda sudah melakukan pendaftaran pada kegiatan ini'
                ], 400);
            }

            // Jika ada file CV yang diunggah
            if ($request->hasFile('cv')) {
                $this->handleFileUpload($request->file('cv'), 'cv', $user, 'cv');
                $user->save(); // Simpan perubahan ke user
            } else if (!$user->cv) {
                // Jika user belum memiliki CV sebelumnya
                return response()->json([
                    'message' => 'Anda belum memiliki CV. Silakan unggah CV baru.',
                ], 400);
            }

            $apply->save();

            Redis::del("mitra:pendaftar");// MASIH BINGUNG YANG INI

            return response()->json([
                'message' => 'Pendaftaran behasil dilakukan',
                'data'=>$apply,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal melakukan pendaftaran',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Kelola Skill
    public function addSkill(Request $request, $userId) 
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return response()->json(['message' => 'User tidak ditemukan'], 404);
            }

            $validator = Validator::make($request->all(), [
                'nama_skill' => 'max:30',
            ], [
                'nama_skill.max' => 'Skill tidak boleh lebih dari 30 karakter'              
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 400);
            }
            
            $skill = Skill::firstOrCreate(['nama_skill' => $request->input('nama_skill')]);
            $user->skills()->attach($skill->id_skill);

            Redis::del("user:profile:{$userId}");

            return response()->json([
                'message' => 'Berhasil menambahkan skill',
                'data' => $skill
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menambahkan skill',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteSkill($userId, $idSkill) 
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                return response()->json(['message' => 'User tidak ditemukan'], 404);
            }

            $skill = $user->skills()->find($idSkill);
            if (!$skill) {
                return response()->json(['message' => 'Skill tidak ditemukan untuk user ini'], 404);
            }

            // Hapus hubungan skill dengan user
            $user->skills()->detach($skill->id_skill);

            // Periksa apakah skill masih digunakan oleh user lain
            $otherUsers = $skill->users()->count();

            if ($otherUsers > 0) {
                return response()->json([
                    'message' => 'Skill dihapus dari user ini'
                ], 200);
            }

            $skill->delete();
            
            Redis::del("user:profile:{$userId}");

            return response()->json([
                'message' => 'Skill berhasil dihapus'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menghapus skill',
                'error' => $e->getMessage()
            ], 500);
        } 
    }

    // Kelola Experience
    public function addExperience(Request $request, $userId) 
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return response()->json(['message' => 'User tidak ditemukan'], 404);
            }

            $validator = Validator::make($request->all(), [
                'judul_kegiatan' => 'required|max:30',
                'lokasi_kegiatan' => 'required|max:30',
                'tgl_mulai' => 'required|date',
                'tgl_selesai' => 'required|date',
                'deskripsi' => 'required|max:255',
                'mitra' => 'required|max:50',
            ], [
                'judul_kegiatan.required' => 'Nama kegiatan wajib diisi',
                'judul_kegiatan.max' => 'Nama kegiatan tidak boleh lebih dari 30 karakter',
                'lokasi_kegiatan.required' => 'Lokasi kegiatan wajib diisi',
                'lokasi_kegiatan.max' => 'Lokasi kegiatan tidak boleh lebih dari 30 karakter',
                'tgl_mulai.required' => 'Tanggal mulai kegiatan wajib diisi',
                'tgl_mulai.max' => 'Tanggal mulai kegiatan diisi dengan format YYYY-MM-DD',
                'tgl_selesai.required' => 'Tanggal selesai kegiatan wajib diisi',
                'tgl_selesai.max' => 'Tanggal selesai kegiatan diisi dengan format YYYY-MM-DD',
                'deskripsi.required' => 'Deskripsi kegiatan wajib diisi',
                'deskripsi.max' => 'Deskripsi kegiatan tidak boleh lebih dari 255 karakter',
                'mitra.required' => 'Mitra kegiatan wajib diisi',
                'mitra.max' => 'Mitra kegiatan tidak boleh lebih dari 50 karakter',             
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 400);
            }

            $experience = new Experience;
            $experience->id_user= $user->id;
            $experience->judul_kegiatan= $request->judul_kegiatan;
            $experience->lokasi_kegiatan = $request->lokasi_kegiatan;
            $experience->tgl_mulai = $request->tgl_mulai;
            $experience->tgl_selesai = $request->tgl_selesai;
            $experience->deskripsi = $request->deskripsi;
            $experience->mitra = $request->mitra;

            $experience->save();

            Redis::del("user:profile:{$userId}");

            return response()->json([
                'message' => 'Experience berhasil ditambahkan',
                'data'=>$experience
            ], 200);

        } catch (\Exception $e) {
            return response()->json ([
                'message' => 'Gagal menambahkan experience baru',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteExperience($userId, $idExperience) 
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return response()->json(['message' => 'User tidak ditemukan'], 404);
            }

            $experience = $user->experiences()->find($idExperience);

            if (!$experience) {
                return response()->json([
                    'message' => 'Experience tidak ditemukan untuk user ini'
                ], 404);
            }

            $experience->delete();

            Redis::del("user:profile:{$userId}");

            return response()->json([
                'message' => 'Experience berhasil dihapus'
            ], 200);
        } catch (\Exception $e) {
            return response()->json ([
                'message' => 'Gagal menghapus experience',
                'error' => $e->getMessage()
            ], 500);

        }
    }

    // INI GUNANYA UNTUK HAPUS FILE LAMA KALAU TERJADI PERUBAHAN
    private function handleFileUpload($file, $directory, $user, $attribute)
    {
        // Hapus file lama jika ada
        if ($user->$attribute) {
            $oldFilePath = storage_path("app/public/{$directory}/{$user->$attribute}");
            if (File::exists($oldFilePath)) {
                File::delete($oldFilePath);
            }
        }

        // Upload file baru
        $fileName = Date::now()->timestamp . '-' . $file->getClientOriginalName();
        $file->storeAs($directory, $fileName, 'public');

        // Simpan nama file baru ke user
        $user->$attribute = $fileName;
    }
}