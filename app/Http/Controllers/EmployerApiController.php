<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\Admin;
use App\Models\User;
use App\Models\Mitra;
use App\Models\Pendaftar;
use App\Models\Kegiatan;
use App\Models\Kategori;
use App\Models\Benefit;
use App\Models\Kriteria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Date;

use Carbon\Carbon;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Redis;

use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;


class EmployerApiController extends Controller
{
    public function login(Request $request) 
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
            if (!$token = auth('employer')->attempt($credentials)) {
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

    public function registrasi(Request $request) 
    {
        try {
            // Validasi input
            $validator = Validator::make($request->all(), [
                'nama_mitra' => 'required|max:50',
                'username' => 'required|max:50',
                'email_mitra' => 'required|email',
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
                'nama_mitra.required' => 'Nama Perusahaan wajib diisi',
                'nama_mitra.max' => 'Nama Perusahaan tidak boleh lebih dari 50 karakter',
                'username.required' => 'Username wajib diisi',
                'username.max' => 'Username tidak boleh lebih dari 50 karakter',
                'email_mitra.required' => 'Email peruhasaan wajib diisi',
                'email_mitra.email' => 'Format email tidak valid',
                'password.required' => 'Password wajib diisi',
                'password.min' => 'Password harus memiliki minimal 8 karakter.',
                'password.max' => 'Password tidak boleh lebih dari 255 karakter.',
                'password.regex' => 'Password harus mengandung setidaknya satu huruf kapital, satu angka, dan satu simbol.',
                'nomor_telephone.required' => 'Nomor telephone wajib diisi',
                'nomor_telephone.regex' => 'Nomor telephone harus berupa angka dan memiliki panjang 10-15 digit'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $registrasi = new Mitra;
            $registrasi->nama_mitra = $request->nama_mitra;
            $registrasi->username = $request->username;
            $registrasi->email_mitra = $request->email_mitra;
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
            
            Redis::del('mitra:all');

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

    // Kelola Profile
    public function profile() 
    {
        $employerId = auth('employer')->id();
        
        $key = "employer:profile:{$employerId}";
        $employerData = Redis::get($key);

        if (!$employerData) {
            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'message' => '"Nope, we couldn’t find that ID. It’s either gone or never existed 🙄"'
                ], 404);
            }

            Redis::setex($key, 3600, json_encode($employer));

            return response()->json([
                'success' => true,
                'message' => 'Data employer berhasil diambil dari database',
                'data' => $employer
            ]);
        } else {
            $employer = json_decode($employerData, true);
            return response()->json([
                'message' => 'Data employer masih ada di Redis',
                'data' => $employer,
            ], 200);
        }
    }

    public function editProfile(Request $request, $employerId) 
    {
        try {
            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json(['message' => 'Mitra tidak ditemukan'], 404);
            }

            $validator = Validator::make($request->all(), [
                'username' => 'nullable|max:50',
                'password' => 'nullable|min:8|max:255|regex:/[A-Z]/|regex:/[0-9]/|regex:/[\W_]/',
                'email_mitra' => 'nullable|email',
                'nama_mitra' => 'nullable|max:50',
                'bio' => 'nullable|max:50',
                'industri' => 'nullable|max:30',
                'ukuran_perusahaan' => 'nullable|regex:/^[0-9]+$/',
                'situs' => 'nullable',
                'deskripsi' => 'nullable|max:255',
                'alamat' => 'nullable|max:255',
                'nomor_telephone' => 'nullable|regex:/^[0-9]{10,15}$/',
                'gambar' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
                'logo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            ], [
                'username.max' => 'Username tidak boleh lebih dari 50 karakter',
                'password.min' => 'Password harus memiliki minimal 8 karakter.',
                'password.max' => 'Password tidak boleh lebih dari 255 karakter.',
                'password.regex' => 'Password harus mengandung setidaknya satu huruf kapital, satu angka, dan satu simbol.',
                'email_mitra.email' => 'Format email tidak valid',
                'nama_mitra.max' => 'Nama mitra tidak boleh lebih dari 50 karakter',
                'bio.max' => 'Bio tidak boleh lebih dari 50 karakter',
                'industri.max' => 'Industri tidak boleh lebih dari 50 karakter',
                'ukuran_perusahaan.regex' => 'Ukuran perusahaan harus berupa angka',
                'deskripsi.max' => 'Deskripsi tidak boleh lebih dari 255 karakter',
                'alamat.max' => 'Alamat tidak boleh lebih dari 255 karakter',
                'nomor_telephone.regex' => 'Nomor telephone harus berupa angka dan memiliki panjang 10-15 digit',
                'gambar.image' => 'Gambar harus berupa gambar.',
                'gambar.mimes' => 'Gambar harus berupa file JPG, JPEG, atau PNG.',
                'gambar.max' => 'Ukuran gambar tidak boleh lebih dari 2MB.', 
                'logo.image' => 'Logo harus berupa gambar.',
                'logo.mimes' => 'Logo harus berupa file JPG, JPEG, atau PNG.',
                'logo.max' => 'Ukuran logo tidak boleh lebih dari 2MB.'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 400);
            }

            $employer->username = $request->username ?? $employer->username;

            $existing_username = User::where('username', $request->username)->first();
            if ($existing_username) {
                // Jika username sudah digunakan, return dengan pesan error
                return response()->json([
                    'message' => 'Username sudah digunakan',
                    'status' => 'error'
                ], 400); // Menggunakan status kode 400 untuk menandakan adanya kesalahan validasi
            }

            $employer->nama_mitra = $request->nama_mitra ?? $employer->nama_mitra;
            $employer->email_mitra = $request->email_mitra ?? $employer->email_mitra;
            $employer->bio = $request->bio ?? $employer->bio;
            $employer->industri = $request->industri ?? $employer->industri;
            $employer->ukuran_perusahaan = $request->ukuran_perusahaan ?? $employer->ukuran_perusahaan;
            $employer->situs = $request->situs ?? $employer->situs;
            $employer->deskripsi = $request->deskripsi ?? $employer->deskripsi;
            $employer->alamat = $request->alamat ?? $employer->alamat;
            $employer->nomor_telephone = $request->nomor_telephone ?? $employer->nomor_telephone;
            
            // Upload foto profile
            if ($request->hasFile('gambar')) {
                $this->handleFileUpload($request->file('gambar'), 'gambar', $employer, 'gambar');
            }

            // Upload foto profile
            if ($request->hasFile('logo')) {
                $this->handleFileUpload($request->file('logo'), 'logo', $employer, 'logo');
            }

            // Hash password jika diubah
            if ($request->filled('password')) {
                $employer->password = Hash::make($request->password);
            }

            $employer->save();

            Redis::del("employer:all", "employer:profile:{$employerId}");

            return response()->json([
                'success' => true,
                'data' => $employer,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memperbarui profile mitra',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Kelola Activity
    public function activities($employerId) 
    {
        $key = "employer:activities:{$employerId}";
        $employerActivitiesData = Redis::get($key);

        if(!$employerActivitiesData) {
            $employer = Mitra::find($employerId);

            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nope, we couldn’t find that ID. It’s either gone or never existed 🙄',
                ], 404);
            }

            $activities = Kegiatan::where('id_mitra', $employer->id_mitra)->get();

            Redis::setex("$key", 3600, json_encode($activities));
            return response()->json([
                'success' => true,
                'message' => 'Berhasil mengambil seluruh kegiatan pada mitra ini',
                'data' => $activities
            ], 200);
        } else {
            $activities = json_decode($employerActivitiesData);
            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diambil dari redis',
                'data' => $activities
            ]);
        }
    }

    public function detailActivity($employerId, $activityId) 
    {
        $key = "detail:acitivity:{$employerId}:{$activityId}";
        $detailActivitiData = Redis::get($key);

        if(!$detailActivitiData) 
        {
            $employer = Mitra::find($employerId);

            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nope, we couldn’t find that ID. It’s either gone or never existed 🙄',
                ], 404);
            }

            $activity = Kegiatan::with('benefits', 'kriterias')
                                    ->where('id_mitra', $employer->id_mitra)->find($activityId);

            Redis::setex("$key", 3600, json_encode($activity));

            return response()->json([
                'success' => true,
                'message' => 'Berhasil mengambil detail kegiatan',
                'data' => $activity
            ], 200);
        } else {
            $activity = json_decode($detailActivitiData);
            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diambil dari redis',
                'data' => $activity
            ]);
        }
    }

    public function addActivity(Request $request, $idEmployer) 
    {
        try{
            $employer = Mitra::find($idEmployer);
            if (!$employer) {
                return response()->json([
                    'message' => 'Employer tidak ditemukan.'
                ], 404);
            }

            $kategori = Kategori::find($request->id_kategori);
            if (!$kategori) {
                return response()->json([
                    'message' => 'Kategori tidak ditemukan.'
                ], 404);
            }

            // Upload file gambar
            $gambar = $request->file('gambar');
            $extension = $gambar->getClientOriginalExtension();
            $newName = $request->nama_kegiatan . '-' . Date::now()->timestamp . '.' . $extension;
            $gambarPath = $gambar->storeAs('gambar', $newName, 'public');
            
            $activity = new Kegiatan;
            $activity->id_mitra = $employer->id_mitra;
            $activity->id_kategori = $request->id_kategori;
            $activity->nama_kegiatan = $request->nama_kegiatan;
            $activity->lokasi_kegiatan = $request->lokasi_kegiatan;
            $activity->lama_kegiatan = $request->lama_kegiatan;
            $activity->sistem_kegiatan = $request->sistem_kegiatan;
            $activity->deskripsi = $request->deskripsi;
            $activity->tgl_penutupan = $request->tgl_penutupan;
            $activity->tgl_kegiatan = $request->tgl_kegiatan;
            $activity->gambar = $gambarPath;

            $activity->save();

            return response()->json([
                'message' => 'Kegiatan berhasil ditambahkan',
                'data' => $activity
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menambahkan kegiatan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function editActivity(Request $request, $idEmployer, $idActivity) {
        try{
            $employer = Mitra::find($idEmployer);
            if (!$employer) {
                    return response()->json([
                        'message' => 'Employer tidak ditemukan.'
                    ], 404);
            }

            $activity = Kegiatan::find($idActivity);
            if (!$activity) {
                return response()->json([
                    'message' => 'Kegiatan tidak ditemukan.'
                ], 404);
            }

            $kategori = Kategori::find($request->id_kategori);
            if (!$kategori) {
                return response()->json([
                    'message' => 'Kategori tidak ditemukan.'
                ], 404);
            }

            $activity->id_mitra = $employer->id_mitra;
            $activity->id_kategori = $request->id_kategori ?? $activity->id_kategori;
            $activity->nama_kegiatan = $request->nama_kegiatan ?? $activity->nama_kegiatan;
            $activity->lokasi_kegiatan = $request->lokasi_kegiatan ?? $activity->lokasi_kegiatan;
            $activity->lama_kegiatan = $request->lama_kegiatan ?? $activity->lama_kegiatan;
            $activity->sistem_kegiatan = $request->sistem_kegiatan ?? $activity->sistem_kegiatan;
            $activity->deskripsi = $request->deskripsi ?? $activity->deskripsi;
            $activity->tgl_penutupan = $request->tgl_penutupan ?? $activity->tgl_penutupan;
            $activity->tgl_kegiatan = $request->tgl_kegiatan ?? $activity->tgl_kegiatan;
            // Handle file upload jika ada file gambar
            if ($request->hasFile('gambar')) {
                $this->handleFileUpload($request->file('gambar'), 'kegiatan', $activity, 'gambar');
            }

            $activity->save();

            return response()->json([
                'message' => 'Kegiatan berhasil diperbarui',
                'data' => $activity
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memperbarui kegiatan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteActivity($idEmployer, $idActivity) {
        $employer = Mitra::find($idEmployer);
        if (!$employer) {
            return response()->json([
                'message' => 'Employer tidak ditemukan.'
            ], 404);
        }

        $activity = Kegiatan::where('id_mitra', $employer->id_mitra)->find($idActivity);
        if (!$activity) {
            return response()->json([
                'message' => 'Kegiatan tidak ditemukan.'
            ], 404);
        }
        
        $activity->delete();

        return response()->json([
            'message' => 'Kegiatan berhasil dihapus'
        ], 200);
    }

    // Kelola Benefit
    public function addBenefit(Request $request, $idEmployer, $idActivity){
        $employer = Mitra::find($idEmployer);
        if (!$employer) {
            return response()->json([
                'message' => 'Employer tidak ditemukan.'
            ], 404);
        }

        $activity = Kegiatan::where('id_mitra', $employer->id_mitra)->find($idActivity);
        if (!$activity) {
            return response()->json([
                'message' => 'Kegiatan tidak ditemukan.'
            ], 404);
        }

        $benefit = Benefit::firstOrCreate(['nama_benefit' => $request->input('nama_benefit')]);
        $activity->benefits()->attach($benefit->id_benefit);

        $benefit->save();

        return response()->json([
            'message' => 'Benefit berhasil ditambahkan pada kegiatan ini',
            'data' => $benefit
        ], 201);
    }

    public function deleteBenefit($idEmployer, $idActivity, $idBenefit) {
        $employer = Mitra::find($idEmployer);
        if (!$employer) {
            return response()->json([
                'message' => 'Employer tidak ditemukan.'
            ], 404);
        }

        $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                                ->find($idActivity);
        if (!$activity) {
            return response()->json([
                'message' => 'Kegiatan tidak ditemukan.'
            ], 404);
        }
        
        $benefit = $activity->benefits()->find($idBenefit);
        if (!$benefit) {
            return response()->json([
                'message' => 'Benefit tidak ditemukan.'
            ], 404);
        }

        // Hapus hubungan benefit dengan kegiatan
        $activity->benefits()->detach($benefit->id_benefit);

        // Periksa apakah benefit masih digunakan oleh kegiatan lain
        $otherActivity = $benefit->kegiatans()->count();

        if ($otherActivity > 0) {
            return response()->json([
                'message' => 'Benefit dihapus dari kegiatan ini'
            ], 200);
        }

        $benefit->delete();

        return response()->json([
            'message' => 'Benefit berhasil dihapus'
        ], 200);
    }

    // Kelola Requirement
    public function addRequirement(Request $request, $idEmployer, $idActivity) {
        $employer = Mitra::find($idEmployer);
        if (!$employer) {
            return response()->json([
                'message' => 'Employer tidak ditemukan.'
            ], 404);
        }

        $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                                ->find($idActivity);
        if (!$activity) {
            return response()->json([
                'message' => 'Kegiatan tidak ditemukan.'
            ], 404);
        }

        $requirement = Kriteria::firstOrCreate(['nama_kriteria' => $request->input('nama_kriteria')]);
        $activity->kriterias()->attach($requirement->id_kriteria);

        $requirement->save(); 

        return response()->json([
            'message' => 'Kriteria berhasil ditambahkan pada kegiatan ini',
            'data' => $requirement
        ], 201);
    }

    public function deleteRequirement($idEmployer, $idActivity, $idRequirement) {
        $employer = Mitra::find($idEmployer);
        if (!$employer) {
            return response()->json([
                'message' => 'Employer tidak ditemukan.'
            ], 404);
        }

        $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                                ->find($idActivity);
        if (!$activity) {
            return response()->json([
                'message' => 'Kegiatan tidak ditemukan.'
            ], 404);
        }
        
        $requirement = $activity->kriterias()->find($idRequirement);
        if (!$requirement) {
            return response()->json([
                'message' => 'Kriteria tidak ditemukan.'
            ], 404);
        }

        // Hapus hubungan benefit dengan kegiatan
        $activity->kriterias()->detach($requirement->id_benefit);

        // Periksa apakah benefit masih digunakan oleh kegiatan lain
        $otherActivity = $requirement->kegiatans()->count();

        if ($otherActivity > 0) {
            return response()->json([
                'message' => 'Kriteria dihapus dari kegiatan ini'
            ], 200);
        }

        $requirement->delete();

        return response()->json([
            'message' => 'Kriteria berhasil dihapus'
        ], 200);
    }

    // Kelola Pendaftar
    public function updateApplicant(Request $request, $idEmployer, $idApplicant, $idActivity) {
        try{ 
            $employer = Mitra::find($idEmployer);
            if (!$employer) {
                return response()->json([
                    'message' => 'Employer tidak ditemukan.'
                ], 404);
            }

            $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                            ->where('id_kegiatan', $idActivity)
                            ->first();

            if (!$activity) {
                return response()->json([
                    'message' => 'Kegiatan tidak ditemukan atau bukan kegiatan Anda.'
                ], 404);
            }

            // Cek apakah pendaftar yang dimaksud terdaftar pada kegiatan ini
            $applicant = Pendaftar::where('id_kegiatan', $activity->id_kegiatan)
                                ->where('id_pendaftar', $idApplicant)
                                ->first();

            if (!$applicant) {
                return response()->json([
                    'message' => 'Pendaftar tidak ditemukan untuk kegiatan ini.'
                ], 404);
            }

            $applicant->status_applicant = $request->status_applicant;

            // Tentukan note_to_applicant berdasarkan status_applicant
            switch (ucfirst(strtolower($request->status_applicant))) {
                case 'Shortlist':
                    $applicant->note_to_applicant = "You have been shortlisted.";
                    break;

                case 'Interview':
                    $applicant->note_to_applicant = "You have advanced to the Interview stage.";
                    break;

                case 'Hire':
                case 'Reject':
                    if (!$request->has('note_to_applicant') || empty($request->note_to_applicant)) {
                        return response()->json([
                            'message' => 'Note for Hire or Reject status must be provided.'
                        ], 400);
                    }
                    $applicant->note_to_applicant = $request->note_to_applicant;
                    break;

                default:
                    return response()->json([
                        'message' => 'Invalid status_applicant value.'
                    ], 400);
            }

            $applicant->tgl_note = Date::now();
            $applicant->save();

            return response()->json([
                'message' => 'Update Pendaftar Berhasil',
                'data'=>$applicant
            ], 200);
        } catch (\Exception $e){
            return response()->json([
                'message' => 'Gagal melakukan update pendaftaran',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateInterview(Request $request, $idEmployer, $idApplicant, $idActivity) {
        try{
            $employer = Mitra::find($idEmployer);
            if (!$employer) {
                return response()->json([
                    'message' => 'Employer tidak ditemukan.'
                ], 404);
            }

            $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                            ->where('id_kegiatan', $idActivity)
                            ->first();

            if (!$activity) {
                return response()->json([
                    'message' => 'Kegiatan tidak ditemukan atau bukan kegiatan Anda.'
                ], 404);
            }

            // Cek apakah pendaftar yang dimaksud terdaftar pada kegiatan ini
            $applicant = Pendaftar::where('id_kegiatan', $activity->id_kegiatan)
                                ->where('id_pendaftar', $idApplicant)
                                ->first();

            if (!$applicant) {
                return response()->json([
                    'message' => 'Pendaftar tidak ditemukan untuk kegiatan ini.'
                ], 404);
            }

            $applicant->status_applicant = 'Interview';
            $applicant->tgl_interview = $request->tgl_interview;
            $applicant->lokasi_interview = $request->lokasi_interview;
            $applicant->interview_time = $request->interview_time;
            if (empty($request->input('tgl_interview'))) {
                $applicant->status_interview = 'Not scheduled yet';
            } else {
                $tglInterview = Carbon::parse($request->input('tgl_interview'));
            
                if ($tglInterview->isPast()) {
                    $applicant->status_interview = 'Interview Complete';
                } elseif ($tglInterview->isFuture() || $tglInterview->isToday()) {
                    $applicant->status_interview = 'On progress';
                }
            }
            $applicant->note_interview = $request->note_interview;
            $applicant->note_to_applicant = "Kamu masuk tahap Interview";
            $applicant->tgl_note = Date::now();

            $applicant->save();

            return response()->json([
                'message' => 'Data pendaftar berhasil diperbarui',
                'data' => $applicant
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal memperbarui jadwal Interview',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // INI GUNANYA UNTUK HAPUS FILE LAMA KALAU TERJADI PERUBAHAN
    private function handleFileUpload($file, $directory, $model, $attribute)
    {
        // Hapus file lama jika ada
        if ($model->$attribute) {
            $oldFilePath = storage_path("app/public/{$directory}/{$model->$attribute}");
            if (File::exists($oldFilePath)) {
                File::delete($oldFilePath);
            }
        }

        // Upload file baru
        $fileName = Date::now()->timestamp . '-' . $file->getClientOriginalName();
        $file->storeAs($directory, $fileName, 'public');

        // Simpan nama file baru ke atribut model
        $model->$attribute = $fileName;
    }
}